<?php
// Apply the 20260825 TotalCoin migration to an explicitly supplied SQLite file.
// Usage: php run_20260825_totalcoin_payment_flow.php --db=/path/to/copy.sqlite

function migration_arg_db($argv)
{
    foreach ($argv as $arg) {
        if (strpos($arg, '--db=') === 0) return substr($arg, 5);
    }
    return '';
}

$dbFile = migration_arg_db($argv);
if ($dbFile === '' || !is_file($dbFile)) {
    fwrite(STDERR, "Usage: php run_20260825_totalcoin_payment_flow.php --db=/path/to/copy.sqlite\n");
    exit(2);
}

$pdo = new PDO('sqlite:' . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$sqlFile = __DIR__ . '/20260825_totalcoin_payment_flow.sql';
$pdo->exec(file_get_contents($sqlFile));

function migration_columns($pdo, $table)
{
    $cols = array();
    $st = $pdo->query('PRAGMA table_info(' . $table . ')');
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (isset($row['name'])) $cols[(string)$row['name']] = true;
    }
    return $cols;
}

$tcCols = migration_columns($pdo, 'tc_orders');
$tcAdds = array(
    'payment_status' => "ALTER TABLE tc_orders ADD COLUMN payment_status TEXT NOT NULL DEFAULT 'pending'",
    'payment_confirmed_at' => 'ALTER TABLE tc_orders ADD COLUMN payment_confirmed_at TEXT',
    'processing_status' => "ALTER TABLE tc_orders ADD COLUMN processing_status TEXT NOT NULL DEFAULT 'pending'",
    'processing_started_at' => 'ALTER TABLE tc_orders ADD COLUMN processing_started_at TEXT',
    'email_status' => "ALTER TABLE tc_orders ADD COLUMN email_status TEXT NOT NULL DEFAULT 'pending'",
    'email_attempts' => 'ALTER TABLE tc_orders ADD COLUMN email_attempts INTEGER NOT NULL DEFAULT 0',
    'email_sent_at' => 'ALTER TABLE tc_orders ADD COLUMN email_sent_at TEXT',
    'email_last_error' => 'ALTER TABLE tc_orders ADD COLUMN email_last_error TEXT',
);
foreach ($tcAdds as $column => $statement) {
    if (!isset($tcCols[$column])) $pdo->exec($statement);
}

$entryCols = migration_columns($pdo, 'entradas');
if (!isset($entryCols['issuance_key'])) {
    $pdo->exec('ALTER TABLE entradas ADD COLUMN issuance_key TEXT');
}
$pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_entradas_issuance_key ON entradas(issuance_key)');
$pdo->exec('CREATE INDEX IF NOT EXISTS idx_tc_orders_payment_status ON tc_orders(payment_status, processing_status)');

// Normalize rows created before this migration without changing financial state.
$pdo->exec("UPDATE tc_orders SET payment_status = 'pending' WHERE payment_status IS NULL OR payment_status = '' OR payment_status <> 'confirmed'");
$pdo->exec("UPDATE tc_orders SET processing_status = CASE WHEN processed_at IS NOT NULL THEN 'issued' ELSE 'pending' END WHERE processing_status IS NULL OR processing_status = ''");
$pdo->exec("UPDATE tc_orders SET email_status = CASE WHEN processed_at IS NOT NULL THEN 'pending' ELSE email_status END WHERE email_status IS NULL OR email_status = ''");

echo "Migration applied to: " . $dbFile . "\n";
