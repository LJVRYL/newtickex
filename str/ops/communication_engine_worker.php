<?php
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/communication_execution_engine.php';
require_once __DIR__ . '/../inc/communication_ops.php';

$pdo = db();
$max = 5;
if (isset($_GET['max'])) {
    $max = (int)$_GET['max'];
}
if ($max <= 0) $max = 5;

$batchSize = 200;
if (isset($_GET['batch_size'])) {
    $batchSize = (int)$_GET['batch_size'];
} elseif (isset($_GET['batch'])) {
    $batchSize = (int)$_GET['batch'];
}
if ($batchSize <= 0) $batchSize = 200;

$workerId = isset($_GET['worker']) ? trim((string)$_GET['worker']) : '';
if ($workerId === '') {
    $workerId = 'web-worker-' . getmypid();
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
