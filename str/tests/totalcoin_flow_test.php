<?php
// Local-only TotalCoin flow test. The caller must provide TICKEX_DB_FILE.
// No network, production services, or real mail transport are used.
if (PHP_SAPI !== 'cli') die("CLI only\n");
$dbFile = getenv('TICKEX_DB_FILE');
if (!is_string($dbFile) || $dbFile === '' || !is_file($dbFile)) die("TICKEX_DB_FILE must point to a temporary SQLite copy\n");

function test_ok($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: " . $message . "\n");
        exit(1);
    }
    echo "PASS: " . $message . "\n";
}

putenv('TICKEX_MAIL_TRANSPORT=fake');
putenv('TOTALCOIN_CALLBACK_KEY=local-test-callback-key');
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/totalcoin_callback_auth.php';
require_once __DIR__ . '/../inc/totalcoin_confirmation.php';
$pdo = db();
$pdo->exec("CREATE TABLE IF NOT EXISTS tc_orders (id INTEGER PRIMARY KEY AUTOINCREMENT, request_id TEXT UNIQUE, state TEXT, evento_id INTEGER, ref TEXT, amount REAL, buyer_first TEXT, buyer_last TEXT, buyer_email TEXT, selected_tickets_json TEXT, processed_at TEXT, updated_at TEXT, payment_status TEXT NOT NULL DEFAULT 'pending', payment_confirmed_at TEXT, processing_status TEXT NOT NULL DEFAULT 'pending', processing_started_at TEXT, email_status TEXT NOT NULL DEFAULT 'pending', email_attempts INTEGER NOT NULL DEFAULT 0, email_sent_at TEXT, email_last_error TEXT)");
$pdo->exec("CREATE TABLE IF NOT EXISTS entradas (id INTEGER PRIMARY KEY AUTOINCREMENT, evento_id INTEGER, nombre TEXT, email TEXT, fecha_registro TEXT, codigo TEXT, checked_in INTEGER, checked_in_at TEXT, tipo TEXT, monto_pagado REAL, tc_order_request_id TEXT, issuance_key TEXT UNIQUE)");
$pdo->exec("CREATE TABLE IF NOT EXISTS tipos_entrada (id INTEGER PRIMARY KEY, evento_id INTEGER, nombre TEXT, tipo TEXT, precio REAL, cantidad_total INTEGER, cantidad_disponible INTEGER, qr_quantity INTEGER NOT NULL DEFAULT 1)");
$pdo->exec("CREATE TABLE IF NOT EXISTS order_events (id INTEGER PRIMARY KEY AUTOINCREMENT, tc_order_id INTEGER, request_id TEXT, event_type TEXT, payload_json TEXT, created_at TEXT)");
$pdo->exec("CREATE TABLE IF NOT EXISTS entrada_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, entrada_id INTEGER UNIQUE, token TEXT UNIQUE, created_at TEXT)");
$pdo->exec("CREATE TABLE IF NOT EXISTS email_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, created_at TEXT NOT NULL DEFAULT (datetime('now')), resend_of_id INTEGER, trace_id TEXT, context TEXT, related_table TEXT, related_id INTEGER, to_email TEXT NOT NULL, from_email TEXT, from_name TEXT, reply_to TEXT, subject TEXT, body TEXT, headers TEXT, extra_params TEXT, mail_ok INTEGER NOT NULL DEFAULT 0, error_text TEXT)");
$pdo->exec("CREATE TABLE IF NOT EXISTS email_templates (id INTEGER PRIMARY KEY AUTOINCREMENT, context TEXT UNIQUE, enabled INTEGER, subject TEXT, body TEXT)");
$pdo->exec("INSERT OR IGNORE INTO email_templates (context, enabled, subject, body) VALUES ('entradas_compra', 1, 'Tus entradas', '{{entradas}}')");
$pdo->exec("INSERT OR REPLACE INTO tipos_entrada (id, evento_id, nombre, tipo, precio, cantidad_total, cantidad_disponible, qr_quantity) VALUES (7, 1, 'Entrada local de prueba', 'paga', 100, 10, 10, 1)");
$pdo->exec("INSERT OR REPLACE INTO tipos_entrada (id, evento_id, nombre, tipo, precio, cantidad_total, cantidad_disponible, qr_quantity) VALUES (8, 1, 'Paquete de dos', 'paga', 150, 6, 6, 2)");
$pdo->exec("INSERT OR REPLACE INTO tipos_entrada (id, evento_id, nombre, tipo, precio, cantidad_total, cantidad_disponible, qr_quantity) VALUES (9, 1, 'Paquete de cuatro', 'paga', 300, 8, 8, 4)");

require_once __DIR__ . '/../inc/order_processing.php';

$selected = json_encode(array(array('id' => 7, 'name' => 'General', 'qty' => 2, 'price' => 100, 'qr_quantity' => 1)));
$pdo->prepare("INSERT INTO tc_orders (request_id, state, evento_id, ref, amount, buyer_first, buyer_last, buyer_email, selected_tickets_json, payment_status) VALUES ('test-rid', 'created', 1, 'test-ref', 200, 'Test', 'Buyer', 'test@example.invalid', :tickets, 'confirmed')")->execute(array(':tickets' => $selected));

