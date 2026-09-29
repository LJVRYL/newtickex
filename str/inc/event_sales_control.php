<?php

// One event-level switch shared by every channel that can start a sale or issue a new ticket.
if (!function_exists('tickex_event_sales_paused')) {
    function tickex_event_sales_paused($pdo, $eventId)
    {
        if ((int)$eventId <= 0) return false;
        $exists = $pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='event_sales_control' LIMIT 1");
        if (!$exists->fetchColumn()) return false;
        $read = $pdo->prepare('SELECT paused FROM event_sales_control WHERE event_id = :event LIMIT 1');
        $read->execute(array(':event' => (int)$eventId));
        return (int)$read->fetchColumn() === 1;
    }
}

if (!function_exists('tickex_event_sales_set_paused')) {
    function tickex_event_sales_set_paused($pdo, $eventId, $adminId, $paused, $reason)
    {
        $eventId = (int)$eventId;
        $adminId = (int)$adminId;
        $reason = trim((string)$reason);
        if ($eventId <= 0 || $adminId <= 0 || !is_bool($paused) || strlen($reason) > 240) throw new InvalidArgumentException('Control de ventas inválido.');
        $pdo->exec('CREATE TABLE IF NOT EXISTS event_sales_control (event_id INTEGER PRIMARY KEY, paused INTEGER NOT NULL DEFAULT 0, reason TEXT, updated_by_admin_id INTEGER NOT NULL, updated_at TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS event_sales_control_log (id INTEGER PRIMARY KEY AUTOINCREMENT, event_id INTEGER NOT NULL, paused INTEGER NOT NULL, reason TEXT, admin_id INTEGER NOT NULL, created_at TEXT NOT NULL)');
        $pdo->beginTransaction();
        try {
            $write = $pdo->prepare('INSERT OR REPLACE INTO event_sales_control (event_id, paused, reason, updated_by_admin_id, updated_at) VALUES (:event, :paused, :reason, :admin, CURRENT_TIMESTAMP)');
            $write->execute(array(':event' => $eventId, ':paused' => $paused ? 1 : 0, ':reason' => $reason, ':admin' => $adminId));
            $audit = $pdo->prepare('INSERT INTO event_sales_control_log (event_id, paused, reason, admin_id, created_at) VALUES (:event, :paused, :reason, :admin, CURRENT_TIMESTAMP)');
            $audit->execute(array(':event' => $eventId, ':paused' => $paused ? 1 : 0, ':reason' => $reason, ':admin' => $adminId));
            $pdo->commit();
        } catch (Exception $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
        return array('eventId' => (string)$eventId, 'paused' => $paused, 'reason' => $reason);
    }
}
