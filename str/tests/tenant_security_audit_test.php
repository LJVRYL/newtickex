<?php
require_once __DIR__ . '/../inc/event_access.php';
require_once __DIR__ . '/../inc/produccion.php';

function security_assert($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "FAIL: " . $message . PHP_EOL);
        exit(1);
    }
    echo "PASS: " . $message . PHP_EOL;
}

function security_source($relative) {
    $path = __DIR__ . '/../' . $relative;
    $source = file_get_contents($path);
    security_assert($source !== false, $relative . ' can be audited');
    return (string)$source;
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE eventos (id INTEGER PRIMARY KEY, creado_por_admin_id INTEGER NOT NULL)');
$pdo->exec('CREATE TABLE staff_eventos (staff_id INTEGER NOT NULL, evento_id INTEGER NOT NULL)');
$pdo->exec("CREATE TABLE usuarios_admin (id INTEGER PRIMARY KEY, evento_id INTEGER, tipo_global TEXT)");
$pdo->exec('INSERT INTO eventos(id,creado_por_admin_id) VALUES (11,2),(22,9)');
$pdo->exec('INSERT INTO staff_eventos(staff_id,evento_id) VALUES (30,22)');

$adminA = array('id'=>2, 'tipo_global'=>'admin_evento');
$adminB = array('id'=>9, 'tipo_global'=>'admin_evento');
$staff = array('id'=>30, 'tipo_global'=>'staff_evento');
$super = array('id'=>1, 'tipo_global'=>'super_admin');
security_assert(tickex_can_access_event($pdo, 11, $adminA), 'an organizer can access the own event');
security_assert(!tickex_can_access_event($pdo, 22, $adminA), 'an organizer cannot access another tenant event');
security_assert(tickex_can_access_event($pdo, 22, $adminB), 'the second organizer keeps the own event');
security_assert(tickex_can_access_event($pdo, 22, $staff), 'staff can access an assigned event');
security_assert(!tickex_can_access_event($pdo, 11, $staff), 'staff cannot access an unassigned event');
security_assert(tickex_can_access_event($pdo, 11, $super) && tickex_can_access_event($pdo, 22, $super), 'superadministration can audit every event');

ensure_produccion_assignment_table($pdo);
ensure_produccion_table($pdo);
$pdo->exec("INSERT INTO produccion_artistas(nombre,owner_admin_id) VALUES ('Artist A',2),('Artist B',9)");
$artistsA = get_produccion_artistas($pdo, 2, false);
$artistsB = get_produccion_artistas($pdo, 9, false);
$artistsSuper = get_produccion_artistas($pdo, 1, true);
security_assert(count($artistsA) === 1 && $artistsA[0]['nombre'] === 'Artist A', 'production contacts are isolated for the first organizer');
security_assert(count($artistsB) === 1 && $artistsB[0]['nombre'] === 'Artist B', 'production contacts are isolated for the second organizer');
security_assert(count($artistsSuper) === 2, 'superadministration can audit all production contacts');

$manualDelete = security_source('delete_manual_income.php');
security_assert(strpos($manualDelete, 'tickex_csrf_verify') !== false, 'manual income deletion requires request protection');
security_assert(strpos($manualDelete, 'tickex_can_access_event') !== false, 'manual income deletion validates the movement event owner');

$manualAdd = security_source('add_manual_income.php');
security_assert(strpos($manualAdd, 'tickex_csrf_verify') !== false, 'manual income creation requires request protection');
security_assert(strpos($manualAdd, 'tickex_can_access_event') !== false, 'manual income creation validates event access');

$eventEditor = security_source('editar_evento.php');
security_assert(strpos($eventEditor, 'tickex_require_event_access') !== false, 'event editing validates tenant ownership');
security_assert(strpos($eventEditor, 'tickex_csrf_verify') !== false, 'event editing protects every write request');
security_assert(strpos($eventEditor, '$' . "_GET['del_te']") === false, 'ticket types cannot be deleted through a GET link');
security_assert(strpos($eventEditor, 'display_errors') === false && strpos($eventEditor, 'str-hit.log') === false, 'event editing does not expose temporary diagnostics');

$ticketConfig = security_source('configurar_entradas_evento.php');
security_assert(strpos($ticketConfig, 'tickex_require_event_access') !== false, 'ticket configuration validates tenant ownership');
security_assert(strpos($ticketConfig, 'tickex_csrf_verify') !== false, 'ticket configuration protects write requests');
security_assert(strpos($ticketConfig, '$' . "_GET['del_te']") === false, 'ticket configuration uses POST for deletion');

$inventory = security_source('inventario.php');
security_assert(strpos($inventory, 'tickex_csrf_verify') !== false, 'inventory writes require request protection');
security_assert(strpos($inventory, 'owner_admin_id') !== false, 'inventory remains scoped to an organizer owner');

$production = security_source('produccion.php');
security_assert(strpos($production, 'tickex_csrf_verify') !== false, 'production writes require request protection');
security_assert(strpos($production, 'owner_admin_id') !== false, 'production contacts are written and modified within an organizer');

$manualTicket = security_source('cargar_entrada.php');
security_assert(strpos($manualTicket, 'tickex_csrf_verify') !== false, 'manual ticket issuance requires request protection');
security_assert(strpos($manualTicket, 'tickex_require_event_access') !== false, 'manual ticket issuance validates event ownership');

$doorTicket = security_source('nueva_entrada.php');
security_assert(strpos($doorTicket, 'tickex_csrf_verify') !== false, 'door ticket issuance requires request protection');
security_assert(strpos($doorTicket, 'tickex_require_event_access') !== false, 'door ticket issuance validates the staff event assignment');

$legacyEditor = security_source('edit_tickex.php');
security_assert(strpos($legacyEditor, 'tickex_csrf_verify') !== false, 'legacy Bridge editor protects every write request');

$door = security_source('puerta.php');
security_assert(strpos($door, 'tickex_require_event_access') !== false, 'door dashboard validates the selected event assignment');
$doorApi = security_source('puerta_api.php');
security_assert(strpos($doorApi, 'tickex_can_access_event') !== false, 'door API validates the selected event assignment');
security_assert(strpos($doorApi, 'tickex_csrf_verify') !== false, 'door API protects check-in writes');
$checkin = security_source('checkin.php');
security_assert(strpos($checkin, "action'] === 'confirm_checkin'") !== false, 'opening a QR link does not perform check-in by itself');
security_assert(strpos($checkin, 'tickex_csrf_verify') !== false, 'check-in confirmation requires request protection');

$bridge = security_source('set_bridge_mapping.php');
security_assert(strpos($bridge, 'tickex_can_access_event') !== false, 'Bridge mapping validates event ownership');
security_assert(strpos($bridge, 'tickex_csrf_verify') !== false, 'Bridge mapping protects write requests');

$arcaPage = security_source('config_arca.php');
security_assert(strpos($arcaPage, "array('super_admin', 'superadmin')") !== false, 'global tax credentials are restricted to superadministration');
security_assert(strpos($arcaPage, 'tickex_csrf_verify') !== false, 'global tax configuration protects write requests');
security_assert(strpos($arcaPage, "e(isset(" . '$config' . "['key'])") === false, 'private tax key is never rendered back into HTML');
security_assert(strpos($arcaPage, "e(isset(" . '$config' . "['cert'])") === false, 'tax certificate is never rendered back into HTML');

$strHtaccess = security_source('.htaccess');
security_assert(strpos($strHtaccess, 'arca_config\\.json') !== false, 'legacy public tax configuration is blocked by the web server');
$rootHtaccess = file_get_contents(__DIR__ . '/../../.htaccess');
security_assert($rootHtaccess !== false && strpos($rootHtaccess, '\\.secrets') !== false, 'private secret directory is blocked by the web server');

$tmpConfig = tempnam(sys_get_temp_dir(), 'tickex-arca-security-');
if ($tmpConfig !== false && is_file($tmpConfig)) unlink($tmpConfig);
putenv('TICKEX_ARCA_CONFIG_FILE=' . $tmpConfig);
require_once __DIR__ . '/../inc/arca.php';
arca_save_config(array('cuit'=>'20123456789','cert'=>'CERT-SECRET','key'=>'KEY-SECRET','modo'=>'homologacion'));
$stored = arca_get_config();
security_assert(is_array($stored) && $stored['key'] === 'KEY-SECRET', 'tax secrets can be recovered only from private storage');
security_assert(strpos(str_replace('\\', '/', arca_config_file_path()), '/str/') === false, 'default tax secret storage is outside the public str directory');
if (is_file($tmpConfig)) unlink($tmpConfig);

echo "ALL TENANT SECURITY AUDIT TESTS PASSED" . PHP_EOL;
