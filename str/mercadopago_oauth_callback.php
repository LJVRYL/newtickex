<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/mercadopago_marketplace.php';
require_once __DIR__ . '/inc/mobile_admin_payments.php';

$returnToOnboarding = !empty($_SESSION['mp_onboarding_admin_id']) && isset($_SESSION['user_id']) && (int)$_SESSION['mp_onboarding_admin_id'] === (int)$_SESSION['user_id'];
unset($_SESSION['mp_onboarding_admin_id']);
$state = isset($_GET['state']) ? trim((string)$_GET['state']) : '';
$code = isset($_GET['code']) ? trim((string)$_GET['code']) : '';
$error = isset($_GET['error']) ? trim((string)$_GET['error']) : '';
$onboardingReturn = '';
$connectionFailed = false;
$mobileReturnAdminId = 0;
try {
    $pdo = db();
    $mobileReturnAdminId = tickex_mobile_mp_return_admin($pdo, $state);
    // The session on www is deliberately not shared with other subdomains.
    $hasOnboardingReturn = (bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='producer_onboarding_oauth_returns' LIMIT 1")->fetchColumn();
    if ($hasOnboardingReturn) {
        $st = $pdo->prepare("SELECT r.return_url FROM producer_onboarding_oauth_returns r JOIN mercadopago_oauth_states s ON s.state_hash=r.state_hash WHERE s.state_hash=? AND s.used_at IS NULL AND s.expires_at>=CURRENT_TIMESTAMP");
        $st->execute(array(hash('sha256',$state)));
        $savedReturn = (string)$st->fetchColumn();
        if (in_array($savedReturn, array('https://www.tickex.com.ar/empezar.php','https://tickex.com.ar/empezar.php','https://str.tickex.com.ar/empezar.php'), true)) $onboardingReturn=$savedReturn;
    }
    if ($error !== '') throw new RuntimeException('Mercado Pago no autorizo la vinculacion: ' . $error);
    if ($state === '' || $code === '') throw new RuntimeException('La respuesta de Mercado Pago esta incompleta.');
    $pdo = db();
    $adminId = tickex_mp_consume_oauth_state($pdo, $state);
    if ($adminId <= 0) throw new RuntimeException('La autorizacion vencio o ya fue utilizada. Volve a conectar la cuenta.');
    if ($mobileReturnAdminId > 0 && $adminId !== $mobileReturnAdminId) throw new RuntimeException('La autorizacion no corresponde a esta cuenta.');
    $tokens = tickex_mp_exchange_oauth_code($code, $state);
    tickex_mp_save_account_tokens($pdo, $adminId, $tokens);
    $_SESSION['mp_flash_ok'] = 'Cuenta de Mercado Pago conectada correctamente.';
} catch (Exception $e) {
    $connectionFailed = true;
    $_SESSION['mp_flash_error'] = $e->getMessage();
}
if ($mobileReturnAdminId > 0) {
    tickex_mobile_mp_clear_return($pdo, $state);
    header('Cache-Control: no-store');
    header('Location: ' . tickex_mobile_mp_return_location(!$connectionFailed), true, 303);
    exit;
}
header('Location: ' . ($onboardingReturn !== '' ? $onboardingReturn . ($connectionFailed ? '?mp_error=1' : '') : ($returnToOnboarding ? 'empezar.php' : 'mercadopago_config.php')), true, 303);
exit;
