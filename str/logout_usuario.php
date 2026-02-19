<?php
require_once __DIR__ . '/inc/security.php';
tickex_send_security_headers();
tickex_session_start();

// Claves conocidas que queremos limpiar de forma explícita
$keys = array(
    'usuario', 'usuario_id', 'usuario_email', 'usuario_nombre', 'usuario_rol', 'nombre', 'email',
    'tipo_global', 'rol', 'rol_evento', 'es_admin', 'admin_id', 'user_id', 'evento_id',
    'usuario_lista', 'usuario_clientes', 'token', 'uid'
);
foreach ($keys as $k) {
    if (isset($_SESSION[$k])) {
        unset($_SESSION[$k]);
    }
}

// Limpiar todo el array de sesión por las dudas
$_SESSION = array();

// Borrar cookie de sesión si existe
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

session_destroy();

// Volver al login
header('Location: login.php');
exit;
