<?php
$page = file_get_contents(__DIR__ . '/../mercadopago_config.php');
$view = file_get_contents(__DIR__ . '/../inc/mercadopago_config_view.php');

function mp_config_ui_assert($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }
    echo 'PASS: ' . $message . PHP_EOL;
}

mp_config_ui_assert(strpos($page, '$adminId = isset($cu[\'id\'])') !== false, 'Mercado Pago uses the current administrator identity');
mp_config_ui_assert(strpos($page, 'e.creado_por_admin_id=:admin') !== false, 'events and payment activity are scoped to the current administrator');
mp_config_ui_assert(strpos($page, 'tickex_event_is_current') !== false, 'current and finished events are separated');
mp_config_ui_assert(strpos($view, 'Aplicación Tickex') !== false && strpos($view, 'Cuenta vinculada') !== false && strpos($view, 'Pago verificado') !== false, 'the integration is presented as a guided checklist');
mp_config_ui_assert(strpos($view, 'Tickex nunca solicita la contraseña del organizador') !== false, 'OAuth connection is explained without requesting credentials');
mp_config_ui_assert(strpos($view, 'Desconectar no mueve eventos a TotalCoin') !== false, 'disconnect behavior is described accurately');
mp_config_ui_assert(strpos($view, 'Ver <?php echo count($pastEvents); ?> evento') !== false, 'finished events use progressive disclosure');
mp_config_ui_assert(strpos($view, 'Actividad reciente de Mercado Pago') !== false, 'recent payment activity is visible');
mp_config_ui_assert(strpos($view, 'sólo superadmin') !== false, 'platform policy is visually separated from organizer settings');
mp_config_ui_assert(strpos($view, 'client_secret') === false && strpos($view, 'access_token') === false, 'technical secrets are never rendered in the settings view');

echo 'ALL MERCADO PAGO CONFIG UI TESTS PASSED' . PHP_EOL;
