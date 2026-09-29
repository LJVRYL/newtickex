<?php
require_once dirname(__DIR__).'/inc/communication_delivery_policy.php';
function passert($ok,$msg){if(!$ok){fwrite(STDERR,'FAIL: '.$msg.PHP_EOL);exit(1);}echo 'PASS: '.$msg.PHP_EOL;}
$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE usuarios_admin(id INTEGER PRIMARY KEY,email TEXT,nombre TEXT,apellido TEXT,tipo_global TEXT,activo INTEGER)');
$pdo->exec('CREATE TABLE communication_campaigns(id INTEGER PRIMARY KEY,created_by_admin_id INTEGER)');
$pdo->exec('CREATE TABLE communication_campaign_runs(id INTEGER PRIMARY KEY,campaign_id INTEGER)');
$pdo->exec('CREATE TABLE communication_campaign_run_recipients(id INTEGER PRIMARY KEY,run_id INTEGER,status TEXT,processed_at TEXT)');
$pdo->exec('CREATE TABLE communication_execution_commands(id INTEGER PRIMARY KEY,campaign_id INTEGER,status TEXT)');
communication_delivery_policy_ensure_schema($pdo);
$p=communication_delivery_policy_get($pdo,7);passert((int)$p['enforcement_enabled']===0,'default policy observes without blocking');
$a=communication_delivery_policy_allowance($pdo,7,500);passert((int)$a['allowed']===500,'observation mode preserves existing campaign volume');
communication_delivery_policy_save($pdo,7,array('enforcement_enabled'=>1,'hourly_limit'=>2,'daily_limit'=>3,'max_attempts'=>3,'retry_base_seconds'=>60),1);
$pdo->exec('INSERT INTO communication_campaigns VALUES(1,7),(2,8); INSERT INTO communication_campaign_runs VALUES(1,1),(2,2);');
$pdo->exec("INSERT INTO communication_campaign_run_recipients VALUES(1,1,'accepted',CURRENT_TIMESTAMP),(2,1,'accepted',CURRENT_TIMESTAMP),(3,2,'accepted',CURRENT_TIMESTAMP)");
$a=communication_delivery_policy_allowance($pdo,7,20);passert((int)$a['allowed']===0 && $a['reason']==='rate_limit','hourly limit is isolated and enforced per organizer');
$retry=communication_delivery_policy_retry_decision('transient_error',1,communication_delivery_policy_get($pdo,7));passert($retry['retry'] && $retry['status']==='queued' && (int)$retry['delay_seconds']===60,'transient failure is scheduled for retry');
$retry=communication_delivery_policy_retry_decision('transient_error',3,communication_delivery_policy_get($pdo,7));passert(!$retry['retry'] && $retry['status']==='transient_error','retry stops at configured maximum');
$accepted=communication_delivery_policy_retry_decision('accepted',1,communication_delivery_policy_get($pdo,7));passert(!$accepted['retry'] && $accepted['status']==='accepted','accepted email is never retried');
communication_delivery_policy_save($pdo,8,array('enforcement_enabled'=>1,'paused'=>1,'hourly_limit'=>10,'daily_limit'=>20,'max_attempts'=>2,'retry_base_seconds'=>30),1);
$a=communication_delivery_policy_allowance($pdo,8,5);passert((int)$a['allowed']===0 && $a['reason']==='paused','organizer campaigns can be paused independently');
$pdo->exec("INSERT INTO usuarios_admin VALUES(7,'organizador@example.com','Organizador','Demo','admin_evento',1),(8,'otro@example.com','Otro','Demo','admin_evento',1),(9,'super@example.com','Super','Admin','super_admin',1)");
$rows=communication_delivery_policy_dashboard($pdo);passert(count($rows)===2,'dashboard lists active organizers only');
passert((int)$rows[0]['sent_day']+(int)$rows[1]['sent_day']===3,'dashboard reports isolated delivery usage');
echo 'ALL COMMUNICATION DELIVERY POLICY TESTS PASSED'.PHP_EOL;
