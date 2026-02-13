<?php
// inc/senforms_bridge_admin.php
// Helpers para acciones administrativas sobre SenForms desde STR + auditoría local

require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/senforms.php';

// Tabla de auditoría en SQLite local
function sba_ensure_audit_table() {
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS senforms_admin_audit (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ts DATETIME DEFAULT CURRENT_TIMESTAMP,
        user_id INTEGER,
        action TEXT,
        payload_json TEXT,
        result TEXT,
        error TEXT
    )");
    return $pdo;
}

function sba_log($action, $payload, $result = '', $error = '') {
    try {
        $pdo = sba_ensure_audit_table();
        $uid = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : (isset($_SESSION['admin_id']) ? (int)$_SESSION['admin_id'] : null);
        $st = $pdo->prepare("INSERT INTO senforms_admin_audit (user_id, action, payload_json, result, error) VALUES (:u,:a,:p,:r,:e)");
        $st->execute(array(
            ':u' => $uid,
            ':a' => $action,
            ':p' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            ':r' => $result,
            ':e' => $error,
        ));
    } catch (Exception $e) {
        // no romper flujo si el log falla
    }
}

// Obtiene eventos por IDs específicos
function sba_get_events_by_ids($ids) {
    if (empty($ids)) return array();
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $pdo = sf_db();
    $st = $pdo->prepare("SELECT Id, Name, SiteName FROM Events WHERE Id IN ($placeholders) ORDER BY Id ASC");
    $st->execute(array_values($ids));
    return $st->fetchAll();
}

function sba_get_ticket_types($eventId) {
    return sf_get_ticket_types_with_sales($eventId);
}

function sba_move_ticket_type($ticketTypeId, $fromEventId, $toEventId) {
    $pdo = sf_db();
    $pdo->beginTransaction();
    try {
        // Validar pertenencia
        $chk = $pdo->prepare("SELECT Id, Name, Price, EventId FROM TicketType WHERE Id = ? FOR UPDATE");
        $chk->execute(array((int)$ticketTypeId));
        $row = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException('TicketType no encontrado');
        if ((int)$row['EventId'] !== (int)$fromEventId) {
            throw new RuntimeException('El TicketType no pertenece al evento seleccionado');
        }

        $up = $pdo->prepare("UPDATE TicketType SET EventId = ? WHERE Id = ?");
        $up->execute(array((int)$toEventId, (int)$ticketTypeId));
        if ($up->rowCount() !== 1) {
            throw new RuntimeException('No se pudo mover (sin filas afectadas)');
        }
        $pdo->commit();
        sba_log('move_ticket_type', array('ticket_type_id'=>$ticketTypeId,'from'=>$fromEventId,'to'=>$toEventId), 'ok', '');
        return $row;
    } catch (Exception $e) {
        try { $pdo->rollBack(); } catch (Exception $_) {}
        sba_log('move_ticket_type', array('ticket_type_id'=>$ticketTypeId,'from'=>$fromEventId,'to'=>$toEventId), 'error', $e->getMessage());
        throw $e;
    }
}

function sba_create_ticket_type($eventId, $name, $price) {
    $name = trim($name);
    $price = (float)$price;
    if ($name === '') throw new InvalidArgumentException('Nombre requerido');
    if ($price <= 0) throw new InvalidArgumentException('Precio debe ser mayor a 0');
    $id = sf_create_ticket_type($eventId, $name, $price);
    sba_log('create_ticket_type', array('event_id'=>$eventId,'name'=>$name,'price'=>$price), 'ok', '');
    return $id;
}

function sba_rename_ticket_type($ticketTypeId, $name) {
    $name = trim($name);
    if ($name === '') throw new InvalidArgumentException('Nombre requerido');
    sf_rename_ticket_type($ticketTypeId, $name);
    sba_log('rename_ticket_type', array('ticket_type_id'=>$ticketTypeId,'name'=>$name), 'ok', '');
    return true;
}

?>
