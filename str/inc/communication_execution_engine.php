<?php
require_once __DIR__ . '/communication_contacts.php';
require_once __DIR__ . '/communication_campaigns.php';
require_once __DIR__ . '/communication_template_renderer.php';
require_once __DIR__ . '/communication_transport.php';
require_once __DIR__ . '/communication_suppressions.php';

if (!function_exists('communication_execution_now')) {
    function communication_execution_now()
    {
        return gmdate('Y-m-d H:i:s');
    }
}

if (!function_exists('communication_execution_email_fingerprint')) {
    function communication_execution_email_fingerprint($email)
    {
        $email = strtolower(trim((string)$email));
        return sha1($email);
    }
}

if (!function_exists('communication_execution_make_request_key')) {
    function communication_execution_make_request_key($campaignId, $adminId)
    {
        return sha1('exec|' . (int)$campaignId . '|' . (int)$adminId . '|' . microtime(true) . '|' . mt_rand());
    }
}

if (!function_exists('communication_execution_ensure_schema')) {
    function communication_execution_ensure_schema($pdo)
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS communication_execution_commands (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            organization_id INTEGER NOT NULL DEFAULT 1,
            campaign_id INTEGER NOT NULL,
            command_type TEXT NOT NULL DEFAULT "execute_campaign",
            request_key TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT "queued",
            payload_json TEXT,
            scheduled_for TEXT,
            locked_by TEXT,
            lock_expires_at TEXT,
            attempt_count INTEGER NOT NULL DEFAULT 0,
            result_json TEXT,
            error_text TEXT,
            created_by_admin_id INTEGER,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(request_key)
        )');

        $pdo->exec('CREATE TABLE IF NOT EXISTS communication_campaign_runs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            organization_id INTEGER NOT NULL DEFAULT 1,
            campaign_id INTEGER NOT NULL,
            command_id INTEGER,
            status TEXT NOT NULL DEFAULT "requested",
            started_at TEXT,
            finished_at TEXT,
            snapshot_subject TEXT,
            snapshot_body_html TEXT,
            snapshot_body_text TEXT,
            snapshot_taken_at TEXT,
            audience_filters_json TEXT,
            resolved_recipients INTEGER NOT NULL DEFAULT 0,
            processed_count INTEGER NOT NULL DEFAULT 0,
            accepted_count INTEGER NOT NULL DEFAULT 0,
            rejected_count INTEGER NOT NULL DEFAULT 0,
            transient_error_count INTEGER NOT NULL DEFAULT 0,
            permanent_error_count INTEGER NOT NULL DEFAULT 0,
            skipped_duplicate_count INTEGER NOT NULL DEFAULT 0,
            last_error TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');

        $pdo->exec('CREATE TABLE IF NOT EXISTS communication_campaign_run_recipients (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            run_id INTEGER NOT NULL,
            campaign_id INTEGER NOT NULL,
            recipient_email TEXT,
            recipient_name TEXT,
            recipient_fingerprint TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT "queued",
            attempt_count INTEGER NOT NULL DEFAULT 0,
            last_error TEXT,
            last_response_code TEXT,
            last_response_message TEXT,
            provider_name TEXT,
            provider_message_id TEXT,
            locked_until TEXT,
            last_attempt_at TEXT,
            processed_at TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(run_id, recipient_fingerprint)
        )');

        $pdo->exec('CREATE TABLE IF NOT EXISTS communication_campaign_delivery_attempts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            run_id INTEGER NOT NULL,
            recipient_fingerprint TEXT NOT NULL,
            attempt_no INTEGER NOT NULL,
            provider_name TEXT,
            transport_status TEXT,
            response_code TEXT,
            response_message TEXT,
            provider_message_id TEXT,
            latency_ms INTEGER,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_comm_exec_cmd_status ON communication_execution_commands(status, scheduled_for)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_comm_exec_cmd_campaign ON communication_execution_commands(campaign_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_comm_runs_campaign ON communication_campaign_runs(campaign_id, status)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_comm_run_rcpt_run_status ON communication_campaign_run_recipients(run_id, status)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_comm_run_rcpt_campaign_fp ON communication_campaign_run_recipients(campaign_id, recipient_fingerprint, status)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_comm_attempts_run ON communication_campaign_delivery_attempts(run_id, recipient_fingerprint)');
        communication_suppressions_ensure_schema($pdo);
    }
}

if (!function_exists('communication_execution_enqueue_campaign')) {
    function communication_execution_enqueue_campaign($pdo, $organizationId, $campaignId, $adminId, $isSuper, $options)
    {
        communication_execution_ensure_schema($pdo);
        communication_campaigns_ensure_schema($pdo);

        $organizationId = (int)$organizationId;
        $campaignId = (int)$campaignId;
        $adminId = (int)$adminId;
        $isSuper = !empty($isSuper);
        $options = is_array($options) ? $options : array();

        if ($campaignId <= 0) {
            return array('ok' => false, 'error' => 'Campana invalida.');
        }

        $scopeSql = communication_campaigns_scope_sql($isSuper);
        $scopeParams = communication_campaigns_scope_params($organizationId, $adminId, $isSuper);
        $st = $pdo->prepare('SELECT * FROM communication_campaigns WHERE id = :id AND ' . $scopeSql . ' LIMIT 1');
        $st->execute(array(':id' => $campaignId) + $scopeParams);
        $campaign = $st->fetch(PDO::FETCH_ASSOC);

        if (!$campaign) {
            return array('ok' => false, 'error' => 'No se encontro la campana para ejecutar.');
        }

        $status = isset($campaign['status']) ? (string)$campaign['status'] : 'draft';
        if (in_array($status, array('archived', 'cancelled'), true)) {
            return array('ok' => false, 'error' => 'La campana no se puede ejecutar en estado ' . $status . '.');
        }

        $requestKey = isset($options['request_key']) ? trim((string)$options['request_key']) : '';
        if ($requestKey === '') {
            $requestKey = communication_execution_make_request_key($campaignId, $adminId);
        }

        $payload = array(
            'requested_by_admin_id' => $adminId,
            'requested_by_is_super' => $isSuper ? 1 : 0,
            'requested_at' => communication_execution_now(),
        );

        try {
            $stIns = $pdo->prepare('INSERT INTO communication_execution_commands (organization_id, campaign_id, command_type, request_key, status, payload_json, scheduled_for, created_by_admin_id, created_at, updated_at) VALUES (:org, :cid, :ct, :rk, :st, :pj, :sf, :aid, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)');
            $stIns->execute(array(
                ':org' => $organizationId,
                ':cid' => $campaignId,
                ':ct' => 'execute_campaign',
                ':rk' => $requestKey,
                ':st' => 'queued',
                ':pj' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ':sf' => isset($options['scheduled_for']) ? $options['scheduled_for'] : null,
                ':aid' => $adminId,
            ));
            $commandId = (int)$pdo->lastInsertId();

            if (function_exists('communication_ops_log')) {
                communication_ops_log($pdo, $organizationId, 'engine', 'command.enqueued', 'info', 'Campana encolada para ejecucion.', array(
                    'campaign_id' => $campaignId,
                    'command_id' => $commandId,
                    'requested_by_admin_id' => $adminId,
                ), 'command.enqueued|' . (int)$commandId);
            }

            return array('ok' => true, 'command_id' => $commandId, 'request_key' => $requestKey);
        } catch (Exception $e) {
            return array('ok' => false, 'error' => 'No se pudo encolar la ejecucion: ' . $e->getMessage());
        }
    }
}

if (!function_exists('communication_execution_claim_command')) {
    function communication_execution_claim_command($pdo, $commandId, $workerId)
    {
        $workerId = (string)$workerId;
        $st = $pdo->prepare('UPDATE communication_execution_commands SET status = :st, locked_by = :wb, lock_expires_at = datetime(\'now\',\'+10 minutes\'), attempt_count = attempt_count + 1, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND status = :queued');
        $st->execute(array(':st' => 'processing', ':wb' => $workerId, ':id' => (int)$commandId, ':queued' => 'queued'));
        return ($st->rowCount() > 0);
    }
}

if (!function_exists('communication_execution_requeue_stale_processing_commands')) {
    function communication_execution_requeue_stale_processing_commands($pdo)
    {
        // Si un worker muere a mitad de ejecución, el comando puede quedar en processing.
        // Reencolar al vencer el lock permite retomar el run sin intervención manual.
        $st = $pdo->prepare("UPDATE communication_execution_commands
            SET status = :queued,
                locked_by = NULL,
                lock_expires_at = NULL,
                updated_at = CURRENT_TIMESTAMP
            WHERE status = :processing
              AND (
                    (lock_expires_at IS NOT NULL AND lock_expires_at <= CURRENT_TIMESTAMP)
                 OR (lock_expires_at IS NULL AND updated_at <= datetime('now','-10 minutes'))
              )");
        $st->execute(array(
            ':queued' => 'queued',
            ':processing' => 'processing',
        ));
        return (int)$st->rowCount();
    }
}

if (!function_exists('communication_execution_update_command')) {
    function communication_execution_update_command($pdo, $commandId, $status, $resultJson, $errorText)
    {
        $params = array(
            ':st' => (string)$status,
            ':rj' => $resultJson,
            ':er' => $errorText,
            ':id' => (int)$commandId,
        );

        $updated = false;
        $attempts = 0;
        while ($attempts < 3 && !$updated) {
            $attempts++;
            try {
                $st = $pdo->prepare('UPDATE communication_execution_commands SET status = :st, result_json = :rj, error_text = :er, locked_by = NULL, lock_expires_at = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
                $st->execute($params);
                $updated = true;
            } catch (PDOException $e) {
                $msg = strtolower((string)$e->getMessage());
                $isLocked = (strpos($msg, 'database is locked') !== false) || (strpos($msg, 'database table is locked') !== false) || (strpos($msg, 'sqlstate[hy000]: general error: 5') !== false);
                if ($isLocked && $attempts < 3) {
                    usleep(50000);
                    continue;
                }
                if (function_exists('error_log')) {
                    error_log('communication_execution_update_command failed: ' . $e->getMessage() . ' | command_id=' . (int)$commandId . ' | status=' . (string)$status);
                }
                break;
            }
        }

        if (!$updated) {
            if (function_exists('error_log')) {
                error_log('communication_execution_update_command lock timeout: command_id=' . (int)$commandId . ' | status=' . (string)$status);
            }
            return false;
        }

        if (function_exists('communication_ops_log')) {
            try {
                $level = ((string)$status === 'failed') ? 'error' : (((string)$status === 'cancelled') ? 'warning' : 'info');
                communication_ops_log($pdo, 1, 'engine', 'command.status_updated', $level, 'Comando actualizado: ' . (string)$status, array(
                    'command_id' => (int)$commandId,
                    'status' => (string)$status,
                    'error_text' => (string)$errorText,
                ), 'command.status_updated|' . (int)$commandId . '|' . (string)$status . '|' . gmdate('YmdHis'));
            } catch (Exception $e) {
                // El logging operativo no debe frenar el worker.
            }
        }

        return true;
    }
}

if (!function_exists('communication_execution_find_active_run')) {
    function communication_execution_find_active_run($pdo, $campaignId)
    {
        $st = $pdo->prepare('SELECT * FROM communication_campaign_runs WHERE campaign_id = :cid AND status IN (\'requested\',\'preparing\',\'running\',\'finalizing\') ORDER BY id DESC LIMIT 1');
        $st->execute(array(':cid' => (int)$campaignId));
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ? $row : null;
    }
}

if (!function_exists('communication_execution_insert_run')) {
    function communication_execution_insert_run($pdo, $organizationId, $campaignId, $commandId, $audienceFiltersJson)
    {
        $st = $pdo->prepare('INSERT INTO communication_campaign_runs (organization_id, campaign_id, command_id, status, started_at, audience_filters_json, created_at, updated_at) VALUES (:org, :cid, :cmd, :st, CURRENT_TIMESTAMP, :af, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)');
        $st->execute(array(
            ':org' => (int)$organizationId,
            ':cid' => (int)$campaignId,
            ':cmd' => (int)$commandId,
            ':st' => 'preparing',
            ':af' => $audienceFiltersJson,
        ));
        return (int)$pdo->lastInsertId();
    }
}

if (!function_exists('communication_execution_update_run_snapshot')) {
    function communication_execution_update_run_snapshot($pdo, $runId, $subject, $html, $text)
    {
        $st = $pdo->prepare('UPDATE communication_campaign_runs SET snapshot_subject = :s, snapshot_body_html = :h, snapshot_body_text = :t, snapshot_taken_at = CURRENT_TIMESTAMP, status = :st, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
        $st->execute(array(
            ':s' => (string)$subject,
            ':h' => (string)$html,
            ':t' => (string)$text,
            ':st' => 'running',
            ':id' => (int)$runId,
        ));
    }
}

if (!function_exists('communication_execution_materialize_recipients')) {
    function communication_execution_materialize_recipients($pdo, $runId, $campaignId, $contacts, $organizationId = 1, $adminId = 0)
    {
        $contacts = is_array($contacts) ? $contacts : array();
        $count = 0;

        $st = $pdo->prepare('INSERT OR IGNORE INTO communication_campaign_run_recipients (run_id, campaign_id, recipient_email, recipient_name, recipient_fingerprint, status, attempt_count, created_at, updated_at) VALUES (:rid, :cid, :em, :nm, :fp, :st, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)');

        foreach ($contacts as $row) {
            $email = isset($row['email']) ? trim((string)$row['email']) : '';
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !empty($row['bloqueado'])) continue;
            if (communication_suppressions_is_suppressed($pdo, $organizationId, $adminId, $email)) continue;
            $name = isset($row['nombre']) ? (string)$row['nombre'] : '';
            $fp = communication_execution_email_fingerprint($email);
            $st->execute(array(
                ':rid' => (int)$runId,
                ':cid' => (int)$campaignId,
                ':em' => $email,
                ':nm' => $name,
                ':fp' => $fp,
                ':st' => 'queued',
            ));
            $count++;
        }

        $stUp = $pdo->prepare('UPDATE communication_campaign_runs SET resolved_recipients = :n, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
        $stUp->execute(array(':n' => (int)$count, ':id' => (int)$runId));

        return $count;
    }
}

if (!function_exists('communication_execution_mark_campaign_sending')) {
    function communication_execution_mark_campaign_sending($pdo, $campaignId)
    {
        $st = $pdo->prepare('UPDATE communication_campaigns SET status = :st, sending_started_at = COALESCE(sending_started_at, CURRENT_TIMESTAMP), updated_at = CURRENT_TIMESTAMP WHERE id = :id');
        $st->execute(array(':st' => 'sending', ':id' => (int)$campaignId));
    }
}

if (!function_exists('communication_execution_refresh_run_counters')) {
    function communication_execution_refresh_run_counters($pdo, $runId)
    {
        $sql = 'SELECT status, COUNT(*) AS n FROM communication_campaign_run_recipients WHERE run_id = :rid GROUP BY status';
        $st = $pdo->prepare($sql);
        $st->execute(array(':rid' => (int)$runId));

        $counts = array(
            'accepted' => 0,
            'rejected' => 0,
            'transient_error' => 0,
            'permanent_error' => 0,
            'skipped_duplicate' => 0,
            'processing' => 0,
            'queued' => 0,
        );

        $processedCount = 0;
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $k = isset($r['status']) ? (string)$r['status'] : '';
            $n = isset($r['n']) ? (int)$r['n'] : 0;
            if (isset($counts[$k])) $counts[$k] = $n;
        }

        $processedCount = $counts['accepted'] + $counts['rejected'] + $counts['transient_error'] + $counts['permanent_error'] + $counts['skipped_duplicate'];

        $stUp = $pdo->prepare('UPDATE communication_campaign_runs SET processed_count = :pc, accepted_count = :ac, rejected_count = :rc, transient_error_count = :tc, permanent_error_count = :pc2, skipped_duplicate_count = :sd, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
        $stUp->execute(array(
            ':pc' => $processedCount,
            ':ac' => $counts['accepted'],
            ':rc' => $counts['rejected'],
            ':tc' => $counts['transient_error'],
            ':pc2' => $counts['permanent_error'],
            ':sd' => $counts['skipped_duplicate'],
            ':id' => (int)$runId,
        ));

        return $counts;
    }
}

if (!function_exists('communication_execution_insert_attempt')) {
    function communication_execution_insert_attempt($pdo, $runId, $fingerprint, $attemptNo, $transport)
    {
        $params = array(
            ':rid' => (int)$runId,
            ':fp' => (string)$fingerprint,
            ':an' => (int)$attemptNo,
            ':pn' => isset($transport['provider_name']) ? (string)$transport['provider_name'] : null,
            ':ts' => isset($transport['status']) ? (string)$transport['status'] : null,
            ':rc' => isset($transport['response_code']) ? (string)$transport['response_code'] : null,
            ':rm' => isset($transport['response_message']) ? (string)$transport['response_message'] : null,
            ':pmid' => isset($transport['provider_message_id']) ? (string)$transport['provider_message_id'] : null,
            ':lat' => isset($transport['latency_ms']) ? (int)$transport['latency_ms'] : 0,
        );

        $attempts = 0;
        while ($attempts < 4) {
            $attempts++;
            try {
                $st = $pdo->prepare('INSERT INTO communication_campaign_delivery_attempts (run_id, recipient_fingerprint, attempt_no, provider_name, transport_status, response_code, response_message, provider_message_id, latency_ms, created_at) VALUES (:rid, :fp, :an, :pn, :ts, :rc, :rm, :pmid, :lat, CURRENT_TIMESTAMP)');
                $st->execute($params);
                return true;
            } catch (PDOException $e) {
                $msg = strtolower((string)$e->getMessage());
                $isLocked = (strpos($msg, 'database is locked') !== false) || (strpos($msg, 'database table is locked') !== false) || (strpos($msg, 'sqlstate[hy000]: general error: 5') !== false);
                if ($isLocked && $attempts < 4) {
                    usleep(50000 * $attempts);
                    continue;
                }
                if (function_exists('error_log')) {
                    error_log('communication_execution_insert_attempt failed: ' . $e->getMessage() . ' | run_id=' . (int)$runId . ' | fp=' . (string)$fingerprint);
                }
                return false;
            }
        }

        return false;
    }
}

if (!function_exists('communication_execution_is_duplicate_accepted')) {
    function communication_execution_is_duplicate_accepted($pdo, $campaignId, $runId, $fingerprint)
    {
        $st = $pdo->prepare('SELECT 1 FROM communication_campaign_run_recipients rr JOIN communication_campaign_runs r ON r.id = rr.run_id WHERE r.campaign_id = :cid AND rr.recipient_fingerprint = :fp AND rr.status = :st AND rr.run_id <> :rid LIMIT 1');
        $st->execute(array(
            ':cid' => (int)$campaignId,
            ':fp' => (string)$fingerprint,
            ':st' => 'accepted',
            ':rid' => (int)$runId,
        ));
        return (bool)$st->fetchColumn();
    }
}

if (!function_exists('communication_execution_finalize_run_and_campaign')) {
    function communication_execution_finalize_run_and_campaign($pdo, $runId, $campaignId)
    {
        $counts = communication_execution_refresh_run_counters($pdo, $runId);

        $pending = (int)$counts['queued'] + (int)$counts['processing'];
        if ($pending > 0) {
            return array(
                'complete' => false,
                'campaign_status' => 'sending',
                'counts' => $counts,
            );
        }

        $stRun = $pdo->prepare('UPDATE communication_campaign_runs SET status = :st, finished_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
        $stCamp = $pdo->prepare('UPDATE communication_campaigns SET status = :st, sent_at = CASE WHEN :st = "sent" THEN CURRENT_TIMESTAMP ELSE sent_at END, failed_at = CASE WHEN :st = "failed" THEN CURRENT_TIMESTAMP ELSE failed_at END, updated_at = CURRENT_TIMESTAMP WHERE id = :id');

        $hasTransient = ((int)$counts['transient_error'] > 0);
        $campaignStatus = $hasTransient ? 'failed' : 'sent';

        $stRun->execute(array(':st' => 'completed', ':id' => (int)$runId));
        $stCamp->execute(array(':st' => $campaignStatus, ':id' => (int)$campaignId));

        if (function_exists('communication_ops_log')) {
            communication_ops_log($pdo, 1, 'engine', 'run.finalized', ($campaignStatus === 'failed' ? 'warning' : 'info'), 'Run finalizado.', array(
                'campaign_id' => (int)$campaignId,
                'run_id' => (int)$runId,
                'campaign_status' => (string)$campaignStatus,
                'counts' => $counts,
            ), 'run.finalized|' . (int)$runId . '|' . (string)$campaignStatus);
        }

        return array('complete' => true, 'campaign_status' => $campaignStatus, 'counts' => $counts);
    }
}

if (!function_exists('communication_execution_process_run_recipients')) {
    function communication_execution_process_run_recipients($pdo, $organizationId, $campaign, $run, $scope, $batchSize)
    {
        $batchSize = (int)$batchSize;
        if ($batchSize <= 0) $batchSize = 100;

        $runId = (int)$run['id'];
        $campaignId = (int)$campaign['id'];

        $stRecover = $pdo->prepare('UPDATE communication_campaign_run_recipients SET status = :queued, locked_until = NULL, updated_at = CURRENT_TIMESTAMP WHERE run_id = :rid AND status = :processing AND locked_until IS NOT NULL AND locked_until <= CURRENT_TIMESTAMP');
        $stRecover->execute(array(':queued' => 'queued', ':processing' => 'processing', ':rid' => $runId));

        $stSel = $pdo->prepare('SELECT * FROM communication_campaign_run_recipients WHERE run_id = :rid AND status = \'queued\' ORDER BY id ASC LIMIT :lim');
        $stSel->bindValue(':rid', $runId, PDO::PARAM_INT);
        $stSel->bindValue(':lim', $batchSize, PDO::PARAM_INT);
        $stSel->execute();
        $rows = $stSel->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $rcpt) {
            $recipientId = (int)$rcpt['id'];
            $email = isset($rcpt['recipient_email']) ? trim((string)$rcpt['recipient_email']) : '';
            $fingerprint = isset($rcpt['recipient_fingerprint']) ? (string)$rcpt['recipient_fingerprint'] : '';
            $attemptNo = (int)$rcpt['attempt_count'] + 1;

            if ($email === '' || $fingerprint === '') {
                $stBad = $pdo->prepare('UPDATE communication_campaign_run_recipients SET status = :st, last_error = :er, processed_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
                $stBad->execute(array(':st' => 'permanent_error', ':er' => 'Destinatario invalido.', ':id' => $recipientId));
                continue;
            }

            $stLock = $pdo->prepare('UPDATE communication_campaign_run_recipients SET status = :st, locked_until = datetime(\'now\',\'+5 minutes\'), attempt_count = :an, last_attempt_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND status = :queued');
            $stLock->execute(array(':st' => 'processing', ':an' => $attemptNo, ':id' => $recipientId, ':queued' => 'queued'));
            if ($stLock->rowCount() === 0) continue;

            if (communication_execution_is_duplicate_accepted($pdo, $campaignId, $runId, $fingerprint)) {
                $stDup = $pdo->prepare('UPDATE communication_campaign_run_recipients SET status = :st, last_error = :er, processed_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
                $stDup->execute(array(':st' => 'skipped_duplicate', ':er' => 'Duplicado idempotente: ya enviado en ejecucion previa.', ':id' => $recipientId));
                continue;
            }

            $data = communication_variables_default_sample();
            $data['nombre'] = isset($rcpt['recipient_name']) && trim((string)$rcpt['recipient_name']) !== '' ? (string)$rcpt['recipient_name'] : (isset($data['nombre']) ? $data['nombre'] : '');
            $data['fecha'] = communication_execution_now();
            $unsubscribeToken = communication_suppressions_token_for($pdo, $organizationId, isset($scope['admin_id']) ? (int)$scope['admin_id'] : 0, $email);
            $unsubscribeUrl = communication_suppressions_url($unsubscribeToken);
            $data['unsubscribe_url'] = $unsubscribeUrl;

            $subjectSnapshot = isset($run['snapshot_subject']) ? (string)$run['snapshot_subject'] : '';
            $htmlSnapshot = isset($run['snapshot_body_html']) ? (string)$run['snapshot_body_html'] : '';
            $textSnapshot = isset($run['snapshot_body_text']) ? (string)$run['snapshot_body_text'] : '';

            $subject = communication_template_renderer_apply($subjectSnapshot, $data);
            $htmlData = $data;
            if (isset($htmlData['nombre'])) {
                $htmlData['nombre'] = htmlspecialchars((string)$htmlData['nombre'], ENT_QUOTES, 'UTF-8');
            }
            $bodyHtml = communication_template_renderer_apply($htmlSnapshot, $htmlData);
            $bodyText = communication_template_renderer_apply($textSnapshot, $data);
            $footerBodies = communication_suppressions_append_footer($bodyHtml, $bodyText, $unsubscribeUrl);
            $bodyHtml = $footerBodies['body_html'];
            $bodyText = $footerBodies['body_text'];

            $transport = communication_transport_send($pdo, (int)$organizationId, array(
                'channel' => 'email',
                'to_email' => $email,
                'subject' => $subject,
                'body_html' => $bodyHtml,
                'body_text' => $bodyText,
                'unsubscribe_url' => $unsubscribeUrl,
            ), array(
                'channel' => 'email',
                'campaign_id' => $campaignId,
                'campaign_run_id' => $runId,
                'recipient_fingerprint' => $fingerprint,
            ));

            communication_execution_insert_attempt($pdo, $runId, $fingerprint, $attemptNo, $transport);

            $statusMap = array(
                'accepted' => 'accepted',
                'rejected' => 'rejected',
                'transient_error' => 'transient_error',
                'permanent_error' => 'permanent_error',
            );
            $finalStatus = isset($statusMap[$transport['status']]) ? $statusMap[$transport['status']] : 'transient_error';

            $paramsUp = array(
                ':st' => $finalStatus,
                ':er' => ($finalStatus === 'accepted') ? null : (isset($transport['response_message']) ? $transport['response_message'] : null),
                ':rc' => isset($transport['response_code']) ? $transport['response_code'] : null,
                ':rm' => isset($transport['response_message']) ? $transport['response_message'] : null,
                ':pn' => isset($transport['provider_name']) ? $transport['provider_name'] : null,
                ':pmid' => isset($transport['provider_message_id']) ? $transport['provider_message_id'] : null,
                ':id' => $recipientId,
            );
            $updatedRecipient = false;
            $upAttempts = 0;
            while ($upAttempts < 4 && !$updatedRecipient) {
                $upAttempts++;
                try {
                    $stUp = $pdo->prepare('UPDATE communication_campaign_run_recipients SET status = :st, last_error = :er, last_response_code = :rc, last_response_message = :rm, provider_name = :pn, provider_message_id = :pmid, locked_until = NULL, processed_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
                    $stUp->execute($paramsUp);
                    $updatedRecipient = true;
                } catch (PDOException $e) {
                    $msg = strtolower((string)$e->getMessage());
                    $isLocked = (strpos($msg, 'database is locked') !== false) || (strpos($msg, 'database table is locked') !== false) || (strpos($msg, 'sqlstate[hy000]: general error: 5') !== false);
                    if ($isLocked && $upAttempts < 4) {
                        usleep(50000 * $upAttempts);
                        continue;
                    }
                    throw $e;
                }
            }
        }

        return count($rows);
    }
}

if (!function_exists('communication_execution_process_command_execute_campaign')) {
    function communication_execution_process_command_execute_campaign($pdo, $command, $workerId, $batchSize = 200)
    {
        $commandId = (int)$command['id'];
        $organizationId = isset($command['organization_id']) ? (int)$command['organization_id'] : 1;
        $campaignId = isset($command['campaign_id']) ? (int)$command['campaign_id'] : 0;

        $payload = array();
        if (!empty($command['payload_json'])) {
            $decoded = json_decode((string)$command['payload_json'], true);
            if (is_array($decoded)) $payload = $decoded;
        }

        $requestIsSuper = !empty($payload['requested_by_is_super']);
        $requestAdminId = isset($payload['requested_by_admin_id']) ? (int)$payload['requested_by_admin_id'] : 0;
        $scope = array('is_super' => $requestIsSuper, 'admin_id' => $requestAdminId);

        $scopeSql = communication_campaigns_scope_sql($requestIsSuper);
        $scopeParams = communication_campaigns_scope_params($organizationId, $requestAdminId, $requestIsSuper);
        $stCampaign = $pdo->prepare('SELECT * FROM communication_campaigns WHERE id = :id AND ' . $scopeSql . ' LIMIT 1');
        $stCampaign->execute(array(':id' => $campaignId) + $scopeParams);
        $campaign = $stCampaign->fetch(PDO::FETCH_ASSOC);

        if (!$campaign) {
            throw new Exception('Campana no accesible para ejecucion.');
        }

        if (function_exists('communication_ops_log')) {
            communication_ops_log($pdo, $organizationId, 'engine', 'command.processing_started', 'info', 'Inicio de procesamiento de comando.', array(
                'campaign_id' => $campaignId,
                'command_id' => $commandId,
                'worker_id' => (string)$workerId,
            ), 'command.processing_started|' . (int)$commandId);
        }

        if (in_array((string)$campaign['status'], array('archived', 'cancelled'), true)) {
            return array('ok' => false, 'cancelled' => true, 'message' => 'Campana no ejecutable en estado actual.');
        }

        $audience = communication_campaigns_find_audience($pdo, $organizationId, $requestAdminId, $requestIsSuper, (int)$campaign['audience_id']);
        $template = communication_campaigns_find_template($pdo, $organizationId, $requestAdminId, $requestIsSuper, (int)$campaign['template_id']);
        if (!$audience || !$template) {
            throw new Exception('Campana sin audiencia o plantilla valida.');
        }

        $activeRun = communication_execution_find_active_run($pdo, $campaignId);
        $runId = 0;
        if ($activeRun) {
            $runId = (int)$activeRun['id'];
        } else {
            $runId = communication_execution_insert_run($pdo, $organizationId, $campaignId, $commandId, isset($audience['filters_json']) ? $audience['filters_json'] : null);

            if (function_exists('communication_ops_log')) {
                communication_ops_log($pdo, $organizationId, 'engine', 'run.created', 'info', 'Run creado para ejecucion de campana.', array(
                    'campaign_id' => $campaignId,
                    'run_id' => $runId,
                    'command_id' => $commandId,
                ), 'run.created|' . (int)$runId);
            }
        }

        communication_execution_mark_campaign_sending($pdo, $campaignId);

        $stRun = $pdo->prepare('SELECT * FROM communication_campaign_runs WHERE id = :id LIMIT 1');
        $stRun->execute(array(':id' => $runId));
        $run = $stRun->fetch(PDO::FETCH_ASSOC);
        if (!$run) {
            throw new Exception('No se pudo recuperar la ejecucion de campana.');
        }

        if (empty($run['snapshot_taken_at'])) {
            $subject = !empty($campaign['subject_override']) ? (string)$campaign['subject_override'] : (string)$template['subject_template'];
            $html = isset($template['body_html_template']) ? (string)$template['body_html_template'] : '';
            $text = isset($template['body_text_template']) ? (string)$template['body_text_template'] : '';

            communication_execution_update_run_snapshot($pdo, $runId, $subject, $html, $text);

            // Snapshot operativo también en campaña para trazabilidad rápida
            $stSnapCamp = $pdo->prepare('UPDATE communication_campaigns SET snapshot_subject = :s, snapshot_body_html = :h, snapshot_body_text = :t, snapshot_taken_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
            $stSnapCamp->execute(array(':s' => $subject, ':h' => $html, ':t' => $text, ':id' => $campaignId));

            $filters = communication_contacts_filters_from_json(isset($audience['filters_json']) ? $audience['filters_json'] : '');
            $allContacts = communication_contacts_resolve($pdo, $scope);
            $targetRows = communication_contacts_apply_filters(array_values($allContacts), $filters);
            communication_execution_materialize_recipients($pdo, $runId, $campaignId, $targetRows, $organizationId, $requestAdminId);

            if (function_exists('communication_ops_log')) {
                communication_ops_log($pdo, $organizationId, 'engine', 'run.snapshot_materialized', 'info', 'Snapshot y destinatarios materializados.', array(
                    'campaign_id' => $campaignId,
                    'run_id' => $runId,
                    'resolved_recipients' => count($targetRows),
                ), 'run.snapshot_materialized|' . (int)$runId);
            }
        }

        // refrescar run luego de potencial snapshot/materialización
        $stRun->execute(array(':id' => $runId));
        $run = $stRun->fetch(PDO::FETCH_ASSOC);

        $batchSize = (int)$batchSize;
        if ($batchSize <= 0) $batchSize = 200;

        communication_execution_process_run_recipients($pdo, $organizationId, $campaign, $run, $scope, $batchSize);
        $final = communication_execution_finalize_run_and_campaign($pdo, $runId, $campaignId);

        return array(
            'ok' => true,
            'complete' => !empty($final['complete']),
            'run_id' => $runId,
            'campaign_status' => isset($final['campaign_status']) ? $final['campaign_status'] : null,
            'counts' => isset($final['counts']) ? $final['counts'] : array(),
        );
    }
}

if (!function_exists('communication_execution_process_single_command')) {
    function communication_execution_process_single_command($pdo, $commandId, $workerId, $batchSize = 200)
    {
        $workerId = (string)$workerId;

        if (!communication_execution_claim_command($pdo, $commandId, $workerId)) {
            return array('processed' => false, 'reason' => 'not-claimed');
        }

        $st = $pdo->prepare('SELECT * FROM communication_execution_commands WHERE id = :id LIMIT 1');
        $st->execute(array(':id' => (int)$commandId));
        $command = $st->fetch(PDO::FETCH_ASSOC);
        if (!$command) {
            return array('processed' => false, 'reason' => 'not-found');
        }

        try {
            $type = isset($command['command_type']) ? (string)$command['command_type'] : 'execute_campaign';
            if ($type !== 'execute_campaign') {
                communication_execution_update_command($pdo, $commandId, 'failed', null, 'Tipo de comando no soportado: ' . $type);
                return array('processed' => true, 'status' => 'failed', 'error' => 'command-type-not-supported');
            }

            $result = communication_execution_process_command_execute_campaign($pdo, $command, $workerId, $batchSize);
            if (!empty($result['cancelled'])) {
                communication_execution_update_command($pdo, $commandId, 'cancelled', json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), isset($result['message']) ? $result['message'] : null);
                return array('processed' => true, 'status' => 'cancelled', 'result' => $result);
            }

            if (empty($result['complete'])) {
                communication_execution_update_command($pdo, $commandId, 'queued', json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), null);
                return array('processed' => true, 'status' => 'queued', 'result' => $result);
            }

            communication_execution_update_command($pdo, $commandId, 'done', json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), null);
            return array('processed' => true, 'status' => 'done', 'result' => $result);
        } catch (Exception $e) {
            $msg = $e->getMessage();

            try {
                if (!empty($command['campaign_id'])) {
                    $stFail = $pdo->prepare('UPDATE communication_campaigns SET status = :st, failed_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
                    $stFail->execute(array(':st' => 'failed', ':id' => (int)$command['campaign_id']));

                    $stRun = $pdo->prepare('UPDATE communication_campaign_runs SET status = :st, last_error = :er, finished_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE campaign_id = :cid AND status IN (\'requested\',\'preparing\',\'running\',\'finalizing\')');
                    $stRun->execute(array(':st' => 'failed', ':er' => $msg, ':cid' => (int)$command['campaign_id']));
                }
            } catch (Exception $e2) {
                // ignore fail-safe update errors
            }

            communication_execution_update_command($pdo, $commandId, 'failed', null, $msg);
            return array('processed' => true, 'status' => 'failed', 'error' => $msg);
        }
    }
}

if (!function_exists('communication_execution_process_queue')) {
    function communication_execution_process_queue($pdo, $maxCommands, $workerId, $batchSize = 200)
    {
        communication_execution_ensure_schema($pdo);
        communication_transport_ensure_schema($pdo);

        $recovered = communication_execution_requeue_stale_processing_commands($pdo);

        $maxCommands = (int)$maxCommands;
        if ($maxCommands <= 0) $maxCommands = 5;

        $workerId = trim((string)$workerId);
        if ($workerId === '') {
            $workerId = 'worker-' . getmypid() . '-' . mt_rand(1000, 9999);
        }

        $out = array(
            'worker_id' => $workerId,
            'recovered' => $recovered,
            'picked' => 0,
            'done' => 0,
            'failed' => 0,
            'cancelled' => 0,
            'details' => array(),
        );

        for ($iteration = 0; $iteration < $maxCommands; $iteration++) {
            // Elegir de nuevo en cada vuelta permite que un comando reencolado
            // continúe con el lote siguiente dentro de la misma ejecución.
            $stSel = $pdo->prepare('SELECT id FROM communication_execution_commands WHERE status = :st AND (scheduled_for IS NULL OR scheduled_for <= CURRENT_TIMESTAMP) ORDER BY id ASC LIMIT 1');
            $stSel->execute(array(':st' => 'queued'));
            $id = $stSel->fetchColumn();
            if (!$id) break;

            $out['picked']++;
            $res = communication_execution_process_single_command($pdo, (int)$id, $workerId, $batchSize);
            $out['details'][] = array('command_id' => (int)$id, 'result' => $res);
            if (!empty($res['processed'])) {
                if (isset($res['status']) && $res['status'] === 'done') $out['done']++;
                if (isset($res['status']) && $res['status'] === 'failed') $out['failed']++;
                if (isset($res['status']) && $res['status'] === 'cancelled') $out['cancelled']++;
            }
        }

        if (function_exists('communication_ops_log')) {
            communication_ops_log($pdo, 1, 'worker', 'worker.queue_batch_processed', 'info', 'Lote de cola procesado.', array(
                'worker_id' => $workerId,
                'recovered' => (int)$out['recovered'],
                'picked' => (int)$out['picked'],
                'done' => (int)$out['done'],
                'failed' => (int)$out['failed'],
                'cancelled' => (int)$out['cancelled'],
            ), 'worker.queue_batch_processed|' . (string)$workerId . '|' . gmdate('YmdHis'));
        }

        return $out;
    }
}
