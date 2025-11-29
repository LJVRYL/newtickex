<?php
// inc/auth.php (PHP5-safe)
function require_login(){
    if (empty($_SESSION['usuario'])) {
        header("Location: login.php");
        exit;
    }
}

function current_user(){
    return isset($_SESSION['usuario']) ? $_SESSION['usuario'] : null;
}

function current_role_global(){
    return isset($_SESSION['tipo_global']) ? $_SESSION['tipo_global'] : '';
}

function current_role_evento(){
    return isset($_SESSION['rol_evento']) ? $_SESSION['rol_evento'] : '';
}

function require_roles($roles){
    $tg = current_role_global();
    if (!in_array($tg, $roles, true)) {
        header("Location: login.php");
        exit;
    }
}
