<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/mercadopago_marketplace.php';
require_once __DIR__ . '/inc/order_processing.php';
require_once __DIR__ . '/inc/order_events.php';

header('Content-Type: application/json; charset=UTF-8');
$raw = file_get_contents('php://input');
$payload = json_decode((string)$raw, true);
if (!is_array($payload)) $payload = array();
$dataId = '';
if (isset($_GET['data_id'])) $dataId = (string)$_GET['data_id'];
if ($dataId === '' && isset($_GET['data.id'])) $dataId = (string)$_GET['data.id'];
if ($dataId === '' && isset($payload['data']['id'])) $dataId = (string)$payload['data']['id'];
$action = isset($payload['action']) ? (string)$payload['action'] : (isset($_GET['topic']) ? (string)$_GET['topic'] : 'payment');
$xSignature = isset($_SERVER['HTTP_X_SIGNATURE']) ? (string)$_SERVER['HTTP_X_SIGNATURE'] : '';
$xRequestId = isset($_SERVER['HTTP_X_REQUEST_ID']) ? (string)$_SERVER['HTTP_X_REQUEST_ID'] : '';

if ($dataId === '' || !tickex_mp_verify_webhook_signature($xSignature, $xRequestId, $dataId)) {
    http_response_code(401);
    echo json_encode(array('ok' => false));
    exit;
}

$pdo = db();
tickex_mp_ensure_schema($pdo);
$eventKey = hash('sha256', $xRequestId . '|' . $action . '|' . $dataId);
$insert = $pdo->prepare("INSERT OR IGNORE INTO mercadopago_webhook_events (event_key,action,payment_id,status,payload_json) VALUES (:key,:action,:payment,'received',:payload)");
$insert->execute(array(':key' => $eventKey, ':action' => $action, ':payment' => $dataId, ':payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
if ($insert->rowCount() !== 1) {
    $existingStatus = $pdo->prepare('SELECT status FROM mercadopago_webhook_events WHERE event_key=:key LIMIT 1');
    $existingStatus->execute(array(':key' => $eventKey));
    if ($existingStatus->fetchColumn() === 'processed') {
        echo json_encode(array('ok' => true, 'duplicate' => true));
        exit;
    }
    $pdo->prepare("UPDATE mercadopago_webhook_events SET status='received',error_text=NULL,updated_at=CURRENT_TIMESTAMP WHERE event_key=:key")->execute(array(':key' => $eventKey));
}

try {
    /* Resolve seller without trusting webhook fields: payment is queried with each
       connected seller only when its ID is already linked, otherwise by account
       candidates until external_reference identifies the order. */
    $order = null;
    $payment = null;
    $stKnown = $pdo->prepare("SELECT * FROM tc_orders WHERE payment_provider='mercadopago' AND provider_payment_id=:payment LIMIT 1");
    $stKnown->execute(array(':payment' => $dataId));
    $order = $stKnown->fetch(PDO::FETCH_ASSOC);
    if ($order) {
        $payment = tickex_mp_get_payment($pdo, (int)$order['seller_admin_id'], $dataId);
    } else {
        $mpUserId = isset($payload['user_id']) ? (string)$payload['user_id'] : '';
        if ($mpUserId === '') throw new RuntimeException('La notificacion no identifica al vendedor.');
        $stAdmin = $pdo->prepare("SELECT admin_id FROM mercadopago_marketplace_accounts WHERE status='connected' AND mp_user_id=:user LIMIT 1");
        $stAdmin->execute(array(':user' => $mpUserId));
        $adminId = (int)$stAdmin->fetchColumn();
        if ($adminId <= 0) throw new RuntimeException('La cuenta vendedora no esta vinculada a Tickex.');
        $payment = tickex_mp_get_payment($pdo, $adminId, $dataId);
        $ref = isset($payment['external_reference']) ? (string)$payment['external_reference'] : '';
        $st = $pdo->prepare("SELECT * FROM tc_orders WHERE payment_provider='mercadopago' AND seller_admin_id=:admin AND ref=:ref LIMIT 1");
        $st->execute(array(':admin' => $adminId, ':ref' => $ref));
        $order = $st->fetch(PDO::FETCH_ASSOC);
    }
    if (!$order || !$payment) throw new RuntimeException('No se encontro la orden Mercado Pago asociada.');
    $result = tickex_mp_confirm_payment($pdo, $order, $payment);
    if (!empty($result['confirmed'])) process_tc_order_by_request_id((string)$order['request_id']);
    $up = $pdo->prepare("UPDATE mercadopago_webhook_events SET order_id=:order,status='processed',updated_at=CURRENT_TIMESTAMP WHERE event_key=:key");
    $up->execute(array(':order' => (int)$order['id'], ':key' => $eventKey));
    log_order_event($pdo, (int)$order['id'], (string)$order['request_id'], 'mercadopago_webhook', $result);
    echo json_encode(array('ok' => true));
} catch (Exception $e) {
    $up = $pdo->prepare("UPDATE mercadopago_webhook_events SET status='error',error_text=:error,updated_at=CURRENT_TIMESTAMP WHERE event_key=:key");
    $up->execute(array(':error' => substr($e->getMessage(), 0, 1000), ':key' => $eventKey));
    error_log('[MercadoPago webhook] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(array('ok' => false));
}