$st = $pdo->query("SELECT * FROM tc_orders WHERE request_id = 'test-rid'");
$order = $st->fetch(PDO::FETCH_ASSOC);
$result = process_tc_order_row($pdo, $order);
test_ok(!empty($result['processed']), 'confirmed order is processed');
test_ok((int)$pdo->query("SELECT COUNT(*) FROM entradas WHERE tc_order_request_id = 'test-rid'")->fetchColumn() === 2, 'exactly requested quantity is issued');
test_ok((int)$pdo->query("SELECT COUNT(*) FROM entrada_tokens t INNER JOIN entradas e ON e.id = t.entrada_id WHERE e.tc_order_request_id = 'test-rid'")->fetchColumn() === 2, 'secure tokens are generated');
test_ok((int)$pdo->query("SELECT COUNT(*) FROM email_logs WHERE mail_ok = 1 AND context = 'entradas_compra'")->fetchColumn() === 1, 'fake transport records one consolidated email per order');
test_ok((int)$pdo->query("SELECT cantidad_disponible FROM tipos_entrada WHERE id = 7")->fetchColumn() === 8, 'normal tickets decrement one stock unit per QR');
test_ok(abs((float)$pdo->query("SELECT SUM(monto_pagado) FROM entradas WHERE tc_order_request_id = 'test-rid'")->fetchColumn() - 200.0) < 0.001, 'normal ticket revenue is not duplicated');

$st->execute();
$orderAgain = $st->fetch(PDO::FETCH_ASSOC);
$resultAgain = process_tc_order_row($pdo, $orderAgain);
test_ok((int)$pdo->query("SELECT COUNT(*) FROM entradas WHERE tc_order_request_id = 'test-rid'")->fetchColumn() === 2, 'reprocessing does not duplicate entries');
test_ok((int)$pdo->query("SELECT COUNT(*) FROM email_logs WHERE mail_ok = 1 AND context = 'entradas_compra'")->fetchColumn() === 1, 'reprocessing does not duplicate successful emails');

$selectedTwo = json_encode(array(array('id' => 8, 'name' => 'Paquete de dos', 'qty' => 1, 'price' => 150, 'qr_quantity' => 2)));
$pdo->prepare("INSERT INTO tc_orders (request_id, state, evento_id, ref, amount, buyer_first, buyer_last, buyer_email, selected_tickets_json, payment_status) VALUES ('bundle-two-rid', 'created', 1, 'bundle-two-ref', 150, 'Bundle', 'Two', 'two@example.invalid', :tickets, 'confirmed')")->execute(array(':tickets' => $selectedTwo));
$bundleTwoOrder = $pdo->query("SELECT * FROM tc_orders WHERE request_id = 'bundle-two-rid'")->fetch(PDO::FETCH_ASSOC);
$bundleTwoResult = process_tc_order_row($pdo, $bundleTwoOrder);
test_ok(!empty($bundleTwoResult['processed']), 'two-QR package is processed');
test_ok((int)$pdo->query("SELECT COUNT(*) FROM entradas WHERE tc_order_request_id = 'bundle-two-rid'")->fetchColumn() === 2, 'one two-QR package issues two independent entries');
test_ok((int)$pdo->query("SELECT cantidad_disponible FROM tipos_entrada WHERE id = 8")->fetchColumn() === 4, 'two-QR package decrements two stock units');
test_ok(abs((float)$pdo->query("SELECT SUM(monto_pagado) FROM entradas WHERE tc_order_request_id = 'bundle-two-rid'")->fetchColumn() - 150.0) < 0.001, 'two-QR package revenue equals package price');

$selectedFour = json_encode(array(array('id' => 9, 'name' => 'Paquete de cuatro', 'qty' => 1, 'price' => 300, 'qr_quantity' => 4)));
$pdo->prepare("INSERT INTO tc_orders (request_id, state, evento_id, ref, amount, buyer_first, buyer_last, buyer_email, selected_tickets_json, payment_status) VALUES ('bundle-four-rid', 'created', 1, 'bundle-four-ref', 300, 'Bundle', 'Four', 'four@example.invalid', :tickets, 'confirmed')")->execute(array(':tickets' => $selectedFour));
$bundleFourOrder = $pdo->query("SELECT * FROM tc_orders WHERE request_id = 'bundle-four-rid'")->fetch(PDO::FETCH_ASSOC);
$bundleFourResult = process_tc_order_row($pdo, $bundleFourOrder);
test_ok(!empty($bundleFourResult['processed']), 'four-QR package is processed');
test_ok((int)$pdo->query("SELECT COUNT(*) FROM entradas WHERE tc_order_request_id = 'bundle-four-rid'")->fetchColumn() === 4, 'one four-QR package issues four independent entries');
test_ok((int)$pdo->query("SELECT COUNT(DISTINCT codigo) FROM entradas WHERE tc_order_request_id = 'bundle-four-rid'")->fetchColumn() === 4, 'four-QR package generates four distinct codes');
test_ok((int)$pdo->query("SELECT cantidad_disponible FROM tipos_entrada WHERE id = 9")->fetchColumn() === 4, 'four-QR package decrements four stock units');
test_ok(abs((float)$pdo->query("SELECT SUM(monto_pagado) FROM entradas WHERE tc_order_request_id = 'bundle-four-rid'")->fetchColumn() - 300.0) < 0.001, 'four-QR package revenue equals package price');
test_ok((int)$pdo->query("SELECT COUNT(*) FROM email_logs WHERE related_table = 'tc_orders' AND related_id = " . (int)$bundleFourOrder['id'] . " AND mail_ok = 1")->fetchColumn() === 1, 'four QR links are delivered in one email');
$bundleFourMailBody = (string)$pdo->query("SELECT body FROM email_logs WHERE related_table = 'tc_orders' AND related_id = " . (int)$bundleFourOrder['id'] . " AND mail_ok = 1 LIMIT 1")->fetchColumn();
test_ok(substr_count($bundleFourMailBody, 'ticket.php?') === 4, 'consolidated email contains all four ticket links');

