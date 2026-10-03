<?php
require_once __DIR__.'/../inc/mobile_admin_payments.php';
function payment_settings_ok($value,$label){if(!$value){fwrite(STDERR,'FAIL: '.$label.PHP_EOL);exit(1);}echo 'PASS: '.$label.PHP_EOL;}
$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE mercadopago_marketplace_accounts(admin_id INTEGER,status TEXT,expires_at TEXT,connected_at TEXT,access_token_enc TEXT,refresh_token_enc TEXT)');
$pdo->exec('CREATE TABLE mercadopago_admin_policies(admin_id INTEGER,account_type TEXT)');
$pdo->exec('CREATE TABLE mercadopago_platform_settings(id INTEGER,enforcement_enabled INTEGER)');
$pdo->exec('CREATE TABLE mercadopago_event_configs(event_id INTEGER,provider TEXT)');
$pdo->exec('CREATE TABLE eventos(id INTEGER,nombre TEXT,fecha_desde TEXT,creado_por_admin_id INTEGER,borrado_en TEXT)');
$pdo->exec("INSERT INTO mercadopago_marketplace_accounts VALUES(3,'connected','2099-01-01 00:00:00','2026-01-01','SECRET_ACCESS','SECRET_REFRESH'),(4,'connected','2020-01-01 00:00:00','2020-01-01','OTHER_SECRET','OTHER_REFRESH'); INSERT INTO mercadopago_admin_policies VALUES(3,'client'),(4,'str_owner'); INSERT INTO mercadopago_platform_settings VALUES(1,1); INSERT INTO mercadopago_event_configs VALUES(8,'totalcoin'),(9,'totalcoin'); INSERT INTO eventos VALUES(8,'Propio','2026-12-01',3,NULL),(9,'Ajeno','2026-12-02',4,NULL)");
$owner=array('id'=>3,'tipo_global'=>'admin_evento');$other=array('id'=>4,'tipo_global'=>'admin_evento');
$result=tickex_mobile_admin_payment_settings($pdo,$owner);
payment_settings_ok($result['connection']==='connected'&&$result['accountType']==='client'&&$result['commercialEnabled'],'own connection and policy');
payment_settings_ok(count($result['events'])===1&&$result['events'][0]['eventId']==='8'&&$result['events'][0]['provider']==='mercadopago','client provider forced and events scoped');
payment_settings_ok(strpos(json_encode($result),'SECRET')===false,'no credential value is serialized');
$otherResult=tickex_mobile_admin_payment_settings($pdo,$other);
payment_settings_ok($otherResult['connection']==='expired'&&$otherResult['events'][0]['provider']==='totalcoin','internal account provider and expiry');
payment_settings_ok(tickex_mobile_admin_payment_settings($pdo,array('id'=>5,'tipo_global'=>'staff'))['error']==='forbidden','staff denied');
echo 'ALL MOBILE PAYMENT SETTINGS TESTS PASSED'.PHP_EOL;
