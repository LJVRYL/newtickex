<?php
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/communication_execution_engine.php';

$pdo = db();
$max = 5;
if (isset($_GET['max'])) {
    $max = (int)$_GET['max'];
}
if ($max <= 0) $max = 5;

$workerId = isset($_GET['worker']) ? trim((string)$_GET['worker']) : '';
if ($workerId === '') {
    $workerId = 'web-worker-' . getmypid();
}

$result = communication_execution_process_queue($pdo, $max, $workerId);

if (!headers_sent()) {
    header('Content-Type: application/json; charset=UTF-8');
}

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
