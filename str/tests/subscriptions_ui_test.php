<?php
function sub_ui_ok($condition,$message){if(!$condition){fwrite(STDERR,'FAIL: '.$message.PHP_EOL);exit(1);}echo 'PASS: '.$message.PHP_EOL;}
$super=file_get_contents(__DIR__.'/../superadmin_suscripciones.php');
$client=file_get_contents(__DIR__.'/../suscripcion.php');
$nav=file_get_contents(__DIR__.'/../inc/nav.php');
$dashboard=file_get_contents(__DIR__.'/../inc/superadmin_dashboard_view.php');
sub_ui_ok(strpos($super,'tickex_is_super_admin')!==false,'subscription management is restricted to superadministrators');
sub_ui_ok(strpos($super,'tickex_csrf_verify')!==false&&strpos($super,'name="_csrf"')!==false,'subscription mutations require CSRF protection');
sub_ui_ok(strpos($super,'Catálogo editable')!==false&&strpos($super,'Suscriptores')!==false,'superadministrator sees plans and subscribers in separate sections');
sub_ui_ok(strpos($super,'limit_exempt')!==false,'superadministrator can exempt an internal organizer from QR limits');
sub_ui_ok(strpos($client,'$role!==\'admin_evento\'')!==false,'plan detail is restricted to the current organizer');
sub_ui_ok(strpos($client,'Modo observación activo')!==false,'organizer sees when limits are informational');
sub_ui_ok(strpos($nav,'superadmin_suscripciones.php')!==false&&strpos($nav,'suscripcion.php')!==false,'both roles receive the correct plan navigation');
sub_ui_ok(strpos($dashboard,'Planes y suscripciones')!==false,'platform dashboard links to subscription management');
echo 'ALL SUBSCRIPTION UI TESTS PASSED'.PHP_EOL;
