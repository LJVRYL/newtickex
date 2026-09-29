<?php
if (PHP_SAPI !== 'cli') die("CLI only\n");
$dbFile = tempnam(sys_get_temp_dir(), 'tickex-manual-');
if ($dbFile === false) die("Could not create temporary SQLite fixture\n");
register_shutdown_function(function () use ($dbFile) { if (is_file($dbFile)) unlink($dbFile); });
putenv('TICKEX_DB_FILE=' . $dbFile);
$fixture = new PDO('sqlite:' . $dbFile);
$fixture->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$fixture->exec("CREATE TABLE tc_orders (id INTEGER PRIMARY KEY AUTOINCREMENT, request_id TEXT UNIQUE, state TEXT, evento_id INTEGER, ref TEXT, concept TEXT, amount REAL, buyer_first TEXT, buyer_last TEXT, buyer_email TEXT, selected_tickets_json TEXT, created_at TEXT, updated_at TEXT, processed_at TEXT, payment_status TEXT NOT NULL DEFAULT 'pending', payment_confirmed_at TEXT, processing_status TEXT NOT NULL DEFAULT 'pending', processing_started_at TEXT, email_status TEXT NOT NULL DEFAULT 'pending', email_attempts INTEGER NOT NULL DEFAULT 0, email_sent_at TEXT, email_last_error TEXT, payment_provider TEXT)");
$fixture->exec("CREATE TABLE entradas (id INTEGER PRIMARY KEY AUTOINCREMENT, evento_id INTEGER, nombre TEXT, email TEXT, fecha_registro TEXT, codigo TEXT, checked_in INTEGER, checked_in_at TEXT, tipo TEXT, monto_pagado REAL, tc_order_request_id TEXT, issuance_key TEXT UNIQUE)");
$fixture->exec("CREATE TABLE eventos (id INTEGER PRIMARY KEY, nombre TEXT, slug TEXT, creado_por_admin_id INTEGER, creado_en TEXT, capacidad_total INTEGER)");
$fixture->exec("CREATE TABLE tipos_entrada (id INTEGER PRIMARY KEY, evento_id INTEGER, nombre TEXT, tipo TEXT, precio REAL, cantidad_total INTEGER, cantidad_disponible INTEGER, qr_quantity INTEGER NOT NULL DEFAULT 1)");
$fixture->exec("CREATE TABLE order_events (id INTEGER PRIMARY KEY AUTOINCREMENT, tc_order_id INTEGER, request_id TEXT, event_type TEXT, payload_json TEXT, created_at TEXT)");
$fixture->exec("CREATE TABLE entrada_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, entrada_id INTEGER UNIQUE, token TEXT UNIQUE, created_at TEXT)");
$fixture->exec("CREATE TABLE email_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, created_at TEXT NOT NULL DEFAULT (datetime('now')), resend_of_id INTEGER, trace_id TEXT, context TEXT, related_table TEXT, related_id INTEGER, to_email TEXT NOT NULL, from_email TEXT, from_name TEXT, reply_to TEXT, subject TEXT, body TEXT, headers TEXT, extra_params TEXT, mail_ok INTEGER NOT NULL DEFAULT 0, error_text TEXT)");
$fixture->exec("CREATE TABLE email_templates (id INTEGER PRIMARY KEY AUTOINCREMENT, context TEXT UNIQUE, enabled INTEGER, subject TEXT, body TEXT)");
$fixture->exec("INSERT INTO email_templates (context,enabled,subject,body) VALUES ('entradas_compra',1,'Tus entradas','{{entradas}}')");
unset($fixture);

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
$singleTypeId = 990002;
$pdo->exec('DELETE FROM entradas WHERE evento_id = ' . $eventId);
$pdo->exec('DELETE FROM tipos_entrada WHERE id = ' . $singleTypeId);
$pdo->exec('DELETE FROM tipos_entrada WHERE id = ' . $typeId);
$pdo->exec('DELETE FROM eventos WHERE id = ' . $eventId);
$pdo->exec("INSERT INTO eventos (id, nombre, slug, creado_por_admin_id, creado_en) VALUES ($eventId, 'Evento manual test', 'evento-manual-test', 77, CURRENT_TIMESTAMP)");
$pdo->exec("INSERT INTO tipos_entrada (id, evento_id, nombre, tipo, precio, cantidad_total, cantidad_disponible, qr_quantity) VALUES ($typeId, $eventId, 'Promo 3x4', 'paga', 30000, 20, 20, 4)");
$pdo->exec("INSERT INTO tipos_entrada (id, evento_id, nombre, tipo, precio, cantidad_total, cantidad_disponible, qr_quantity) VALUES ($singleTypeId, $eventId, 'Entrada general', 'paga', 15000, 10, 10, 1)");

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
    'request_id' => 'mobile-manual-1234567890abcdef1234567890abcdef',
));
manual_test_ok((int)$courtesy['issued_quantity'] === 4, 'one 3x4 courtesy issues four independent QR entries');
manual_test_ok(abs((float)$pdo->query("SELECT SUM(monto_pagado) FROM entradas WHERE tc_order_request_id='" . $courtesy['request_id'] . "'")->fetchColumn()) < 0.001, 'courtesy records no revenue');
manual_test_ok((int)$pdo->query('SELECT cantidad_disponible FROM tipos_entrada WHERE id=' . $typeId)->fetchColumn() === 12, 'courtesy still decrements four stock units');
manual_test_ok((int)$pdo->query("SELECT COUNT(*) FROM email_logs WHERE related_table='tc_orders' AND related_id=" . (int)$courtesy['order_id'] . " AND context='tickex_cortesia' AND mail_ok=1")->fetchColumn() === 1, 'courtesy uses its own consolidated email context');
$repeat = tickex_manual_issue_package($pdo, array('evento_id' => $eventId, 'tipo_id' => $typeId, 'cantidad' => 1, 'modo' => 'courtesy', 'email' => 'cortesia@example.invalid', 'nombre' => 'Cortesia Manual', 'admin_id' => 77, 'restrict_to_admin' => true, 'request_id' => 'mobile-manual-1234567890abcdef1234567890abcdef'));
manual_test_ok((int)$repeat['order_id'] === (int)$courtesy['order_id'], 'retry returns the same order');
manual_test_ok((int)$pdo->query('SELECT cantidad_disponible FROM tipos_entrada WHERE id=' . $typeId)->fetchColumn() === 12, 'retry does not consume stock twice');
manual_test_ok((int)$pdo->query("SELECT COUNT(*) FROM email_logs WHERE related_table='tc_orders' AND related_id=" . (int)$courtesy['order_id'] . " AND context='tickex_cortesia' AND mail_ok=1")->fetchColumn() === 1, 'retry does not send a second email');

