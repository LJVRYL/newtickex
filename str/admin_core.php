<?php
// Compatibilidad para enlaces antiguos. El panel legacy fue retirado porque
// operaba sobre entradas globales y admitía eliminaciones por URL.
require_once __DIR__ . '/inc/bootstrap.php';
require_login();
header('Location: panel_admin.php', true, 302);
exit;
