<?php
// Correlates mobile users with the existing server-authoritative web checkout.
// This does not create an order or assert payment. The checkout and webhook own both.

// A mobile checkout belongs to the account that created its reference. The web
// checkout must reject a different buyer email before creating a payment.
function tickex_mobile_checkout_intent_guard($pdo, $ref, $eventId, $buyerEmail)
{
    $ref = trim((string)$ref);
    if (stripos($ref, 'mobile-') !== 0) return 'not_mobile';
    if (!preg_match('/^mobile-[a-f0-9]{32}$/', $ref)) return 'invalid_intent';
    $read = $pdo->prepare('SELECT i.evento_id, i.expires_at, c.email FROM mobile_checkout_intents i JOIN registro_pendientes c ON c.id = i.cliente_id WHERE i.ref = :ref LIMIT 1');
    $read->execute(array(':ref' => $ref));
    $intent = $read->fetch(PDO::FETCH_ASSOC);
    if (!$intent || (int)$intent['evento_id'] !== (int)$eventId || strtotime((string)$intent['expires_at']) <= time()) return 'invalid_intent';
    if ($buyerEmail !== null && strcasecmp(trim((string)$intent['email']), trim((string)$buyerEmail)) !== 0) return 'email_mismatch';
    return 'ok';
}

function tickex_mobile_create_checkout_intent($pdo, $client, $eventId, $idempotencyKey)
{
    if (!preg_match('/^[1-9][0-9]*$/', (string)$eventId)) return array('error' => 'invalid_checkout_request');
    $eventId = (int)$eventId;
    $key = trim((string)$idempotencyKey);
    if ($eventId <= 0 || !preg_match('/^[A-Za-z0-9_-]{16,128}$/', $key)) return array('error' => 'invalid_checkout_request');
    $hash = hash('sha256', $key);
    $read = $pdo->prepare('SELECT ref, evento_id, expires_at FROM mobile_checkout_intents WHERE cliente_id = :client AND idempotency_hash = :hash LIMIT 1');
    $read->execute(array(':client' => (int)$client['id'], ':hash' => $hash));
    $existing = $read->fetch(PDO::FETCH_ASSOC);
    if ($existing) return tickex_mobile_checkout_intent_payload($existing, $eventId);
    $events = tickex_mobile_public_events($pdo, $eventId);
    if (count($events) !== 1) return array('error' => 'event_unavailable');
    $event = $events[0];
    if (!tickex_mobile_event_is_current($event)) return array('error' => 'event_unavailable');
    $types = tickex_mobile_ticket_types($pdo, $eventId, tickex_mobile_global_available($pdo, $event));
    $onSale = false;
    foreach ($types as $type) if ($type['status'] === 'on_sale') { $onSale = true; break; }
    if (!$onSale) return array('error' => 'event_unavailable');

    $ref = 'mobile-' . substr(tickex_mobile_random_token(), 0, 32);
    $created = date('Y-m-d H:i:s');
    $expires = date('Y-m-d H:i:s', time() + 2 * 86400);
    $insert = $pdo->prepare('INSERT OR IGNORE INTO mobile_checkout_intents (cliente_id, evento_id, idempotency_hash, ref, created_at, expires_at) VALUES (:client, :event, :hash, :ref, :created, :expires)');
    $insert->execute(array(':client' => (int)$client['id'], ':event' => $eventId, ':hash' => $hash, ':ref' => $ref, ':created' => $created, ':expires' => $expires));
    $read->execute(array(':client' => (int)$client['id'], ':hash' => $hash));
    $row = $read->fetch(PDO::FETCH_ASSOC);
    if (!$row) return array('error' => 'idempotency_conflict');
    return tickex_mobile_checkout_intent_payload($row, $eventId);
}

function tickex_mobile_checkout_intent_payload($row, $eventId)
{
    if ((int)$row['evento_id'] !== $eventId) return array('error' => 'idempotency_conflict');
    if (strtotime($row['expires_at']) <= time()) return array('error' => 'checkout_intent_expired');
    return array(
        'ref' => (string)$row['ref'],
        'eventId' => (string)$eventId,
        'checkoutUrl' => 'https://str.tickex.com.ar/checkout_totalcoin.php?event=' . $eventId . '&ref=' . rawurlencode($row['ref']),
        'expiresAt' => tickex_mobile_datetime($row['expires_at']),
    );
}

function tickex_mobile_checkout_intent_status($pdo, $client, $ref)
{
    if (!preg_match('/^mobile-[a-f0-9]{32}$/', (string)$ref)) return null;
    $statement = $pdo->prepare('SELECT ref, evento_id, created_at FROM mobile_checkout_intents WHERE ref = :ref AND cliente_id = :client LIMIT 1');
    $statement->execute(array(':ref' => $ref, ':client' => (int)$client['id']));
    $intent = $statement->fetch(PDO::FETCH_ASSOC);
    if (!$intent) return null;
    $orderQuery = $pdo->prepare("SELECT o.id, o.buyer_email, o.payment_status, o.processing_status, o.amount, o.created_at, COALESCE(e.nombre, :fallback) AS event_title, CASE WHEN o.payment_status = 'confirmed' AND (o.processing_status IS NULL OR o.processing_status <> 'issued') AND o.payment_confirmed_at IS NOT NULL AND o.payment_confirmed_at <= datetime('now', '-15 minutes') THEN 1 ELSE 0 END AS needs_assistance FROM tc_orders o LEFT JOIN eventos e ON e.id = o.evento_id WHERE o.ref = :ref AND o.evento_id = :event AND lower(o.buyer_email) = lower(:email) ORDER BY o.id DESC LIMIT 1");
    $orderQuery->execute(array(':fallback' => 'Evento Tickex', ':ref' => $ref, ':event' => (int)$intent['evento_id'], ':email' => trim((string)$client['email'])));
    $order = $orderQuery->fetch(PDO::FETCH_ASSOC);
    return array(
        'ref' => (string)$intent['ref'],
        'eventId' => (string)$intent['evento_id'],
        'createdAt' => tickex_mobile_datetime($intent['created_at']),
        'checkoutUrl' => 'https://str.tickex.com.ar/checkout_totalcoin.php?event=' . (int)$intent['evento_id'] . '&ref=' . rawurlencode($intent['ref']),
        'order' => $order ? tickex_mobile_order_projection($order) : null,
    );
}
