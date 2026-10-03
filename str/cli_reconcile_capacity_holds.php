<?php
// Run from the server scheduler. The Ferozo PHP 5.6 binary reports CGI SAPI
// even with -q, so reject HTTP by request metadata rather than PHP_SAPI.
if (isset($_SERVER['REQUEST_METHOD']) || isset($_SERVER['REMOTE_ADDR'])) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/inc/checkout_capacity_reconciliation.php';

try {
    $configured = getenv('TICKEX_DB_FILE');
    $dbFile = is_string($configured) && trim($configured) !== '' ? trim($configured) : __DIR__ . '/save_the_rave.sqlite';
    if (!is_file($dbFile)) throw new RuntimeException('database_unavailable');
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA busy_timeout=15000');
    $result = tickex_capacity_holds_reconcile_due($pdo, 50);
    echo json_encode($result, JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Exception $error) {
    error_log('[Tickex capacity reconciliation] ' . $error->getMessage());
    fwrite(STDERR, "capacity_reconciliation_failed\n");
    exit(1);
}
