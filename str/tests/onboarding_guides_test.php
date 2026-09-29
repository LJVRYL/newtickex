<?php
function guide_assert($condition, $message)
{
    if (!$condition) { fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL); exit(1); }
    echo 'PASS: ' . $message . PHP_EOL;
}

$guides = file_get_contents(__DIR__ . '/../guias.php');
$support = file_get_contents(__DIR__ . '/../soporte.php');
$nav = file_get_contents(__DIR__ . '/../inc/nav.php');

guide_assert(strpos($guides, 'require_login()') !== false && strpos($guides, 'admin_evento') !== false, 'organizer guides require an administrative session');
foreach (array('Preparar tu cuenta','Crear y publicar un evento','Vender y emitir entradas','Organizar staff y puerta','Comunicar sin duplicados','Cerrar y revisar el evento') as $title) {
    guide_assert(strpos($guides, $title) !== false, 'guide exists: ' . $title);
}
guide_assert(strpos($guides, 'mercadopago_config.php') !== false && strpos($guides, 'enviar_tickex.php') !== false && strpos($guides, 'facturacion_admin.php') !== false, 'guides connect explanations with real workflows');
guide_assert(strpos($support, 'guias.php?guia=ventas') !== false && strpos($support, 'Ver todas las guías') !== false, 'support center opens the detailed guide library');
guide_assert(strpos($nav, "array('soporte.php','guias.php')") !== false, 'guide library keeps the help navigation context');

echo "ALL ONBOARDING GUIDE TESTS PASSED\n";
