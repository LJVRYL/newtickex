<?php
// Read-only projection of Tickex orders. Payment state always comes from the shared DB.

function tickex_mobile_order_for_client($pdo, $orderId, $email)
{
    $statement = $pdo->prepare("SELECT o.id, o.buyer_email, o.payment_status, o.processing_status, o.amount, o.created_at, COALESCE(e.nombre, :fallback) AS event_title, CASE WHEN o.payment_status = 'confirmed' AND (o.processing_status IS NULL OR o.processing_status <> 'issued') AND o.payment_confirmed_at IS NOT NULL AND o.payment_confirmed_at <= datetime('now', '-15 minutes') THEN 1 ELSE 0 END AS needs_assistance FROM tc_orders o LEFT JOIN eventos e ON e.id = o.evento_id WHERE o.id = :id LIMIT 1");
    $statement->bindValue(':fallback', 'Evento Tickex');
    $statement->bindValue(':id', (int)$orderId, PDO::PARAM_INT);
    $statement->execute();
    $order = $statement->fetch(PDO::FETCH_ASSOC);
    if (!$order || strcasecmp(trim((string)$order['buyer_email']), trim((string)$email)) !== 0) return null;

    return tickex_mobile_order_projection($order);
}

function tickex_mobile_order_projection($order)
{

    $paymentRaw = strtolower(trim((string)$order['payment_status']));
    $processingRaw = strtolower(trim((string)$order['processing_status']));
    $payment = $paymentRaw === 'confirmed' ? 'confirmed' : (in_array($paymentRaw, array('failed', 'rejected', 'cancelled', 'canceled'), true) ? 'failed' : 'pending');
    $issuance = $payment === 'confirmed' && $processingRaw === 'issued' ? 'issued' : 'pending';
    return array(
        'orderId' => (string)$order['id'],
        'eventTitle' => (string)$order['event_title'],
        'amount' => isset($order['amount']) ? (float)$order['amount'] : null,
        'createdAt' => isset($order['created_at']) ? tickex_mobile_datetime($order['created_at']) : null,
        'payment' => $payment,
        'issuance' => $issuance,
        'needsAssistance' => isset($order['needs_assistance']) && (int)$order['needs_assistance'] === 1,
    );
}

function tickex_mobile_orders_for_client($pdo, $email)
{
    $statement = $pdo->prepare("SELECT o.id, o.buyer_email, o.payment_status, o.processing_status, o.amount, o.created_at, COALESCE(e.nombre, :fallback) AS event_title, CASE WHEN o.payment_status = 'confirmed' AND (o.processing_status IS NULL OR o.processing_status <> 'issued') AND o.payment_confirmed_at IS NOT NULL AND o.payment_confirmed_at <= datetime('now', '-15 minutes') THEN 1 ELSE 0 END AS needs_assistance FROM tc_orders o LEFT JOIN eventos e ON e.id = o.evento_id WHERE lower(o.buyer_email) = lower(:email) ORDER BY o.id DESC LIMIT 20");
    $statement->execute(array(':fallback' => 'Evento Tickex', ':email' => trim((string)$email)));
    $result = array();
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $order) $result[] = tickex_mobile_order_projection($order);
    return $result;
}
