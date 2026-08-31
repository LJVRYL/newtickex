<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/mercadopago_marketplace.php';

$state = isset($_GET['state']) ? trim((string)$_GET['state']) : '';
$code = isset($_GET['code']) ? trim((string)$_GET['code']) : '';
$error = isset($_GET['error']) ? trim((string)$_GET['error']) : '';
try {
    if ($error !== '') throw new RuntimeException('Mercado Pago no autorizo la vinculacion: ' . $error);
    if ($state === '' || $code === '') throw new RuntimeException('La respuesta de Mercado Pago esta incompleta.');
    $pdo = db();
    $adminId = tickex_mp_consume_oauth_state($pdo, $state);
    if ($adminId <= 0) throw new RuntimeException('La autorizacion vencio o ya fue utilizada. Volve a conectar la cuenta.');
    $tokens = tickex_mp_exchange_oauth_code($code, $state);
    tickex_mp_save_account_tokens($pdo, $adminId, $tokens);
    $_SESSION['mp_flash_ok'] = 'Cuenta de Mercado Pago conectada correctamente.';
} catch (Exception $e) {
    $_SESSION['mp_flash_error'] = $e->getMessage();
}
header('Location: mercadopago_config.php', true, 303);
exit;
