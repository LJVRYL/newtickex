<?php
// El selector de paneles antiguos queda como redirección segura para no romper
// marcadores existentes ni volver a exponer herramientas retiradas.
require_once __DIR__ . '/inc/bootstrap.php';
require_login();
header('Location: panel_admin.php', true, 302);
exit;
