<?php

if (!function_exists('communication_delivery_policy_ensure_schema')) {
    function communication_delivery_policy_ensure_schema($pdo)
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS communication_delivery_policies (
            admin_id INTEGER PRIMARY KEY,
            enforcement_enabled INTEGER NOT NULL DEFAULT 0,
            paused INTEGER NOT NULL DEFAULT 0,
            hourly_limit INTEGER NOT NULL DEFAULT 100,
            daily_limit INTEGER NOT NULL DEFAULT 500,
            max_attempts INTEGER NOT NULL DEFAULT 3,
            retry_base_seconds INTEGER NOT NULL DEFAULT 900,
            updated_by_admin_id INTEGER,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');
        $pdo->exec('INSERT OR IGNORE INTO communication_delivery_policies
            (admin_id,enforcement_enabled,paused,hourly_limit,daily_limit,max_attempts,retry_base_seconds)
            VALUES (0,0,0,100,500,3,900)');
    }
}

if (!function_exists('communication_delivery_policy_clamp')) {
    function communication_delivery_policy_clamp($value, $min, $max)
    {
        return max((int)$min, min((int)$max, (int)$value));
    }
}

if (!function_exists('communication_delivery_policy_get')) {
    function communication_delivery_policy_get($pdo, $adminId)
    {
        communication_delivery_policy_ensure_schema($pdo);
        $adminId = max(0, (int)$adminId);
        $st = $pdo->prepare('SELECT * FROM communication_delivery_policies WHERE admin_id IN (0,:aid) ORDER BY CASE WHEN admin_id=:aid2 THEN 0 ELSE 1 END LIMIT 1');
        $st->execute(array(':aid' => $adminId, ':aid2' => $adminId));
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: array('admin_id'=>0,'enforcement_enabled'=>0,'paused'=>0,'hourly_limit'=>100,'daily_limit'=>500,'max_attempts'=>3,'retry_base_seconds'=>900);
    }
}

if (!function_exists('communication_delivery_policy_save')) {
    function communication_delivery_policy_save($pdo, $adminId, $values, $updatedByAdminId)
    {
        communication_delivery_policy_ensure_schema($pdo);
        $adminId = max(0, (int)$adminId);
        $values = is_array($values) ? $values : array();
        $row = array(
            ':aid'=>$adminId,
            ':en'=>!empty($values['enforcement_enabled']) ? 1 : 0,
            ':pa'=>!empty($values['paused']) ? 1 : 0,
            ':hl'=>communication_delivery_policy_clamp(isset($values['hourly_limit'])?$values['hourly_limit']:100, 1, 100000),
            ':dl'=>communication_delivery_policy_clamp(isset($values['daily_limit'])?$values['daily_limit']:500, 1, 1000000),
            ':ma'=>communication_delivery_policy_clamp(isset($values['max_attempts'])?$values['max_attempts']:3, 1, 10),
            ':rb'=>communication_delivery_policy_clamp(isset($values['retry_base_seconds'])?$values['retry_base_seconds']:900, 0, 86400),
            ':uid'=>(int)$updatedByAdminId,
        );
        if ($row[':dl'] < $row[':hl']) $row[':dl'] = $row[':hl'];
        $st = $pdo->prepare('INSERT INTO communication_delivery_policies
            (admin_id,enforcement_enabled,paused,hourly_limit,daily_limit,max_attempts,retry_base_seconds,updated_by_admin_id,created_at,updated_at)
            VALUES (:aid,:en,:pa,:hl,:dl,:ma,:rb,:uid,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)
            ON CONFLICT(admin_id) DO UPDATE SET enforcement_enabled=excluded.enforcement_enabled,paused=excluded.paused,hourly_limit=excluded.hourly_limit,daily_limit=excluded.daily_limit,max_attempts=excluded.max_attempts,retry_base_seconds=excluded.retry_base_seconds,updated_by_admin_id=excluded.updated_by_admin_id,updated_at=CURRENT_TIMESTAMP');
        $st->execute($row);
        return communication_delivery_policy_get($pdo, $adminId);
    }
}

if (!function_exists('communication_delivery_policy_usage')) {
    function communication_delivery_policy_usage($pdo, $adminId)
    {
        communication_delivery_policy_ensure_schema($pdo);
        $adminId = (int)$adminId;
        $base = ' FROM communication_campaign_run_recipients rr JOIN communication_campaign_runs r ON r.id=rr.run_id JOIN communication_campaigns c ON c.id=r.campaign_id WHERE rr.status=\'accepted\' AND c.created_by_admin_id=:aid AND rr.processed_at >= ';
        $hour = $pdo->prepare('SELECT COUNT(*)'.$base.'datetime(\'now\',\'-1 hour\')');
        $day = $pdo->prepare('SELECT COUNT(*)'.$base.'datetime(\'now\',\'-1 day\')');
        $hour->execute(array(':aid'=>$adminId)); $day->execute(array(':aid'=>$adminId));
        return array('hour'=>(int)$hour->fetchColumn(),'day'=>(int)$day->fetchColumn());
    }
}

if (!function_exists('communication_delivery_policy_allowance')) {
    function communication_delivery_policy_allowance($pdo, $adminId, $requested)
    {
        $policy = communication_delivery_policy_get($pdo, $adminId);
        $usage = communication_delivery_policy_usage($pdo, $adminId);
        $requested = max(0, (int)$requested);
        $allowed = $requested;
        $reason = 'observation';
        if (!empty($policy['enforcement_enabled'])) {
            if (!empty($policy['paused'])) { $allowed = 0; $reason = 'paused'; }
            else {
                $allowed = min($requested, max(0, (int)$policy['hourly_limit']-$usage['hour']), max(0, (int)$policy['daily_limit']-$usage['day']));
                $reason = $allowed < $requested ? 'rate_limit' : 'allowed';
            }
        }
        return array('allowed'=>$allowed,'reason'=>$reason,'usage'=>$usage,'policy'=>$policy);
    }
}

if (!function_exists('communication_delivery_policy_retry_decision')) {
    function communication_delivery_policy_retry_decision($transportStatus, $attemptNo, $policy)
    {
        $status = (string)$transportStatus;
        $attemptNo = max(1, (int)$attemptNo);
        $max = max(1, (int)$policy['max_attempts']);
        if ($status !== 'transient_error' || $attemptNo >= $max) {
            return array('status'=>$status,'retry'=>false,'delay_seconds'=>0);
        }
        $base = max(0, (int)$policy['retry_base_seconds']);
        $delay = min(86400, (int)($base * pow(2, $attemptNo-1)));
        return array('status'=>'queued','retry'=>true,'delay_seconds'=>$delay);
    }
}

if (!function_exists('communication_delivery_policy_dashboard')) {
    function communication_delivery_policy_dashboard($pdo)
    {
        communication_delivery_policy_ensure_schema($pdo);
        $sql = "SELECT a.id,a.email,COALESCE(NULLIF(TRIM(COALESCE(a.nombre,'')||' '||COALESCE(a.apellido,'')),''),a.email) AS display_name,
            COALESCE(p.enforcement_enabled,g.enforcement_enabled) enforcement_enabled,COALESCE(p.paused,g.paused) paused,
            COALESCE(p.hourly_limit,g.hourly_limit) hourly_limit,COALESCE(p.daily_limit,g.daily_limit) daily_limit,
            COALESCE(p.max_attempts,g.max_attempts) max_attempts,COALESCE(p.retry_base_seconds,g.retry_base_seconds) retry_base_seconds,
            (SELECT COUNT(*) FROM communication_campaign_run_recipients rr JOIN communication_campaign_runs r ON r.id=rr.run_id JOIN communication_campaigns c ON c.id=r.campaign_id WHERE rr.status='accepted' AND c.created_by_admin_id=a.id AND rr.processed_at>=datetime('now','-1 hour')) sent_hour,
            (SELECT COUNT(*) FROM communication_campaign_run_recipients rr JOIN communication_campaign_runs r ON r.id=rr.run_id JOIN communication_campaigns c ON c.id=r.campaign_id WHERE rr.status='accepted' AND c.created_by_admin_id=a.id AND rr.processed_at>=datetime('now','-1 day')) sent_day,
            (SELECT COUNT(*) FROM communication_execution_commands ec JOIN communication_campaigns c ON c.id=ec.campaign_id WHERE c.created_by_admin_id=a.id AND ec.status IN ('queued','processing')) pending_commands
            FROM usuarios_admin a CROSS JOIN communication_delivery_policies g LEFT JOIN communication_delivery_policies p ON p.admin_id=a.id
            WHERE g.admin_id=0 AND a.tipo_global='admin_evento' AND COALESCE(a.activo,1)=1 ORDER BY display_name";
        try { return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC); } catch (Exception $e) { return array(); }
    }
}
