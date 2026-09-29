<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_login();
$cu = current_user();
$role = isset($cu['tipo_global']) ? (string)$cu['tipo_global'] : '';
if (!in_array($role, array('super_admin', 'superadmin'), true)) {
    abort_404('No tenés permiso.');
}
header('Location: '.tickex_route('admin_home', array()), true, 302);
exit;
