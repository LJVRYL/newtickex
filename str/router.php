<?php
// Router para desarrollo local; Apache replica estas rutas en producción.
$path = parse_url(isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH);
$path = rawurldecode((string)$path);
$file = __DIR__ . str_replace('/', DIRECTORY_SEPARATOR, $path);
if ($path !== '/' && is_file($file)) return false;

$fixed = array(
    '/ingresar' => 'login.php',
    '/mi-cuenta' => 'panel_usuario.php',
    '/mi-cuenta/perfil' => 'panel_usuario_mi_perfil.php',
    '/mi-cuenta/staff' => 'panel_staff.php',
    '/mi-cuenta/staff/escanear' => 'staff_scan_qr.php',
    '/mi-cuenta/staff/puerta' => 'panel_staff_venta_puerta.php',
    '/mi-cuenta/staff/actividad' => 'panel_staff_checkin_log.php',
    '/mi-cuenta/revendedor' => 'panel_revendedor.php',
    '/salir' => 'logout_usuario.php',
    '/administrar' => 'panel_admin.php',
);
$normalized = $path === '/' ? '/' : rtrim($path, '/');
if (isset($fixed[$normalized])) { require __DIR__ . '/' . $fixed[$normalized]; exit; }
if (preg_match('#^/entrada/([a-f0-9]{40,128})/?$#i', $path, $m)) { $_GET['t'] = $m[1]; require __DIR__ . '/ticket.php'; exit; }
if (preg_match('#^/validar/([a-f0-9]{40,128})/?$#i', $path, $m)) { $_GET['t'] = $m[1]; require __DIR__ . '/checkin.php'; exit; }
if ($path === '/') { require __DIR__ . '/conocer_tickex.php'; exit; }
http_response_code(404);
if (is_file(__DIR__ . '/404.php')) require __DIR__ . '/404.php'; else echo 'Página no encontrada.';
