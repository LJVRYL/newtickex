<?php
// A door QR is a Checkout Pro URL. Only the verified MP webhook can issue tickets.
require_once __DIR__ . '/mercadopago_marketplace.php';
require_once __DIR__ . '/totalcoin_checkout_claim.php';
require_once __DIR__ . '/event_sales_control.php';
require_once __DIR__ . '/event_capacity.php';

function tickex_mobile_door_types($pdo, $eventId)
{
    $st = $pdo->prepare('SELECT id, nombre, precio, cantidad_disponible, qr_quantity, tipo_venta FROM tipos_entrada WHERE evento_id=:event ORDER BY id ASC');
    $st->execute(array(':event' => (int)$eventId));
    $types = array();
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $door = strtoupper(trim((string)$row['tipo_venta'])) === 'PUERTA' || stripos((string)$row['nombre'], 'PUERTA') !== false;
        if (!$door) continue;
        $qrQuantity = max(1, (int)$row['qr_quantity']);
        if ($qrQuantity !== 1) continue; // This flow is explicitly one QR per payment.
        $available = $row['cantidad_disponible'] === null ? null : max(0, (int)$row['cantidad_disponible']);
        $types[] = array('id' => (string)$row['id'], 'name' => (string)$row['nombre'], 'basePrice' => (float)$row['precio'], 'available' => $available);
    }
    return $types;
}

function tickex_mobile_door_breakdown($finalPrice, array $config)
{
    if (!is_numeric($finalPrice)) throw new InvalidArgumentException('invalid_amount');
    $gross = round((float)$finalPrice, 2);
    if ($gross < 1 || $gross > 10000000 || abs((float)$finalPrice - $gross) > 0.00001) throw new InvalidArgumentException('invalid_amount');
    $rate = max(0, min(100, (float)$config['service_charge_percent']));
    $subtotal = round($gross / (1 + $rate / 100), 2);
    $breakdown = tickex_mp_checkout_breakdown($subtotal, $rate, $config['mp_cost_estimate_percent'], $config['organizer_share_percent'], $config['tickex_min_checkout_percent']);
    // The displayed price is authoritative; keep the MP total exact to the cent.
    $breakdown['service_fee'] = round($gross - $subtotal, 2);
    $breakdown['checkout_total'] = $gross;
    $mpCost = round($gross * (float)$config['mp_cost_estimate_percent'] / 100, 2);
    $remainder = max(0, round($breakdown['service_fee'] - $mpCost, 2));
    $organizerShare = round($subtotal * (float)$config['organizer_share_percent'] / 100, 2);
    $minimumFee = round($gross * (float)$config['tickex_min_checkout_percent'] / 100, 2);
    $breakdown['marketplace_fee'] = min($remainder, max($minimumFee, round($remainder - $organizerShare, 2)));
    return $breakdown;
}

function tickex_mobile_door_order($pdo, $ref)
{
    $st = $pdo->prepare('SELECT request_id, ref, evento_id, amount, payment_url, payment_status, processed_at, state, created_at FROM tc_orders WHERE ref=:ref AND payment_provider=\'mercadopago\' LIMIT 1');
    $st->execute(array(':ref' => $ref));
    return $st->fetch(PDO::FETCH_ASSOC);
}

function tickex_mobile_door_projection(array $order)
{
    $created = strtotime((string)$order['created_at'] . ' UTC');
    $expiresAt = $created === false ? null : date('c', $created + 15 * 60);
    return array('ref' => (string)$order['ref'], 'eventId' => (string)$order['evento_id'], 'finalPrice' => (float)$order['amount'], 'expiresAt' => $expiresAt,
        'paymentUrl' => (string)$order['payment_url'], 'payment' => (string)$order['payment_status'] === 'confirmed' ? 'confirmed' : ((string)$order['state'] === 'failed' ? 'failed' : 'pending'),
        'issuance' => !empty($order['processed_at']) ? 'issued' : 'pending');
}

function tickex_mobile_door_recent($pdo, $eventId)
{
    $st = $pdo->prepare("SELECT request_id, ref, evento_id, amount, payment_url, payment_status, processed_at, state, created_at FROM tc_orders WHERE evento_id=:event AND ref LIKE 'door-%' AND payment_provider='mercadopago' ORDER BY id DESC LIMIT 10");
    $st->execute(array(':event' => (int)$eventId));
    $rows = array();
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $order) $rows[] = tickex_mobile_door_projection($order);
    return $rows;
}

