<?php
require_once __DIR__ . '/mobile_admin.php';

function tickex_mobile_admin_staff_operations($pdo, $admin, $eventId)
{
    $ownerId = tickex_mobile_admin_event_owner($pdo, $admin, $eventId);
    if ($ownerId <= 0) return array('error' => 'forbidden');
    tickex_staff_operations_ensure_schema($pdo);
    $summary = tickex_staff_operations_summary($pdo, $ownerId, $eventId);
    $members = $pdo->prepare("SELECT se.staff_id, se.attendance_status, se.checked_in_at, se.checked_out_at, se.settlement_status, se.settled_amount, se.costo_servicio, COALESCE(NULLIF(se.rol_staff, ''), sa.rol_staff) AS role_code, rp.nombre, rp.apellido, rp.apodo, rp.email FROM staff_eventos se JOIN staff_admins sa ON sa.cliente_id = se.staff_id AND sa.owner_admin_id = :owner AND sa.activo = 1 JOIN registro_pendientes rp ON rp.id = se.staff_id WHERE se.evento_id = :event ORDER BY rp.nombre, rp.apellido, se.staff_id LIMIT 200");
    $members->execute(array(':owner' => $ownerId, ':event' => $eventId));
    $roster = array();
    foreach ($members->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $name = trim((string)$row['nombre'] . ' ' . (string)$row['apellido']);
        if ($name === '') $name = trim((string)$row['apodo']);
        if ($name === '') $name = (string)$row['email'];
        $roster[] = array('clientId' => (string)$row['staff_id'], 'name' => $name, 'roleCode' => (string)$row['role_code'], 'attendance' => (string)$row['attendance_status'], 'checkedInAt' => tickex_mobile_datetime($row['checked_in_at']), 'checkedOutAt' => tickex_mobile_datetime($row['checked_out_at']), 'settlement' => (string)$row['settlement_status'], 'plannedCost' => (float)$row['costo_servicio'], 'settledAmount' => (float)$row['settled_amount']);
    }
    $shiftQuery = $pdo->prepare('SELECT id, staff_id, starts_at, ends_at, area, notes, status FROM staff_shifts WHERE owner_admin_id = :owner AND evento_id = :event ORDER BY starts_at, id LIMIT 200');
    $shiftQuery->execute(array(':owner' => $ownerId, ':event' => $eventId));
    $shifts = array();
    foreach ($shiftQuery->fetchAll(PDO::FETCH_ASSOC) as $row) $shifts[] = array('id' => (string)$row['id'], 'clientId' => (string)$row['staff_id'], 'startsAt' => tickex_mobile_datetime($row['starts_at']), 'endsAt' => tickex_mobile_datetime($row['ends_at']), 'area' => (string)$row['area'], 'notes' => (string)$row['notes'], 'status' => (string)$row['status']);
    $taskQuery = $pdo->prepare('SELECT id, assigned_staff_id, role_code, title, notes, due_at, status FROM staff_tasks WHERE owner_admin_id = :owner AND evento_id = :event ORDER BY CASE status WHEN \'done\' THEN 1 ELSE 0 END, due_at, id DESC LIMIT 200');
    $taskQuery->execute(array(':owner' => $ownerId, ':event' => $eventId));
    $tasks = array();
    foreach ($taskQuery->fetchAll(PDO::FETCH_ASSOC) as $row) $tasks[] = array('id' => (string)$row['id'], 'clientId' => $row['assigned_staff_id'] === null ? null : (string)$row['assigned_staff_id'], 'roleCode' => $row['role_code'] === null ? null : (string)$row['role_code'], 'title' => (string)$row['title'], 'notes' => (string)$row['notes'], 'dueAt' => tickex_mobile_datetime($row['due_at']), 'status' => (string)$row['status']);
    return array('eventId' => (string)$eventId, 'summary' => array('assigned' => (int)$summary['assigned'], 'present' => (int)$summary['present'], 'settled' => (int)$summary['settled'], 'plannedCost' => (float)$summary['planned_cost'], 'paidCost' => (float)$summary['paid_cost'], 'tasks' => (int)$summary['tasks'], 'tasksDone' => (int)$summary['tasks_done']), 'members' => $roster, 'shifts' => $shifts, 'tasks' => $tasks);
}

