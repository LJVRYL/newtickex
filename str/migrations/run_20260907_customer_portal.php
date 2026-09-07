<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$dbFile = __DIR__ . '/../save_the_rave.sqlite';
foreach ($argv as $arg) {
    if (strpos($arg, '--db=') === 0) $dbFile = substr($arg, 5);
}
if (!is_file($dbFile)) { fwrite(STDERR, "Base de datos inexistente: " . $dbFile . PHP_EOL); exit(1); }

require_once __DIR__ . '/../inc/customer_portal.php';
$pdo = new PDO('sqlite:' . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA busy_timeout=15000');
tickex_customer_portal_ensure_schema($pdo);
$integrity = $pdo->query('PRAGMA integrity_check')->fetchColumn();
if ($integrity !== 'ok') { fwrite(STDERR, "Integridad SQLite: " . $integrity . PHP_EOL); exit(2); }
echo "Portal de compradores preparado en: " . $dbFile . PHP_EOL;
