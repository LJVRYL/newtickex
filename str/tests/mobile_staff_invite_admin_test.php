<?php
function tickex_mobile_admin_event_owner($pdo, $admin, $eventId) { return (int)$eventId === 9 && (int)$admin['id'] === 7 ? 7 : 0; }
function tickex_mobile_admin_roles($pdo, $admin, $eventId) { return array('roles' => array(array('code' => 'puerta'))); }
require_once __DIR__ . '/../inc/mobile_staff_invite_admin.php';
require_once __DIR__ . '/../inc/mobile_staff_invitations.php';
function invite_assert($condition, $message) { if (!$condition) { fwrite(STDERR, $message . "\n"); exit(1); } }
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE registro_pendientes (id INTEGER PRIMARY KEY, email TEXT, apodo TEXT, token TEXT, nombre TEXT, apellido TEXT, password_hash TEXT, completado_en TEXT, next_url TEXT, creado_en TEXT)');
$pdo->exec('CREATE TABLE staff_admin_invitaciones (id INTEGER PRIMARY KEY, owner_admin_id INTEGER, email TEXT, token TEXT, mensaje TEXT, rol_staff TEXT, estado TEXT, cliente_id INTEGER, created_at TEXT, updated_at TEXT)');
$pdo->exec('CREATE TABLE staff_admins (owner_admin_id INTEGER, cliente_id INTEGER, rol_staff TEXT, activo INTEGER, UNIQUE(owner_admin_id, cliente_id))');
$pdo->exec("INSERT INTO registro_pendientes (id,email,apodo,password_hash,completado_en) VALUES (50,'staff@example.invalid','staff50','hash','2026-10-02')");
$mailCalls = 0;
$mail = function ($email, $template, $vars, $trace, $options) use (&$mailCalls) { $mailCalls++; return true; };
$notify = function () { return true; };
$admin = array('id' => 7);
invite_assert(tickex_mobile_admin_invite_staff($pdo, array('id' => 8), 9, array('email' => 'staff@example.invalid'), $mail, $notify)['error'] === 'forbidden', 'foreign admin rejected');
invite_assert(tickex_mobile_admin_invite_staff($pdo, $admin, 9, array('email' => 'staff@example.invalid', 'roleCode' => 'unknown'), $mail, $notify)['error'] === 'invalid_role', 'unknown role rejected');
$invite = tickex_mobile_admin_invite_staff($pdo, $admin, 9, array('tickexId' => '#50', 'roleCode' => 'puerta', 'message' => 'Puerta'), $mail, $notify);
invite_assert($invite['status'] === 'pending' && $invite['emailSent'] && $mailCalls === 1, 'known client invited once');
invite_assert(tickex_mobile_admin_invite_staff($pdo, $admin, 9, array('email' => 'staff@example.invalid'), $mail, $notify)['error'] === 'already_pending', 'duplicate invitation blocked');
invite_assert($mailCalls === 1, 'duplicate does not send mail');
$client = array('id' => 50, 'email' => 'staff@example.invalid');
invite_assert(tickex_mobile_staff_invitation_decide($pdo, $client, $invite['id'], 'accept')['status'] === 'accepted', 'invitation can be accepted');
$new = tickex_mobile_admin_invite_staff($pdo, $admin, 9, array('email' => 'new@example.invalid', 'roleCode' => 'puerta'), $mail, $notify);
invite_assert($new['status'] === 'pending' && $mailCalls === 2, 'unregistered person invited');
invite_assert((int)$pdo->query("SELECT COUNT(*) FROM registro_pendientes WHERE email='new@example.invalid' AND token IS NOT NULL")->fetchColumn() === 1, 'registration prepared');
echo "mobile staff invite admin PASS\n";
