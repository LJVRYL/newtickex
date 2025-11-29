<?php
session_start();
date_default_timezone_set('America/Argentina/Buenos_Aires');

// Wrapper STR: este archivo solo redirige al panel del evento STR (id=1)
// Mantiene compatibilidad con links viejos.

// Logout centralizado
if (isset($_GET['logout'])) {
    $_SESSION = array();
    if (ini_get("session.use_cookies")) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $p["path"], $p["domain"], $p["secure"], $p["httponly"]
        );
    }
    session_destroy();
    header("Location: login.php");
    exit;
}

// Si no hay sesión, mandar a login unificado
if (empty($_SESSION['usuario'])) {
    header("Location: login.php");
    exit;
}

// Si es staff puerta, mandarlo a su panel (con evento asignado)
$tipoGlobal = isset($_SESSION['tipo_global']) ? $_SESSION['tipo_global'] : '';
$rolEvento  = isset($_SESSION['rol_evento']) ? $_SESSION['rol_evento'] : '';
$eventoId   = isset($_SESSION['evento_id']) ? (int)$_SESSION['evento_id'] : 0;
if ($eventoId <= 0) { $eventoId = 1; }

if ($tipoGlobal === 'staff_evento' && $rolEvento === 'puerta') {
    header("Location: puerta.php?evento_id=".$eventoId);
    exit;
}

// Admin / Superadmin => panel real del evento STR
header("Location: panel_evento.php?id=1");
exit;
