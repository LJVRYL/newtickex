<?php
// Mobile gate operations over the same STR entries and staff invitations as the web.
function tickex_mobile_gate_permission($pdo, $clientId, $eventId, $permission)
{
    foreach (tickex_mobile_staff_events($pdo, $clientId) as $event) {
        if ((int)$event['id'] === (int)$eventId && in_array($permission, $event['permissions'], true)) return true;
    }
    return false;
}

function tickex_mobile_gate_code($raw)
{
    if (!is_string($raw) || strlen($raw) > 512) return '';
    $raw = trim($raw);
    if (preg_match('~^https?://~i', $raw)) {
        $parts = parse_url($raw);
        if (!is_array($parts) || !isset($parts['host']) || strtolower($parts['host']) !== 'str.tickex.com.ar' || !isset($parts['path']) || $parts['path'] !== '/checkin.php') return '';
        $query = array();
        parse_str(isset($parts['query']) ? $parts['query'] : '', $query);
        $raw = isset($query['c']) && is_string($query['c']) ? $query['c'] : '';
    }
    return preg_match('/^[A-Za-z0-9._-]{6,160}$/', $raw) ? $raw : '';
}

function tickex_mobile_gate_ticket($pdo, $eventId, $ticketId, $code)
{
    $extra = '';
    $columns = tickex_mobile_columns($pdo, 'entradas');
    foreach (array('anulado', 'estado') as $column) if (isset($columns[$column])) $extra .= ', ' . $column;
    $sql = 'SELECT id, evento_id, nombre, email, tipo, codigo, checked_in, checked_in_at' . $extra . ' FROM entradas WHERE evento_id = :event AND ' . ($ticketId > 0 ? 'id = :ticket' : 'codigo = :code') . ' LIMIT 1';
    $statement = $pdo->prepare($sql);
    $statement->bindValue(':event', (int)$eventId, PDO::PARAM_INT);
    if ($ticketId > 0) $statement->bindValue(':ticket', (int)$ticketId, PDO::PARAM_INT);
    else $statement->bindValue(':code', $code);
    $statement->execute();
    return $statement->fetch(PDO::FETCH_ASSOC);
}

function tickex_mobile_gate_projection($row)
{
    $cancelled = (isset($row['anulado']) && (int)$row['anulado'] === 1) || (isset($row['estado']) && in_array(strtolower((string)$row['estado']), array('cancelled', 'anulada', 'anulado'), true));
    return array('ticketId' => (string)$row['id'], 'eventId' => (string)$row['evento_id'], 'holderName' => (string)$row['nombre'], 'typeName' => (string)$row['tipo'], 'status' => $cancelled ? 'cancelled' : ((int)$row['checked_in'] === 1 ? 'used' : 'valid'), 'checkedInAt' => isset($row['checked_in_at']) ? tickex_mobile_datetime($row['checked_in_at']) : null);
}

function tickex_mobile_gate_stats($pdo, $eventId)
{
    $statement = $pdo->prepare('SELECT COUNT(*) AS total, SUM(CASE WHEN checked_in = 1 THEN 1 ELSE 0 END) AS used FROM entradas WHERE evento_id = :event');
    $statement->execute(array(':event' => (int)$eventId));
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    $total = (int)$row['total']; $used = (int)$row['used'];
    return array('eventId' => (string)$eventId, 'total' => $total, 'checkedIn' => $used, 'remaining' => max(0, $total - $used));
}

function tickex_mobile_gate_search($pdo, $eventId, $query)
{
    $query = trim((string)$query);
    if (strlen($query) < 2 || strlen($query) > 80) return array();
    $statement = $pdo->prepare('SELECT id, evento_id, nombre, tipo, checked_in, checked_in_at FROM entradas WHERE evento_id = :event AND (nombre LIKE :name OR email LIKE :email OR codigo = :code) ORDER BY id DESC LIMIT 20');
    $like = '%' . str_replace(array('%', '_'), '', $query) . '%';
    $statement->execute(array(':event' => (int)$eventId, ':name' => $like, ':email' => $like, ':code' => $query));
    $rows = array();
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) $rows[] = tickex_mobile_gate_projection($row);
    return $rows;
}

function tickex_mobile_gate_audit($pdo, $clientId, $row, $action, $result)
{
    $has = $pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='checkin_audit_log' LIMIT 1")->fetchColumn();
    if (!$has) return;
    $statement = $pdo->prepare('INSERT INTO checkin_audit_log (actor_user_id, evento_id, source, source_ticket_id, ticket_ref, attendee_name, action, result, detail) VALUES (NULL, :event, :source, :ticket, :ref, :name, :action, :result, :detail)');
    $statement->execute(array(':event' => (int)$row['evento_id'], ':source' => 'STR', ':ticket' => (int)$row['id'], ':ref' => '', ':name' => '', ':action' => $action, ':result' => $result, ':detail' => 'mobile_client_' . (int)$clientId));
}

