<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/google_identity.php';

header('Cache-Control: no-store');
$state = isset($_GET['state']) ? (string)$_GET['state'] : '';
$code = isset($_GET['code']) ? (string)$_GET['code'] : '';
try {
    if ($state === '' || $code === '') throw new RuntimeException('Google canceló o no completó el acceso.');
    $request = tickex_google_consume_state($state);
    $profile = tickex_google_exchange_code($code, $request['verifier']);
    $pdo = db();
    list($type, $account) = tickex_google_find_or_create_account($pdo, $profile, $request['context']);
    $default = tickex_google_establish_session($pdo, $type, $account);
    $next = tickex_google_safe_next($request['next']);
    header('Location: ' . ($next !== '' ? $next : $default), true, 303);
    exit;
} catch (Exception $e) {
    error_log('Tickex Google callback: ' . $e->getMessage());
    $context = isset($request['context']) && in_array($request['context'], array('admin', 'buyer'), true)
        ? (string)$request['context']
        : 'unified';
    $target = $context === 'admin' ? 'login_admin.php' : 'login.php';
    header('Location: ' . $target . '?google_error=' . rawurlencode($e->getMessage()), true, 303);
    exit;
}
