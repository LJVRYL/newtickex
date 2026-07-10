<?php
require_once __DIR__ . '/communication_transport_provider_legacy_mail.php';

if (!function_exists('communication_transport_ensure_schema')) {
    function communication_transport_ensure_schema($pdo)
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS communication_transport_configs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            organization_id INTEGER NOT NULL DEFAULT 0,
            channel TEXT NOT NULL DEFAULT "email",
            provider_name TEXT NOT NULL DEFAULT "legacy_mail_php",
            enabled INTEGER NOT NULL DEFAULT 1,
            config_json TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(organization_id, channel)
        )');

        $pdo->exec('CREATE TABLE IF NOT EXISTS communication_transport_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            organization_id INTEGER,
            campaign_id INTEGER,
            campaign_run_id INTEGER,
            recipient_fingerprint TEXT,
            provider_name TEXT,
            status TEXT,
            response_code TEXT,
            response_message TEXT,
            provider_message_id TEXT,
            latency_ms INTEGER,
            classification_reason TEXT
        )');

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_comm_transport_cfg_org_channel ON communication_transport_configs(organization_id, channel)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_comm_transport_logs_run ON communication_transport_logs(campaign_run_id)');

        $st = $pdo->prepare('INSERT OR IGNORE INTO communication_transport_configs (organization_id, channel, provider_name, enabled, config_json, created_at, updated_at) VALUES (0, :ch, :pn, 1, NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)');
        $st->execute(array(':ch' => 'email', ':pn' => 'legacy_mail_php'));
    }
}

if (!function_exists('communication_transport_provider_supported')) {
    function communication_transport_provider_supported($providerName)
    {
        $providerName = trim((string)$providerName);
        return in_array($providerName, array(
            'legacy_mail_php',
            'smtp',
            'amazon_ses',
            'sendgrid',
            'mailgun',
            'resend',
            'postmark',
        ), true);
    }
}