$bundleFourAgain = $pdo->query("SELECT * FROM tc_orders WHERE request_id = 'bundle-four-rid'")->fetch(PDO::FETCH_ASSOC);
process_tc_order_row($pdo, $bundleFourAgain);
test_ok((int)$pdo->query("SELECT COUNT(*) FROM entradas WHERE tc_order_request_id = 'bundle-four-rid'")->fetchColumn() === 4, 'reprocessing four-QR package does not duplicate entries');
test_ok((int)$pdo->query("SELECT COUNT(*) FROM email_logs WHERE related_table = 'tc_orders' AND related_id = " . (int)$bundleFourOrder['id'] . " AND mail_ok = 1")->fetchColumn() === 1, 'reprocessing four-QR package does not duplicate email');

$callbacks = tickex_totalcoin_build_callbacks('https://local.test', 'signed-ref');
parse_str(parse_url($callbacks['success'], PHP_URL_QUERY), $signedQuery);
test_ok(isset($signedQuery['ref']) && $signedQuery['ref'] === 'signed-ref', 'callback carries the local reference');
test_ok(tickex_totalcoin_callback_is_valid($signedQuery['ref'], $signedQuery['state'], $signedQuery['token']), 'signed callback validates');
test_ok(!tickex_totalcoin_callback_is_valid($signedQuery['ref'], 'failed', $signedQuery['token']), 'signature cannot be reused for another state');

$pdo->prepare("INSERT INTO tc_orders (request_id, state, evento_id, ref, amount, buyer_first, buyer_last, buyer_email, selected_tickets_json, payment_status) VALUES ('status-rid', 'created', 1, 'status-ref', 200, 'Status', 'Buyer', 'status@example.invalid', :tickets, 'pending')")->execute(array(':tickets' => $selected));
$statusOrder = $pdo->query("SELECT * FROM tc_orders WHERE request_id = 'status-rid'")->fetch(PDO::FETCH_ASSOC);
$fakeApproved = function ($reference) {
    return array('found' => true, 'http_status' => 200, 'data' => array('Concepto' => $reference, 'Monto' => 200, 'Estado' => 'APROBADO'));
};
$confirmation = tickex_totalcoin_confirm_from_status($pdo, $statusOrder, $fakeApproved);
test_ok(!empty($confirmation['confirmed']), 'official approved status confirms matching order');
test_ok($pdo->query("SELECT payment_status FROM tc_orders WHERE request_id = 'status-rid'")->fetchColumn() === 'confirmed', 'confirmed status is persisted');

$pdo->prepare("INSERT INTO tc_orders (request_id, state, evento_id, ref, amount, buyer_first, buyer_last, buyer_email, selected_tickets_json, payment_status) VALUES ('mismatch-rid', 'created', 1, 'mismatch-ref', 200, 'Mismatch', 'Buyer', 'mismatch@example.invalid', :tickets, 'pending')")->execute(array(':tickets' => $selected));
$mismatchOrder = $pdo->query("SELECT * FROM tc_orders WHERE request_id = 'mismatch-rid'")->fetch(PDO::FETCH_ASSOC);
$fakeWrongAmount = function ($reference) {
    return array('found' => true, 'http_status' => 200, 'data' => array('Concepto' => $reference, 'Monto' => 199, 'Estado' => 'APROBADO'));
};
$mismatch = tickex_totalcoin_confirm_from_status($pdo, $mismatchOrder, $fakeWrongAmount);
test_ok(empty($mismatch['confirmed']) && $mismatch['result'] === 'amount_mismatch', 'amount mismatch cannot confirm payment');
test_ok($pdo->query("SELECT payment_status FROM tc_orders WHERE request_id = 'mismatch-rid'")->fetchColumn() === 'pending', 'mismatched order remains pending');

echo "ALL LOCAL FLOW TESTS PASSED\n";
