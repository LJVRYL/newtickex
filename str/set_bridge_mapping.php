<?php
/**
 * set_bridge_mapping.php
 * Guardar mapping entre evento STR y slug del bridge (POST)
 */
require_once __DIR__.'/inc/bootstrap.php';
require_once __DIR__.'/inc/unified_tickets.php';

require_login();

header('Content-Type: application/json; charset=utf-8');

$cu = current_user();
$rol = isset($cu['tipo_global']) && $cu['tipo_global'] !== ''
  ? $cu['tipo_global']
  : (isset($cu['rol']) ? $cu['rol'] : (isset($_SESSION['tipo_global']) ? $_SESSION['tipo_global'] : ''));

if (!in_array($rol, array('admin_evento','super_admin','superadmin'), true)) {
    http_response_code(403);
    echo json_encode(array('error' => 'No autorizado'));
    exit;
}

$evento_id = isset($_POST['evento_id']) ? (int)$_POST['evento_id'] : 0;
$bridge_slug = isset($_POST['bridge_slug']) ? trim($_POST['bridge_slug']) : '';

if (!$evento_id || $bridge_slug === '') {
    http_response_code(400);
    echo json_encode(array('error' => 'Parámetros inválidos'));
    exit;
}

$pdo = db();

if (!tickex_can_access_event($pdo, $evento_id, $cu)) {
    http_response_code(403);
    echo json_encode(array('error' => 'No autorizado para este evento'));
    exit;
}

if (!tickex_event_bridge_allowed($pdo, $evento_id)) {
    http_response_code(403);
    echo json_encode(array('error' => 'Bridge solo está disponible para cuentas internas'));
    exit;
}

$stmtEv = $pdo->prepare("SELECT id FROM eventos WHERE id=:id");
$stmtEv->execute(array(':id'=>$evento_id));
if (!$stmtEv->fetch()) {
    http_response_code(404);
    echo json_encode(array('error' => 'Evento no encontrado'));
    exit;
}

if (set_bridge_mapping($pdo, $evento_id, $bridge_slug)) {
    echo json_encode(array('success' => true, 'message' => 'Mapping guardado'));
} else {
    http_response_code(500);
    echo json_encode(array('error' => 'Error al guardar mapping'));
}

?>