$customSale = tickex_manual_issue_package($pdo, array(
    'evento_id' => $eventId,
    'tipo_id' => $singleTypeId,
    'cantidad' => 2,
    'modo' => 'manual_transfer',
    'monto_total' => '20000',
    'email' => 'monto-libre@example.invalid',
    'nombre' => 'Monto Libre',
    'admin_id' => 77,
    'restrict_to_admin' => true,
));
manual_test_ok((int)$customSale['issued_quantity'] === 2, 'custom total sale issues the requested two QR entries');
manual_test_ok(abs((float)$customSale['total'] - 20000.0) < 0.001, 'custom total returns the exact collected amount');
manual_test_ok(abs((float)$pdo->query("SELECT SUM(monto_pagado) FROM entradas WHERE tc_order_request_id='" . $customSale['request_id'] . "'")->fetchColumn() - 20000.0) < 0.001, 'custom total is recorded exactly once across both entries');
manual_test_ok(abs((float)$pdo->query('SELECT amount FROM tc_orders WHERE id=' . (int)$customSale['order_id'])->fetchColumn() - 20000.0) < 0.001, 'manual order stores the custom total');
manual_test_ok((int)$pdo->query('SELECT cantidad_disponible FROM tipos_entrada WHERE id=' . $singleTypeId)->fetchColumn() === 8, 'custom total sale decrements exactly two stock units');

$stockBeforeUncategorized = (int)$pdo->query('SELECT SUM(cantidad_disponible) FROM tipos_entrada WHERE evento_id=' . $eventId)->fetchColumn();
$uncategorized = tickex_manual_issue_package($pdo, array(
    'evento_id' => $eventId,
    'tipo_id' => 0,
    'sin_tipo' => true,
    'cantidad' => 2,
    'modo' => 'manual_transfer',
    'monto_total' => '20000',
    'email' => 'sin-categoria@example.invalid',
    'nombre' => 'Venta Sin Categoria',
    'admin_id' => 77,
    'restrict_to_admin' => true,
));
manual_test_ok((int)$uncategorized['issued_quantity'] === 2, 'uncategorized manual sale issues one QR per requested entry');
manual_test_ok(abs((float)$uncategorized['total'] - 20000.0) < 0.001, 'uncategorized manual sale records its exact free amount');
manual_test_ok((int)$pdo->query("SELECT COUNT(*) FROM entradas WHERE tc_order_request_id='" . $uncategorized['request_id'] . "' AND tipo='Entrada manual'")->fetchColumn() === 2, 'uncategorized QR entries use the manual label');
manual_test_ok((int)$pdo->query('SELECT SUM(cantidad_disponible) FROM tipos_entrada WHERE evento_id=' . $eventId)->fetchColumn() === $stockBeforeUncategorized, 'uncategorized sale does not alter category stock');
$issuedNow = tickex_event_capacity_issued($pdo, $eventId);
tickex_event_capacity_set($pdo, $eventId, $issuedNow + 1);
$globalBlocked = false;
try {
    tickex_manual_issue_package($pdo, array(
        'evento_id'=>$eventId, 'tipo_id'=>0, 'sin_tipo'=>true, 'cantidad'=>2,
        'modo'=>'manual_transfer', 'monto_total'=>'20000', 'email'=>'blocked@example.invalid',
        'admin_id'=>77, 'restrict_to_admin'=>true,
    ));
} catch (RuntimeException $e) { $globalBlocked = strpos($e->getMessage(), 'Cupo global insuficiente') !== false; }
manual_test_ok($globalBlocked, 'uncategorized sale cannot exceed the event global capacity');

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
