<?php
// Reconciliador de órdenes pendientes de TotalCoin.
// Consulta el estado oficial por ER y luego procesa emisión/email idempotente.

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/order_processing.php';
require_once __DIR__ . '/../inc/totalcoin_confirmation.php';

$pdo = db();

try {
    $colsTcOrders = $pdo->query("PRAGMA table_info(tc_orders)")->fetchAll(PDO::FETCH_ASSOC);
    $hasProcessedAt = false;
    foreach ($colsTcOrders as $c) {
        if (isset($c['name']) && $c['name'] === 'processed_at') {
            $hasProcessedAt = true;
            break;
        }
    }
    if (!$hasProcessedAt) {
        $pdo->exec("ALTER TABLE tc_orders ADD COLUMN processed_at TEXT");
    }
} catch (Exception $e) {
    fwrite(STDERR, "Schema warning (tc_orders.processed_at): " . $e->getMessage() . "\n");
}

$eventoId = isset($argv[1]) ? (int)$argv[1] : 0;
$statusWhere = "payment_status = 'pending' AND ref IS NOT NULL AND ref <> '' AND created_at >= datetime('now', '-7 days')";
$statusParams = array();
if ($eventoId > 0) {
    $statusWhere .= ' AND evento_id = :status_eid';
    $statusParams[':status_eid'] = $eventoId;
}
$stPending = $pdo->prepare("SELECT * FROM tc_orders WHERE $statusWhere ORDER BY created_at ASC LIMIT 50");
$stPending->execute($statusParams);
foreach ($stPending->fetchAll(PDO::FETCH_ASSOC) as $pendingOrder) {
    try {
        $confirmation = tickex_totalcoin_confirm_from_status($pdo, $pendingOrder);
        echo sprintf("[status] request_id=%s ref=%s result=%s\n", (string)$pendingOrder['request_id'], (string)$pendingOrder['ref'], (string)$confirmation['result']);
    } catch (Exception $e) {
        fwrite(STDERR, sprintf("[status] request_id=%s error=%s\n", (string)$pendingOrder['request_id'], $e->getMessage()));
    }
}

$where = "payment_status = 'confirmed' AND (processed_at IS NULL OR email_status = 'pending')";
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
