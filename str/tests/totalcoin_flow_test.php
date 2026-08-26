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
require_once __DIR__ . '/../inc/db.php';
$pdo = db();
$pdo->exec("CREATE TABLE IF NOT EXISTS tc_orders (id INTEGER PRIMARY KEY AUTOINCREMENT, request_id TEXT UNIQUE, state TEXT, evento_id INTEGER, ref TEXT, amount REAL, buyer_first TEXT, buyer_last TEXT, buyer_email TEXT, selected_tickets_json TEXT, processed_at TEXT, payment_status TEXT NOT NULL DEFAULT 'pending', payment_confirmed_at TEXT, processing_status TEXT NOT NULL DEFAULT 'pending', processing_started_at TEXT, email_status TEXT NOT NULL DEFAULT 'pending', email_attempts INTEGER NOT NULL DEFAULT 0, email_sent_at TEXT, email_last_error TEXT)");
$pdo->exec("CREATE TABLE IF NOT EXISTS entradas (id INTEGER PRIMARY KEY AUTOINCREMENT, evento_id INTEGER, nombre TEXT, email TEXT, fecha_registro TEXT, codigo TEXT, checked_in INTEGER, checked_in_at TEXT, tipo TEXT, monto_pagado REAL, tc_order_request_id TEXT, issuance_key TEXT UNIQUE)");
$pdo->exec("CREATE TABLE IF NOT EXISTS tipos_entrada (id INTEGER PRIMARY KEY, cantidad_disponible INTEGER)");
$pdo->exec("CREATE TABLE IF NOT EXISTS order_events (id INTEGER PRIMARY KEY AUTOINCREMENT, tc_order_id INTEGER, request_id TEXT, event_type TEXT, payload_json TEXT, created_at TEXT)");
$pdo->exec("CREATE TABLE IF NOT EXISTS entrada_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, entrada_id INTEGER UNIQUE, token TEXT UNIQUE, created_at TEXT)");
$pdo->exec("CREATE TABLE IF NOT EXISTS email_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, related_table TEXT, related_id INTEGER, context TEXT, mail_ok INTEGER, to_email TEXT, subject TEXT, body TEXT, created_at TEXT)");
$pdo->exec("CREATE TABLE IF NOT EXISTS email_templates (id INTEGER PRIMARY KEY AUTOINCREMENT, context TEXT UNIQUE, enabled INTEGER, subject TEXT, body TEXT)");
$pdo->exec("INSERT OR IGNORE INTO email_templates (context, enabled, subject, body) VALUES ('entrada_registro', 1, 'Entrada {{id}}', 'Ticket {{ticket_url}}')");
$pdo->exec("INSERT OR REPLACE INTO tipos_entrada (id, evento_id, nombre, tipo, precio, cantidad_total, cantidad_disponible) VALUES (7, 1, 'Entrada local de prueba', 'paga', 100, 10, 10)");

require_once __DIR__ . '/../inc/order_processing.php';

$selected = json_encode(array(array('id' => 7, 'name' => 'General', 'qty' => 2, 'price' => 100)));
$pdo->prepare("INSERT INTO tc_orders (request_id, state, evento_id, ref, amount, buyer_first, buyer_last, buyer_email, selected_tickets_json, payment_status) VALUES ('test-rid', 'created', 1, 'test-ref', 200, 'Test', 'Buyer', 'test@example.invalid', :tickets, 'confirmed')")->execute(array(':tickets' => $selected));

$st = $pdo->query("SELECT * FROM tc_orders WHERE request_id = 'test-rid'");
$order = $st->fetch(PDO::FETCH_ASSOC);
$result = process_tc_order_row($pdo, $order);
test_ok(!empty($result['processed']), 'confirmed order is processed');
test_ok((int)$pdo->query("SELECT COUNT(*) FROM entradas WHERE tc_order_request_id = 'test-rid'")->fetchColumn() === 2, 'exactly requested quantity is issued');
test_ok((int)$pdo->query("SELECT COUNT(*) FROM entrada_tokens t INNER JOIN entradas e ON e.id = t.entrada_id WHERE e.tc_order_request_id = 'test-rid'")->fetchColumn() === 2, 'secure tokens are generated');
test_ok((int)$pdo->query("SELECT COUNT(*) FROM email_logs WHERE mail_ok = 1")->fetchColumn() === 2, 'fake transport records one email per entry');

$st->execute();
$orderAgain = $st->fetch(PDO::FETCH_ASSOC);
$resultAgain = process_tc_order_row($pdo, $orderAgain);
test_ok((int)$pdo->query("SELECT COUNT(*) FROM entradas WHERE tc_order_request_id = 'test-rid'")->fetchColumn() === 2, 'reprocessing does not duplicate entries');
test_ok((int)$pdo->query("SELECT COUNT(*) FROM email_logs WHERE mail_ok = 1")->fetchColumn() === 2, 'reprocessing does not duplicate successful emails');

echo "ALL LOCAL FLOW TESTS PASSED\n";
