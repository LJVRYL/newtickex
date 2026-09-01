<?php
require_once __DIR__ . '/../inc/event_access.php';

function tenant_ok($value, $message) {
    if (!$value) { fwrite(STDERR, 'FAIL: '.$message.PHP_EOL); exit(1); }
    echo 'PASS: '.$message.PHP_EOL;
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE eventos (id INTEGER PRIMARY KEY,nombre TEXT,creado_por_admin_id INTEGER,borrado_en TEXT)');
$pdo->exec('CREATE TABLE staff_eventos (staff_id INTEGER,evento_id INTEGER)');
$pdo->exec('CREATE TABLE usuarios_admin (id INTEGER PRIMARY KEY,tipo_global TEXT,evento_id INTEGER)');
$pdo->exec("INSERT INTO eventos VALUES (15,'STR',2,NULL),(16,'Juan',9,NULL),(17,'Borrado',9,'2026-01-01')");
$pdo->exec("INSERT INTO usuarios_admin VALUES (20,'staff_evento',NULL)");
$pdo->exec('INSERT INTO staff_eventos VALUES (20,16)');

$str = array('id'=>2,'tipo_global'=>'admin_evento');
$juan = array('id'=>9,'tipo_global'=>'admin_evento');
$super = array('id'=>1,'tipo_global'=>'super_admin');
$staff = array('id'=>20,'tipo_global'=>'staff_evento');
tenant_ok(tickex_can_access_event($pdo, 15, $str), 'STR administrator can access own event');
tenant_ok(!tickex_can_access_event($pdo, 16, $str), 'STR administrator cannot access Juan event');
tenant_ok(tickex_can_access_event($pdo, 16, $juan), 'Juan can access own event');
tenant_ok(!tickex_can_access_event($pdo, 15, $juan), 'Juan cannot access STR event');
tenant_ok(tickex_can_access_event($pdo, 15, $super) && tickex_can_access_event($pdo, 16, $super), 'superadministrator can access every event');
tenant_ok(tickex_can_access_event($pdo, 16, $staff) && !tickex_can_access_event($pdo, 15, $staff), 'staff access follows event assignment');
$visible = tickex_visible_events($pdo, $juan);
tenant_ok(count($visible) === 1 && (int)$visible[0]['id'] === 16, 'event list is isolated per administrator');
echo 'ALL EVENT TENANT ISOLATION TESTS PASSED'.PHP_EOL;
