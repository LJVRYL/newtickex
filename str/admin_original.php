<?php
// Compatibilidad para enlaces antiguos. Toda administración continúa en el
// panel actual, que aplica permisos por organizador y evento.
require_once __DIR__ . '/inc/bootstrap.php';
require_login();
header('Location: panel_admin.php', true, 302);
exit;
