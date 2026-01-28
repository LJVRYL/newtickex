<?php
// inc/bootstrap.php (PHP5-safe)
// Asegurar path de sesiones escribible (WSL suele bloquear /var/lib/php/sessions)
$sp = ini_get('session.save_path');
if (!$sp || !is_writable($sp)) {
    $tmp = sys_get_temp_dir();
    if (is_dir($tmp) && is_writable($tmp)) {
        session_save_path($tmp);
    }
}
session_start();
date_default_timezone_set('America/Argentina/Buenos_Aires');

require_once __DIR__.'/db.php';
require_once __DIR__.'/auth.php';
require_once __DIR__.'/flash.php';

// helper escape (solo si no existe)
if (!function_exists('e')) {
    function e($s){
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('abort_404')) {
    function abort_404($msg){
        http_response_code(404);
        include __DIR__.'/layout_top.php';
        echo "<div class='card error'><h2>404</h2><p>".e($msg)."</p></div>";
        include __DIR__.'/layout_bottom.php';
        exit;
    }
}
