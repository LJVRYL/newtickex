<?php
/**
 * delete_manual_income.php
 * Elimina un ingreso manual
 */
require_once __DIR__.'/inc/bootstrap.php';
require_once __DIR__.'/inc/manual_income.php';

require_login();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !tickex_csrf_verify(isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '')) {
    http_response_code(403);
    echo json_encode(array('error' => 'Solicitud vencida o inválida'));
    exit;
}

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

$stIncome = $pdo->prepare('SELECT evento_id FROM manual_incomes WHERE id = :id LIMIT 1');
$stIncome->execute(array(':id' => $income_id));
$incomeEventId = (int)$stIncome->fetchColumn();
if ($incomeEventId <= 0 || !tickex_can_access_event($pdo, $incomeEventId, $cu)) {
    http_response_code(404);
    echo json_encode(array('error' => 'Movimiento no encontrado'));
    exit;
}

if (delete_manual_income($pdo, $income_id)) {
    echo json_encode(array('success' => true));
} else {
    http_response_code(500);
    echo json_encode(array('error' => 'Error al eliminar'));
}
?>
