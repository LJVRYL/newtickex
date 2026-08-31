<?php
if (PHP_SAPI !== 'cli') die("CLI only\n");

$dbFile = '';
$apply = false;
foreach ($argv as $arg) {
    if (strpos($arg, '--db=') === 0) $dbFile = substr($arg, 5);
    if ($arg === '--apply') $apply = true;
}
if ($dbFile === '' || !is_file($dbFile)) {
    fwrite(STDERR, "Usage: php run_20260831_event15_ticket_package_repair.php --db=/path/to/database.sqlite [--apply]\n");
    exit(2);
}

$pdo = new PDO('sqlite:' . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA busy_timeout=15000');
require_once __DIR__ . '/../inc/legacy_package_repair.php';

$result = tickex_repair_event15_ticket_packages($pdo, $apply);
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
if (!$apply && empty($result['already_applied'])) echo "Vista previa solamente. Agregá --apply para modificar la copia indicada.\n";
