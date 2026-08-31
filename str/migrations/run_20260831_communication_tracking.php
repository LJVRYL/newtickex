<?php

$dbFile = '';
foreach ($argv as $arg) {
    if (strpos($arg, '--db=') === 0) $dbFile = substr($arg, 5);
}
if ($dbFile === '') {
    fwrite(STDERR, "Usage: php run_20260831_communication_tracking.php --db=/path/to/copy.sqlite\n");
    exit(1);
}
if (!file_exists($dbFile)) {
    fwrite(STDERR, 'Database not found: ' . $dbFile . PHP_EOL);
    exit(1);
}

require_once __DIR__ . '/../inc/communication_tracking.php';
$pdo = new PDO('sqlite:' . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA busy_timeout = 15000');
$pdo->beginTransaction();
try {
    communication_tracking_ensure_schema($pdo);
    communication_tracking_ensure_order_columns($pdo);
    $pdo->commit();
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, 'Migration failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
echo 'Migration applied to: ' . $dbFile . PHP_EOL;

