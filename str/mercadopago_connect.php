<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/mercadopago_marketplace.php';

require_login();
$cu = current_user();
$adminId = isset($cu['id']) ? (int)$cu['id'] : 0;
$role = isset($cu['tipo_global']) ? (string)$cu['tipo_global'] : '';
if ($adminId <= 0 || !in_array($role, array('admin_evento', 'super_admin', 'superadmin'), true)) {
    http_response_code(403);
    exit('Acceso denegado.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !tickex_csrf_verify(isset($_POST['_csrf']) ? $_POST['_csrf'] : '')) {
    http_response_code(400);
    exit('Solicitud invalida.');
}
try {
    $_SESSION['mp_onboarding_admin_id'] = isset($_POST['return_to']) && $_POST['return_to'] === 'empezar' ? $adminId : 0;
    $pdo = db();
    if (!empty($_SESSION['mp_onboarding_admin_id'])) {
        $ready = (bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='producer_onboarding_oauth_returns' LIMIT 1")->fetchColumn();
        if (!$ready) throw new RuntimeException('El alta de productores está en preparación. Intentá nuevamente más tarde.');
    }
    $url = tickex_mp_begin_oauth($pdo, $adminId);
    if (!empty($_SESSION['mp_onboarding_admin_id'])) {
        // Bind the return host to the server-issued OAuth state, not a callback query parameter.
        $parts = array(); parse_str((string)parse_url($url, PHP_URL_QUERY), $parts);
        $host = strtolower(isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '');
        if (!in_array($host, array('www.tickex.com.ar','tickex.com.ar','str.tickex.com.ar'), true)) $host = 'www.tickex.com.ar';
        if (empty($parts['state'])) throw new RuntimeException('No se pudo preparar la autorización.');
        $st = $pdo->prepare('INSERT OR REPLACE INTO producer_onboarding_oauth_returns(state_hash,return_url) VALUES(?,?)');
        $st->execute(array(hash('sha256',$parts['state']), 'https://'.$host.'/empezar.php'));
    }
    header('Cache-Control: no-store');
    header('Location: ' . $url, true, 303);
    exit;
} catch (Exception $e) {
    $_SESSION['mp_flash_error'] = $e->getMessage();
    header('Location: ' . (!empty($_SESSION['mp_onboarding_admin_id']) ? 'empezar.php' : 'mercadopago_config.php'), true, 303);
    exit;
}

