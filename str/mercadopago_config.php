<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/mercadopago_marketplace.php';
require_once __DIR__ . '/inc/event_lifecycle.php';

require_login();
$title = 'Mercado Pago';
$pdo = db();
$cu = current_user();
$adminId = isset($cu['id']) ? (int)$cu['id'] : 0;
$role = isset($cu['tipo_global']) ? (string)$cu['tipo_global'] : '';
if ($adminId <= 0 || !in_array($role, array('admin_evento', 'super_admin', 'superadmin'), true)) abort_404('No tenes permiso.');
$isSuper = in_array($role, array('super_admin', 'superadmin'), true);
tickex_mp_ensure_schema($pdo);

$error = isset($_SESSION['mp_flash_error']) ? (string)$_SESSION['mp_flash_error'] : '';
$ok = isset($_SESSION['mp_flash_ok']) ? (string)$_SESSION['mp_flash_ok'] : '';
unset($_SESSION['mp_flash_error'], $_SESSION['mp_flash_ok']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_event'])) {
    if (!tickex_csrf_verify(isset($_POST['_csrf']) ? $_POST['_csrf'] : '')) {
        $error = 'La sesion vencio. Recarga la pagina.';
    } else {
        try {
            $eventId = isset($_POST['event_id']) ? (int)$_POST['event_id'] : 0;
            $provider = isset($_POST['provider']) ? (string)$_POST['provider'] : 'totalcoin';
            tickex_mp_save_event_config($pdo, $eventId, $adminId, $provider, 0);
            $ok = 'Medio de pago actualizado para el evento.';
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_platform'])) {
    if (!$isSuper) {
        $error = 'Solo el superadministrador puede cambiar la politica de cobros.';
    } elseif (!tickex_csrf_verify(isset($_POST['_csrf']) ? $_POST['_csrf'] : '')) {
        $error = 'La sesion vencio. Recarga la pagina.';
    } else {
        try {
            $totalTarget = isset($_POST['total_cost_target_percent']) ? (float)str_replace(',', '.', (string)$_POST['total_cost_target_percent']) : 10;
            $mpEstimate = isset($_POST['mp_cost_estimate_percent']) ? (float)str_replace(',', '.', (string)$_POST['mp_cost_estimate_percent']) : 0;
            tickex_mp_save_platform_settings($pdo, $totalTarget, $mpEstimate, !empty($_POST['enforcement_enabled']), $adminId);
            $ok = 'Politica general de cobros actualizada.';
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_policy'])) {
    if (!$isSuper) {
        $error = 'Solo el superadministrador puede cambiar el tipo de cuenta.';
    } elseif (!tickex_csrf_verify(isset($_POST['_csrf']) ? $_POST['_csrf'] : '')) {
        $error = 'La sesion vencio. Recarga la pagina.';
    } else {
        try {
            $policyAdminId = isset($_POST['policy_admin_id']) ? (int)$_POST['policy_admin_id'] : 0;
            if ($policyAdminId <= 0) throw new RuntimeException('Administrador invalido.');
            tickex_mp_save_admin_policy($pdo, $policyAdminId, isset($_POST['account_type']) ? (string)$_POST['account_type'] : 'client', isset($_POST['fee_override']) ? $_POST['fee_override'] : '', $adminId);
            $ok = 'Politica del administrador actualizada.';
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['disconnect'])) {
    if (!tickex_csrf_verify(isset($_POST['_csrf']) ? $_POST['_csrf'] : '')) {
        $error = 'La sesion vencio. Recarga la pagina.';
    } else {
        $st = $pdo->prepare("UPDATE mercadopago_marketplace_accounts SET status='disconnected', access_token_enc=NULL, refresh_token_enc=NULL, updated_at=CURRENT_TIMESTAMP WHERE admin_id=:admin");
        $st->execute(array(':admin' => $adminId));
        $ok = 'Cuenta desconectada. Las ventas Mercado Pago quedaron pausadas y no pasarán a TotalCoin.';
    }
}

$account = tickex_mp_account($pdo, $adminId, false);
$mpRuntimeConfig = tickex_mp_config();
$configured = tickex_mp_configured($mpRuntimeConfig);
$isSandbox = !empty($mpRuntimeConfig['sandbox']);
$settings = tickex_mp_platform_settings($pdo);
$policy = tickex_mp_admin_policy($pdo, $adminId);
$effectiveFee = tickex_mp_effective_platform_fee_percent($settings, $policy);
$effectiveServiceCharge = tickex_mp_effective_service_charge_percent($settings, $policy);
if (!$isSuper && $policy['account_type'] !== 'str_owner') {
    $commercialTerms = tickex_subscription_commercial_terms($pdo, $adminId, 15.0);
    $effectiveServiceCharge = 15.0;
    $serviceShareOfCheckout = $effectiveServiceCharge > 0 ? ($effectiveServiceCharge / (100 + $effectiveServiceCharge)) * 100 : 0;
    $organizerShareOfCheckout = ((float)$commercialTerms['organizer_share_percent'] / (100 + $effectiveServiceCharge)) * 100;
    $effectiveFee = max(0, round(max((float)$commercialTerms['tickex_min_checkout_percent'], $serviceShareOfCheckout - (float)$settings['mp_cost_estimate_percent'] - $organizerShareOfCheckout), 4));
}
$stEvents = $pdo->prepare("SELECT e.id,e.nombre,e.slug,e.fecha_desde,e.fecha_hasta,c.provider,c.marketplace_fee_percent FROM eventos e LEFT JOIN mercadopago_event_configs c ON c.event_id=e.id WHERE e.creado_por_admin_id=:admin AND (e.borrado_en IS NULL) ORDER BY e.id DESC");
$stEvents->execute(array(':admin' => $adminId));
$events = $stEvents->fetchAll(PDO::FETCH_ASSOC);
$currentEvents = array();
$pastEvents = array();
$eventConfigs = array();
$mercadoPagoEventCount = 0;
foreach ($events as $eventRow) {
    $eventId = (int)$eventRow['id'];
    $eventConfig = tickex_mp_event_config($pdo, $eventId);
    $eventConfigs[$eventId] = $eventConfig;
    if ((string)$eventConfig['provider'] === 'mercadopago') $mercadoPagoEventCount++;
    if (tickex_event_is_current($eventRow)) $currentEvents[] = $eventRow;
    else $pastEvents[] = $eventRow;
}

$accountConnected = $account && isset($account['status']) && (string)$account['status'] === 'connected';
$accountExpiresAt = $accountConnected && !empty($account['expires_at']) ? strtotime((string)$account['expires_at'] . ' UTC') : false;
$accountExpired = $accountConnected && $accountExpiresAt !== false && $accountExpiresAt < time();
$accountExpiresSoon = $accountConnected && !$accountExpired && $accountExpiresAt !== false && $accountExpiresAt < time() + (30 * 86400);
$commercialEnabled = !empty($settings['enforcement_enabled']);
$integrationReady = $configured && $accountConnected && !$accountExpired && ($policy['account_type'] === 'str_owner' || $commercialEnabled);

$recentMpOrders = array();
$approvedMpOrders = 0;
try {
    $stRecent = $pdo->prepare("SELECT o.id,o.evento_id,o.ref,o.amount,o.ticket_subtotal,o.service_fee_amount,o.marketplace_fee,o.payment_status,o.state,o.provider_payment_id,o.created_at,o.updated_at,e.nombre AS evento_nombre FROM tc_orders o INNER JOIN eventos e ON e.id=o.evento_id WHERE e.creado_por_admin_id=:admin AND o.payment_provider='mercadopago' ORDER BY o.id DESC LIMIT 6");
    $stRecent->execute(array(':admin' => $adminId));
    $recentMpOrders = $stRecent->fetchAll(PDO::FETCH_ASSOC);
    $stApproved = $pdo->prepare("SELECT COUNT(*) FROM tc_orders o INNER JOIN eventos e ON e.id=o.evento_id WHERE e.creado_por_admin_id=:admin AND o.payment_provider='mercadopago' AND o.payment_status='confirmed'");
    $stApproved->execute(array(':admin' => $adminId));
    $approvedMpOrders = (int)$stApproved->fetchColumn();
} catch (Exception $_mpOrdersError) {
    $recentMpOrders = array();
    $approvedMpOrders = 0;
}
$adminPolicies = array();
if ($isSuper) {
    try {
        $rows = $pdo->query("SELECT id,nombre,email,tipo_global FROM usuarios_admin WHERE tipo_global IN ('admin_evento','super_admin','superadmin') ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $adminRow) {
            $adminRow['mp_policy'] = tickex_mp_admin_policy($pdo, (int)$adminRow['id']);
            $adminRow['mp_account'] = tickex_mp_account($pdo, (int)$adminRow['id'], false);
            $adminPolicies[] = $adminRow;
        }
    } catch (Exception $_adminListError) {
        $adminPolicies = array();
    }
}
$csrf = tickex_csrf_token();
include __DIR__ . '/inc/layout_top.php';
?>
<?php include __DIR__ . '/inc/mercadopago_config_view.php'; ?>
<?php include __DIR__ . '/inc/layout_bottom.php'; ?>
