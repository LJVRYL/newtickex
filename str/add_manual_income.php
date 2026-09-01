<?php
/**
 * add_manual_income.php
 * Procesa la adición de un nuevo ingreso manual (vía AJAX o form submit)
 */
require_once __DIR__.'/inc/bootstrap.php';
require_once __DIR__.'/inc/manual_income.php';

require_login();

header('Content-Type: application/json; charset=utf-8');

$cu = current_user();
$rol = isset($cu['tipo_global']) && $cu['tipo_global'] !== ''
  ? $cu['tipo_global']
  : (isset($cu['rol']) ? $cu['rol'] : (isset($_SESSION['tipo_global']) ? $_SESSION['tipo_global'] : ''));

// Solo admin_evento, super_admin pueden agregar ingresos
if (!in_array($rol, array('admin_evento','super_admin','superadmin'), true)) {
    http_response_code(403);
    echo json_encode(array('error' => 'No autorizado'));
    exit;
}

$evento_id = isset($_POST['evento_id']) ? (int)$_POST['evento_id'] : 0;
$concepto = isset($_POST['concepto']) ? trim($_POST['concepto']) : '';
$monto = isset($_POST['monto']) ? (float)$_POST['monto'] : 0;
$tipo  = isset($_POST['tipo']) ? trim($_POST['tipo']) : 'ingreso';
$descripcion = isset($_POST['descripcion']) ? trim($_POST['descripcion']) : '';

if (!$evento_id || !$concepto || $monto <= 0) {
    http_response_code(400);
    echo json_encode(array('error' => 'Datos inválidos'));
    exit;
}

if (!in_array(strtolower($tipo), array('ingreso','egreso'), true)) {
    $tipo = 'ingreso';
}

$pdo = db();
$adminId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : (isset($cu['id'])?(int)$cu['id']:0);
if (!tickex_can_access_event($pdo, $evento_id, $cu)) {
    http_response_code(404);
    echo json_encode(array('error' => 'Evento no encontrado'));
    exit;
}

// Verificar que el evento existe y el usuario tiene permiso
$stmtEv = $pdo->prepare("SELECT id FROM eventos WHERE id=:id");
$stmtEv->execute(array(':id'=>$evento_id));
if (!$stmtEv->fetch()) {
    http_response_code(404);
    echo json_encode(array('error' => 'Evento no encontrado'));
    exit;
}

// Agregar el ingreso
if (add_manual_income($pdo, $evento_id, $concepto, $monto, $descripcion, $adminId, $tipo)) {
    echo json_encode(array(
        'success' => true,
        'message' => 'Ingreso agregado correctamente',
    ));
} else {
    http_response_code(500);
    echo json_encode(array('error' => 'Error al guardar el ingreso'));
}
?>
