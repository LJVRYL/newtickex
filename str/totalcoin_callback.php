<?php
require_once __DIR__.'/inc/bootstrap.php';
require_once __DIR__ . '/inc/order_processing.php';
require_once __DIR__ . '/inc/order_events.php';
$title = 'TotalCoin Callback';
$state = 'unknown';
$uuid  = '';
$updated = false;
$processed = false;
$debugMsg = '';
$logFile = __DIR__ . '/totalcoin_callback.log';
$traceId = date('YmdHis') . '-' . substr(sha1(uniqid('', true)), 0, 8);

$callbackLogFile = __DIR__ . '/../uploads/totalcoin_callback_request.log';
$rawBody = file_get_contents('php://input');
$headers = array();
if (function_exists('getallheaders')) {
  $headers = getallheaders();
} else {
  foreach ($_SERVER as $key => $value) {
    if (strpos($key, 'HTTP_') === 0 || $key === 'CONTENT_TYPE' || $key === 'CONTENT_LENGTH') {
      $name = str_replace('HTTP_', '', $key);
      $name = str_replace('_', '-', strtolower($name));
      $headers[$name] = $value;
    }
  }
}

if (!function_exists('tc_cb_pick')) {
  function tc_cb_pick($arr, $keys)
  {
    if (!is_array($arr)) return '';
    foreach ($keys as $k) {
      if (isset($arr[$k]) && trim((string)$arr[$k]) !== '') {
        return trim((string)$arr[$k]);
      }
    }
    return '';
  }
}

if (!function_exists('tc_cb_parse_raw')) {
  function tc_cb_parse_raw($raw)
  {
    $out = array();
    $raw = trim((string)$raw);
    if ($raw === '') return $out;

    $json = json_decode($raw, true);
    if (is_array($json)) {
      return $json;
    }

    $tmp = array();
    parse_str($raw, $tmp);
    if (is_array($tmp) && !empty($tmp)) {
      return $tmp;
    }
    return $out;
  }
}

