<?php
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/communication_execution_engine.php';
require_once __DIR__ . '/../inc/communication_ops.php';

$isCli = (PHP_SAPI === 'cli');
if (!$isCli) {
    require_login();
    $cu = current_user();
    $tipoGlobal = isset($cu['tipo_global']) ? (string)$cu['tipo_global'] : (isset($_SESSION['tipo_global']) ? (string)$_SESSION['tipo_global'] : '');
    $isSuper = in_array($tipoGlobal, array('super_admin', 'superadmin'), true);
    $isAllowed = (is_admin() && ($isSuper || $tipoGlobal === 'admin_evento'));
    if (!$isAllowed) {
        http_response_code(403);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(array('ok' => false, 'error' => 'Acceso restringido.'));
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(array('ok' => false, 'error' => 'Metodo no permitido.'));
        exit;
    }
    $provided = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
    if (function_exists('tickex_csrf_verify') && !tickex_csrf_verify($provided)) {
        http_response_code(400);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(array('ok' => false, 'error' => 'CSRF invalido.'));
        exit;
    }
}

$pdo = db();
$input = $isCli ? array() : $_POST;
$max = 5;
if (isset($input['max'])) {
    $max = (int)$input['max'];
}
if ($max <= 0) $max = 5;
if ($max > 100) $max = 100;

$safeMaxCommands = getenv('TICKEX_CAMPAIGN_MAX_COMMANDS_PER_WORKER');
$safeMaxCommands = ($safeMaxCommands === false || trim((string)$safeMaxCommands) === '') ? 1 : (int)$safeMaxCommands;
if ($safeMaxCommands <= 0) $safeMaxCommands = 1;
if ($safeMaxCommands > 100) $safeMaxCommands = 100;
if ($max > $safeMaxCommands) $max = $safeMaxCommands;

$batchSize = 200;
if (isset($input['batch_size'])) {
    $batchSize = (int)$input['batch_size'];
} elseif (isset($input['batch'])) {
    $batchSize = (int)$input['batch'];
}
if ($batchSize <= 0) $batchSize = 200;
if ($batchSize > 500) $batchSize = 500;

$safeBatchSize = getenv('TICKEX_CAMPAIGN_MAX_BATCH_SIZE');
$safeBatchSize = ($safeBatchSize === false || trim((string)$safeBatchSize) === '') ? 3 : (int)$safeBatchSize;
if ($safeBatchSize <= 0) $safeBatchSize = 3;
if ($safeBatchSize > 500) $safeBatchSize = 500;
if ($batchSize > $safeBatchSize) $batchSize = $safeBatchSize;

$workerId = isset($input['worker']) ? trim((string)$input['worker']) : '';
if ($workerId === '') {
    $workerId = ($isCli ? 'cli-worker-' : 'web-worker-') . getmypid();
}

$result = communication_execution_process_queue($pdo, $max, $workerId, $batchSize);

if (function_exists('communication_ops_log')) {
    communication_ops_log($pdo, 1, 'worker', 'worker.http_invocation', 'info', 'Invocacion HTTP de worker completada.', array(
        'worker_id' => $workerId,
        'picked' => isset($result['picked']) ? (int)$result['picked'] : 0,
        'done' => isset($result['done']) ? (int)$result['done'] : 0,
        'failed' => isset($result['failed']) ? (int)$result['failed'] : 0,
        'cancelled' => isset($result['cancelled']) ? (int)$result['cancelled'] : 0,
    ), 'worker.http_invocation|' . (string)$workerId . '|' . gmdate('YmdHis'));
}

if (!headers_sent()) {
    header('Content-Type: application/json; charset=UTF-8');
}

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
