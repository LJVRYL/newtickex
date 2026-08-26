<?php
// Webhook local compatible con POST /notification/tcwbh.
// La migracion 20260825_totalcoin_payment_flow debe aplicarse antes.
require_once __DIR__ . '/inc/bootstrap.php';

function tc_webhook_pick($data, $keys)
{
    foreach ($keys as $key) {
        if (isset($data[$key]) && trim((string)$data[$key]) !== '') {
            return trim((string)$data[$key]);
        }
    }
    return '';
}

function tc_webhook_json_response($status, $body)
{
    http_response_code((int)$status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    tc_webhook_json_response(405, array('ok' => false, 'error' => 'method_not_allowed'));
}

$expectedKey = getenv('TOTALCOIN_WEBHOOK_KEY');
if (!is_string($expectedKey) || trim($expectedKey) === '') {
    $secretFile = dirname(__DIR__) . '/.secrets/totalcoin_webhook_key';
    if (is_readable($secretFile)) {
        $expectedKey = trim((string)file_get_contents($secretFile));
    }
}
if (!is_string($expectedKey) || trim($expectedKey) === '') {
    tc_webhook_json_response(503, array('ok' => false, 'error' => 'webhook_not_configured'));
}

// SenForms uses ApiKey. HTTP_API_KEY remains only as a temporary compatibility alias.
$providedKey = isset($_SERVER['HTTP_APIKEY']) ? (string)$_SERVER['HTTP_APIKEY'] : '';
if ($providedKey === '' && isset($_SERVER['HTTP_API_KEY'])) {
    $providedKey = (string)$_SERVER['HTTP_API_KEY'];
}
$keyOk = function_exists('hash_equals')
    ? hash_equals((string)$expectedKey, $providedKey)
    : ((strlen((string)$expectedKey) === strlen($providedKey)) && ($providedKey !== '') && !strcmp((string)$expectedKey, $providedKey));
if (!$keyOk) {
    tc_webhook_json_response(401, array('ok' => false, 'error' => 'unauthorized'));
}

$maxBodyBytes = 262144;
if (isset($_SERVER['CONTENT_LENGTH']) && (int)$_SERVER['CONTENT_LENGTH'] > $maxBodyBytes) {
    tc_webhook_json_response(413, array('ok' => false, 'error' => 'payload_too_large'));
}
$raw = file_get_contents('php://input');
if (!is_string($raw) || strlen($raw) > $maxBodyBytes) {
    tc_webhook_json_response(413, array('ok' => false, 'error' => 'payload_too_large'));
}

$data = array();
$contentType = isset($_SERVER['CONTENT_TYPE']) ? strtolower((string)$_SERVER['CONTENT_TYPE']) : '';
if (strpos($contentType, 'application/json') !== false && trim($raw) !== '') {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) $data = $decoded;
} elseif (is_array($_POST) && !empty($_POST)) {
    $data = $_POST;
} elseif (trim($raw) !== '') {
    parse_str($raw, $data);
}
if (!is_array($data) || empty($data)) {
    tc_webhook_json_response(400, array('ok' => false, 'error' => 'invalid_payload'));
}

$concepto = tc_webhook_pick($data, array('Concepto', 'concepto'));
$referencia = tc_webhook_pick($data, array('Referencia', 'referencia'));
$estado = strtoupper(tc_webhook_pick($data, array('Estado', 'estado')));
$amountRaw = tc_webhook_pick($data, array('Monto', 'monto'));
$amount = ($amountRaw !== '' && is_numeric(str_replace(',', '.', $amountRaw))) ? (float)str_replace(',', '.', $amountRaw) : null;
$fechaCreacion = tc_webhook_pick($data, array('FechaCreacion', 'fechaCreacion', 'fecha_creacion'));
$fechaConfirmacion = tc_webhook_pick($data, array('FechaConfirmacion', 'fechaConfirmacion', 'fecha_confirmacion'));
$metodoPago = tc_webhook_pick($data, array('MetodoPago', 'metodoPago', 'metodo_pago'));

if ($concepto === '' || $estado === '') {
    tc_webhook_json_response(400, array('ok' => false, 'error' => 'missing_required_fields'));
}

