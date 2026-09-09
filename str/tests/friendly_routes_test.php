<?php
require_once __DIR__ . '/../inc/routes.php';
require_once __DIR__ . '/../inc/secure_links.php';

function friendly_assert($condition, $message)
{
    if (!$condition) { fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL); exit(1); }
    echo 'PASS: ' . $message . PHP_EOL;
}

$_SERVER['HTTP_HOST'] = '127.0.0.1:8088';
$_SERVER['HTTPS'] = '';
friendly_assert(tickex_public_base_url('http://127.0.0.1:8088') === 'http://127.0.0.1:8088', 'local development keeps its own host');
unset($_SERVER['HTTP_HOST']);
friendly_assert(tickex_public_base_url('https://str.tickex.com.ar') === 'https://www.tickex.com.ar', 'new public links use the canonical www domain');
friendly_assert(tickex_route('customer_home', array()) === '/mi-cuenta', 'buyer panel has a clean route');
friendly_assert(tickex_route('customer_profile', array()) === '/mi-cuenta/perfil', 'buyer profile has a clean route');
friendly_assert(tickex_route('staff_home', array()) === '/mi-cuenta/staff', 'staff panel has a clean route');
friendly_assert(tickex_route('staff_scan', array()) === '/mi-cuenta/staff/escanear', 'staff scanner has a clean route');
friendly_assert(tickex_route('staff_sales', array()) === '/mi-cuenta/staff/puerta', 'door sales have a clean route');
friendly_assert(tickex_route('staff_activity', array()) === '/mi-cuenta/staff/actividad', 'staff activity has a clean route');
friendly_assert(tickex_route('reseller_home', array()) === '/mi-cuenta/revendedor', 'reseller panel has a clean route');

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE entradas(id INTEGER PRIMARY KEY, codigo TEXT)');
$pdo->exec("INSERT INTO entradas(id,codigo) VALUES(1,'INTERNAL-CODE')");
$ticket = tickex_secure_ticket_url($pdo, 'https://str.tickex.com.ar', 1, 'INTERNAL-CODE');
$checkin = tickex_secure_checkin_url($pdo, 'https://str.tickex.com.ar', 1, 'INTERNAL-CODE');
friendly_assert((bool)preg_match('#^https://www\\.tickex\\.com\\.ar/entrada/[a-f0-9]{48}$#', $ticket), 'ticket URL is canonical and opaque');
friendly_assert((bool)preg_match('#^https://www\\.tickex\\.com\\.ar/validar/[a-f0-9]{48}$#', $checkin), 'check-in URL is canonical and opaque');
friendly_assert(strpos($ticket, 'INTERNAL-CODE') === false && strpos($checkin, 'INTERNAL-CODE') === false, 'internal ticket code is never exposed in new URLs');

$router = file_get_contents(__DIR__ . '/../router.php');
$apache = file_get_contents(__DIR__ . '/../../ops/apache/999-tickex.conf');
$strApache = file_get_contents(__DIR__ . '/../../ops/apache/str.conf');
$legacy = file_get_contents(__DIR__ . '/../entrada.php');
$auth = file_get_contents(__DIR__ . '/../inc/auth.php');
friendly_assert(strpos($router, "'/mi-cuenta' => 'panel_usuario.php'") !== false, 'local router resolves the buyer panel');
friendly_assert(strpos($router, "'/mi-cuenta/staff' => 'panel_staff.php'") !== false, 'local router resolves the staff panel');
friendly_assert(strpos($apache, 'RewriteRule ^/entrada/') !== false && strpos($apache, 'https://str.tickex.com.ar/$1') === false, 'production vhost serves clean routes without redirecting to str');
friendly_assert(substr_count($strApache, 'RewriteRule ^/mi-cuenta/?$ /panel_usuario.php [END]') === 2, 'compatibility host serves the buyer clean route over HTTP and HTTPS');
friendly_assert(substr_count($strApache, 'RewriteRule ^/administrar/?$ /panel_admin.php [END]') === 2, 'compatibility host serves the administrator clean route over HTTP and HTTPS');
friendly_assert(substr_count($strApache, 'RewriteRule ^/mi-cuenta/staff/?$ /panel_staff.php [END]') === 2, 'compatibility host serves the staff clean route over HTTP and HTTPS');
friendly_assert(strpos($apache, '"\\s/+login\\.php(?:[?\\s])"') !== false, 'legacy route matching uses executable Apache escapes');
friendly_assert(strpos($auth, "tickex_route('login', array())") !== false && strpos($auth, "header('Location: login.php')") === false, 'protected pages keep authentication on the clean login route');
friendly_assert(strpos($legacy, 'tickex_secure_ticket_url') !== false && strpos($legacy, "SELECT id, codigo") !== false, 'historical ticket links migrate to opaque links');

echo 'ALL FRIENDLY ROUTES TESTS PASSED' . PHP_EOL;
