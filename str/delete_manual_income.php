<?php
/**
 * delete_manual_income.php
 * Elimina un ingreso manual
 */
require_once __DIR__.'/inc/bootstrap.php';
require_once __DIR__.'/inc/manual_income.php';

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

$income_id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

if (!$income_id) {
    http_response_code(400);
    echo json_encode(array('error' => 'ID inválido'));
    exit;
}

$pdo = db();

if (delete_manual_income($pdo, $income_id)) {
    echo json_encode(array('success' => true));
} else {
    http_response_code(500);
    echo json_encode(array('error' => 'Error al eliminar'));
}
?>
