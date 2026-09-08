<?php
require_once __DIR__.'/../inc/organizer_site.php';
function site_ok($condition,$message){if(!$condition){fwrite(STDERR,'FAIL: '.$message.PHP_EOL);exit(1);}echo 'PASS: '.$message.PHP_EOL;}
$db=tempnam(sys_get_temp_dir(),'tickex-site-');$pdo=new PDO('sqlite:'.$db);$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE eventos(id INTEGER PRIMARY KEY, publicado_site INTEGER)');
tickex_organizer_site_ensure_schema($pdo);
$columns=$pdo->query("PRAGMA table_info('clientes_sites')")->fetchAll(PDO::FETCH_COLUMN,1);
foreach(array('primary_color','accent_color','background_color','logo_url','custom_domain','custom_domain_status','white_label_enabled') as $column)site_ok(in_array($column,$columns,true),'schema includes '.$column);
site_ok(tickex_organizer_site_slug(' Fiesta Ñandú 2026! ')==='fiesta-and-2026','public slug is normalized predictably');
site_ok(tickex_organizer_site_color('#AABBCC','#000000')==='#aabbcc','valid brand colors are normalized');
site_ok(tickex_organizer_site_color('red','#112233')==='#112233','invalid colors use safe fallback');
site_ok(tickex_organizer_site_asset_url('/uploads/logo.svg')==='/uploads/logo.svg','local logo paths are accepted');
$badLogo=false;try{tickex_organizer_site_asset_url('javascript:alert(1)');}catch(Exception $e){$badLogo=true;}site_ok($badLogo,'unsafe logo protocols are rejected');
site_ok(tickex_organizer_site_domain('https://entradas.productora.com/')==='entradas.productora.com','custom domains are normalized');
$badDomain=false;try{tickex_organizer_site_domain('productora.com/eventos');}catch(Exception $e){$badDomain=true;}site_ok($badDomain,'custom domains cannot include paths');
$pdo->exec("INSERT INTO clientes_sites(admin_id,slug_publico,nombre_publico,visible,custom_domain,custom_domain_status) VALUES(9,'productora','Productora',1,'entradas.productora.com','pending')");
$duplicateDomain=false;try{$pdo->exec("INSERT INTO clientes_sites(admin_id,slug_publico,nombre_publico,visible,custom_domain,custom_domain_status) VALUES(10,'otra','Otra',1,'entradas.productora.com','pending')");}catch(Exception $e){$duplicateDomain=true;}site_ok($duplicateDomain,'custom domains remain unique on legacy SQLite');
site_ok(tickex_organizer_site_slug_from_host($pdo,'productora.tickex.com.ar')==='productora','Tickex subdomain resolves its organizer slug');
site_ok(tickex_organizer_site_slug_from_host($pdo,'entradas.productora.com')==='','pending custom domains are never routed');
$pdo->exec("UPDATE clientes_sites SET custom_domain_status='verified'");
site_ok(tickex_organizer_site_slug_from_host($pdo,'entradas.productora.com')==='productora','only verified custom domains are routed');
$site=tickex_organizer_site_by_admin($pdo,9);site_ok(tickex_organizer_site_public_url($site,true)==='https://entradas.productora.com/','verified domain becomes the canonical public URL');
$pdo->exec("UPDATE clientes_sites SET custom_domain_status='pending'");$site=tickex_organizer_site_by_admin($pdo,9);site_ok(tickex_organizer_site_public_url($site,true)==='https://str.tickex.com.ar/site.php?slug=productora','stable Tickex URL remains canonical until domain verification');
@unlink($db);echo 'ALL ORGANIZER SITE TESTS PASSED'.PHP_EOL;
