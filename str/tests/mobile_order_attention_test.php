<?php
require_once __DIR__ . '/../inc/mobile_events.php';
require_once __DIR__ . '/../inc/mobile_orders.php';
require_once __DIR__ . '/../inc/mobile_checkout_intents.php';
function attention_assert($ok, $message) { if (!$ok) { fwrite(STDERR, "FAIL: $message\n"); exit(1); } }
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE eventos (id INTEGER PRIMARY KEY, nombre TEXT)');
$pdo->exec("INSERT INTO eventos VALUES (1, 'QA')");
$pdo->exec('CREATE TABLE tc_orders (id INTEGER PRIMARY KEY, ref TEXT, evento_id INTEGER, buyer_email TEXT, payment_status TEXT, processing_status TEXT, payment_confirmed_at TEXT, amount REAL, created_at TEXT)');
$pdo->exec("INSERT INTO tc_orders VALUES (1, 'mobile-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 1, 'qa@example.invalid', 'confirmed', 'pending', datetime('now', '-20 minutes'), 115, datetime('now', '-30 minutes'))");
$pdo->exec("INSERT INTO tc_orders VALUES (2, 'mobile-bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', 1, 'qa@example.invalid', 'confirmed', 'pending', datetime('now', '-2 minutes'), 115, datetime('now', '-30 minutes'))");
$pdo->exec("INSERT INTO tc_orders VALUES (3, 'mobile-cccccccccccccccccccccccccccccccc', 1, 'qa@example.invalid', 'confirmed', 'issued', datetime('now', '-20 minutes'), 115, datetime('now', '-30 minutes'))");
attention_assert(tickex_mobile_order_for_client($pdo, 1, 'qa@example.invalid')['needsAssistance'] === true, 'confirmed unissued order requires attention after 15 minutes');
attention_assert(tickex_mobile_order_for_client($pdo, 2, 'qa@example.invalid')['needsAssistance'] === false, 'recent confirmation is still processing');
attention_assert(tickex_mobile_order_for_client($pdo, 3, 'qa@example.invalid')['needsAssistance'] === false, 'issued order is not flagged');
attention_assert(tickex_mobile_order_for_client($pdo, 1, 'other@example.invalid') === null, 'foreign buyer cannot read order');
$pdo->exec('CREATE TABLE mobile_checkout_intents (ref TEXT PRIMARY KEY, cliente_id INTEGER, evento_id INTEGER, created_at TEXT)');
$pdo->exec("INSERT INTO mobile_checkout_intents VALUES ('mobile-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 7, 1, datetime('now', '-30 minutes'))");
$client = array('id' => 7, 'email' => 'qa@example.invalid');
attention_assert(tickex_mobile_checkout_intent_status($pdo, $client, 'mobile-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa')['order']['needsAssistance'] === true, 'checkout return exposes the same attention state');
attention_assert(tickex_mobile_checkout_intent_status($pdo, array('id' => 8, 'email' => 'other@example.invalid'), 'mobile-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa') === null, 'foreign checkout intent is hidden');
echo "mobile order attention PASS\n";
