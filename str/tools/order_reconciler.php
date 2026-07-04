<?php
// Reconciliador de órdenes pendientess de TotalCoin.
// Este script solo procesa órdenes que ya están en state='success' y no tienen processed_at.

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/order_processing.php';

$pdo = db();
$eventoId = isset($argv[1]) ? (int)$argv[1] : 0;
$where = "state = 'success' AND processed_at IS NULL";
$params = array();
if ($eventoId > 0) {
    $where .= ' AND evento_id = :eid';
    $params[':eid'] = $eventoId;
}

$st = $pdo->prepare("SELECT request_id FROM tc_orders WHERE $where ORDER BY created_at ASC");
$st->execute($params);
$orders = $st->fetchAll(PDO::FETCH_ASSOC);

$count = 0;
$processed = 0;
$skipped = 0;
$errors = 0;

foreach ($orders as $order) {
    $count++;
    $requestId = isset($order['request_id']) ? (string)$order['request_id'] : '';
    if ($requestId === '') {
        continue;
    }

    $result = process_tc_order_by_request_id($requestId);
    $message = trim($result['debugMsg']);
    $status = 'error';
    if (!empty($result['processed'])) {
        $status = 'processed';
        $processed++;
    } elseif (stripos($message, 'ya procesada') !== false || stripos($message, 'Orden ya completada') !== false) {
        $status = 'skipped';
        $skipped++;
    } else {
        $status = 'error';
        $errors++;
    }

    echo sprintf("[%d] request_id=%s status=%s order_id=%s msg=%s\n", $count, $requestId, $status, isset($result['order_id']) ? $result['order_id'] : 'n/a', $message);
}

echo "\nTotal orders checked: $count\n";
echo "Processed: $processed\n";
echo "Skipped: $skipped\n";
echo "Errors: $errors\n";
