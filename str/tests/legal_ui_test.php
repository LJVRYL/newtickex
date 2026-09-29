<?php
function legal_ui_test($condition,$message){if(!$condition){fwrite(STDERR,'FAIL: '.$message.PHP_EOL);exit(1);}echo 'PASS: '.$message.PHP_EOL;}
$root=dirname(__DIR__);
$checkout=file_get_contents($root.'/checkout_totalcoin.php');
$site=file_get_contents($root.'/site.php');
$nav=file_get_contents($root.'/inc/nav.php');
$admin=file_get_contents($root.'/superadmin_legales.php');
legal_ui_test(strpos($checkout,'$legalCheckoutRequired && empty($_POST[\'legal_acceptance\'])')!==false,'checkout validates consent only after publication');
legal_ui_test(strpos($checkout,"'checkout:' . \$requestId")!==false&&strpos($checkout,"array('terms','privacy','refunds')")!==false,'checkout records accepted document versions against the order');
legal_ui_test(strpos($checkout,'id="chkLegal" disabled')!==false&&strpos($checkout,'chkLegal.required = on')!==false,'interactive checkout enables and requires legal consent at confirmation');
legal_ui_test(strpos($site,'BOTÓN DE ARREPENTIMIENTO')!==false&&strpos($site,'datos_personales.php')!==false,'public event site exposes consumer rights links');
legal_ui_test(strpos($nav,'superadmin_legales.php')!==false,'superadministrator navigation exposes the legal center');
legal_ui_test(strpos($admin,"action==='privacy'")!==false&&strpos($admin,'Solicitudes de arrepentimiento')!==false,'legal center manages both consumer workflows');
foreach(array('legal.php','arrepentimiento.php','datos_personales.php') as $file)legal_ui_test(is_file($root.'/'.$file),$file.' is available publicly');
echo 'ALL LEGAL UI TESTS PASSED'.PHP_EOL;
