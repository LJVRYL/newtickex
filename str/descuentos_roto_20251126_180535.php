<?php
// Copia histórica retirada. Se conserva la ruta para marcadores antiguos.
require_once __DIR__ . '/inc/bootstrap.php';
require_login();
header('Location: descuentos.php', true, 302);
exit;
