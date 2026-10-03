<?php
function tickex_mobile_admin_sales_page($pdo, $admin, $eventId, $beforeId, $limit, $status)
{
    if (!tickex_mobile_admin_can_event($pdo, $admin, (int)$eventId)) return array('error' => 'forbidden');
    $beforeId = (int)$beforeId;
    $limit = (int)$limit;
    if ($limit < 1 || $limit > 50 || $beforeId < 0 || !in_array($status, array('all', 'confirmed', 'pending', 'duplicate', 'failed', 'rejected', 'cancelled'), true)) return array('error' => 'invalid_filter');
    $sql = 'SELECT id, amount, payment_status, processing_status, payment_provider, created_at, payment_confirmed_at FROM tc_orders WHERE evento_id=:event';
    if ($beforeId > 0) $sql .= ' AND id<:before';
    if ($status !== 'all') $sql .= ' AND payment_status=:status';
    $sql .= ' ORDER BY id DESC LIMIT :limit';
    $read = $pdo->prepare($sql);
    $read->bindValue(':event', (int)$eventId, PDO::PARAM_INT);
    if ($beforeId > 0) $read->bindValue(':before', $beforeId, PDO::PARAM_INT);
    if ($status !== 'all') $read->bindValue(':status', $status);
    $read->bindValue(':limit', $limit + 1, PDO::PARAM_INT);
    $read->execute();
    $rows = $read->fetchAll(PDO::FETCH_ASSOC);
    $hasMore = count($rows) > $limit;
    if ($hasMore) array_pop($rows);
    $items = array();
    foreach ($rows as $row) $items[] = array(
        'orderId' => (string)$row['id'],
        'amount' => (float)$row['amount'],
        'payment' => (string)$row['payment_status'],
        'issuance' => (string)$row['processing_status'],
        'provider' => (string)$row['payment_provider'],
        'createdAt' => tickex_mobile_datetime($row['created_at']),
        'confirmedAt' => tickex_mobile_datetime($row['payment_confirmed_at']),
    );
    return array('items' => $items, 'nextBeforeId' => $hasMore && $rows ? (string)$rows[count($rows) - 1]['id'] : null);
}

function tickex_mobile_admin_sale_detail($pdo, $admin, $eventId, $orderId)
{
    if (!tickex_mobile_admin_can_event($pdo, $admin, (int)$eventId)) return array('error' => 'forbidden');
    $read = $pdo->prepare('SELECT id, request_id, amount, ticket_subtotal, service_fee_amount, buyer_first, buyer_last, buyer_email, payment_status, processing_status, payment_provider, created_at, payment_confirmed_at FROM tc_orders WHERE id=:id AND evento_id=:event LIMIT 1');
    $read->execute(array(':id' => (int)$orderId, ':event' => (int)$eventId));
    $row = $read->fetch(PDO::FETCH_ASSOC);
    if (!$row) return array('error' => 'not_found');
    $ticketCount = 0;
    if (trim((string)$row['request_id']) !== '') {
        $tickets = $pdo->prepare('SELECT COUNT(*) FROM entradas WHERE evento_id=:event AND tc_order_request_id=:request');
        $tickets->execute(array(':event' => (int)$eventId, ':request' => $row['request_id']));
        $ticketCount = (int)$tickets->fetchColumn();
    }
    return array(
        'orderId' => (string)$row['id'],
        'eventId' => (string)$eventId,
        'amount' => (float)$row['amount'],
        'ticketSubtotal' => (float)$row['ticket_subtotal'],
        'serviceFee' => (float)$row['service_fee_amount'],
        'buyerName' => trim((string)$row['buyer_first'] . ' ' . (string)$row['buyer_last']),
        'buyerEmail' => (string)$row['buyer_email'],
        'payment' => (string)$row['payment_status'],
        'issuance' => (string)$row['processing_status'],
        'provider' => (string)$row['payment_provider'],
        'ticketCount' => $ticketCount,
        'createdAt' => tickex_mobile_datetime($row['created_at']),
        'confirmedAt' => tickex_mobile_datetime($row['payment_confirmed_at']),
    );
}
