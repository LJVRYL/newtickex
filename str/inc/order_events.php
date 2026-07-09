<?php
// Helpers de auditoría de órdenes / eventos de flujo de pago.

if (!function_exists('ensure_order_events_table')) {
    function ensure_order_events_table($pdo)
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS order_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tc_order_id INTEGER,
            request_id TEXT,
            event_type TEXT NOT NULL,
            payload_json TEXT,
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        )");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_order_events_tc_order_id ON order_events(tc_order_id)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_order_events_request_id ON order_events(request_id)");
    }
}

if (!function_exists('log_order_event')) {
    function log_order_event($pdo, $tcOrderId, $requestId, $eventType, $payload = array())
    {
        ensure_order_events_table($pdo);
        $payloadJson = '';
        try {
            $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (Exception $_e) {
            $payloadJson = '';
        }

        $params = array(
            ':oid' => $tcOrderId !== null ? $tcOrderId : null,
            ':rid' => $requestId !== null ? (string)$requestId : null,
            ':type' => (string)$eventType,
            ':payload' => $payloadJson,
        );

        $attempts = 0;
        while ($attempts < 4) {
            $attempts++;
            try {
                $st = $pdo->prepare("INSERT INTO order_events (tc_order_id, request_id, event_type, payload_json, created_at) VALUES (:oid, :rid, :type, :payload, datetime('now'))");
                $st->execute($params);
                return (int)$pdo->lastInsertId();
            } catch (PDOException $e) {
                $msg = strtolower((string)$e->getMessage());
                $isLocked = (strpos($msg, 'database is locked') !== false) || (strpos($msg, 'database table is locked') !== false) || (strpos($msg, 'sqlstate[hy000]: general error: 5') !== false);
                if ($isLocked && $attempts < 4) {
                    usleep(50000 * $attempts);
                    continue;
                }

                if (function_exists('error_log')) {
                    error_log('log_order_event failed: ' . $e->getMessage() . ' | event_type=' . (string)$eventType . ' | request_id=' . (string)$requestId);
                }
                return 0;
            } catch (Exception $e) {
                if (function_exists('error_log')) {
                    error_log('log_order_event failed: ' . $e->getMessage() . ' | event_type=' . (string)$eventType . ' | request_id=' . (string)$requestId);
                }
                return 0;
            }
        }

        return 0;
    }
}
