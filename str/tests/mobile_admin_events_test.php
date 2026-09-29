<?php
function tickex_mobile_datetime($value)
{
    if ($value === null || trim((string)$value) === '') return null;
    $timestamp = strtotime((string)$value);
    return $timestamp === false ? null : date('c', $timestamp);
}
require_once __DIR__ . '/../inc/mobile_admin.php';
function admin_event_assert($condition, $message)
{
    if (!$condition) { fwrite(STDERR, "FAIL: " . $message . "\n"); exit(1); }
}
date_default_timezone_set('America/Argentina/Buenos_Aires');
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE eventos (id INTEGER PRIMARY KEY, nombre TEXT, slug TEXT UNIQUE, descripcion TEXT, fecha_desde TEXT, fecha_hasta TEXT, lugar TEXT, creado_por_admin_id INTEGER, creado_en TEXT, actualizado_en TEXT, publicado_site INTEGER DEFAULT 0, borrado_en TEXT)");
$owner = array('id' => 7, 'tipo_global' => 'admin_evento');
$other = array('id' => 8, 'tipo_global' => 'admin_evento');
$created = tickex_mobile_admin_create_event($pdo, $owner, array('name' => 'Evento de prueba', 'slug' => 'evento-prueba', 'description' => 'Prueba', 'startsAt' => '2026-10-05T20:00:00-03:00', 'endsAt' => '2026-10-06T03:00:00-03:00', 'venue' => 'Sala'));
admin_event_assert(isset($created['id']) && $created['published'] === false, 'creates private draft for owner');
$eventId = (int)$created['id'];
admin_event_assert($created['endsAt'] !== null, 'returns end date');
admin_event_assert(count(tickex_mobile_admin_events($pdo, $owner)) === 1, 'owner sees event');
admin_event_assert(count(tickex_mobile_admin_events($pdo, $other)) === 0, 'other producer cannot list event');
admin_event_assert(tickex_mobile_admin_event_detail($pdo, $other, $eventId) === null, 'other producer cannot read detail');
admin_event_assert(tickex_mobile_admin_create_event($pdo, $owner, array('name' => 'Duplicado', 'slug' => 'evento-prueba'))['error'] === 'slug_in_use', 'duplicate slug rejected');
$updated = tickex_mobile_admin_edit_event($pdo, $owner, $eventId, array('name' => 'Nombre nuevo', 'description' => 'Actualizado', 'startsAt' => '2026-10-05T20:00:00-03:00', 'endsAt' => '2026-10-06T03:00:00-03:00', 'venue' => 'Sala 2'));
admin_event_assert($updated['name'] === 'Nombre nuevo' && $updated['venue'] === 'Sala 2', 'owner edits event');
admin_event_assert(tickex_mobile_admin_edit_event($pdo, $other, $eventId, array('name' => 'Intrusión'))['error'] === 'not_found', 'other producer cannot edit');
admin_event_assert(tickex_mobile_admin_create_event($pdo, $owner, array('name' => 'Mala fecha', 'slug' => 'mala-fecha', 'startsAt' => 'yesterday'))['error'] === 'invalid_event', 'relative dates rejected');
$pdo->exec("CREATE TABLE staff_roles (owner_admin_id INTEGER, code TEXT, name TEXT, permissions_json TEXT, activo INTEGER, is_system INTEGER)");
$pdo->exec("CREATE TABLE staff_admins (id INTEGER PRIMARY KEY, owner_admin_id INTEGER, cliente_id INTEGER, rol_staff TEXT, activo INTEGER)");
$pdo->exec("CREATE TABLE staff_eventos (staff_id INTEGER, evento_id INTEGER, PRIMARY KEY (staff_id, evento_id))");
$pdo->exec("CREATE TABLE registro_pendientes (id INTEGER PRIMARY KEY, nombre TEXT, apellido TEXT, apodo TEXT, email TEXT)");
$pdo->exec("CREATE TABLE staff_admin_invitaciones (id INTEGER PRIMARY KEY, owner_admin_id INTEGER, email TEXT, estado TEXT, created_at TEXT)");
$pdo->exec("INSERT INTO staff_roles VALUES (7, 'puerta', 'Puerta', '[\"checkin_scan\"]', 1, 1)");
$pdo->exec("INSERT INTO staff_admins VALUES (1, 7, 101, 'puerta', 1), (2, 8, 102, 'puerta', 1)");
$pdo->exec("INSERT INTO registro_pendientes VALUES (101, 'QA', 'Staff', '', 'staff@example.invalid'), (102, 'Otro', 'Staff', '', 'otro@example.invalid')");
$staff = tickex_mobile_admin_staff($pdo, $owner, $eventId);
admin_event_assert(count($staff['members']) === 1 && !$staff['members'][0]['assigned'], 'lists only producer staff');
admin_event_assert(tickex_mobile_admin_assign_staff($pdo, $owner, $eventId, 102, 'puerta', true)['error'] === 'staff_not_found', 'cannot assign another producer staff');
$staff = tickex_mobile_admin_assign_staff($pdo, $owner, $eventId, 101, 'puerta', true);
admin_event_assert($staff['members'][0]['assigned'], 'assigns authorized staff');
$staff = tickex_mobile_admin_assign_staff($pdo, $owner, $eventId, 101, '', false);
admin_event_assert(!$staff['members'][0]['assigned'], 'removes staff assignment');
$pdo->exec('CREATE TABLE tipos_entrada (id INTEGER PRIMARY KEY, evento_id INTEGER, nombre TEXT, precio REAL, cantidad_total INTEGER, cantidad_disponible INTEGER, visible_publico INTEGER, qr_quantity INTEGER)');
$pdo->exec("INSERT INTO tipos_entrada VALUES (10, $eventId, 'General', 100, 20, 20, 1, 1)");
$stock = tickex_mobile_admin_update_ticket_type($pdo, $owner, $eventId, 10, array('expectedAvailable' => 20, 'available' => 5, 'visible' => false));
admin_event_assert($stock[0]['available'] === 5 && !$stock[0]['visible'] && $stock[0]['total'] === 5, 'owner adjusts stock and visibility');
admin_event_assert(tickex_mobile_admin_update_ticket_type($pdo, $owner, $eventId, 10, array('expectedAvailable' => 20, 'available' => 0, 'visible' => false))['error'] === 'stock_changed', 'stale stock edit rejected');
admin_event_assert(tickex_mobile_admin_update_ticket_type($pdo, $other, $eventId, 10, array('expectedAvailable' => 5, 'available' => 0, 'visible' => false))['error'] === 'forbidden', 'other producer cannot edit stock');
echo "mobile admin events PASS\n";
