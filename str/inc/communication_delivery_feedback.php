<?php

if (!function_exists('communication_delivery_feedback_add_column')) {
    function communication_delivery_feedback_add_column($pdo, $table, $column, $definition)
    {
        $columns = $pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($columns as $row) {
            if (isset($row['name']) && (string)$row['name'] === (string)$column) return;
        }
        $pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition);
    }
}

if (!function_exists('communication_delivery_feedback_ensure_schema')) {
    function communication_delivery_feedback_ensure_schema($pdo)
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS communication_exim_messages (
            exim_message_id TEXT PRIMARY KEY,
            trace_id TEXT,
            sender_email TEXT,
            recipient_email TEXT,
            accepted_at TEXT,
            completed_at TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');
        $pdo->exec('CREATE TABLE IF NOT EXISTS communication_delivery_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            event_key TEXT NOT NULL UNIQUE,
            exim_message_id TEXT NOT NULL,
            trace_id TEXT,
            recipient_email TEXT,
            status TEXT NOT NULL,
            diagnostic TEXT,
            remote_message_id TEXT,
            observed_at TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');
        $pdo->exec('CREATE TABLE IF NOT EXISTS communication_delivery_log_cursors (
            log_path TEXT PRIMARY KEY,
            inode TEXT,
            byte_offset INTEGER NOT NULL DEFAULT 0,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_comm_exim_trace ON communication_exim_messages(trace_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_comm_delivery_events_trace ON communication_delivery_events(trace_id, status)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_comm_delivery_events_observed ON communication_delivery_events(observed_at, status)');

        communication_delivery_feedback_add_column($pdo, 'email_logs', 'trace_id', 'TEXT');
        communication_delivery_feedback_add_column($pdo, 'email_logs', 'delivery_status', 'TEXT');
        communication_delivery_feedback_add_column($pdo, 'email_logs', 'delivery_status_at', 'TEXT');
        communication_delivery_feedback_add_column($pdo, 'email_logs', 'delivery_response', 'TEXT');
        communication_delivery_feedback_add_column($pdo, 'email_logs', 'exim_message_id', 'TEXT');
        communication_delivery_feedback_add_column($pdo, 'communication_campaign_run_recipients', 'delivery_status', 'TEXT');
        communication_delivery_feedback_add_column($pdo, 'communication_campaign_run_recipients', 'delivery_status_at', 'TEXT');
        communication_delivery_feedback_add_column($pdo, 'communication_campaign_run_recipients', 'delivery_response', 'TEXT');
        communication_delivery_feedback_add_column($pdo, 'communication_campaign_run_recipients', 'exim_message_id', 'TEXT');
    }
}

if (!function_exists('communication_delivery_feedback_clean_email')) {
    function communication_delivery_feedback_clean_email($value)
    {
        $value = trim((string)$value, " <>\t\r\n");
        return filter_var($value, FILTER_VALIDATE_EMAIL) ? strtolower($value) : '';
    }
}

if (!function_exists('communication_delivery_feedback_is_hard_mailbox_bounce')) {
    function communication_delivery_feedback_is_hard_mailbox_bounce($diagnostic)
    {
        $text = strtolower((string)$diagnostic);
        foreach (array('/\b5\.1\.1\b/', '/user unknown/', '/unknown user/', '/no such (user|mailbox|recipient)/', '/mailbox (does not exist|not found)/', '/recipient (does not exist|unknown|rejected)/', '/unrouteable address/') as $pattern) {
            if (preg_match($pattern, $text)) return true;
        }
        return false;
    }
}

if (!function_exists('communication_delivery_feedback_parse_line')) {
    function communication_delivery_feedback_parse_line($line)
    {
        $line = rtrim((string)$line, "\r\n");
        if (!preg_match('/^(\d{4}-\d{2}-\d{2})\s+(\d{2}:\d{2}:\d{2})\s+([A-Za-z0-9-]+)\s+(.*)$/', $line, $m)) return null;
        $at = $m[1] . ' ' . $m[2];
        $messageId = $m[3];
        $rest = $m[4];

        if (strpos($rest, '<=') === 0) {
            if (!preg_match('/\bid=tickex-([A-Za-z0-9]+)@tickex\.com\.ar\b/i', $rest, $tm)) return null;
            $sender = '';
            if (preg_match('/^<=\s+([^\s]+)/', $rest, $sm)) $sender = communication_delivery_feedback_clean_email($sm[1]);
            return array('type'=>'accepted','message_id'=>$messageId,'trace_id'=>strtolower($tm[1]),'sender'=>$sender,'observed_at'=>$at,'raw'=>$line);
        }
        if (strpos($rest, 'Completed') === 0) return array('type'=>'completed','message_id'=>$messageId,'observed_at'=>$at,'raw'=>$line);

        $status = '';
        $marker = '';
        if (strpos($rest, '=>') === 0) { $status = 'delivered'; $marker = '=>'; }
        elseif (strpos($rest, '==') === 0) { $status = 'deferred'; $marker = '=='; }
        elseif (strpos($rest, '**') === 0) { $status = 'bounced'; $marker = '**'; }
        if ($status === '') return null;

        $recipient = '';
        if (preg_match('/^' . preg_quote($marker, '/') . '\s+([^\s]+)/', $rest, $rm)) $recipient = communication_delivery_feedback_clean_email($rm[1]);
        $remoteId = '';
        if ($status === 'delivered' && preg_match('/\bid=([A-Za-z0-9._-]+)/', $rest, $im)) $remoteId = $im[1];
        return array('type'=>'delivery','status'=>$status,'message_id'=>$messageId,'recipient'=>$recipient,'diagnostic'=>$rest,'remote_message_id'=>$remoteId,'observed_at'=>$at,'raw'=>$line);
    }
}

if (!function_exists('communication_delivery_feedback_apply_status')) {
    function communication_delivery_feedback_apply_status($pdo, $traceId, $messageId, $status, $observedAt, $diagnostic)
    {
        if ($traceId === '') return;
        $params = array(':status'=>$status,':at'=>$observedAt,':response'=>$diagnostic,':mid'=>$messageId,':trace'=>$traceId);
        $condition = $status === 'deferred' ? " AND (delivery_status IS NULL OR delivery_status = '' OR delivery_status = 'deferred')" : '';
        $st = $pdo->prepare('UPDATE email_logs SET delivery_status=:status,delivery_status_at=:at,delivery_response=:response,exim_message_id=:mid WHERE trace_id=:trace' . $condition);
        $st->execute($params);
        $st = $pdo->prepare('UPDATE communication_campaign_run_recipients SET delivery_status=:status,delivery_status_at=:at,delivery_response=:response,exim_message_id=:mid,updated_at=CURRENT_TIMESTAMP WHERE provider_message_id=:trace' . $condition);
        $st->execute($params);
    }
}

if (!function_exists('communication_delivery_feedback_process_line')) {
    function communication_delivery_feedback_process_line($pdo, $line)
    {
        $event = communication_delivery_feedback_parse_line($line);
        if (!$event) return array('parsed'=>false,'stored'=>false);

        if ($event['type'] === 'accepted') {
            $messageParams = array(':mid'=>$event['message_id'],':trace'=>$event['trace_id'],':sender'=>$event['sender'],':at'=>$event['observed_at']);
            $st = $pdo->prepare('INSERT OR IGNORE INTO communication_exim_messages(exim_message_id,trace_id,sender_email,accepted_at,created_at,updated_at) VALUES(:mid,:trace,:sender,:at,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)');
            $st->execute($messageParams);
            $st = $pdo->prepare('UPDATE communication_exim_messages SET trace_id=:trace,sender_email=:sender,accepted_at=:at,updated_at=CURRENT_TIMESTAMP WHERE exim_message_id=:mid');
            $st->execute($messageParams);
            $up = $pdo->prepare("UPDATE email_logs SET exim_message_id=:mid,delivery_status=COALESCE(NULLIF(delivery_status,''),'accepted'),delivery_status_at=COALESCE(delivery_status_at,:at) WHERE trace_id=:trace");
            $up->execute(array(':mid'=>$event['message_id'],':at'=>$event['observed_at'],':trace'=>$event['trace_id']));
            return array('parsed'=>true,'stored'=>true,'type'=>'accepted');
        }
        if ($event['type'] === 'completed') {
            $st = $pdo->prepare('UPDATE communication_exim_messages SET completed_at=:at,updated_at=CURRENT_TIMESTAMP WHERE exim_message_id=:mid');
            $st->execute(array(':at'=>$event['observed_at'],':mid'=>$event['message_id']));
            return array('parsed'=>true,'stored'=>$st->rowCount()>0,'type'=>'completed');
        }

        $st = $pdo->prepare('SELECT trace_id FROM communication_exim_messages WHERE exim_message_id=:mid LIMIT 1');
        $st->execute(array(':mid'=>$event['message_id']));
        $traceId = (string)$st->fetchColumn();
        if ($traceId === '') return array('parsed'=>true,'stored'=>false,'type'=>$event['status']);
        $ins = $pdo->prepare('INSERT OR IGNORE INTO communication_delivery_events(event_key,exim_message_id,trace_id,recipient_email,status,diagnostic,remote_message_id,observed_at) VALUES(:key,:mid,:trace,:recipient,:status,:diagnostic,:remote,:at)');
        $ins->execute(array(':key'=>sha1($event['raw']),':mid'=>$event['message_id'],':trace'=>$traceId,':recipient'=>$event['recipient'],':status'=>$event['status'],':diagnostic'=>$event['diagnostic'],':remote'=>$event['remote_message_id'],':at'=>$event['observed_at']));
        $stored = $ins->rowCount() > 0;
        if ($stored) {
            $up = $pdo->prepare('UPDATE communication_exim_messages SET recipient_email=COALESCE(NULLIF(:recipient,""),recipient_email),updated_at=CURRENT_TIMESTAMP WHERE exim_message_id=:mid');
            $up->execute(array(':recipient'=>$event['recipient'],':mid'=>$event['message_id']));
            communication_delivery_feedback_apply_status($pdo,$traceId,$event['message_id'],$event['status'],$event['observed_at'],$event['diagnostic']);
        }
        return array('parsed'=>true,'stored'=>$stored,'type'=>$event['status']);
    }
}

if (!function_exists('communication_delivery_feedback_process_log')) {
    function communication_delivery_feedback_process_log($pdo, $logPath)
    {
        communication_delivery_feedback_ensure_schema($pdo);
        if (!is_file($logPath) || !is_readable($logPath)) throw new RuntimeException('No se puede leer el log de Exim: ' . $logPath);
        clearstatcache(true, $logPath);
        $inode = (string)@fileinode($logPath);
        $size = (int)@filesize($logPath);
        $st = $pdo->prepare('SELECT inode,byte_offset FROM communication_delivery_log_cursors WHERE log_path=:path');
        $st->execute(array(':path'=>$logPath));
        $cursor = $st->fetch(PDO::FETCH_ASSOC);
        $offset = $cursor ? (int)$cursor['byte_offset'] : 0;
        if (!$cursor || (string)$cursor['inode'] !== $inode || $offset > $size) $offset = 0;
        $fh = fopen($logPath,'rb');
        if (!$fh) throw new RuntimeException('No se pudo abrir el log de Exim.');
        if ($offset > 0) fseek($fh,$offset);
        $counts = array('lines'=>0,'parsed'=>0,'stored'=>0,'accepted'=>0,'delivered'=>0,'deferred'=>0,'bounced'=>0,'completed'=>0);
        while (($line = fgets($fh)) !== false) {
            $counts['lines']++;
            $result = communication_delivery_feedback_process_line($pdo,$line);
            if (!empty($result['parsed'])) $counts['parsed']++;
            if (!empty($result['stored'])) $counts['stored']++;
            if (!empty($result['type']) && isset($counts[$result['type']])) $counts[$result['type']]++;
        }
        $newOffset = ftell($fh);
        fclose($fh);
        $cursorParams = array(':path'=>$logPath,':inode'=>$inode,':offset'=>$newOffset);
        $up = $pdo->prepare('INSERT OR IGNORE INTO communication_delivery_log_cursors(log_path,inode,byte_offset,updated_at) VALUES(:path,:inode,:offset,CURRENT_TIMESTAMP)');
        $up->execute($cursorParams);
        $up = $pdo->prepare('UPDATE communication_delivery_log_cursors SET inode=:inode,byte_offset=:offset,updated_at=CURRENT_TIMESTAMP WHERE log_path=:path');
        $up->execute($cursorParams);
        $counts['offset'] = $newOffset;
        return $counts;
    }
}

if (!function_exists('communication_delivery_feedback_metrics')) {
    function communication_delivery_feedback_metrics($pdo, $days, $adminId = null, $isSuper = true)
    {
        communication_delivery_feedback_ensure_schema($pdo);
        $days = max(1,min(365,(int)$days));
        $result = array('delivered'=>0,'deferred'=>0,'bounced'=>0,'hard_bounces'=>0);
        $sql = "SELECT e.id,e.status,e.diagnostic FROM communication_delivery_events e WHERE e.observed_at>=datetime('now',:window)";
        $params = array(':window'=>'-'.$days.' days');
        if (!$isSuper && $adminId !== null) {
            $sql = "SELECT DISTINCT e.id,e.status,e.diagnostic
                FROM communication_delivery_events e
                INNER JOIN communication_campaign_run_recipients rr ON rr.provider_message_id=e.trace_id
                INNER JOIN communication_campaign_runs cr ON cr.id=rr.run_id
                INNER JOIN communication_campaigns c ON c.id=cr.campaign_id
                WHERE e.observed_at>=datetime('now',:window) AND c.created_by_admin_id=:aid";
            $params[':aid'] = (int)$adminId;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            if (isset($result[$row['status']])) $result[$row['status']]++;
            if ($row['status'] === 'bounced' && communication_delivery_feedback_is_hard_mailbox_bounce($row['diagnostic'])) $result['hard_bounces']++;
        }
        return $result;
    }
}
