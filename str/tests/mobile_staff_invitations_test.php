<?php
require_once __DIR__ . '/../inc/mobile_staff_invitations.php';
function invitation_assert($condition, $message) { if (!$condition) { fwrite(STDERR, $message . "\n"); exit(1); } }
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE staff_admin_invitaciones (id INTEGER PRIMARY KEY, owner_admin_id INTEGER, email TEXT, cliente_id INTEGER, token TEXT, mensaje TEXT, rol_staff TEXT, estado TEXT, created_at TEXT, updated_at TEXT)");
$pdo->exec("CREATE TABLE staff_admins (owner_admin_id INTEGER, cliente_id INTEGER, rol_staff TEXT, activo INTEGER, UNIQUE(owner_admin_id, cliente_id))");
$pdo->exec("INSERT INTO staff_admin_invitaciones (id, owner_admin_id, email, cliente_id, mensaje, rol_staff, estado, created_at) VALUES (1, 10, 'staff@example.invalid', NULL, 'Puerta', 'puerta', 'pending', '2026-10-02'), (2, 11, 'other@example.invalid', 50, '', 'puerta', 'pending', '2026-10-02'), (3, 10, 'staff@example.invalid', NULL, '', 'puerta', 'pending', '2026-10-02')");
$client = array('id' => 50, 'email' => 'staff@example.invalid');
$other = array('id' => 51, 'email' => 'other@example.invalid');
$pending = tickex_mobile_staff_invitations($pdo, $client);
invitation_assert(count($pending) === 3 && !isset($pending[0]['token']), 'only eligible pending invitations, without token');
invitation_assert(tickex_mobile_staff_invitation_decide($pdo, $other, 2, 'accept')['error'] === 'forbidden', 'bound invitation cannot be accepted by same-email different account');
invitation_assert(tickex_mobile_staff_invitation_decide($pdo, $other, 1, 'accept')['error'] === 'forbidden', 'different email cannot accept');
$accepted = tickex_mobile_staff_invitation_decide($pdo, $client, 1, 'accept');
invitation_assert($accepted['status'] === 'accepted', 'eligible user accepts');
invitation_assert((int)$pdo->query('SELECT COUNT(*) FROM staff_admins WHERE owner_admin_id=10 AND cliente_id=50 AND activo=1')->fetchColumn() === 1, 'staff relation active');
invitation_assert(tickex_mobile_staff_invitation_decide($pdo, $client, 1, 'accept')['error'] === 'not_found', 'repeat decision does not duplicate');
$rejected = tickex_mobile_staff_invitation_decide($pdo, $client, 3, 'reject');
invitation_assert($rejected['status'] === 'rejected', 'rejection recorded');
invitation_assert((int)$pdo->query("SELECT COUNT(*) FROM staff_admin_invitaciones WHERE estado='pending'")->fetchColumn() === 1, 'other invitation untouched');
echo "mobile staff invitations PASS\n";
