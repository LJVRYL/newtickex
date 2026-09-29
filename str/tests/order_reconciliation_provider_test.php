<?php

require_once __DIR__ . '/../inc/order_reconciliation.php';

function reconciliation_provider_assert($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "PASS: {$message}\n";
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE tc_orders (id INTEGER PRIMARY KEY, payment_status TEXT, payment_provider TEXT, ref TEXT, created_at TEXT)");
$pdo->exec("INSERT INTO tc_orders VALUES
    (1, 'pending', 'totalcoin', 'tc-current', datetime('now')),
    (2, 'pending', 'mercadopago', 'mp-current', datetime('now')),
    (3, 'pending', NULL, 'legacy-current', datetime('now')),
    (4, 'pending', '', 'legacy-empty', datetime('now')),
    (5, 'confirmed', 'totalcoin', 'tc-confirmed', datetime('now')),
    (6, 'pending', 'totalcoin', 'tc-old', datetime('now', '-8 days'))");

$sql = 'SELECT ref FROM tc_orders WHERE ' . tickex_totalcoin_pending_status_where() . ' ORDER BY id';
$refs = $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN);

reconciliation_provider_assert(
    $refs === array('tc-current', 'legacy-current', 'legacy-empty'),
    'TotalCoin reconciliation excludes Mercado Pago and retains legacy TotalCoin orders'
);

echo "ALL ORDER RECONCILIATION PROVIDER TESTS PASSED\n";

