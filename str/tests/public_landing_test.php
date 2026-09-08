<?php
function landing_assert($condition, $message)
{
    if (!$condition) { fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL); exit(1); }
    echo 'PASS: ' . $message . PHP_EOL;
}

$landing = file_get_contents(__DIR__ . '/../conocer_tickex.php');
$plansPath = __DIR__ . '/../inc/subscriptions.php';
$plans = is_file($plansPath) ? file_get_contents($plansPath) : '';

landing_assert(strpos($landing, 'Todo tu evento') !== false && strpos($landing, 'bajo control') !== false, 'landing opens with a clear product promise');
foreach (array('Creá y publicá','Vendé y emití','Organizá el equipo','Medí y comunicá') as $stage) {
    landing_assert(strpos($landing, $stage) !== false, 'product journey includes: ' . $stage);
}
landing_assert(strpos($landing, 'Mercado Pago') !== false && strpos($landing, 'separa su comisión') !== false, 'payment flow explains organizer collection and Tickex fee');
landing_assert(strpos($landing, 'hasta 300 QR/mes') !== false, 'landing publishes the initial plan capacity');
landing_assert(strpos($landing, 'hasta 2.000 QR/mes') !== false, 'landing publishes the growth plan capacity');
if ($plans !== '') {
    landing_assert(strpos($plans, "array('initial','Inicial'") !== false && strpos($plans, "array('growth','Crecimiento'") !== false, 'published plans match the configured product plans');
}
landing_assert(strpos($landing, 'logos de clientes') === false && strpos($landing, 'testimonio') === false, 'landing does not invent customer proof');
landing_assert(substr_count($landing, 'mailto:info@tickex.com.ar') >= 4, 'commercial calls to action use the public Tickex contact');
foreach (array('login_admin.php','login.php') as $route) {
    landing_assert(strpos($landing, $route) !== false, 'landing links to existing route: ' . $route);
}
landing_assert(strpos($landing, 'legal.php') === false && strpos($landing, 'arrepentimiento.php') === false, 'landing does not expose legal routes before their deployment');
landing_assert(strpos($landing, '@media(max-width:980px)') !== false && strpos($landing, '@media(max-width:680px)') !== false, 'landing includes tablet and mobile layouts');
landing_assert(strpos($landing, 'tickex-isotipo.svg') !== false && strpos($landing, '--green:#32c864') !== false, 'landing uses the Tickex symbol and brand green');
landing_assert(strpos($landing, 'Aplicación web disponible') !== false && strpos($landing, 'App móvil próximamente en Google Play') !== false, 'landing distinguishes the available web app from the future mobile app');
landing_assert(strpos($landing, 'computadora, tablet o celular') !== false, 'landing presents Tickex as multi-device event software');

echo "ALL PUBLIC LANDING TESTS PASSED\n";