// No stable TotalCoin notification ID was available locally; hash immutable fields.
$idempotencyData = array(
    'concepto' => $concepto,
    'referencia' => $referencia,
    'amount' => $amount,
    'fecha_creacion' => $fechaCreacion,
    'fecha_confirmacion' => $fechaConfirmacion,
    'metodo_pago' => $metodoPago,
);
$idempotencyKey = hash('sha256', json_encode($idempotencyData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$payloadHash = hash('sha256', (string)$raw);
$storedPayload = json_encode(array(
    'Concepto' => $concepto,
    'Referencia' => $referencia,
    'Monto' => $amount,
    'Estado' => $estado,
    'FechaCreacion' => $fechaCreacion,
    'FechaConfirmacion' => $fechaConfirmacion,
    'MetodoPago' => $metodoPago,
), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

try {
    $pdo = db();
    $stExisting = $pdo->prepare('SELECT id, result, tc_order_id FROM payment_notifications WHERE idempotency_key = :key LIMIT 1');
    $stExisting->execute(array(':key' => $idempotencyKey));
    $existing = $stExisting->fetch(PDO::FETCH_ASSOC);
    if ($existing && (string)$existing['result'] === 'processed') {
        tc_webhook_json_response(200, array('ok' => true, 'duplicate' => true, 'result' => 'processed'));
    }
    if ($existing && in_array((string)$existing['result'], array('ignored', 'order_not_found', 'ambiguous_concept', 'amount_mismatch'), true)) {
        tc_webhook_json_response(200, array('ok' => true, 'duplicate' => true, 'result' => (string)$existing['result']));
    }
    if ($existing && (string)$existing['result'] === 'confirmed') {
        $stRetry = $pdo->prepare('SELECT request_id FROM tc_orders WHERE id = :id LIMIT 1');
        $stRetry->execute(array(':id' => (int)$existing['tc_order_id']));
        $retryRequestId = (string)$stRetry->fetchColumn();
        if ($retryRequestId !== '') {
            require_once __DIR__ . '/inc/order_processing.php';
            $processing = process_tc_order_by_request_id($retryRequestId);
            if (!empty($processing['processed']) || strpos((string)$processing['debugMsg'], 'ya procesada') !== false) {
                tc_webhook_json_response(200, array('ok' => true, 'duplicate' => true, 'result' => 'confirmed'));
            }
        }
        tc_webhook_json_response(500, array('ok' => false, 'error' => 'order_processing_failed'));
    }

    $orderId = null;
    $order = null;
    $lookupResult = 'ignored';
    $lookupError = null;
    if ($estado === 'APROBADO') {
        $stOrder = $pdo->prepare('SELECT id, request_id, ref, amount, payment_status FROM tc_orders WHERE ref = :ref LIMIT 2');
        $stOrder->execute(array(':ref' => $concepto));
        $matches = $stOrder->fetchAll(PDO::FETCH_ASSOC);
        if (count($matches) !== 1) {
            $lookupError = count($matches) === 0 ? 'order_not_found' : 'ambiguous_concept';
        } else {
            $order = $matches[0];
            $orderId = (int)$order['id'];
            if ($amount === null || abs((float)$order['amount'] - $amount) > 0.01) {
                $lookupError = 'amount_mismatch';
            } else {
                $stConfirm = $pdo->prepare("UPDATE tc_orders SET payment_status = 'confirmed', payment_confirmed_at = CURRENT_TIMESTAMP, state = 'success', updated_at = CURRENT_TIMESTAMP WHERE id = :id AND (payment_status IS NULL OR payment_status IN ('pending','created'))");
                $stConfirm->execute(array(':id' => $orderId));
                $lookupResult = 'confirmed';
            }
        }
    }

    if ($lookupError !== null) $lookupResult = $lookupError;
    $stInsert = $pdo->prepare('INSERT INTO payment_notifications (idempotency_key, concepto, referencia, estado, amount, tc_order_id, payload_hash, payload_json, received_at, processed_at, result, error) VALUES (:key, :concepto, :referencia, :estado, :amount, :order_id, :payload_hash, :payload_json, CURRENT_TIMESTAMP, :processed_at, :result, :error)');
    try {
        $stInsert->execute(array(
            ':key' => $idempotencyKey,
            ':concepto' => $concepto,
            ':referencia' => $referencia,
            ':estado' => $estado,
            ':amount' => $amount,
            ':order_id' => $orderId,
            ':payload_hash' => $payloadHash,
            ':payload_json' => $storedPayload,
            ':processed_at' => null,
            ':result' => $lookupResult,
            ':error' => $lookupError,
        ));
    } catch (PDOException $e) {
        if (strpos(strtolower($e->getMessage()), 'unique') !== false) {
            tc_webhook_json_response(200, array('ok' => true, 'duplicate' => true, 'result' => $lookupResult));
        }
        throw $e;
    }

    if ($lookupResult === 'confirmed' && $order) {
        require_once __DIR__ . '/inc/order_processing.php';
        $processing = process_tc_order_by_request_id((string)$order['request_id']);
        if (empty($processing['processed']) && strpos((string)$processing['debugMsg'], 'ya procesada') === false) {
            tc_webhook_json_response(500, array('ok' => false, 'error' => 'order_processing_failed'));
        }
        $stDone = $pdo->prepare("UPDATE payment_notifications SET processed_at = CURRENT_TIMESTAMP, result = 'processed', error = NULL WHERE idempotency_key = :key");
        $stDone->execute(array(':key' => $idempotencyKey));
    }

    tc_webhook_json_response(200, array('ok' => true, 'result' => $lookupResult));
} catch (Exception $e) {
    error_log('[TotalCoin webhook] ' . $e->getMessage());
    tc_webhook_json_response(500, array('ok' => false, 'error' => 'webhook_processing_failed'));
}
