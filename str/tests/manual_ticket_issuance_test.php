<?php
if (PHP_SAPI !== 'cli') die("CLI only\n");
$dbFile = getenv('TICKEX_DB_FILE');
if (!is_string($dbFile) || $dbFile === '' || !is_file($dbFile)) die("TICKEX_DB_FILE must point to a temporary SQLite copy\n");

function manual_test_ok($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, 'FAIL: ' . $message . "\n");
        exit(1);
    }
    echo 'PASS: ' . $message . "\n";
}

putenv('TICKEX_MAIL_TRANSPORT=fake');
putenv('TICKEX_SITE_URL=https://local.test');
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/manual_ticket_issuance.php';
$pdo = db();

$eventId = 990001;
$typeId = 990001;
$pdo->exec('DELETE FROM entradas WHERE evento_id = ' . $eventId);
$pdo->exec('DELETE FROM tipos_entrada WHERE id = ' . $typeId);
$pdo->exec('DELETE FROM eventos WHERE id = ' . $eventId);
$pdo->exec("INSERT INTO eventos (id, nombre, slug, creado_por_admin_id, creado_en) VALUES ($eventId, 'Evento manual test', 'evento-manual-test', 77, CURRENT_TIMESTAMP)");
$pdo->exec("INSERT INTO tipos_entrada (id, evento_id, nombre, tipo, precio, cantidad_total, cantidad_disponible, qr_quantity) VALUES ($typeId, $eventId, 'Promo 3x4', 'paga', 30000, 20, 20, 4)");

$sale = tickex_manual_issue_package($pdo, array(
    'evento_id' => $eventId,
    'tipo_id' => $typeId,
    'cantidad' => 1,
    'modo' => 'manual_transfer',
    'email' => 'venta@example.invalid',
    'nombre' => 'Venta Manual',
    'admin_id' => 77,
    'restrict_to_admin' => true,
));
manual_test_ok((int)$sale['issued_quantity'] === 4, 'one 3x4 manual sale issues four independent QR entries');
manual_test_ok(abs((float)$pdo->query("SELECT SUM(monto_pagado) FROM entradas WHERE tc_order_request_id='" . $sale['request_id'] . "'")->fetchColumn() - 30000.0) < 0.001, 'manual transfer records the package price only once');
manual_test_ok((int)$pdo->query('SELECT cantidad_disponible FROM tipos_entrada WHERE id=' . $typeId)->fetchColumn() === 16, 'manual transfer decrements four stock units');
manual_test_ok((int)$pdo->query("SELECT COUNT(DISTINCT codigo) FROM entradas WHERE tc_order_request_id='" . $sale['request_id'] . "'")->fetchColumn() === 4, 'manual transfer creates four distinct codes');
manual_test_ok((int)$pdo->query("SELECT COUNT(*) FROM email_logs WHERE related_table='tc_orders' AND related_id=" . (int)$sale['order_id'] . " AND mail_ok=1")->fetchColumn() === 1, 'all manual-sale QR links are sent in one email');

$courtesy = tickex_manual_issue_package($pdo, array(
    'evento_id' => $eventId,
    'tipo_id' => $typeId,
    'cantidad' => 1,
    'modo' => 'courtesy',
    'email' => 'cortesia@example.invalid',
    'nombre' => 'Cortesia Manual',
    'admin_id' => 77,
    'restrict_to_admin' => true,
));
manual_test_ok((int)$courtesy['issued_quantity'] === 4, 'one 3x4 courtesy issues four independent QR entries');
manual_test_ok(abs((float)$pdo->query("SELECT SUM(monto_pagado) FROM entradas WHERE tc_order_request_id='" . $courtesy['request_id'] . "'")->fetchColumn()) < 0.001, 'courtesy records no revenue');
manual_test_ok((int)$pdo->query('SELECT cantidad_disponible FROM tipos_entrada WHERE id=' . $typeId)->fetchColumn() === 12, 'courtesy still decrements four stock units');
manual_test_ok((int)$pdo->query("SELECT COUNT(*) FROM email_logs WHERE related_table='tc_orders' AND related_id=" . (int)$courtesy['order_id'] . " AND context='tickex_cortesia' AND mail_ok=1")->fetchColumn() === 1, 'courtesy uses its own consolidated email context');

$denied = false;
try {
    tickex_manual_issue_package($pdo, array(
        'evento_id' => $eventId,
        'tipo_id' => $typeId,
        'modo' => 'manual_transfer',
        'email' => 'otro@example.invalid',
        'admin_id' => 88,
        'restrict_to_admin' => true,
    ));
} catch (Exception $e) {
    $denied = true;
}
manual_test_ok($denied, 'another administrator cannot issue tickets for this event');
manual_test_ok((string)$pdo->query('PRAGMA integrity_check')->fetchColumn() === 'ok', 'database remains consistent');

echo "ALL MANUAL TICKET ISSUANCE TESTS PASSED\n";
