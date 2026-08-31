<?php
require_once __DIR__ . '/communication_execution_engine.php';
require_once __DIR__ . '/communication_transport.php';
require_once __DIR__ . '/communication_campaigns.php';
require_once __DIR__ . '/communication_contacts.php';
require_once __DIR__ . '/communication_templates.php';
require_once __DIR__ . '/communication_template_renderer.php';

if (!function_exists('communication_module_version')) {
    function communication_module_version()
    {
        return '6.0.0';
    }
}

if (!function_exists('communication_ops_scope_sql')) {
    function communication_ops_scope_sql($isSuper, $campaignAlias)
    {
        $campaignAlias = trim((string)$campaignAlias);
        if ($campaignAlias === '') $campaignAlias = 'c';

        $sql = $campaignAlias . '.organization_id = :org';
        if (!$isSuper) {
            $sql .= ' AND ' . $campaignAlias . '.created_by_admin_id = :aid';
        }
        return $sql;
    }
}

if (!function_exists('communication_ops_scope_params')) {
    function communication_ops_scope_params($organizationId, $adminId, $isSuper)
    {
        $params = array(':org' => (int)$organizationId);
        if (!$isSuper) {
            $params[':aid'] = (int)$adminId;
        }
        return $params;
    }
}

if (!function_exists('communication_ops_ensure_schema')) {
    function communication_ops_ensure_schema($pdo)
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS communication_module_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            organization_id INTEGER NOT NULL DEFAULT 1,
            campaign_id INTEGER,
            run_id INTEGER,
            command_id INTEGER,
            component TEXT NOT NULL,
            level TEXT NOT NULL DEFAULT "info",
            event_name TEXT NOT NULL,
            message TEXT,
            context_json TEXT,
            event_key TEXT
        )');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_comm_mod_logs_created ON communication_module_logs(created_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_comm_mod_logs_component ON communication_module_logs(component, level)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_comm_mod_logs_campaign ON communication_module_logs(campaign_id, run_id)');
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_comm_mod_logs_event_key ON communication_module_logs(event_key)');
    }
}