function tickex_mobile_door_create($pdo, $clientId, $eventId, array $body, $key, $preferenceHttp = null)
{
    $key = trim((string)$key);
    if (!preg_match('/^[a-zA-Z0-9._-]{16,100}$/', $key)) return array('error' => 'idempotency_key_required');
    $eventId = (int)$eventId;
    $typeId = isset($body['ticketTypeId']) ? (int)$body['ticketTypeId'] : 0;
    $first = trim((string)(isset($body['firstName']) ? $body['firstName'] : ''));
    $last = trim((string)(isset($body['lastName']) ? $body['lastName'] : ''));
    $email = strtolower(trim((string)(isset($body['email']) ? $body['email'] : '')));
    $dni = preg_replace('/[^0-9]/', '', (string)(isset($body['dni']) ? $body['dni'] : ''));
    if ($typeId <= 0 || $first === '' || $last === '' || strlen($first) > 80 || strlen($last) > 80 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 180 || strlen($dni) < 7 || strlen($dni) > 9) return array('error' => 'invalid_buyer');
    if (!isset($body['finalPrice']) || !is_numeric($body['finalPrice'])) return array('error' => 'invalid_amount');
    $gross = (float)$body['finalPrice'];
    if ($gross < 1 || $gross > 10000000 || abs($gross - round($gross, 2)) > 0.00001) return array('error' => 'invalid_amount');
    $ref = 'door-' . substr(hash('sha256', $clientId . ':' . $eventId . ':' . $key), 0, 32);
    tickex_mp_ensure_order_columns($pdo);
    if (tickex_event_sales_paused($pdo, $eventId)) return array('error' => 'sales_paused');
    $stEvent = $pdo->prepare('SELECT id, nombre FROM eventos WHERE id=:id AND (borrado_en IS NULL OR borrado_en=\'\') LIMIT 1');
    $stEvent->execute(array(':id' => $eventId));
    $event = $stEvent->fetch(PDO::FETCH_ASSOC);
    if (!$event) return array('error' => 'event_not_found');
    $type = null;
    foreach (tickex_mobile_door_types($pdo, $eventId) as $candidate) if ((int)$candidate['id'] === $typeId) $type = $candidate;
    if (!$type || ($type['available'] !== null && $type['available'] < 1)) return array('error' => 'type_unavailable');
    try { tickex_event_capacity_assert_available($pdo, $eventId, 1); }
    catch (Exception $_e) { return array('error' => 'event_full'); }
    $config = tickex_mp_event_config($pdo, $eventId);
    if (empty($config['enabled']) || empty($config['enforcement_enabled']) || (string)$config['provider'] !== 'mercadopago') return array('error' => 'mercadopago_unavailable');
    $seller = tickex_mp_event_owner_id($pdo, $eventId);
    if ($seller <= 0) return array('error' => 'mercadopago_unavailable');
    $breakdown = tickex_mobile_door_breakdown($gross, $config);
    $ticketsJson = json_encode(array(array('id' => $typeId, 'name' => $type['name'], 'qty' => 1, 'price' => $breakdown['ticket_subtotal'], 'qr_quantity' => 1)), JSON_UNESCAPED_UNICODE);
    $fingerprint = hash('sha256', implode('|', array($eventId, $typeId, number_format($gross, 2, '.', ''), $first, $last, $email, $dni)));
    $existing = tickex_mobile_door_order($pdo, $ref);
    if ($existing) {
        $stClaim = $pdo->prepare('SELECT fingerprint FROM totalcoin_checkout_claims WHERE ref=:ref LIMIT 1');
        $stClaim->execute(array(':ref' => $ref));
        $saved = $stClaim->fetchColumn();
        return $saved !== false && hash_equals((string)$saved, $fingerprint) ? tickex_mobile_door_projection($existing) : array('error' => 'idempotency_conflict');
    }
    $claim = tickex_totalcoin_checkout_claim($pdo, $ref, $fingerprint, 120);
    if ($claim['result'] === 'conflict') return array('error' => 'idempotency_conflict');
    if ($claim['result'] === 'ready') {
        $existing = tickex_mobile_door_order($pdo, $ref);
        return $existing ? tickex_mobile_door_projection($existing) : array('error' => 'order_pending');
    }
    if ($claim['result'] !== 'acquired') return array('error' => 'order_pending');
    try {
        $preference = tickex_mp_create_preference($pdo, $seller, array('amount' => $gross, 'concept' => 'Entrada puerta · ' . $event['nombre'], 'dni' => $dni, 'reference' => $ref,
            'last_name' => $last, 'first_name' => $first, 'email' => $email, 'event_id' => $eventId, 'marketplace_fee' => $breakdown['marketplace_fee'],
            'expires_at' => date('c', time() + 15 * 60)), $preferenceHttp);
        $st = $pdo->prepare("INSERT INTO tc_orders (request_id,state,evento_id,ref,concept,amount,buyer_dni,buyer_last,buyer_first,buyer_email,selected_tickets_json,payment_url,payment_provider,provider_preference_id,marketplace_fee,seller_admin_id,ticket_subtotal,service_fee_amount,service_fee_percent,mp_cost_estimate_percent,updated_at) VALUES (:rid,'created',:event,:ref,:concept,:amount,:dni,:last,:first,:email,:tickets,:url,'mercadopago',:pref,:fee,:seller,:subtotal,:service,:rate,:mpcost,CURRENT_TIMESTAMP)");
        $st->execute(array(':rid' => $preference['request_id'], ':event' => $eventId, ':ref' => $ref, ':concept' => 'Entrada puerta · ' . $event['nombre'], ':amount' => $gross,
            ':dni' => $dni, ':last' => $last, ':first' => $first, ':email' => $email, ':tickets' => $ticketsJson, ':url' => $preference['payment_url'], ':pref' => $preference['preference_id'],
            ':fee' => $breakdown['marketplace_fee'], ':seller' => $seller, ':subtotal' => $breakdown['ticket_subtotal'], ':service' => $breakdown['service_fee'],
            ':rate' => $config['service_charge_percent'], ':mpcost' => $config['mp_cost_estimate_percent']));
        tickex_totalcoin_checkout_claim_ready($pdo, $ref, $fingerprint, $preference['request_id'], $preference['payment_url']);
        return tickex_mobile_door_projection(tickex_mobile_door_order($pdo, $ref));
    } catch (Exception $e) {
        tickex_totalcoin_checkout_claim_failed($pdo, $ref, $fingerprint, $e->getMessage());
        throw $e;
    }
}
