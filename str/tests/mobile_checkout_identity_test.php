<?php
require_once __DIR__ . '/../inc/mobile_checkout_intents.php';
function identity_assert($condition, $message)
{
    if (!$condition) { fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL); exit(1); }
}
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE registro_pendientes (id INTEGER PRIMARY KEY, email TEXT)');
$pdo->exec('CREATE TABLE mobile_checkout_intents (ref TEXT PRIMARY KEY, cliente_id INTEGER, evento_id INTEGER, expires_at TEXT)');
$pdo->exec('CREATE TABLE tc_orders (id INTEGER PRIMARY KEY, ref TEXT, evento_id INTEGER, payment_status TEXT)');
$pdo->exec("INSERT INTO registro_pendientes VALUES (7, 'comprador@example.invalid')");
$ref = 'mobile-' . str_repeat('a', 32);
$insert = $pdo->prepare('INSERT INTO mobile_checkout_intents VALUES (:ref, 7, 21, :expires)');
$insert->execute(array(':ref' => $ref, ':expires' => date('Y-m-d H:i:s', time() + 3600)));
identity_assert(tickex_mobile_checkout_intent_guard($pdo, $ref, 21, null) === 'ok', 'valid app intent permits selection');
identity_assert(tickex_mobile_checkout_intent_guard($pdo, $ref, 21, 'COMPRADOR@example.invalid') === 'ok', 'matching buyer may pay');
$pdo->exec("INSERT INTO tc_orders (ref, evento_id, payment_status) VALUES ('" . $ref . "', 21, 'pending')");
identity_assert(tickex_mobile_checkout_intent_guard($pdo, $ref, 21, 'comprador@example.invalid') === 'already_started', 'second payment for the same mobile reference is blocked');
identity_assert(tickex_mobile_checkout_intent_guard($pdo, $ref, 21, null) === 'ok', 'existing payment remains consultable');
$pdo->exec('DELETE FROM tc_orders');
identity_assert(tickex_mobile_checkout_intent_guard($pdo, $ref, 21, 'otro@example.invalid') === 'email_mismatch', 'different buyer must be blocked before payment');
identity_assert(tickex_mobile_checkout_intent_guard($pdo, $ref, 22, 'comprador@example.invalid') === 'invalid_intent', 'intent cannot cross events');
identity_assert(tickex_mobile_checkout_intent_guard($pdo, 'mobile-' . str_repeat('b', 32), 21, 'comprador@example.invalid') === 'invalid_intent', 'unknown intent is rejected');
identity_assert(tickex_mobile_checkout_intent_guard($pdo, 'mobile-invalid', 21, 'comprador@example.invalid') === 'invalid_intent', 'malformed mobile reference is rejected');
identity_assert(tickex_mobile_checkout_intent_guard($pdo, 'web-reference', 21, 'otro@example.invalid') === 'not_mobile', 'ordinary web checkout is unchanged');
$pdo->exec("UPDATE mobile_checkout_intents SET expires_at = '2020-01-01 00:00:00'");
identity_assert(tickex_mobile_checkout_intent_guard($pdo, $ref, 21, 'comprador@example.invalid') === 'invalid_intent', 'expired intent is rejected');
echo "mobile checkout identity PASS\n";
