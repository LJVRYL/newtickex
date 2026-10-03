<?php
require_once __DIR__ . '/support_center.php';
require_once __DIR__ . '/notificaciones.php';

function tickex_mobile_support_allowed($admin)
{
    return isset($admin['id'], $admin['tipo_global']) && (int)$admin['id'] > 0 && $admin['tipo_global'] === 'admin_evento';
}

function tickex_mobile_support_project_ticket($row, $withMessages)
{
    $out = array(
        'id'=>(string)$row['id'], 'publicId'=>(string)$row['public_id'], 'eventId'=>$row['event_id'] === null ? null : (string)$row['event_id'],
        'eventName'=>isset($row['event_name']) ? (string)$row['event_name'] : '',
        'category'=>(string)$row['category'], 'subject'=>(string)$row['subject'], 'priority'=>(string)$row['priority'],
        'status'=>(string)$row['status'], 'createdAt'=>(string)$row['created_at'], 'updatedAt'=>(string)$row['updated_at'],
        'lastActivityAt'=>(string)$row['last_activity_at'], 'messageCount'=>isset($row['message_count']) ? (int)$row['message_count'] : null,
    );
    if ($withMessages) {
        $out['messages'] = array();
        foreach ($row['messages'] as $message) $out['messages'][] = array('id'=>(string)$message['id'], 'role'=>(string)$message['author_role'], 'body'=>(string)$message['body'], 'createdAt'=>(string)$message['created_at']);
        $out['messageCount'] = count($out['messages']);
    }
    return $out;
}

function tickex_mobile_support_list($pdo, $admin, $filters)
{
    if (!tickex_mobile_support_allowed($admin)) return array('error'=>'forbidden');
    $safe = array();
    foreach (array('status'=>tickex_support_statuses(), 'category'=>tickex_support_categories(), 'priority'=>tickex_support_priorities()) as $key=>$catalog) {
        if (!isset($filters[$key]) || $filters[$key] === '') continue;
        if (!is_string($filters[$key]) || !isset($catalog[$filters[$key]])) return array('error'=>'invalid_filter');
        $safe[$key] = $filters[$key];
    }
    $rows = tickex_support_list_tickets($pdo, (int)$admin['id'], false, $safe);
    $items = array(); foreach ($rows as $row) $items[] = tickex_mobile_support_project_ticket($row, false);
    return array('items'=>$items, 'categories'=>tickex_support_categories(), 'statuses'=>tickex_support_statuses(), 'priorities'=>tickex_support_priorities());
}

function tickex_mobile_support_detail($pdo, $admin, $ticketId)
{
    if (!tickex_mobile_support_allowed($admin)) return array('error'=>'forbidden');
    $ticket = tickex_support_get_ticket($pdo, (int)$ticketId, (int)$admin['id'], false);
    return $ticket ? tickex_mobile_support_project_ticket($ticket, true) : array('error'=>'not_found');
}

function tickex_mobile_support_create($pdo, $admin, $body)
{
    if (!tickex_mobile_support_allowed($admin)) return array('error'=>'forbidden');
    if (!is_array($body)) return array('error'=>'invalid_body');
    $data = array('event_id'=>isset($body['eventId']) ? $body['eventId'] : 0,
        'category'=>isset($body['category']) ? $body['category'] : '',
        'subject'=>isset($body['subject']) ? $body['subject'] : '',
        'body'=>isset($body['body']) ? $body['body'] : '',
        'priority'=>isset($body['priority']) ? $body['priority'] : 'normal');
    if (!is_numeric($data['event_id']) || (int)$data['event_id'] < 0 || (int)$data['event_id'] != $data['event_id']) return array('error'=>'invalid_event');
    foreach (array('category','subject','body','priority') as $key) if (!is_string($data[$key])) return array('error'=>'invalid_field');
    if (!isset(tickex_support_priorities()[$data['priority']])) return array('error'=>'invalid_priority');
    list($ok, $message, $id) = tickex_support_create_ticket($pdo, (int)$admin['id'], $data);
    if (!$ok) return array('error'=>'invalid_ticket','message'=>$message);
    $created = tickex_mobile_support_detail($pdo, $admin, $id);
    try {
        $superadmins = $pdo->query("SELECT id FROM usuarios_admin WHERE tipo_global IN ('super_admin','superadmin') AND COALESCE(activo,1)=1")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($superadmins as $superId) add_notification((int)$superId, 'Nueva consulta de soporte '.$created['publicId'].'.', 'support', array('url'=>'superadmin_soporte.php?id='.$id), $pdo);
    } catch (Exception $ignored) {}
    return $created;
}

function tickex_mobile_support_reply($pdo, $admin, $ticketId, $body)
{
    if (!tickex_mobile_support_allowed($admin)) return array('error'=>'forbidden');
    if (!is_array($body) || !isset($body['body']) || !is_string($body['body'])) return array('error'=>'invalid_body');
    if (isset(tickex_mobile_support_detail($pdo, $admin, $ticketId)['error'])) return array('error'=>'not_found');
    list($ok, $message) = tickex_support_add_message($pdo, (int)$ticketId, (int)$admin['id'], false, $body['body']);
    return $ok ? tickex_mobile_support_detail($pdo, $admin, $ticketId) : array('error'=>'invalid_reply','message'=>$message);
}

function tickex_mobile_support_reopen($pdo, $admin, $ticketId)
{
    if (!tickex_mobile_support_allowed($admin)) return array('error'=>'forbidden');
    if (isset(tickex_mobile_support_detail($pdo, $admin, $ticketId)['error'])) return array('error'=>'not_found');
    list($ok, $message) = tickex_support_update_ticket($pdo, (int)$ticketId, (int)$admin['id'], false, 'open');
    return $ok ? tickex_mobile_support_detail($pdo, $admin, $ticketId) : array('error'=>'invalid_state','message'=>$message);
}
