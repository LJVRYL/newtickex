<?php
require_once __DIR__.'/../inc/mobile_admin_account.php';
function account_ok($value,$label){if(!$value){fwrite(STDERR,'FAIL: '.$label.PHP_EOL);exit(1);}echo 'PASS: '.$label.PHP_EOL;}
$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE usuarios_admin(id INTEGER PRIMARY KEY,nombre TEXT,apellido TEXT,email TEXT,dni TEXT,cbu TEXT,apodo TEXT)');
$pdo->exec('CREATE TABLE registro_pendientes(id INTEGER PRIMARY KEY,apodo TEXT)');
$pdo->exec('CREATE TABLE subscription_settings(id INTEGER PRIMARY KEY,enforcement_enabled INTEGER)');
$pdo->exec('CREATE TABLE subscription_plans(id INTEGER PRIMARY KEY,code TEXT,name TEXT,description TEXT,monthly_price REAL,organizer_share_percent REAL,qr_limit_monthly INTEGER,features_json TEXT)');
$pdo->exec('CREATE TABLE admin_subscriptions(admin_id INTEGER,plan_id INTEGER,status TEXT,ends_at TEXT,limit_exempt INTEGER)');
$pdo->exec('CREATE TABLE eventos(id INTEGER PRIMARY KEY,creado_por_admin_id INTEGER)');
$pdo->exec('CREATE TABLE entradas(id INTEGER PRIMARY KEY,evento_id INTEGER,oculto INTEGER,fecha_registro TEXT)');
$pdo->exec("INSERT INTO usuarios_admin VALUES(3,'Ana','QA','ana@example.invalid','12345678',NULL,'ana'),(4,'Beto','QA','beto@example.invalid','87654321',NULL,'beto'); INSERT INTO registro_pendientes VALUES(1,'cliente'); INSERT INTO subscription_settings VALUES(1,0); INSERT INTO subscription_plans VALUES(2,'inicio','Inicial','Plan QA',0,0,10,'[\"Eventos\",\"QR\"]'); INSERT INTO admin_subscriptions VALUES(3,2,'active',NULL,0); INSERT INTO eventos VALUES(8,3),(9,4); INSERT INTO entradas VALUES(1,8,0,datetime('now')),(2,9,0,datetime('now'))");
$owner=array('id'=>3,'tipo_global'=>'admin_evento');$other=array('id'=>4,'tipo_global'=>'admin_evento');
$before=tickex_mobile_admin_account($pdo,$owner);
account_ok($before['email']==='ana@example.invalid'&&$before['dni']==='12345678'&&$before['version']!=='','own profile read');
account_ok(tickex_mobile_admin_account($pdo,$other)['email']==='beto@example.invalid','other profile isolated');
account_ok(tickex_mobile_admin_account_save($pdo,$owner,array('expectedVersion'=>'stale','firstName'=>'Cambio'))['error']==='conflict','stale profile rejected');
account_ok(tickex_mobile_admin_account_save($pdo,$owner,array('expectedVersion'=>$before['version'],'email'=>'beto@example.invalid'))['error']==='email_taken','duplicate admin email rejected');
account_ok(tickex_mobile_admin_account_save($pdo,$owner,array('expectedVersion'=>$before['version'],'tickexId'=>'cliente'))['error']==='tickex_id_taken','buyer Tickex ID conflict rejected');
account_ok(tickex_mobile_admin_account_save($pdo,$owner,array('expectedVersion'=>$before['version'],'cbu'=>'abc'))['error']==='invalid_cbu','CBU format checked');
$after=tickex_mobile_admin_account_save($pdo,$owner,array('expectedVersion'=>$before['version'],'firstName'=>'Ana María','cbu'=>'1234567890123456789012'));
account_ok($after['firstName']==='Ana María'&&$after['cbu']==='1234567890123456789012'&&$after['version']!==$before['version'],'profile update persisted');
account_ok(tickex_mobile_admin_account($pdo,$other)['firstName']==='Beto','other account unchanged');
$plan=tickex_mobile_admin_plan($pdo,$owner);
account_ok($plan['assigned']&&$plan['name']==='Inicial'&&$plan['used']===1&&$plan['remaining']===9&&$plan['observing'],'own plan and monthly usage');
account_ok(tickex_mobile_admin_plan($pdo,array('id'=>9,'tipo_global'=>'super_admin'))['error']==='forbidden','superadministrator cannot read producer plan');
account_ok(tickex_mobile_admin_plan($pdo,$other)['assigned']===false,'unassigned plan is explicit');
echo 'ALL MOBILE ACCOUNT TESTS PASSED'.PHP_EOL;
