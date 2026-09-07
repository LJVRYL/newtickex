<?php
// Compatibilidad con enlaces antiguos: existe un único panel y una única sesión.
require_once __DIR__ . '/inc/bootstrap.php';
require_login();
$cu = current_user();
header('Location: panel_admin.php');
exit;