function tickex_mobile_admin_staff_action($pdo, $admin, $eventId, $body)
{
    $ownerId = tickex_mobile_admin_event_owner($pdo, $admin, $eventId);
    if ($ownerId <= 0) return array('error' => 'forbidden');
    if (!is_array($body)) return array('error' => 'invalid_action');
    tickex_staff_operations_ensure_schema($pdo);
    $action = isset($body['action']) ? (string)$body['action'] : '';
    $clientId = isset($body['clientId']) ? (int)$body['clientId'] : 0;
    if (in_array($action, array('attendance', 'settlement', 'add_shift'), true) || ($action === 'add_task' && $clientId > 0)) {
        $assigned = $pdo->prepare('SELECT 1 FROM staff_eventos se JOIN staff_admins sa ON sa.cliente_id = se.staff_id AND sa.owner_admin_id = :owner AND sa.activo = 1 WHERE se.staff_id = :staff AND se.evento_id = :event LIMIT 1');
        $assigned->execute(array(':owner' => $ownerId, ':staff' => $clientId, ':event' => $eventId));
        if ($clientId <= 0 || !$assigned->fetchColumn()) return array('error' => 'staff_not_assigned');
    }
    if ($action === 'attendance') {
        $status = isset($body['status']) ? (string)$body['status'] : '';
        if (!in_array($status, array('pending', 'present', 'absent'), true)) return array('error' => 'invalid_status');
        $write = $pdo->prepare("UPDATE staff_eventos SET attendance_status = :status, checked_in_at = CASE WHEN :status = 'present' THEN COALESCE(checked_in_at, CURRENT_TIMESTAMP) ELSE NULL END, checked_out_at = CASE WHEN :status = 'pending' THEN NULL ELSE checked_out_at END WHERE staff_id = :staff AND evento_id = :event");
        $write->execute(array(':status' => $status, ':staff' => $clientId, ':event' => $eventId));
        tickex_staff_audit($pdo, $ownerId, (int)$admin['id'], 'attendance_changed', $clientId, $eventId, array('status' => $status));
    } elseif ($action === 'settlement') {
        $amount = isset($body['amount']) ? $body['amount'] : null;
        $paid = isset($body['paid']) ? $body['paid'] : null;
        if (!is_numeric($amount) || (float)$amount < 0 || (float)$amount > 100000000 || !is_bool($paid)) return array('error' => 'invalid_settlement');
        $write = $pdo->prepare("UPDATE staff_eventos SET settlement_status = :status, settled_amount = :amount, settled_at = CASE WHEN :status = 'paid' THEN CURRENT_TIMESTAMP ELSE NULL END WHERE staff_id = :staff AND evento_id = :event");
        $write->execute(array(':status' => $paid ? 'paid' : 'pending', ':amount' => (float)$amount, ':staff' => $clientId, ':event' => $eventId));
        tickex_staff_audit($pdo, $ownerId, (int)$admin['id'], 'settlement_changed', $clientId, $eventId, array('paid' => $paid, 'amount' => (float)$amount));
    } elseif ($action === 'add_shift') {
        $starts = isset($body['startsAt']) ? trim((string)$body['startsAt']) : '';
        $ends = isset($body['endsAt']) ? trim((string)$body['endsAt']) : '';
        $area = isset($body['area']) ? trim((string)$body['area']) : '';
        $notes = isset($body['notes']) ? trim((string)$body['notes']) : '';
        if (!tickex_mobile_admin_staff_datetime_valid($starts) || ($ends !== '' && (!tickex_mobile_admin_staff_datetime_valid($ends) || strtotime($ends) < strtotime($starts))) || strlen($area) > 100 || strlen($notes) > 500) return array('error' => 'invalid_shift');
        $insert = $pdo->prepare('INSERT INTO staff_shifts (owner_admin_id, staff_id, evento_id, starts_at, ends_at, area, notes) VALUES (:owner, :staff, :event, :starts, :ends, :area, :notes)');
        $insert->execute(array(':owner' => $ownerId, ':staff' => $clientId, ':event' => $eventId, ':starts' => date('Y-m-d H:i:s', strtotime($starts)), ':ends' => $ends === '' ? null : date('Y-m-d H:i:s', strtotime($ends)), ':area' => $area, ':notes' => $notes));
        tickex_staff_audit($pdo, $ownerId, (int)$admin['id'], 'shift_created', $clientId, $eventId, array('starts_at' => $starts, 'ends_at' => $ends));
    } elseif ($action === 'add_task') {
        $title = isset($body['title']) ? trim((string)$body['title']) : '';
        $notes = isset($body['notes']) ? trim((string)$body['notes']) : '';
        $due = isset($body['dueAt']) ? trim((string)$body['dueAt']) : '';
        $role = isset($body['roleCode']) ? trim((string)$body['roleCode']) : '';
        if ($title === '' || strlen($title) > 160 || strlen($notes) > 500 || ($due !== '' && !tickex_mobile_admin_staff_datetime_valid($due))) return array('error' => 'invalid_task');
        if ($role !== '' && !isset(tickex_staff_roles_get_map($pdo, $ownerId)[$role])) return array('error' => 'invalid_role');
        $insert = $pdo->prepare('INSERT INTO staff_tasks (owner_admin_id, evento_id, assigned_staff_id, role_code, title, notes, due_at) VALUES (:owner, :event, :staff, :role, :title, :notes, :due)');
        $insert->execute(array(':owner' => $ownerId, ':event' => $eventId, ':staff' => $clientId > 0 ? $clientId : null, ':role' => $role === '' ? null : $role, ':title' => $title, ':notes' => $notes, ':due' => $due === '' ? null : date('Y-m-d H:i:s', strtotime($due))));
        tickex_staff_audit($pdo, $ownerId, (int)$admin['id'], 'task_created', $clientId, $eventId, array('title' => $title, 'role' => $role));
    } elseif ($action === 'task_status') {
        $taskId = isset($body['taskId']) ? (int)$body['taskId'] : 0;
        $status = isset($body['status']) ? (string)$body['status'] : '';
        if ($taskId <= 0 || !in_array($status, array('pending', 'done'), true)) return array('error' => 'invalid_task');
        $write = $pdo->prepare('UPDATE staff_tasks SET status = :status, completed_at = :completed, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND owner_admin_id = :owner AND evento_id = :event');
        $write->execute(array(':status' => $status, ':completed' => $status === 'done' ? date('Y-m-d H:i:s') : null, ':id' => $taskId, ':owner' => $ownerId, ':event' => $eventId));
        if (!$write->rowCount()) return array('error' => 'task_not_found');
        tickex_staff_audit($pdo, $ownerId, (int)$admin['id'], 'task_status_changed', null, $eventId, array('task_id' => $taskId, 'status' => $status));
    } else return array('error' => 'invalid_action');
    return tickex_mobile_admin_staff_operations($pdo, $admin, $eventId);
}

function tickex_mobile_admin_staff_datetime_valid($value)
{
    return preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(?::\d{2})?(?:Z|[+-]\d{2}:\d{2})?$/', (string)$value) && strtotime((string)$value) !== false;
}
