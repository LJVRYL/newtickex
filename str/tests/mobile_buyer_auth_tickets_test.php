<?php
date_default_timezone_set('America/Argentina/Buenos_Aires');
require_once __DIR__ . '/../inc/mobile_events.php';
require_once __DIR__ . '/../inc/mobile_auth.php';
require_once __DIR__ . '/../inc/mobile_tickets.php';

function buyer_assert($condition, $message)
{
    if (!$condition) { fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL); exit(1); }
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE registro_pendientes (id INTEGER PRIMARY KEY, email TEXT, nombre TEXT, apellido TEXT, password_hash TEXT, completado_en TEXT)');
$pdo->exec('CREATE TABLE mobile_login_attempts (fingerprint TEXT, attempted_at TEXT)');
$pdo->exec('CREATE TABLE mobile_auth_tokens (token_hash TEXT PRIMARY KEY, cliente_id INTEGER, created_at TEXT, expires_at TEXT, revoked_at TEXT)');
$pdo->exec('CREATE TABLE user_blocks (email TEXT, active INTEGER)');
$pdo->exec('CREATE TABLE eventos (id INTEGER PRIMARY KEY, nombre TEXT, fecha_desde TEXT, lugar TEXT)');
$pdo->exec('CREATE TABLE entradas (id INTEGER PRIMARY KEY, evento_id INTEGER, email TEXT, nombre TEXT, tipo TEXT, codigo TEXT, checked_in INTEGER, fecha_registro TEXT)');
$pdo->exec('CREATE TABLE entradas_ocultas_usuario (id INTEGER PRIMARY KEY, entrada_id INTEGER, email TEXT)');
$insert = $pdo->prepare('INSERT INTO registro_pendientes VALUES (1, :email, :first, :last, :hash, :completed)');
$insert->execute(array(':email' => 'buyer@example.invalid', ':first' => 'QA', ':last' => 'Buyer', ':hash' => password_hash('correct-password', PASSWORD_BCRYPT), ':completed' => '2026-09-01'));
$pdo->exec("INSERT INTO eventos VALUES (7, 'Evento', '2027-10-01 20:00:00', 'Teatro')");
$pdo->exec("INSERT INTO entradas VALUES (9, 7, 'buyer@example.invalid', 'QA Buyer', 'General', 'ABC123', 0, '2026-09-01')");
$pdo->exec("INSERT INTO entradas VALUES (10, 7, 'other@example.invalid', 'Other Buyer', 'VIP', 'XYZ999', 0, '2026-09-01')");

buyer_assert(tickex_mobile_login($pdo, 'buyer@example.invalid', 'wrong-password', '127.0.0.1') === null, 'wrong password rejected');
$session = tickex_mobile_login($pdo, 'buyer@example.invalid', 'correct-password', '127.0.0.1');
buyer_assert($session && strlen($session['accessToken']) === 64, 'login returns token');
$token = $session['accessToken'];
buyer_assert((int)tickex_mobile_authenticated_client($pdo, $token)['id'] === 1, 'token belongs to buyer');
$tickets = tickex_mobile_tickets($pdo, 'buyer@example.invalid', null);
buyer_assert(count($tickets) === 1 && $tickets[0]['id'] === '9', 'ticket list excludes another buyer');
buyer_assert(count(tickex_mobile_tickets($pdo, 'buyer@example.invalid', 10)) === 0, 'other buyer ticket detail and QR are denied');
buyer_assert($tickets[0]['qrPayload'] === 'https://str.tickex.com.ar/checkin.php?c=ABC123', 'owned valid QR is available');
$pdo->exec("INSERT INTO entradas_ocultas_usuario VALUES (1, 9, 'buyer@example.invalid')");
buyer_assert(count(tickex_mobile_tickets($pdo, 'buyer@example.invalid', null)) === 0, 'hidden ticket is omitted');
$pdo->exec("INSERT INTO user_blocks VALUES ('buyer@example.invalid', 1)");
buyer_assert(tickex_mobile_authenticated_client($pdo, $token) === null, 'blocked account loses access');
$pdo->exec("DELETE FROM user_blocks");
buyer_assert(tickex_mobile_authenticated_client($pdo, $token) === null, 'blocked token stays revoked');
$fresh = tickex_mobile_login($pdo, 'buyer@example.invalid', 'correct-password', '127.0.0.1');
buyer_assert($fresh !== null, 'account can log in after unblock');
$freshToken = $fresh['accessToken'];
tickex_mobile_logout($pdo, $freshToken);
buyer_assert(tickex_mobile_authenticated_client($pdo, $freshToken) === null, 'logout revokes token');
$expiring = tickex_mobile_login($pdo, 'buyer@example.invalid', 'correct-password', '127.0.0.1');
$pdo->prepare("UPDATE mobile_auth_tokens SET expires_at = '2020-01-01 00:00:00' WHERE token_hash = :hash")->execute(array(':hash' => hash('sha256', $expiring['accessToken'])));
buyer_assert(tickex_mobile_authenticated_client($pdo, $expiring['accessToken']) === null, 'expired token is rejected');
echo "mobile buyer auth and tickets PASS\n";
