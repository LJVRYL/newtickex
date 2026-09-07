<?php
require_once __DIR__ . '/../inc/customer_portal.php';

function customer_assert($condition, $message)
{
    if (!$condition) { fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL); exit(1); }
    echo 'PASS: ' . $message . PHP_EOL;
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE registro_pendientes(id INTEGER PRIMARY KEY,email TEXT,nombre TEXT,apellido TEXT,apodo TEXT,dni TEXT,genero TEXT,creado_en TEXT,completado_en TEXT,password_hash TEXT)");
$pdo->exec("CREATE TABLE eventos(id INTEGER PRIMARY KEY,nombre TEXT,slug TEXT,fecha_desde TEXT,fecha_hasta TEXT,flyer_filename TEXT)");
$pdo->exec("CREATE TABLE entradas(id INTEGER PRIMARY KEY,nombre TEXT,email TEXT,codigo TEXT,tipo TEXT,monto_pagado REAL,evento_id INTEGER,fecha_registro TEXT,checked_in INTEGER,checked_in_at TEXT,oculto INTEGER DEFAULT 0)");
$hash = password_hash('clave-anterior', PASSWORD_DEFAULT);
$insertUser = $pdo->prepare("INSERT INTO registro_pendientes VALUES(1,'buyer@example.com','Ada','Compradora','ada','12345678','X','2026-01-01','2026-01-01',:hash)");
$insertUser->execute(array(':hash' => $hash));
$pdo->exec("INSERT INTO registro_pendientes VALUES(2,'other@example.com','Otra','Persona','','','','2026-01-01','2026-01-01','x')");
$pdo->exec("INSERT INTO eventos VALUES(10,'Evento futuro','futuro','2099-10-10','2099-10-10',''),(20,'Evento pasado','pasado','2000-01-01','2000-01-01','')");
$pdo->exec("INSERT INTO entradas VALUES
 (101,'Ada','buyer@example.com','AAA','General',20000,10,'2026-01-01',0,NULL,0),
 (102,'Ada','buyer@example.com','BBB','General',0,10,'2026-01-01',1,'2026-01-02',0),
 (103,'Ada','buyer@example.com','CCC','General',10000,20,'2026-01-01',0,NULL,0),
 (104,'Otra','other@example.com','DDD','General',99999,10,'2026-01-01',0,NULL,0)");

tickex_customer_portal_ensure_schema($pdo);
$tickets = tickex_customer_portal_load_tickets($pdo, 'BUYER@example.com');
customer_assert(count($tickets) === 3, 'buyer only loads tickets associated with the own email');
customer_assert(!in_array(104, array_map(function ($row) { return (int)$row['id']; }, $tickets), true), 'another buyer ticket is never exposed');
$byId = array(); foreach ($tickets as $ticket) $byId[(int)$ticket['id']] = $ticket;
customer_assert($byId[101]['portal_state'] === 'vigente', 'future unused ticket is available');
customer_assert($byId[102]['portal_state'] === 'utilizada', 'checked-in ticket is marked as used');
customer_assert($byId[103]['portal_state'] === 'vencida', 'past unused ticket moves to history');
$counts = tickex_customer_portal_counts($tickets);
customer_assert($counts['vigentes'] === 1 && $counts['utilizadas'] === 1 && $counts['vencidas'] === 1, 'ticket counters follow lifecycle state');

customer_assert(!tickex_customer_portal_set_hidden($pdo, 'buyer@example.com', 104, true), 'buyer cannot archive another account ticket');
customer_assert(tickex_customer_portal_set_hidden($pdo, 'buyer@example.com', 101, true), 'buyer can archive an owned ticket');
$hidden = tickex_customer_portal_filter_tickets(tickex_customer_portal_load_tickets($pdo, 'buyer@example.com'), 'ocultas');
customer_assert(count($hidden) === 1 && (int)$hidden[0]['id'] === 101, 'archived ticket remains recoverable');
customer_assert(tickex_customer_portal_set_hidden($pdo, 'buyer@example.com', 101, false), 'buyer can restore an archived ticket');

list($badPassword) = tickex_customer_portal_change_password($pdo, 1, 'incorrecta', 'clave-nueva-segura', 'clave-nueva-segura');
customer_assert(!$badPassword, 'password change requires the current password');
list($shortPassword) = tickex_customer_portal_change_password($pdo, 1, 'clave-anterior', 'corta', 'corta');
customer_assert(!$shortPassword, 'new password requires at least ten characters');
list($changedPassword) = tickex_customer_portal_change_password($pdo, 1, 'clave-anterior', 'clave-nueva-segura', 'clave-nueva-segura');
customer_assert($changedPassword, 'buyer can change the password securely');
$updated = tickex_customer_portal_user($pdo, 1);
customer_assert(password_verify('clave-nueva-segura', $updated['password_hash']), 'new password is stored as a one-way hash');

$sent = 0;
$sender = function ($ticket, $url) use (&$sent) { $sent++; return $ticket['id'] == 101 && strpos($url, '/ticket.php?t=') !== false; };
list($foreignResend) = tickex_customer_portal_resend($pdo, 1, 'buyer@example.com', 104, '/ticket.php?t=no', $sender);
customer_assert(!$foreignResend && $sent === 0, 'buyer cannot resend another account ticket');
list($firstResend) = tickex_customer_portal_resend($pdo, 1, 'buyer@example.com', 101, '/ticket.php?t=secure', $sender);
customer_assert($firstResend && $sent === 1, 'owned ticket can be resent once');
list($duplicateResend) = tickex_customer_portal_resend($pdo, 1, 'buyer@example.com', 101, '/ticket.php?t=secure', $sender);
customer_assert(!$duplicateResend && $sent === 1, 'repeated resend is rate limited');

$panel = file_get_contents(__DIR__ . '/../panel_usuario.php');
$profile = file_get_contents(__DIR__ . '/../panel_usuario_mi_perfil.php');
$login = file_get_contents(__DIR__ . '/../login.php');
$register = file_get_contents(__DIR__ . '/../registro_usuario.php');
$complete = file_get_contents(__DIR__ . '/../completar_registro.php');
$ticketPage = file_get_contents(__DIR__ . '/../ticket.php');
customer_assert(strpos($panel, 'tickex_secure_ticket_url') !== false, 'portal exposes only secure ticket links');
customer_assert(strpos($ticketPage, 'tickex_secure_checkin_url') !== false, 'ticket QR keeps the internal code private');
customer_assert(strpos($ticketPage, '$ticketLink = tickex_secure_ticket_url') !== false, 'shared ticket link remains secure');
customer_assert(strpos($panel, "tickex_csrf_verify") !== false, 'portal protects every write action');
customer_assert(strpos($profile, "\$_SESSION['auth_context'] !== 'user'") !== false, 'buyer profile rejects stale administrator sessions');
customer_assert(strpos($panel, 'monto_pagado\']/100') === false, 'portal does not divide peso amounts by one hundred');
customer_assert(strpos($login, 'name="_csrf"') !== false && strpos($register, 'name="_csrf"') !== false && strpos($complete, 'name="_csrf"') !== false, 'login and registration forms require request protection');
customer_assert(strpos($complete, 'strlen($passNueva) < 10') !== false, 'new buyer registration enforces the password policy');

echo 'ALL CUSTOMER PORTAL TESTS PASSED' . PHP_EOL;
