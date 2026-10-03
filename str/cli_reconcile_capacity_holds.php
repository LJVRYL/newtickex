<?php
// Run from the server scheduler. The Ferozo PHP 5.6 binary reports CGI SAPI
// even with -q, so reject HTTP by request metadata rather than PHP_SAPI.
if (isset($_SERVER['REQUEST_METHOD']) || isset($_SERVER['REMOTE_ADDR'])) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/checkout_capacity_reconciliation.php';

try {
    $result = tickex_capacity_holds_reconcile_due(db(), 50);
    echo json_encode($result, JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Exception $error) {
    error_log('[Tickex capacity reconciliation] ' . $error->getMessage());
    fwrite(STDERR, "capacity_reconciliation_failed\n");
    exit(1);
}
