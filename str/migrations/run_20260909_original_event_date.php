<?php

$dbFile = '';
foreach ($argv as $arg) {
    if (strpos($arg, '--db=') === 0) {
        $dbFile = substr($arg, 5);
    }
}

if ($dbFile === '' || !is_file($dbFile)) {
    fwrite(STDERR, "Usage: php run_20260909_original_event_date.php --db=/path/to/database.sqlite\n");
    exit(1);
}

require_once __DIR__ . '/../inc/historical_event_repair.php';

$pdo = new PDO('sqlite:' . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA busy_timeout = 15000');
$pdo->beginTransaction();

try {
    $changed = tickex_repair_original_event_date($pdo);
    $pdo->commit();
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Migration failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

echo ($changed ? 'Original event dated' : 'Original event already dated or not found') . ': ' . $dbFile . PHP_EOL;

