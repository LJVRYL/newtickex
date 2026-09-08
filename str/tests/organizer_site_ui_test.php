<?php
function site_ui_ok($condition,$message){if(!$condition){fwrite(STDERR,'FAIL: '.$message.PHP_EOL);exit(1);}echo 'PASS: '.$message.PHP_EOL;}
$root=dirname(__DIR__);$admin=file_get_contents($root.'/mi_sitio.php');$public=file_get_contents($root.'/site.php');$landing=file_get_contents($root.'/conocer_tickex.php');$super=file_get_contents($root.'/superadmin_sites.php');$nav=file_get_contents($root.'/inc/nav.php');
site_ui_ok(strpos($admin,'action" value="save_brand')!==false&&strpos($admin,'primary_color')!==false&&strpos($admin,'logo_url')!==false,'organizer can configure its visual identity');
site_ui_ok(strpos($admin,'action" value="save_domain')!==false&&strpos($admin,'Pendiente de verificación')!==false,'custom domain request has an explicit verification state');
site_ui_ok(strpos($admin,'Tu dirección estable')!==false&&strpos($admin,'queda reservado para la etapa de DNS')!==false,'admin distinguishes the working URL from the future subdomain');
site_ui_ok(strpos($public,'viewport-fit=cover')!==false&&strpos($public,'@media(max-width:620px)')!==false,'public organizer site has a mobile layout');
site_ui_ok(strpos($public,"WHERE publicado_site=1 AND creado_por_admin_id=:admin")!==false,'public events stay scoped to the organizer');
site_ui_ok(strpos($public,'tickex_site_checkout($slug,$event)')!==false,'public cards preserve the existing checkout route');
site_ui_ok(strpos($public,'BOTÓN DE ARREPENTIMIENTO')!==false&&strpos($public,'datos_personales.php')!==false,'public site preserves consumer rights links');
site_ui_ok(strpos($public,'Powered by Tickex')!==false&&strpos($public,"white_label_enabled")!==false,'Tickex branding remains until white label is explicitly enabled');
site_ui_ok(strpos($landing,'Plataforma para productores')!==false&&strpos($landing,'Quiero usar Tickex')!==false,'commercial landing explains the offer and provides a clear next action');
site_ui_ok(strpos($landing,'@media(max-width:680px)')!==false,'commercial landing is responsive');
site_ui_ok(strpos($super,"array('not_configured','pending','verified','rejected')")!==false&&strpos($super,'white_label_enabled')!==false,'superadministrator controls domain verification and white label');
site_ui_ok(strpos($nav,'superadmin_sites.php')!==false,'superadministrator navigation exposes site operations');
echo 'ALL ORGANIZER SITE UI TESTS PASSED'.PHP_EOL;
