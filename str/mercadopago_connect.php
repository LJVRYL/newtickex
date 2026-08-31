<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/mercadopago_marketplace.php';

require_login();
$cu = current_user();
$adminId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : (isset($cu['id']) ? (int)$cu['id'] : 0);
$role = isset($cu['tipo_global']) ? (string)$cu['tipo_global'] : '';
if ($adminId <= 0 || !in_array($role, array('admin_evento', 'super_admin', 'superadmin'), true)) {
    http_response_code(403);
    exit('Acceso denegado.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !tickex_csrf_verify(isset($_POST['_csrf']) ? $_POST['_csrf'] : '')) {
    http_response_code(400);
    exit('Solicitud invalida.');
}
try {
    $url = tickex_mp_begin_oauth(db(), $adminId);
    header('Cache-Control: no-store');
    header('Location: ' . $url, true, 303);
    exit;
} catch (Exception $e) {
    $_SESSION['mp_flash_error'] = $e->getMessage();
    header('Location: mercadopago_config.php', true, 303);
    exit;
}