if (!function_exists('communication_transport_resolve_provider')) {
    function communication_transport_resolve_provider($pdo, $organizationId, $channel)
    {
        $organizationId = (int)$organizationId;
        $channel = trim((string)$channel);
        if ($channel === '') $channel = 'email';

        $providerRow = null;

        try {
            $stOrg = $pdo->prepare('SELECT * FROM communication_transport_configs WHERE organization_id = :org AND channel = :ch AND enabled = 1 LIMIT 1');
            $stOrg->execute(array(':org' => $organizationId, ':ch' => $channel));
            $providerRow = $stOrg->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $providerRow = null;
        }

        if (!$providerRow) {
            try {
                $stGlobal = $pdo->prepare('SELECT * FROM communication_transport_configs WHERE organization_id = 0 AND channel = :ch AND enabled = 1 LIMIT 1');
                $stGlobal->execute(array(':ch' => $channel));
                $providerRow = $stGlobal->fetch(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                $providerRow = null;
            }
        }

        if (!$providerRow) {
            $providerRow = array(
                'provider_name' => 'legacy_mail_php',
                'config_json' => null,
            );
        }

        $providerName = isset($providerRow['provider_name']) ? (string)$providerRow['provider_name'] : 'legacy_mail_php';
        $configJson = isset($providerRow['config_json']) ? (string)$providerRow['config_json'] : '';
        $config = array();
        if (trim($configJson) !== '') {
            $decoded = json_decode($configJson, true);
            if (is_array($decoded)) {
                $config = $decoded;
            }
        }

        return array(
            'provider_name' => $providerName,
            'config' => $config,
        );
    }
}

if (!function_exists('communication_transport_normalize_result')) {
    function communication_transport_normalize_result($providerName, $result, $latencyMs)
    {
        $result = is_array($result) ? $result : array();
        $status = isset($result['status']) ? (string)$result['status'] : 'transient_error';
        $allowed = array('accepted', 'rejected', 'transient_error', 'permanent_error');
        if (!in_array($status, $allowed, true)) {
            $status = 'transient_error';
        }

        return array(
            'status' => $status,
            'provider_name' => isset($result['provider_name']) ? (string)$result['provider_name'] : (string)$providerName,
            'provider_message_id' => isset($result['provider_message_id']) ? (string)$result['provider_message_id'] : null,
            'response_code' => isset($result['response_code']) ? (string)$result['response_code'] : null,
            'response_message' => isset($result['response_message']) ? (string)$result['response_message'] : '',
            'latency_ms' => (int)$latencyMs,
            'retry_recommended' => !empty($result['retry_recommended']) ? true : false,
            'classification_reason' => isset($result['classification_reason']) ? (string)$result['classification_reason'] : 'provider',
        );
    }
}

if (!function_exists('communication_transport_log_result')) {
    function communication_transport_log_result($pdo, $organizationId, $message, $context, $result)
    {
        $params = array(
            ':org' => (int)$organizationId,
            ':cid' => isset($context['campaign_id']) ? (int)$context['campaign_id'] : null,
            ':rid' => isset($context['campaign_run_id']) ? (int)$context['campaign_run_id'] : null,
            ':rf' => isset($context['recipient_fingerprint']) ? (string)$context['recipient_fingerprint'] : null,
            ':pn' => isset($result['provider_name']) ? (string)$result['provider_name'] : null,
            ':st' => isset($result['status']) ? (string)$result['status'] : null,
            ':rc' => isset($result['response_code']) ? (string)$result['response_code'] : null,
            ':rm' => isset($result['response_message']) ? (string)$result['response_message'] : null,
            ':pmid' => isset($result['provider_message_id']) ? (string)$result['provider_message_id'] : null,
            ':lat' => isset($result['latency_ms']) ? (int)$result['latency_ms'] : 0,
            ':cr' => isset($result['classification_reason']) ? (string)$result['classification_reason'] : null,
        );

        $attempts = 0;
        while ($attempts < 4) {
            $attempts++;
            try {
                $st = $pdo->prepare('INSERT INTO communication_transport_logs (organization_id, campaign_id, campaign_run_id, recipient_fingerprint, provider_name, status, response_code, response_message, provider_message_id, latency_ms, classification_reason) VALUES (:org, :cid, :rid, :rf, :pn, :st, :rc, :rm, :pmid, :lat, :cr)');
                $st->execute($params);
                break;
            } catch (PDOException $e) {
                $msg = strtolower((string)$e->getMessage());
                $isLocked = (strpos($msg, 'database is locked') !== false) || (strpos($msg, 'database table is locked') !== false) || (strpos($msg, 'sqlstate[hy000]: general error: 5') !== false);
                if ($isLocked && $attempts < 4) {
                    usleep(50000 * $attempts);
                    continue;
                }
                break;
            } catch (Exception $e) {
                break;
            }
        }

        // Log unificado (solo errores para evitar duplicidad de volumen).
        try {
            $status = isset($result['status']) ? (string)$result['status'] : '';
            if (in_array($status, array('rejected', 'transient_error', 'permanent_error'), true) && function_exists('communication_ops_log')) {
                communication_ops_log($pdo, (int)$organizationId, 'transport', 'transport.send.result', 'warning', 'Resultado de transporte no exitoso.', array(
                    'campaign_id' => isset($context['campaign_id']) ? (int)$context['campaign_id'] : null,
                    'run_id' => isset($context['campaign_run_id']) ? (int)$context['campaign_run_id'] : null,
                    'provider_name' => isset($result['provider_name']) ? (string)$result['provider_name'] : '',
                    'status' => $status,
                    'response_code' => isset($result['response_code']) ? (string)$result['response_code'] : '',
                ), 'transport.send.result|' . (string)$status . '|' . (string)(isset($context['campaign_run_id']) ? $context['campaign_run_id'] : 0) . '|' . (string)(isset($context['recipient_fingerprint']) ? $context['recipient_fingerprint'] : ''));
            }
        } catch (Exception $e) {
            // ignore unified log failures
        }
    }
}

if (!function_exists('communication_transport_send')) {
    function communication_transport_send($pdo, $organizationId, $message, $context)
    {
        communication_transport_ensure_schema($pdo);

        $message = is_array($message) ? $message : array();
        $context = is_array($context) ? $context : array();
        $channel = isset($context['channel']) ? (string)$context['channel'] : 'email';

        $provider = communication_transport_resolve_provider($pdo, $organizationId, $channel);
        $providerName = isset($provider['provider_name']) ? (string)$provider['provider_name'] : 'legacy_mail_php';
        $providerConfig = isset($provider['config']) && is_array($provider['config']) ? $provider['config'] : array();

        $start = microtime(true);

        if (!communication_transport_provider_supported($providerName)) {
            $out = communication_transport_normalize_result($providerName, array(
                'status' => 'permanent_error',
                'response_code' => 'PROVIDER_UNSUPPORTED',
                'response_message' => 'Proveedor no soportado: ' . $providerName,
                'retry_recommended' => false,
                'classification_reason' => 'configuration',
            ), 0);
            communication_transport_log_result($pdo, $organizationId, $message, $context, $out);
            return $out;
        }

        if ($providerName !== 'legacy_mail_php') {
            // Adaptadores declarados para evolución futura; por ahora solo se implementa legacy.
            $out = communication_transport_normalize_result($providerName, array(
                'status' => 'permanent_error',
                'response_code' => 'PROVIDER_NOT_IMPLEMENTED',
                'response_message' => 'Proveedor no implementado en este commit: ' . $providerName,
                'retry_recommended' => false,
                'classification_reason' => 'configuration',
            ), 0);
            communication_transport_log_result($pdo, $organizationId, $message, $context, $out);
            return $out;
        }

        $raw = communication_transport_provider_legacy_mail_send($pdo, $providerConfig, $message, $context);
        $latencyMs = (int)round((microtime(true) - $start) * 1000);
        $out = communication_transport_normalize_result($providerName, $raw, $latencyMs);

        communication_transport_log_result($pdo, $organizationId, $message, $context, $out);
        return $out;
    }
}

if (!function_exists('communication_transport_simulate_send')) {
    function communication_transport_simulate_send($pdo, $organizationId, $message, $context)
    {
        communication_transport_ensure_schema($pdo);

        $message = is_array($message) ? $message : array();
        $context = is_array($context) ? $context : array();
        $channel = isset($context['channel']) ? (string)$context['channel'] : 'email';
        $resolved = communication_transport_resolve_provider($pdo, $organizationId, $channel);
        $providerName = isset($resolved['provider_name']) ? (string)$resolved['provider_name'] : 'legacy_mail_php';

        $to = isset($message['to_email']) ? strtolower(trim((string)$message['to_email'])) : '';
        $status = 'accepted';
        $code = 'SIM_OK';
        $msg = 'Simulacion aceptada (sin envio real).';

        if ($to === '') {
            $status = 'permanent_error';
            $code = 'SIM_NO_RECIPIENT';
            $msg = 'Simulacion invalida: destinatario vacio.';
        } elseif (strpos($to, 'fail') !== false || strpos($to, 'error') !== false) {
            $status = 'transient_error';
            $code = 'SIM_TRANSIENT';
            $msg = 'Simulacion de fallo transitorio por patron de email.';
        } elseif (strpos($to, 'reject') !== false || strpos($to, 'bounce') !== false) {
            $status = 'rejected';
            $code = 'SIM_REJECTED';
            $msg = 'Simulacion de rechazo por patron de email.';
        }

        $result = communication_transport_normalize_result($providerName, array(
            'status' => $status,
            'provider_name' => $providerName,
            'provider_message_id' => 'sim-' . substr(sha1($to . '|' . microtime(true)), 0, 18),
            'response_code' => $code,
            'response_message' => $msg,
            'retry_recommended' => ($status === 'transient_error'),
            'classification_reason' => 'simulation',
        ), 1);

        if (function_exists('communication_ops_log')) {
            communication_ops_log($pdo, (int)$organizationId, 'transport', 'transport.simulated_send', 'info', 'Simulacion de transporte ejecutada.', array(
                'campaign_id' => isset($context['campaign_id']) ? (int)$context['campaign_id'] : null,
                'run_id' => isset($context['campaign_run_id']) ? (int)$context['campaign_run_id'] : null,
                'provider_name' => $providerName,
                'status' => $status,
                'to_email_hash' => sha1($to),
            ), 'transport.simulated_send|' . sha1($to . '|' . gmdate('YmdHis')));
        }

        return $result;
    }
}

if (!function_exists('communication_transport_validate_provider_config')) {
    function communication_transport_validate_provider_config($providerName, $config)
    {
        $providerName = trim((string)$providerName);
        $config = is_array($config) ? $config : array();

        if (!communication_transport_provider_supported($providerName)) {
            return array('valid' => false, 'errors' => array('Proveedor no soportado.'), 'warnings' => array());
        }

        // Legacy no requiere configuración específica.
        if ($providerName === 'legacy_mail_php') {
            return array('valid' => true, 'errors' => array(), 'warnings' => array());
        }

        return array('valid' => true, 'errors' => array(), 'warnings' => array('Proveedor declarado para implementación futura.'));
    }
}

if (!function_exists('communication_transport_health_check')) {
    function communication_transport_health_check($pdo, $organizationId, $channel)
    {
        communication_transport_ensure_schema($pdo);
        $resolved = communication_transport_resolve_provider($pdo, $organizationId, $channel);
        $providerName = isset($resolved['provider_name']) ? (string)$resolved['provider_name'] : 'legacy_mail_php';
        $cfg = isset($resolved['config']) ? $resolved['config'] : array();
        $validation = communication_transport_validate_provider_config($providerName, $cfg);

        $status = 'healthy';
        if (!$validation['valid']) {
            $status = 'unhealthy';
        } elseif (!empty($validation['warnings'])) {
            $status = 'degraded';
        }

        return array(
            'overall_status' => $status,
            'provider_name' => $providerName,
            'checks' => array(
                array(
                    'name' => 'provider-config',
                    'status' => $validation['valid'] ? 'pass' : 'fail',
                    'message' => $validation['valid'] ? 'Configuracion valida.' : implode(' | ', $validation['errors']),
                ),
            ),
            'warnings' => isset($validation['warnings']) ? $validation['warnings'] : array(),
            'checked_at_utc' => gmdate('Y-m-d H:i:s'),
        );
    }
}
