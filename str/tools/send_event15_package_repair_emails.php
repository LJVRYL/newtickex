<?php
if (PHP_SAPI !== 'cli') die("CLI only\n");
$dbFile = '';
foreach ($argv as $arg) if (strpos($arg, '--db=') === 0) $dbFile = substr($arg, 5);
if ($dbFile === '' || !is_file($dbFile)) {
    fwrite(STDERR, "Usage: php send_event15_package_repair_emails.php --db=/path/to/database.sqlite\n");
    exit(2);
}
putenv('TICKEX_DB_FILE=' . $dbFile);
putenv('TICKEX_SITE_URL=https://str.tickex.com.ar');
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/legacy_package_repair.php';
$pdo = db();
$marker = $pdo->query("SELECT COUNT(*) FROM maintenance_migrations WHERE name='20260831_event15_ticket_packages'")->fetchColumn();
if ((int)$marker !== 1) throw new RuntimeException('La reparación de paquetes todavía no fue aplicada.');
echo json_encode(tickex_send_event15_package_repair_emails($pdo), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
