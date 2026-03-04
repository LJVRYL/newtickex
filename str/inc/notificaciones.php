<?php
// inc/notificaciones.php
// Funciones para notificaciones de usuario

function tickex_notifications_ensure_table($pdo) {
    try {
        $pdo->exec('CREATE TABLE IF NOT EXISTS notificaciones (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            mensaje TEXT NOT NULL,
            tipo TEXT DEFAULT "info",
            extra TEXT,
            created_at DATETIME DEFAULT (datetime("now")),
            leida INTEGER DEFAULT 0
        )');
    } catch (Exception $e) {
        // ignore
    }
}

function get_user_notifications($userId, $pdo = null) {
    $uid = (int)$userId;
    if ($uid <= 0) return array();
    if (!$pdo) {
        require_once __DIR__ . '/db.php';
        $pdo = db();
    }
    tickex_notifications_ensure_table($pdo);
    try {
        $stmt = $pdo->prepare("SELECT * FROM notificaciones WHERE user_id = :uid ORDER BY created_at DESC LIMIT 50");
        $stmt->execute(array(':uid' => $uid));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return array();
    }
}

function add_notification($userId, $mensaje, $tipo = 'info', $extra = null, $pdo = null) {
    $uid = (int)$userId;
    if ($uid <= 0) return false;
    $msg = trim((string)$mensaje);
    if ($msg === '') return false;
    if (!$pdo) {
        require_once __DIR__ . '/db.php';
        $pdo = db();
    }
    tickex_notifications_ensure_table($pdo);
    try {
        $stmt = $pdo->prepare("INSERT INTO notificaciones (user_id, mensaje, tipo, extra, created_at, leida) VALUES (:uid, :msg, :tipo, :extra, datetime('now'), 0)");
        $stmt->execute(array(
            ':uid' => $uid,
            ':msg' => $msg,
            ':tipo' => (string)$tipo,
            ':extra' => $extra ? json_encode($extra) : null
        ));
        return true;
    } catch (Exception $e) {
        return false;
    }
}

function mark_notification_read($notifId, $pdo = null) {
    if (!$pdo) {
        require_once __DIR__ . '/db.php';
        $pdo = db();
    }
    tickex_notifications_ensure_table($pdo);
    try {
        $stmt = $pdo->prepare("UPDATE notificaciones SET leida = 1 WHERE id = :id");
        $stmt->execute(array(':id' => (int)$notifId));
    } catch (Exception $e) {
        // ignore
    }
}

function mark_all_notifications_read($userId, $pdo = null) {
    $uid = (int)$userId;
    if ($uid <= 0) return;
    if (!$pdo) {
        require_once __DIR__ . '/db.php';
        $pdo = db();
    }
    tickex_notifications_ensure_table($pdo);
    try {
        $stmt = $pdo->prepare("UPDATE notificaciones SET leida = 1 WHERE user_id = :uid");
        $stmt->execute(array(':uid' => $uid));
    } catch (Exception $e) {
        // ignore
    }
}

function add_notification_once_by_key($userId, $uniqueKey, $mensaje, $tipo = 'info', $extra = null, $pdo = null) {
    $uid = (int)$userId;
    $key = trim((string)$uniqueKey);
    if ($uid <= 0 || $key === '') return false;

    if (!$pdo) {
        require_once __DIR__ . '/db.php';
        $pdo = db();
    }
    tickex_notifications_ensure_table($pdo);

    try {
        $st = $pdo->prepare("SELECT id FROM notificaciones WHERE user_id = :uid AND tipo = :tipo AND extra LIKE :lk LIMIT 1");
        $st->execute(array(
            ':uid' => $uid,
            ':tipo' => (string)$tipo,
            ':lk' => '%"notif_key":"' . str_replace('"', '\\"', $key) . '"%'
        ));
        if ($st->fetchColumn()) {
            return false;
        }
    } catch (Exception $e) {
        // ignore lookup failures
    }

    $payload = is_array($extra) ? $extra : array();
    $payload['notif_key'] = $key;
    return add_notification($uid, $mensaje, $tipo, $payload, $pdo);
}
