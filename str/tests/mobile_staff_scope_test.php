<?php
require_once __DIR__ . '/../inc/mobile_events.php';
require_once __DIR__ . '/../inc/mobile_staff.php';
require_once __DIR__ . '/../inc/mobile_staff_gate.php';
function scope_assert($condition, $message)
{
    if (!$condition) { fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL); exit(1); }
}
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE eventos (id INTEGER PRIMARY KEY, nombre TEXT, fecha_desde TEXT, fecha_hasta TEXT, lugar TEXT, creado_por_admin_id INTEGER, borrado_en TEXT)');
$pdo->exec('CREATE TABLE staff_admins (id INTEGER PRIMARY KEY, cliente_id INTEGER, owner_admin_id INTEGER, rol_staff TEXT, activo INTEGER)');
$pdo->exec('CREATE TABLE staff_eventos (id INTEGER PRIMARY KEY, staff_id INTEGER, evento_id INTEGER, rol_staff TEXT)');
$pdo->exec('CREATE TABLE staff_roles (owner_admin_id INTEGER, code TEXT, permissions_json TEXT, activo INTEGER)');
$pdo->exec("INSERT INTO eventos VALUES (1, 'Puerta', NULL, NULL, 'Sala 1', 7, NULL), (2, 'Caja', NULL, NULL, 'Sala 2', 7, NULL), (3, 'Sin asignar', NULL, NULL, 'Sala 3', 7, NULL), (4, 'Otro productor', NULL, NULL, 'Sala 4', 8, NULL)");
$pdo->exec("INSERT INTO staff_admins VALUES (1, 51, 7, 'puerta', 1)");
$pdo->exec("INSERT INTO staff_eventos VALUES (1, 51, 1, 'puerta'), (2, 51, 2, 'caja'), (3, 51, 4, 'puerta')");
$pdo->exec("INSERT INTO staff_roles VALUES (7, 'puerta', '[\"dashboard_view\",\"checkin_scan\"]', 1), (7, 'caja', '[\"dashboard_view\",\"sales_view\"]', 1)");
$events = tickex_mobile_staff_events($pdo, 51);
scope_assert(count($events) === 2, 'staff sees only assigned events owned by producer');
$byId = array();
foreach ($events as $event) $byId[$event['id']] = $event;
scope_assert(isset($byId['1']) && $byId['1']['role'] === 'puerta', 'door role belongs to first event');
scope_assert(isset($byId['2']) && $byId['2']['role'] === 'caja', 'cashier role belongs to second event');
scope_assert(tickex_mobile_gate_permission($pdo, 51, 1, 'checkin_scan'), 'door can scan assigned event');
scope_assert(!tickex_mobile_gate_permission($pdo, 51, 2, 'checkin_scan'), 'cashier cannot scan with sales role');
scope_assert(tickex_mobile_gate_permission($pdo, 51, 2, 'sales_view'), 'cashier can view sales on assigned event');
scope_assert(!tickex_mobile_gate_permission($pdo, 51, 3, 'checkin_scan'), 'unassigned event denies door permission');
scope_assert(!tickex_mobile_gate_permission($pdo, 51, 4, 'checkin_scan'), 'other producer event denies door permission');
$pdo->exec("UPDATE staff_roles SET activo = 0 WHERE owner_admin_id = 7 AND code = 'puerta'");
scope_assert(!tickex_mobile_gate_permission($pdo, 51, 1, 'checkin_scan'), 'disabled role cannot regain default permissions');
echo "mobile staff scope PASS\n";