function tickex_mobile_gate_checkin($pdo, $clientId, $eventId, $ticketId, $rawCode)
{
    $code = $ticketId > 0 ? '' : tickex_mobile_gate_code($rawCode);
    if ($ticketId <= 0 && $code === '') return array('error' => 'invalid_code');
    $pdo->beginTransaction();
    try {
        $row = tickex_mobile_gate_ticket($pdo, $eventId, $ticketId, $code);
        if (!$row) { $pdo->rollBack(); return array('error' => 'not_found'); }
        $ticket = tickex_mobile_gate_projection($row);
        if ($ticket['status'] === 'cancelled') { $pdo->rollBack(); return array('error' => 'cancelled', 'ticket' => $ticket); }
        if ($ticket['status'] === 'used') { tickex_mobile_gate_audit($pdo, $clientId, $row, 'mobile_checkin', 'duplicate'); $pdo->commit(); return array('error' => 'already_checked_in', 'ticket' => $ticket); }
        $now = date('Y-m-d H:i:s');
        $update = $pdo->prepare('UPDATE entradas SET checked_in = 1, checked_in_at = :now, oculto = 0 WHERE id = :ticket AND evento_id = :event AND COALESCE(checked_in, 0) = 0');
        $update->execute(array(':now' => $now, ':ticket' => (int)$row['id'], ':event' => (int)$eventId));
        if ($update->rowCount() !== 1) { $pdo->rollBack(); return array('error' => 'already_checked_in'); }
        $action = $pdo->prepare('INSERT INTO mobile_checkin_actions (ticket_id, evento_id, client_id, action, created_at) VALUES (:ticket, :event, :client, :action, :now)');
        $action->execute(array(':ticket' => (int)$row['id'], ':event' => (int)$eventId, ':client' => (int)$clientId, ':action' => 'checkin', ':now' => $now));
        tickex_mobile_gate_audit($pdo, $clientId, $row, 'mobile_checkin', 'ok');
        $pdo->commit();
        $row['checked_in'] = 1; $row['checked_in_at'] = $now;
        return array('ticket' => tickex_mobile_gate_projection($row), 'stats' => tickex_mobile_gate_stats($pdo, $eventId));
    } catch (Exception $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
}

function tickex_mobile_gate_undo($pdo, $clientId, $eventId, $ticketId)
{
    if ($ticketId <= 0) return array('error' => 'invalid_ticket');
    $pdo->beginTransaction();
    try {
        $row = tickex_mobile_gate_ticket($pdo, $eventId, $ticketId, '');
        if (!$row) { $pdo->rollBack(); return array('error' => 'not_found'); }
        if ((int)$row['checked_in'] !== 1) { $pdo->rollBack(); return array('error' => 'not_checked_in'); }
        $latest = $pdo->prepare('SELECT client_id, action, created_at FROM mobile_checkin_actions WHERE ticket_id = :ticket ORDER BY id DESC LIMIT 1');
        $latest->execute(array(':ticket' => $ticketId));
        $action = $latest->fetch(PDO::FETCH_ASSOC);
        if (!$action || $action['action'] !== 'checkin' || (int)$action['client_id'] !== (int)$clientId || (string)$row['checked_in_at'] !== (string)$action['created_at'] || strtotime($action['created_at']) < time() - 600) { $pdo->rollBack(); return array('error' => 'undo_not_allowed'); }
        $update = $pdo->prepare('UPDATE entradas SET checked_in = 0, checked_in_at = NULL WHERE id = :ticket AND evento_id = :event AND checked_in = 1');
        $update->execute(array(':ticket' => $ticketId, ':event' => $eventId));
        if ($update->rowCount() !== 1) { $pdo->rollBack(); return array('error' => 'not_checked_in'); }
        $record = $pdo->prepare('INSERT INTO mobile_checkin_actions (ticket_id, evento_id, client_id, action, created_at) VALUES (:ticket, :event, :client, :action, :now)');
        $record->execute(array(':ticket' => $ticketId, ':event' => $eventId, ':client' => $clientId, ':action' => 'undo', ':now' => date('Y-m-d H:i:s')));
        tickex_mobile_gate_audit($pdo, $clientId, $row, 'mobile_undo', 'ok');
        $pdo->commit();
        $row['checked_in'] = 0; $row['checked_in_at'] = null;
        return array('ticket' => tickex_mobile_gate_projection($row), 'stats' => tickex_mobile_gate_stats($pdo, $eventId));
    } catch (Exception $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
}