if (!function_exists('communication_ops_log')) {
    function communication_ops_log($pdo, $organizationId, $component, $eventName, $level, $message, $context, $eventKey)
    {
        communication_ops_ensure_schema($pdo);

        $component = trim((string)$component);
        $eventName = trim((string)$eventName);
        $level = strtolower(trim((string)$level));
        $message = (string)$message;
        $context = is_array($context) ? $context : array();
        $eventKey = trim((string)$eventKey);

        if ($component === '') $component = 'general';
        if ($eventName === '') $eventName = 'event';
        if (!in_array($level, array('debug', 'info', 'warning', 'error'), true)) {
            $level = 'info';
        }

        try {
            $st = $pdo->prepare('INSERT INTO communication_module_logs (organization_id, campaign_id, run_id, command_id, component, level, event_name, message, context_json, event_key, created_at) VALUES (:org, :cid, :rid, :cmd, :cmp, :lvl, :ev, :msg, :ctx, :ek, CURRENT_TIMESTAMP)');
            $st->execute(array(
                ':org' => (int)$organizationId,
                ':cid' => isset($context['campaign_id']) ? (int)$context['campaign_id'] : null,
                ':rid' => isset($context['run_id']) ? (int)$context['run_id'] : null,
                ':cmd' => isset($context['command_id']) ? (int)$context['command_id'] : null,
                ':cmp' => $component,
                ':lvl' => $level,
                ':ev' => $eventName,
                ':msg' => ($message !== '' ? $message : null),
                ':ctx' => !empty($context) ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                ':ek' => ($eventKey !== '' ? $eventKey : null),
            ));
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
}

if (!function_exists('communication_ops_validate_campaign_scope')) {
    function communication_ops_validate_campaign_scope($pdo, $campaignId, $organizationId, $adminId, $isSuper)
    {
        $scopeSql = communication_ops_scope_sql($isSuper, 'c');
        $params = communication_ops_scope_params($organizationId, $adminId, $isSuper);
        $st = $pdo->prepare('SELECT c.* FROM communication_campaigns c WHERE c.id = :id AND ' . $scopeSql . ' LIMIT 1');
        $st->execute(array(':id' => (int)$campaignId) + $params);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ? $row : null;
    }
}

if (!function_exists('communication_ops_validate_run_scope')) {
    function communication_ops_validate_run_scope($pdo, $runId, $organizationId, $adminId, $isSuper)
    {
        $scopeSql = communication_ops_scope_sql($isSuper, 'c');
        $params = communication_ops_scope_params($organizationId, $adminId, $isSuper);
        $sql = 'SELECT r.*, c.name AS campaign_name, c.status AS campaign_status, c.created_by_admin_id AS campaign_admin_id
                FROM communication_campaign_runs r
                JOIN communication_campaigns c ON c.id = r.campaign_id
                WHERE r.id = :id AND ' . $scopeSql . ' LIMIT 1';
        $st = $pdo->prepare($sql);
        $st->execute(array(':id' => (int)$runId) + $params);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ? $row : null;
    }
}

if (!function_exists('communication_ops_fetch_engine_state')) {
    function communication_ops_fetch_engine_state($pdo, $organizationId, $adminId, $isSuper)
    {
        communication_execution_ensure_schema($pdo);
        communication_transport_ensure_schema($pdo);
        communication_ops_ensure_schema($pdo);

        $scopeSql = communication_ops_scope_sql($isSuper, 'c');
        $scopeParams = communication_ops_scope_params($organizationId, $adminId, $isSuper);

        $counts = array(
            'campaigns_queued' => 0,
            'campaigns_running' => 0,
            'campaigns_finished' => 0,
            'campaigns_failed' => 0,
            'active_runs' => 0,
            'queue_pending' => 0,
        );

        $sqlQueue = 'SELECT COUNT(DISTINCT c.id) AS n
                     FROM communication_execution_commands cmd
                     JOIN communication_campaigns c ON c.id = cmd.campaign_id
                     WHERE cmd.status = :queued AND ' . $scopeSql;
        $stQueue = $pdo->prepare($sqlQueue);
        $stQueue->execute(array(':queued' => 'queued') + $scopeParams);
        $counts['campaigns_queued'] = (int)$stQueue->fetchColumn();

        $sqlRunning = 'SELECT COUNT(*) FROM communication_campaigns c WHERE c.status = :st AND ' . $scopeSql;
        $stRunning = $pdo->prepare($sqlRunning);
        $stRunning->execute(array(':st' => 'sending') + $scopeParams);
        $counts['campaigns_running'] = (int)$stRunning->fetchColumn();

        $sqlFinished = 'SELECT COUNT(*) FROM communication_campaigns c WHERE c.status = :st AND ' . $scopeSql;
        $stFinished = $pdo->prepare($sqlFinished);
        $stFinished->execute(array(':st' => 'sent') + $scopeParams);
        $counts['campaigns_finished'] = (int)$stFinished->fetchColumn();

        $sqlFailed = 'SELECT COUNT(*) FROM communication_campaigns c WHERE c.status = :st AND ' . $scopeSql;
        $stFailed = $pdo->prepare($sqlFailed);
        $stFailed->execute(array(':st' => 'failed') + $scopeParams);
        $counts['campaigns_failed'] = (int)$stFailed->fetchColumn();

        $sqlActiveRuns = 'SELECT COUNT(*)
                          FROM communication_campaign_runs r
                          JOIN communication_campaigns c ON c.id = r.campaign_id
                          WHERE r.status IN (\'requested\',\'preparing\',\'running\',\'finalizing\') AND ' . $scopeSql;
        $stActiveRuns = $pdo->prepare($sqlActiveRuns);
        $stActiveRuns->execute($scopeParams);
        $counts['active_runs'] = (int)$stActiveRuns->fetchColumn();

        $sqlQueuePending = 'SELECT COUNT(*)
                            FROM communication_execution_commands cmd
                            JOIN communication_campaigns c ON c.id = cmd.campaign_id
                            WHERE cmd.status = :queued AND (cmd.scheduled_for IS NULL OR cmd.scheduled_for <= CURRENT_TIMESTAMP) AND ' . $scopeSql;
        $stQueuePending = $pdo->prepare($sqlQueuePending);
        $stQueuePending->execute(array(':queued' => 'queued') + $scopeParams);
        $counts['queue_pending'] = (int)$stQueuePending->fetchColumn();

        $recipientByStatus = array();
        $sqlRecipients = 'SELECT rr.status, COUNT(*) AS n
                          FROM communication_campaign_run_recipients rr
                          JOIN communication_campaign_runs r ON r.id = rr.run_id
                          JOIN communication_campaigns c ON c.id = r.campaign_id
                          WHERE ' . $scopeSql . '
                          GROUP BY rr.status
                          ORDER BY n DESC';
        $stRecipients = $pdo->prepare($sqlRecipients);
        $stRecipients->execute($scopeParams);
        while ($r = $stRecipients->fetch(PDO::FETCH_ASSOC)) {
            $recipientByStatus[] = array(
                'status' => isset($r['status']) ? (string)$r['status'] : '',
                'count' => isset($r['n']) ? (int)$r['n'] : 0,
            );
        }

        $runProgress = array();
        $sqlRuns = 'SELECT r.id, r.campaign_id, c.name AS campaign_name, r.status, r.started_at, r.finished_at,
                           r.resolved_recipients, r.processed_count, r.accepted_count, r.rejected_count,
                           r.transient_error_count, r.permanent_error_count, r.skipped_duplicate_count,
                           cmd.id AS command_id, cmd.status AS command_status
                    FROM communication_campaign_runs r
                    JOIN communication_campaigns c ON c.id = r.campaign_id
                    LEFT JOIN communication_execution_commands cmd ON cmd.id = r.command_id
                    WHERE ' . $scopeSql . '
                    ORDER BY CASE WHEN r.status IN (\'requested\',\'preparing\',\'running\',\'finalizing\') THEN 0 ELSE 1 END, r.id DESC
                    LIMIT 25';
        $stRuns = $pdo->prepare($sqlRuns);
        $stRuns->execute($scopeParams);
        while ($row = $stRuns->fetch(PDO::FETCH_ASSOC)) {
            $resolved = isset($row['resolved_recipients']) ? (int)$row['resolved_recipients'] : 0;
            $processed = isset($row['processed_count']) ? (int)$row['processed_count'] : 0;
            $progressPct = ($resolved > 0) ? (int)floor(($processed * 100) / $resolved) : 0;
            if ($progressPct > 100) $progressPct = 100;

            $runProgress[] = array(
                'run_id' => (int)$row['id'],
                'campaign_id' => (int)$row['campaign_id'],
                'campaign_name' => isset($row['campaign_name']) ? (string)$row['campaign_name'] : '',
                'status' => isset($row['status']) ? (string)$row['status'] : '',
                'started_at' => isset($row['started_at']) ? (string)$row['started_at'] : null,
                'finished_at' => isset($row['finished_at']) ? (string)$row['finished_at'] : null,
                'resolved_recipients' => $resolved,
                'processed_count' => $processed,
                'accepted_count' => isset($row['accepted_count']) ? (int)$row['accepted_count'] : 0,
                'failed_count' => (isset($row['rejected_count']) ? (int)$row['rejected_count'] : 0) + (isset($row['transient_error_count']) ? (int)$row['transient_error_count'] : 0) + (isset($row['permanent_error_count']) ? (int)$row['permanent_error_count'] : 0),
                'pending_count' => max(0, $resolved - $processed),
                'progress_pct' => $progressPct,
                'command_id' => isset($row['command_id']) ? (int)$row['command_id'] : 0,
                'command_status' => isset($row['command_status']) ? (string)$row['command_status'] : '',
            );
        }

        return array(
            'counts' => $counts,
            'recipient_by_status' => $recipientByStatus,
            'run_progress' => $runProgress,
        );
    }
}

if (!function_exists('communication_ops_fetch_campaigns')) {
    function communication_ops_fetch_campaigns($pdo, $organizationId, $adminId, $isSuper)
    {
        $scopeSql = communication_ops_scope_sql($isSuper, 'c');
        $scopeParams = communication_ops_scope_params($organizationId, $adminId, $isSuper);
        $sql = 'SELECT c.id, c.name, c.slug, c.status, c.updated_at
                FROM communication_campaigns c
                WHERE ' . $scopeSql . '
                ORDER BY c.updated_at DESC, c.id DESC
                LIMIT 200';
        $st = $pdo->prepare($sql);
        $st->execute($scopeParams);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('communication_ops_fetch_campaigns_by_status')) {
    function communication_ops_fetch_campaigns_by_status($pdo, $organizationId, $adminId, $isSuper, $statuses, $limit)
    {
        $statuses = is_array($statuses) ? $statuses : array();
        $clean = array();
        foreach ($statuses as $st) {
            $st = trim((string)$st);
            if ($st !== '') $clean[] = $st;
        }
        if (empty($clean)) {
            return array();
        }

        $limit = (int)$limit;
        if ($limit <= 0) $limit = 100;

        $scopeSql = communication_ops_scope_sql($isSuper, 'c');
        $scopeParams = communication_ops_scope_params($organizationId, $adminId, $isSuper);

        $in = array();
        foreach ($clean as $i => $st) {
            $k = ':st' . $i;
            $in[] = $k;
            $scopeParams[$k] = $st;
        }

        $sql = 'SELECT c.id, c.name, c.slug, c.status, c.updated_at, c.sending_started_at, c.sent_at, c.failed_at,
                       a.name AS audience_name, t.name AS template_name
                FROM communication_campaigns c
                LEFT JOIN communication_audiences a ON a.id = c.audience_id
                LEFT JOIN communication_templates t ON t.id = c.template_id
                WHERE ' . $scopeSql . ' AND c.status IN (' . implode(',', $in) . ')
                ORDER BY c.updated_at DESC, c.id DESC
                LIMIT :lim';

        $st = $pdo->prepare($sql);
        foreach ($scopeParams as $k => $v) {
            if ($k === ':lim') continue;
            $st->bindValue($k, $v);
        }
        $st->bindValue(':lim', $limit, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('communication_ops_fetch_pending_commands')) {
    function communication_ops_fetch_pending_commands($pdo, $organizationId, $adminId, $isSuper, $limit)
    {
        $limit = (int)$limit;
        if ($limit <= 0) $limit = 100;

        $scopeSql = communication_ops_scope_sql($isSuper, 'c');
        $scopeParams = communication_ops_scope_params($organizationId, $adminId, $isSuper);

        $sql = 'SELECT cmd.id, cmd.campaign_id, cmd.status, cmd.scheduled_for, cmd.created_at, cmd.updated_at, cmd.error_text,
                       c.name AS campaign_name, c.status AS campaign_status
                FROM communication_execution_commands cmd
                JOIN communication_campaigns c ON c.id = cmd.campaign_id
                WHERE ' . $scopeSql . ' AND cmd.status IN (\'queued\',\'processing\',\'failed\')
                ORDER BY cmd.id DESC
                LIMIT :lim';
        $st = $pdo->prepare($sql);
        foreach ($scopeParams as $k => $v) {
            $st->bindValue($k, $v);
        }
        $st->bindValue(':lim', $limit, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('communication_ops_fetch_run_history')) {
    function communication_ops_fetch_run_history($pdo, $organizationId, $adminId, $isSuper, $campaignId)
    {
        $campaign = communication_ops_validate_campaign_scope($pdo, $campaignId, $organizationId, $adminId, $isSuper);
        if (!$campaign) {
            return array('campaign' => null, 'runs' => array());
        }

        $st = $pdo->prepare('SELECT r.*, cmd.status AS command_status
                             FROM communication_campaign_runs r
                             LEFT JOIN communication_execution_commands cmd ON cmd.id = r.command_id
                             WHERE r.campaign_id = :cid
                             ORDER BY r.id DESC');
        $st->execute(array(':cid' => (int)$campaignId));
        $runs = array();
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $startedAt = isset($row['started_at']) ? (string)$row['started_at'] : '';
            $finishedAt = isset($row['finished_at']) ? (string)$row['finished_at'] : '';
            $durationSeconds = null;
            if ($startedAt !== '' && $finishedAt !== '') {
                $ts1 = strtotime($startedAt);
                $ts2 = strtotime($finishedAt);
                if ($ts1 !== false && $ts2 !== false && $ts2 >= $ts1) {
                    $durationSeconds = (int)($ts2 - $ts1);
                }
            }

            $resolved = isset($row['resolved_recipients']) ? (int)$row['resolved_recipients'] : 0;
            $sent = isset($row['accepted_count']) ? (int)$row['accepted_count'] : 0;
            $failed = (isset($row['rejected_count']) ? (int)$row['rejected_count'] : 0) + (isset($row['transient_error_count']) ? (int)$row['transient_error_count'] : 0) + (isset($row['permanent_error_count']) ? (int)$row['permanent_error_count'] : 0);
            $pending = max(0, $resolved - (isset($row['processed_count']) ? (int)$row['processed_count'] : 0));
            $engagement = communication_tracking_run_metrics($pdo, (int)$row['id']);

            $runs[] = array(
                'id' => (int)$row['id'],
                'status' => isset($row['status']) ? (string)$row['status'] : '',
                'command_id' => isset($row['command_id']) ? (int)$row['command_id'] : 0,
                'command_status' => isset($row['command_status']) ? (string)$row['command_status'] : '',
                'started_at' => $startedAt,
                'finished_at' => $finishedAt,
                'duration_seconds' => $durationSeconds,
                'resolved_recipients' => $resolved,
                'sent_count' => $sent,
                'failed_count' => $failed,
                'pending_count' => $pending,
                'processed_count' => isset($row['processed_count']) ? (int)$row['processed_count'] : 0,
                'unique_opens' => (int)$engagement['unique_opens'],
                'total_opens' => (int)$engagement['total_opens'],
                'unique_clicks' => (int)$engagement['unique_clicks'],
                'total_clicks' => (int)$engagement['total_clicks'],
                'confirmed_orders' => (int)$engagement['confirmed_orders'],
                'revenue' => (float)$engagement['revenue'],
            );
        }

        return array('campaign' => $campaign, 'runs' => $runs);
    }
}

if (!function_exists('communication_ops_fetch_run_detail')) {
    function communication_ops_fetch_run_detail($pdo, $organizationId, $adminId, $isSuper, $runId, $limit, $offset)
    {
        $run = communication_ops_validate_run_scope($pdo, $runId, $organizationId, $adminId, $isSuper);
        if (!$run) {
            return array('run' => null, 'recipients' => array(), 'total' => 0, 'metrics' => communication_tracking_run_metrics($pdo, 0));
        }

        $limit = (int)$limit;
        $offset = (int)$offset;
        if ($limit <= 0) $limit = 100;
        if ($offset < 0) $offset = 0;

        $stCount = $pdo->prepare('SELECT COUNT(*) FROM communication_campaign_run_recipients WHERE run_id = :rid');
        $stCount->execute(array(':rid' => (int)$runId));
        $total = (int)$stCount->fetchColumn();

        $st = $pdo->prepare('SELECT id, recipient_email, recipient_name, status, provider_name, last_response_code, last_response_message, attempt_count, last_attempt_at, processed_at
                             FROM communication_campaign_run_recipients
                             WHERE run_id = :rid
                             ORDER BY id ASC
                             LIMIT :lim OFFSET :off');
        $st->bindValue(':rid', (int)$runId, PDO::PARAM_INT);
        $st->bindValue(':lim', (int)$limit, PDO::PARAM_INT);
        $st->bindValue(':off', (int)$offset, PDO::PARAM_INT);
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        return array('run' => $run, 'recipients' => $rows, 'total' => $total, 'metrics' => communication_tracking_run_metrics($pdo, (int)$runId));
    }
}

if (!function_exists('communication_ops_action_requeue_campaign')) {
    function communication_ops_action_requeue_campaign($pdo, $organizationId, $adminId, $isSuper, $campaignId, $source)
    {
        $campaign = communication_ops_validate_campaign_scope($pdo, $campaignId, $organizationId, $adminId, $isSuper);
        if (!$campaign) {
            return array('ok' => false, 'error' => 'Campana no accesible.');
        }

        $requestKey = sha1('requeue|' . (int)$campaignId . '|' . (int)$adminId . '|' . microtime(true));
        $enqueue = communication_execution_enqueue_campaign($pdo, $organizationId, $campaignId, $adminId, $isSuper, array('request_key' => $requestKey));
        if (!empty($enqueue['ok'])) {
            communication_ops_log($pdo, $organizationId, 'campaigns', 'campaign.requeued', 'info', 'Campana reencolada.', array(
                'campaign_id' => (int)$campaignId,
                'command_id' => isset($enqueue['command_id']) ? (int)$enqueue['command_id'] : 0,
                'source' => (string)$source,
            ), 'campaign.requeued|' . (int)$campaignId . '|' . (int)$enqueue['command_id']);
        }
        return $enqueue;
    }
}

if (!function_exists('communication_ops_action_retry_run')) {
    function communication_ops_action_retry_run($pdo, $organizationId, $adminId, $isSuper, $runId, $source)
    {
        $run = communication_ops_validate_run_scope($pdo, $runId, $organizationId, $adminId, $isSuper);
        if (!$run) {
            return array('ok' => false, 'error' => 'Run no accesible.');
        }

        $campaignId = isset($run['campaign_id']) ? (int)$run['campaign_id'] : 0;
        if ($campaignId <= 0) {
            return array('ok' => false, 'error' => 'Run sin campana asociada.');
        }

        $requestKey = sha1('retry_run|' . (int)$runId . '|' . (int)$adminId . '|' . microtime(true));
        $enqueue = communication_execution_enqueue_campaign($pdo, $organizationId, $campaignId, $adminId, $isSuper, array('request_key' => $requestKey));
        if (!empty($enqueue['ok'])) {
            communication_ops_log($pdo, $organizationId, 'engine', 'run.retry_requested', 'warning', 'Solicitado reintento de run.', array(
                'campaign_id' => $campaignId,
                'run_id' => (int)$runId,
                'command_id' => isset($enqueue['command_id']) ? (int)$enqueue['command_id'] : 0,
                'source' => (string)$source,
            ), 'run.retry_requested|' . (int)$runId . '|' . (int)$enqueue['command_id']);
        }
        return $enqueue;
    }
}

if (!function_exists('communication_ops_action_resume_run')) {
    function communication_ops_action_resume_run($pdo, $organizationId, $adminId, $isSuper, $runId, $source)
    {
        $run = communication_ops_validate_run_scope($pdo, $runId, $organizationId, $adminId, $isSuper);
        if (!$run) {
            return array('ok' => false, 'error' => 'Run no accesible.');
        }

        $runStatus = isset($run['status']) ? (string)$run['status'] : '';
        if (!in_array($runStatus, array('requested', 'preparing', 'running', 'failed'), true)) {
            return array('ok' => false, 'error' => 'El run no esta en un estado reanudable.');
        }

        $campaignId = isset($run['campaign_id']) ? (int)$run['campaign_id'] : 0;
        $requestKey = sha1('resume_run|' . (int)$runId . '|' . (int)$adminId . '|' . microtime(true));
        $enqueue = communication_execution_enqueue_campaign($pdo, $organizationId, $campaignId, $adminId, $isSuper, array('request_key' => $requestKey));
        if (!empty($enqueue['ok'])) {
            communication_ops_log($pdo, $organizationId, 'engine', 'run.resume_requested', 'warning', 'Solicitada reanudacion de run.', array(
                'campaign_id' => $campaignId,
                'run_id' => (int)$runId,
                'command_id' => isset($enqueue['command_id']) ? (int)$enqueue['command_id'] : 0,
                'source' => (string)$source,
            ), 'run.resume_requested|' . (int)$runId . '|' . (int)$enqueue['command_id']);
        }
        return $enqueue;
    }
}

if (!function_exists('communication_ops_action_cancel_run')) {
    function communication_ops_action_cancel_run($pdo, $organizationId, $adminId, $isSuper, $runId, $source)
    {
        $run = communication_ops_validate_run_scope($pdo, $runId, $organizationId, $adminId, $isSuper);
        if (!$run) {
            return array('ok' => false, 'error' => 'Run no accesible.');
        }

        $runStatus = isset($run['status']) ? (string)$run['status'] : '';
        if (in_array($runStatus, array('completed', 'cancelled'), true)) {
            return array('ok' => false, 'error' => 'El run ya esta finalizado.');
        }

        $pdo->beginTransaction();
        try {
            $runId = (int)$runId;
            $campaignId = isset($run['campaign_id']) ? (int)$run['campaign_id'] : 0;
            $commandId = isset($run['command_id']) ? (int)$run['command_id'] : 0;

            $stRun = $pdo->prepare('UPDATE communication_campaign_runs SET status = :st, finished_at = COALESCE(finished_at, CURRENT_TIMESTAMP), last_error = :er, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
            $stRun->execute(array(':st' => 'cancelled', ':er' => 'Cancelado administrativamente', ':id' => $runId));

            $stRc = $pdo->prepare('UPDATE communication_campaign_run_recipients SET status = :st, last_error = :er, processed_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE run_id = :rid AND status IN (\'queued\',\'processing\',\'transient_error\')');
            $stRc->execute(array(':st' => 'cancelled', ':er' => 'Cancelado administrativamente', ':rid' => $runId));

            if ($commandId > 0) {
                $stCmd = $pdo->prepare('UPDATE communication_execution_commands SET status = :st, error_text = :er, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND status IN (\'queued\',\'processing\')');
                $stCmd->execute(array(':st' => 'cancelled', ':er' => 'Cancelado por operacion manual', ':id' => $commandId));
            }

            if ($campaignId > 0) {
                $stCampaign = $pdo->prepare('UPDATE communication_campaigns SET status = :st, cancelled_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND status = :sending');
                $stCampaign->execute(array(':st' => 'cancelled', ':id' => $campaignId, ':sending' => 'sending'));
            }

            $pdo->commit();

            communication_ops_log($pdo, $organizationId, 'engine', 'run.cancelled', 'warning', 'Run cancelado manualmente.', array(
                'campaign_id' => isset($run['campaign_id']) ? (int)$run['campaign_id'] : 0,
                'run_id' => (int)$runId,
                'command_id' => isset($run['command_id']) ? (int)$run['command_id'] : 0,
                'source' => (string)$source,
            ), 'run.cancelled|' . (int)$runId . '|' . gmdate('YmdHis'));

            return array('ok' => true);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return array('ok' => false, 'error' => 'No se pudo cancelar run: ' . $e->getMessage());
        }
    }
}

if (!function_exists('communication_ops_health_check')) {
    function communication_ops_health_check($pdo, $organizationId, $adminId, $isSuper)
    {
        communication_execution_ensure_schema($pdo);
        communication_transport_ensure_schema($pdo);
        communication_ops_ensure_schema($pdo);

        $transport = communication_transport_health_check($pdo, $organizationId, 'email');

        $scopeSql = communication_ops_scope_sql($isSuper, 'c');
        $scopeParams = communication_ops_scope_params($organizationId, $adminId, $isSuper);

        $stPending = $pdo->prepare('SELECT COUNT(*) FROM communication_execution_commands cmd JOIN communication_campaigns c ON c.id = cmd.campaign_id WHERE cmd.status = :st AND ' . $scopeSql);
        $stPending->execute(array(':st' => 'queued') + $scopeParams);
        $pending = (int)$stPending->fetchColumn();

        $stLastProcessing = $pdo->prepare('SELECT MAX(cmd.updated_at) FROM communication_execution_commands cmd JOIN communication_campaigns c ON c.id = cmd.campaign_id WHERE cmd.status IN (\'done\',\'failed\',\'cancelled\') AND ' . $scopeSql);
        $stLastProcessing->execute($scopeParams);
        $lastProcessing = $stLastProcessing->fetchColumn();

        $stLastErr = $pdo->prepare('SELECT cmd.error_text
                                    FROM communication_execution_commands cmd
                                    JOIN communication_campaigns c ON c.id = cmd.campaign_id
                                    WHERE cmd.status = :st AND cmd.error_text IS NOT NULL AND cmd.error_text <> "" AND ' . $scopeSql . '
                                    ORDER BY cmd.updated_at DESC, cmd.id DESC
                                    LIMIT 1');
        $stLastErr->execute(array(':st' => 'failed') + $scopeParams);
        $lastError = $stLastErr->fetchColumn();

        $engineStatus = 'healthy';
        if ($pending > 0 && empty($lastProcessing)) {
            $engineStatus = 'degraded';
        }
        if (!empty($lastError)) {
            $engineStatus = 'degraded';
        }

        $providerName = isset($transport['provider_name']) ? (string)$transport['provider_name'] : 'legacy_mail_php';

        return array(
            'engine_status' => $engineStatus,
            'transport_status' => isset($transport['overall_status']) ? (string)$transport['overall_status'] : 'unknown',
            'provider_name' => $providerName,
            'queue_pending' => $pending,
            'last_processing_at' => ($lastProcessing ? (string)$lastProcessing : null),
            'last_error' => ($lastError ? (string)$lastError : null),
            'module_version' => communication_module_version(),
            'transport' => $transport,
        );
    }
}

if (!function_exists('communication_ops_integrity_checks')) {
    function communication_ops_integrity_checks($pdo, $organizationId, $adminId, $isSuper)
    {
        communication_execution_ensure_schema($pdo);
        communication_transport_ensure_schema($pdo);

        $scopeSql = communication_ops_scope_sql($isSuper, 'c');
        $scopeParams = communication_ops_scope_params($organizationId, $adminId, $isSuper);

        $checks = array();

        $stOrphanCampaigns = $pdo->prepare('SELECT COUNT(*)
            FROM communication_campaigns c
            LEFT JOIN communication_audiences a ON a.id = c.audience_id
            LEFT JOIN communication_templates t ON t.id = c.template_id
            WHERE ' . $scopeSql . ' AND (c.audience_id IS NULL OR a.id IS NULL OR c.template_id IS NULL OR t.id IS NULL)');
        $stOrphanCampaigns->execute($scopeParams);
        $orphanCount = (int)$stOrphanCampaigns->fetchColumn();
        $checks[] = array(
            'name' => 'campanas_huerfanas',
            'count' => $orphanCount,
            'ok' => ($orphanCount === 0),
        );

        $stMissingAudience = $pdo->prepare('SELECT COUNT(*)
            FROM communication_campaigns c
            LEFT JOIN communication_audiences a ON a.id = c.audience_id
            WHERE ' . $scopeSql . ' AND (c.audience_id IS NULL OR a.id IS NULL)');
        $stMissingAudience->execute($scopeParams);
        $missingAudience = (int)$stMissingAudience->fetchColumn();
        $checks[] = array('name' => 'audiencias_inexistentes', 'count' => $missingAudience, 'ok' => ($missingAudience === 0));

        $stMissingTemplate = $pdo->prepare('SELECT COUNT(*)
            FROM communication_campaigns c
            LEFT JOIN communication_templates t ON t.id = c.template_id
            WHERE ' . $scopeSql . ' AND (c.template_id IS NULL OR t.id IS NULL)');
        $stMissingTemplate->execute($scopeParams);
        $missingTemplate = (int)$stMissingTemplate->fetchColumn();
        $checks[] = array('name' => 'plantillas_faltantes', 'count' => $missingTemplate, 'ok' => ($missingTemplate === 0));

        $stRunNoCampaign = $pdo->prepare('SELECT COUNT(*)
            FROM communication_campaign_runs r
            LEFT JOIN communication_campaigns c ON c.id = r.campaign_id
            WHERE c.id IS NULL');
        $stRunNoCampaign->execute();
        $runNoCampaign = (int)$stRunNoCampaign->fetchColumn();
        $checks[] = array('name' => 'runs_inconsistentes_sin_campana', 'count' => $runNoCampaign, 'ok' => ($runNoCampaign === 0));

        $stRcNoRun = $pdo->prepare('SELECT COUNT(*)
            FROM communication_campaign_run_recipients rr
            LEFT JOIN communication_campaign_runs r ON r.id = rr.run_id
            WHERE r.id IS NULL');
        $stRcNoRun->execute();
        $rcNoRun = (int)$stRcNoRun->fetchColumn();
        $checks[] = array('name' => 'destinatarios_sin_run', 'count' => $rcNoRun, 'ok' => ($rcNoRun === 0));

        $stCommandNoCampaign = $pdo->prepare('SELECT COUNT(*)
            FROM communication_execution_commands cmd
            LEFT JOIN communication_campaigns c ON c.id = cmd.campaign_id
            WHERE c.id IS NULL');
        $stCommandNoCampaign->execute();
        $cmdNoCampaign = (int)$stCommandNoCampaign->fetchColumn();
        $checks[] = array('name' => 'comandos_sin_campana', 'count' => $cmdNoCampaign, 'ok' => ($cmdNoCampaign === 0));

        $allOk = true;
        foreach ($checks as $ch) {
            if (empty($ch['ok'])) {
                $allOk = false;
                break;
            }
        }

        return array('ok' => $allOk, 'checks' => $checks);
    }
}

if (!function_exists('communication_ops_fetch_latest_logs')) {
    function communication_ops_fetch_latest_logs($pdo, $organizationId, $limit)
    {
        communication_ops_ensure_schema($pdo);
        $limit = (int)$limit;
        if ($limit <= 0) $limit = 100;

        $st = $pdo->prepare('SELECT * FROM communication_module_logs WHERE organization_id = :org ORDER BY id DESC LIMIT :lim');
        $st->bindValue(':org', (int)$organizationId, PDO::PARAM_INT);
        $st->bindValue(':lim', (int)$limit, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('communication_ops_test_render_template')) {
    function communication_ops_test_render_template($pdo, $organizationId, $adminId, $isSuper, $templateId, $sampleJson)
    {
        $template = communication_campaigns_find_template($pdo, $organizationId, $adminId, $isSuper, $templateId);
        if (!$template) {
            return array('ok' => false, 'error' => 'Plantilla no accesible.');
        }

        $sample = array();
        $raw = trim((string)$sampleJson);
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                return array('ok' => false, 'error' => 'JSON de sample invalido.');
            }
            $sample = $decoded;
        }

        $preview = communication_template_renderer_preview(
            isset($template['subject_template']) ? $template['subject_template'] : '',
            isset($template['body_html_template']) ? $template['body_html_template'] : '',
            isset($template['body_text_template']) ? $template['body_text_template'] : '',
            !empty($sample) ? json_encode($sample, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : (isset($template['sample_data_json']) ? $template['sample_data_json'] : '')
        );

        return array('ok' => true, 'preview' => $preview, 'template' => $template);
    }
}

if (!function_exists('communication_ops_test_audience_resolution')) {
    function communication_ops_test_audience_resolution($pdo, $organizationId, $adminId, $isSuper, $audienceId, $limit)
    {
        $audience = communication_campaigns_find_audience($pdo, $organizationId, $adminId, $isSuper, $audienceId);
        if (!$audience) {
            return array('ok' => false, 'error' => 'Audiencia no accesible.');
        }

        $scope = array('is_super' => $isSuper, 'admin_id' => $adminId);
        $filters = communication_contacts_filters_from_json(isset($audience['filters_json']) ? $audience['filters_json'] : '');
        $allContacts = communication_contacts_resolve($pdo, $scope);
        $rows = communication_contacts_apply_filters(array_values($allContacts), $filters);

        $limit = (int)$limit;
        if ($limit <= 0) $limit = 20;

        return array(
            'ok' => true,
            'audience' => $audience,
            'count' => count($rows),
            'sample' => array_slice($rows, 0, $limit),
        );
    }
}

if (!function_exists('communication_ops_test_execution_simulation')) {
    function communication_ops_test_execution_simulation($pdo, $organizationId, $adminId, $isSuper, $campaignId, $limit)
    {
        $campaign = communication_ops_validate_campaign_scope($pdo, $campaignId, $organizationId, $adminId, $isSuper);
        if (!$campaign) {
            return array('ok' => false, 'error' => 'Campana no accesible.');
        }

        $audience = communication_campaigns_find_audience($pdo, $organizationId, $adminId, $isSuper, (int)$campaign['audience_id']);
        $template = communication_campaigns_find_template($pdo, $organizationId, $adminId, $isSuper, (int)$campaign['template_id']);
        if (!$audience || !$template) {
            return array('ok' => false, 'error' => 'Campana sin audiencia o plantilla valida.');
        }

        $scope = array('is_super' => $isSuper, 'admin_id' => $adminId);
        $filters = communication_contacts_filters_from_json(isset($audience['filters_json']) ? $audience['filters_json'] : '');
        $allContacts = communication_contacts_resolve($pdo, $scope);
        $rows = communication_contacts_apply_filters(array_values($allContacts), $filters);

        $limit = (int)$limit;
        if ($limit <= 0) $limit = 10;
        $rows = array_slice($rows, 0, $limit);

        $subjectBase = !empty($campaign['subject_override']) ? (string)$campaign['subject_override'] : (string)$template['subject_template'];
        $htmlBase = isset($template['body_html_template']) ? (string)$template['body_html_template'] : '';
        $textBase = isset($template['body_text_template']) ? (string)$template['body_text_template'] : '';

        $output = array();
        foreach ($rows as $r) {
            $sample = communication_variables_default_sample();
            $sample['nombre'] = isset($r['nombre']) ? (string)$r['nombre'] : (isset($sample['nombre']) ? $sample['nombre'] : '');
            $sample['email'] = isset($r['email']) ? (string)$r['email'] : '';
            $sample['fecha'] = gmdate('Y-m-d H:i:s');

            $output[] = array(
                'email' => isset($r['email']) ? (string)$r['email'] : '',
                'subject' => communication_template_renderer_apply($subjectBase, $sample),
                'body_text_preview' => substr(communication_template_renderer_apply($textBase, $sample), 0, 220),
                'body_html_preview' => substr(communication_template_renderer_apply($htmlBase, $sample), 0, 220),
                'simulated' => true,
            );
        }

        return array(
            'ok' => true,
            'campaign' => $campaign,
            'total_candidates' => count($allContacts),
            'filtered_recipients' => count(communication_contacts_apply_filters(array_values($allContacts), $filters)),
            'simulated_recipients' => count($output),
            'rows' => $output,
        );
    }
}

if (!function_exists('communication_ops_test_transport_simulation')) {
    function communication_ops_test_transport_simulation($pdo, $organizationId, $campaignId, $runId, $toEmail, $subject)
    {
        $toEmail = trim((string)$toEmail);
        $subject = trim((string)$subject);
        if ($toEmail === '') {
            return array('ok' => false, 'error' => 'Email destino requerido para simulacion.');
        }

        $message = array(
            'channel' => 'email',
            'to_email' => $toEmail,
            'subject' => ($subject !== '' ? $subject : 'Simulacion de transporte'),
            'body_html' => '<p>Simulacion de transporte del modulo Comunicacion.</p>',
            'body_text' => 'Simulacion de transporte del modulo Comunicacion.',
        );
        $context = array(
            'channel' => 'email',
            'campaign_id' => (int)$campaignId,
            'campaign_run_id' => (int)$runId,
            'recipient_fingerprint' => sha1(strtolower($toEmail)),
        );

        $result = communication_transport_simulate_send($pdo, (int)$organizationId, $message, $context);
        return array('ok' => true, 'result' => $result);
    }
}