if (!function_exists('tc_cb_trace')) {
  function tc_cb_trace($filePath, $traceId, $step, $data)
  {
    $row = array(
      'at' => date('Y-m-d H:i:s'),
      'trace_id' => (string)$traceId,
      'step' => (string)$step,
      'data' => is_array($data) ? $data : array('value' => $data),
    );
    @file_put_contents($filePath, json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
  }
}

$rawParsed = tc_cb_parse_raw($rawBody);
$uuid = tc_cb_pick($_GET, array('uuid', 'requestId', 'request_id', 'RequestId'));
if ($uuid === '') $uuid = tc_cb_pick($_POST, array('uuid', 'requestId', 'request_id', 'RequestId'));
if ($uuid === '') $uuid = tc_cb_pick($rawParsed, array('uuid', 'requestId', 'request_id', 'RequestId'));
if ($uuid === '') $uuid = tc_cb_pick($headers, array('x-request-id', 'x-requestid', 'x-uuid'));

$state = tc_cb_pick($_GET, array('state', 'status', 'paymentState', 'payment_state'));
if ($state === '') $state = tc_cb_pick($_POST, array('state', 'status', 'paymentState', 'payment_state'));
if ($state === '') $state = tc_cb_pick($rawParsed, array('state', 'status', 'paymentState', 'payment_state'));
if ($state === '') $state = 'unknown';

$logLines = array(
  str_repeat('-', 50),
  'TRACE_ID: ' . $traceId,
  date('Y-m-d H:i:s'),
  'REQUEST_METHOD: ' . (isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : ''),
  'REQUEST_URI: ' . (isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : ''),
  'QUERY_STRING: ' . (isset($_SERVER['QUERY_STRING']) ? $_SERVER['QUERY_STRING'] : ''),
  'GET: ' . json_encode($_GET, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
  'POST: ' . json_encode($_POST, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
  'RAW BODY: ' . $rawBody,
  'CONTENT_TYPE: ' . (isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : (isset($_SERVER['HTTP_CONTENT_TYPE']) ? $_SERVER['HTTP_CONTENT_TYPE'] : '')),
  'CONTENT_LENGTH: ' . (isset($_SERVER['CONTENT_LENGTH']) ? $_SERVER['CONTENT_LENGTH'] : ''),
  'HEADERS: ' . json_encode($headers, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
  'REMOTE_ADDR: ' . (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : ''),
  'HTTP_USER_AGENT: ' . (isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : ''),
  '',
);
@file_put_contents($callbackLogFile, implode("\n", $logLines) . "\n", FILE_APPEND | LOCK_EX);
tc_cb_trace($callbackLogFile, $traceId, '01_callback_invoked', array(
  'request_method' => isset($_SERVER['REQUEST_METHOD']) ? (string)$_SERVER['REQUEST_METHOD'] : '',
  'request_uri' => isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '',
));
tc_cb_trace($callbackLogFile, $traceId, '02_params_received', array(
  'uuid' => $uuid,
  'state' => $state,
  'get' => $_GET,
  'post' => $_POST,
  'raw_parsed' => $rawParsed,
));

// Persistir estado si tenemos requestId
$updated = false;
$processed = false;
$debugMsg = '';
if ($uuid !== '') {
  try {
    $pdo = db();
    $stOrder = $pdo->prepare("SELECT id, state, processed_at FROM tc_orders WHERE request_id = :rid LIMIT 1");
    $stOrder->execute(array(':rid' => $uuid));
    $tcOrder = $stOrder->fetch(PDO::FETCH_ASSOC);
    $tcOrderId = $tcOrder ? (int)$tcOrder['id'] : null;
    tc_cb_trace($callbackLogFile, $traceId, '03_order_lookup', array(
      'found' => $tcOrder ? true : false,
      'tc_order_id' => $tcOrderId,
      'previous_state' => $tcOrder ? (string)$tcOrder['state'] : null,
      'previous_processed_at' => $tcOrder ? (string)$tcOrder['processed_at'] : null,
    ));

    $st = $pdo->prepare("UPDATE tc_orders SET state = :st, updated_at = datetime('now') WHERE request_id = :rid");
    $st->execute(array(':st' => (string)$state, ':rid' => (string)$uuid));
    $updated = ($st->rowCount() > 0);
    tc_cb_trace($callbackLogFile, $traceId, '04_state_update', array(
      'requested_state' => $state,
      'updated' => $updated,
      'affected_rows' => $st->rowCount(),
      'tc_order_id' => $tcOrderId,
    ));

    if ($state !== 'success') {
      // Loguear todos los callbacks no-success para auditoría
      try {
        log_order_event($pdo, $tcOrderId, $uuid, 'callback_' . preg_replace('/[^a-z0-9_]/', '_', strtolower($state)), array(
          'state' => $state,
          'updated' => $updated,
        ));
      } catch (Exception $_e) {}
    }

    // Para callback success, intentar procesamiento siempre que la orden exista.
    // Esto evita perder procesamiento cuando rowCount()=0 por idempotencia.
    if ($tcOrderId !== null && $state === 'success') {
      $debugMsg .= "Estado actualizado. ";
      tc_cb_trace($callbackLogFile, $traceId, '05_process_call', array(
        'called' => true,
        'reason' => 'state_success_and_order_exists',
        'tc_order_id' => $tcOrderId,
        'updated' => $updated,
      ));
      $result = process_tc_order_by_request_id($uuid);
      $processed = !empty($result['processed']);
      $debugMsg .= $result['debugMsg'];
      tc_cb_trace($callbackLogFile, $traceId, '06_process_result', array(
        'processed' => $processed,
        'debugMsg' => isset($result['debugMsg']) ? (string)$result['debugMsg'] : '',
        'order_id' => isset($result['order_id']) ? (int)$result['order_id'] : null,
      ));
      try {
        log_order_event($pdo, $tcOrderId, $uuid, 'callback_received', array(
          'state' => $state,
          'updated' => $updated,
          'processed' => $processed,
          'debugMsg' => $result['debugMsg'],
        ));
      } catch (Exception $_e) {
        // No bloquear el callback por logging.
      }
      if ($processed) {
        file_put_contents($logFile, date('Y-m-d H:i:s') . " Procesamiento exitoso para UUID: $uuid\n", FILE_APPEND);
      }
    } else {
      tc_cb_trace($callbackLogFile, $traceId, '05_process_call', array(
        'called' => false,
        'reason' => ($state !== 'success') ? 'state_not_success' : 'order_not_found',
        'state' => $state,
        'tc_order_id' => $tcOrderId,
      ));
    }
  } catch (Exception $e) {
    $updated = false;
    $debugMsg .= "Error UPDATE: " . $e->getMessage() . ". ";
    tc_cb_trace($callbackLogFile, $traceId, 'callback_exception', array(
      'message' => $e->getMessage(),
    ));
    file_put_contents($logFile, date('Y-m-d H:i:s') . " Error UPDATE para UUID: $uuid - " . $e->getMessage() . "\n", FILE_APPEND);
  }
} else {
  tc_cb_trace($callbackLogFile, $traceId, '03_order_lookup', array(
    'found' => false,
    'reason' => 'missing_request_id',
  ));
}

include __DIR__.'/inc/layout_top.php';
?>
<div class="card">
  <h2 style="margin:0 0 8px;">Callback recibido</h2>
  <p style="color:var(--muted);margin:0 0 12px;">State: <strong><?php echo e($state); ?></strong> — UUID/RequestId: <strong><?php echo e($uuid); ?></strong></p>
  <?php if ($uuid !== ''): ?>
    <p style="margin:0;"><?php echo $updated ? 'Estado actualizado en el sistema.' : 'No se encontró una orden local para este RequestId.'; ?></p>
    <?php if ($processed): ?>
      <p style="margin:0;color:green;">✅ Entradas creadas exitosamente.</p>
    <?php endif; ?>
    <?php if ($debugMsg): ?>
      <p style="margin:8px 0 0 0;font-size:12px;color:var(--muted);font-family:monospace;">Debug: <?php echo e($debugMsg); ?></p>
    <?php endif; ?>
  <?php else: ?>
    <p style="margin:0;">Callback recibido sin RequestId.</p>
  <?php endif; ?>
</div>
<?php include __DIR__.'/inc/layout_bottom.php'; ?>
