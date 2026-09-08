<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/google_identity.php';

$context = isset($_GET['context']) && in_array($_GET['context'], array('admin', 'buyer'), true)
    ? (string)$_GET['context']
    : 'unified';
$next = isset($_GET['next']) ? (string)$_GET['next'] : '';
try {
    header('Cache-Control: no-store');
    header('Location: ' . tickex_google_begin($context, $next), true, 302);
    exit;
} catch (Exception $e) {
    error_log('Tickex Google login start: ' . $e->getMessage());
    $target = $context === 'admin' ? 'login_admin.php' : 'login.php';
    header('Location: ' . $target . '?google_error=' . rawurlencode($e->getMessage()), true, 302);
    exit;
}
