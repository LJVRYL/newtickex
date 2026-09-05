<?php
if (PHP_SAPI !== 'cli') die("CLI only\n");

function repair_test_ok($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, 'FAIL: ' . $message . "\n");
        exit(1);
    }
    echo 'PASS: ' . $message . "\n";
}

require_once __DIR__ . '/../inc/legacy_package_repair.php';
require_once __DIR__ . '/../inc/manual_income.php';
require_once __DIR__ . '/../inc/unified_tickets.php';
$fixtureDb = sys_get_temp_dir() . '/tickex-repair-fixture-' . uniqid('', true) . '.sqlite';
$pdo = new PDO('sqlite:' . $fixtureDb);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE tipos_entrada (id INTEGER PRIMARY KEY,evento_id INTEGER,nombre TEXT,precio REAL,cantidad_total INTEGER,cantidad_disponible INTEGER,qr_quantity INTEGER)");
$pdo->exec("CREATE TABLE entradas (id INTEGER PRIMARY KEY AUTOINCREMENT,evento_id INTEGER,nombre TEXT,email TEXT,fecha_registro TEXT,codigo TEXT UNIQUE,checked_in INTEGER,checked_in_at TEXT,tipo TEXT,monto_pagado REAL,tc_order_request_id TEXT,issuance_key TEXT UNIQUE,oculto INTEGER DEFAULT 0)");
$pdo->exec("CREATE TABLE tc_orders (id INTEGER PRIMARY KEY AUTOINCREMENT,request_id TEXT UNIQUE,state TEXT,evento_id INTEGER,ref TEXT,concept TEXT,amount REAL,buyer_first TEXT,buyer_last TEXT,buyer_email TEXT,selected_tickets_json TEXT,created_at TEXT,updated_at TEXT,processed_at TEXT,payment_status TEXT,payment_confirmed_at TEXT,processing_status TEXT,email_status TEXT,payment_provider TEXT,service_fee_amount REAL DEFAULT 0)");
$pdo->exec("INSERT INTO tipos_entrada VALUES (39,15,'General Puerta',15000,0,60,1),(41,15,'Promo 2x1',25000,50,49,1),(42,15,'Promo 3x4',40000,50,48,1),(44,15,'Cortesia',0,100,100,1)");
$requestTwo = '94304388-6386-404e-a32f-5bf803602c96';
$requestFour = 'd0fa7a2d-9c4c-460c-97ba-0ec351e31ab4';
$pdo->exec("INSERT INTO tc_orders (id,request_id,state,evento_id,amount,buyer_first,buyer_email,selected_tickets_json,processed_at,payment_status,processing_status,email_status) VALUES
 (75,'$requestTwo','success',15,25000,'Ignacio','infantino@example.invalid','[{\"id\":\"41\",\"name\":\"Promo 2x1\",\"qty\":1,\"price\":25000}]','2026-08-20 23:35:56','pending','issued','sent'),
 (78,'$requestFour','success',15,80000,'Maria','maria@example.invalid','[{\"id\":\"42\",\"name\":\"Promo 3x4\",\"qty\":2,\"price\":40000}]','2026-08-27 06:40:42','confirmed','issued','sent')");
$stEntry = $pdo->prepare("INSERT INTO entradas (id,evento_id,nombre,email,fecha_registro,codigo,checked_in,tipo,monto_pagado,tc_order_request_id,issuance_key,oculto) VALUES (:id,15,:name,:email,CURRENT_TIMESTAMP,:code,0,:type,:amount,:request,:issuance,0)");
$stEntry->execute(array(':id'=>880,':name'=>'Ignacio',':email'=>'infantino@example.invalid',':code'=>'old-880',':type'=>'Promo 2x1',':amount'=>25000,':request'=>$requestTwo,':issuance'=>null));
$stEntry->execute(array(':id'=>882,':name'=>'Maria',':email'=>'maria@example.invalid',':code'=>'old-882',':type'=>'Promo 3x4',':amount'=>40000,':request'=>$requestFour,':issuance'=>$requestFour.':42:0'));
$stEntry->execute(array(':id'=>883,':name'=>'Maria',':email'=>'maria@example.invalid',':code'=>'old-883',':type'=>'Promo 3x4',':amount'=>40000,':request'=>$requestFour,':issuance'=>$requestFour.':42:1'));
$courtesyIds = array(884,885,886,888,889,890,891,892);
foreach ($courtesyIds as $id) $stEntry->execute(array(':id'=>$id,':name'=>'Cortesia',':email'=>'c'.$id.'@example.invalid',':code'=>'old-'.$id,':type'=>'Cortesia',':amount'=>0,':request'=>null,':issuance'=>null));
$stEntry->execute(array(':id'=>893,':name'=>'Facundo',':email'=>'facundo@example.invalid',':code'=>'old-893',':type'=>'Promo 3x4',':amount'=>0,':request'=>null,':issuance'=>null));

$preview = tickex_repair_event15_ticket_packages($pdo, false);
repair_test_ok(!empty($preview['dry_run']) && $preview['summary']['issued'] === 12, 'dry run leaves the twelve legacy rows untouched');
$result = tickex_repair_event15_ticket_packages($pdo, true);
repair_test_ok(!empty($result['applied']), 'repair is applied');
repair_test_ok($result['summary']['issued'] === 22, 'repair creates the ten missing QR entries');
repair_test_ok($result['summary']['paid_qr'] === 14 && $result['summary']['free_qr'] === 8, 'paid and courtesy QR counts are correct');
repair_test_ok(abs($result['summary']['revenue'] - 145000.0) < 0.001, 'repair includes the collected manual transfer');
repair_test_ok($result['summary']['stock_total'] === 260 && $result['summary']['available'] === 238, 'stock total and availability are reconciled');
repair_test_ok((int)$pdo->query('SELECT qr_quantity FROM tipos_entrada WHERE id=41')->fetchColumn() === 2, 'future 2x1 sales issue two QR entries');
repair_test_ok((int)$pdo->query('SELECT qr_quantity FROM tipos_entrada WHERE id=42')->fetchColumn() === 4, 'future 3x4 sales issue four QR entries');
repair_test_ok((string)$pdo->query('SELECT payment_status FROM tc_orders WHERE id=75')->fetchColumn() === 'confirmed', 'historical paid 2x1 order is marked confirmed');
$manualOrder = $pdo->query("SELECT amount,payment_provider FROM tc_orders WHERE request_id='manual-repair-event15-entry893'")->fetch(PDO::FETCH_ASSOC);
repair_test_ok(abs((float)$manualOrder['amount'] - 40000.0) < 0.001 && $manualOrder['payment_provider'] === 'manual_transfer', 'historical manual transfer is registered as paid income');

$stats = get_unified_stats($pdo, 15);
repair_test_ok($stats['total'] === 22 && $stats['paid'] === 14 && $stats['pendiente'] === 22, 'panel counts physical issued QR entries');
repair_test_ok($stats['stock_total'] === 260 && $stats['disponibles'] === 238, 'panel no longer adds issued QR entries to total stock');
$economic = get_economic_stats($pdo, 15);
repair_test_ok($economic['entradas_vendidas'] === 4, 'economy counts the three checkout packages and the manual transfer');
repair_test_ok(abs($economic['total_recaudado'] - 145000.0) < 0.001, 'gross collected total includes the transfer without subtracting costs');
repair_test_ok(abs($economic['resultado_neto_base'] - 141850.0) < 0.001, 'net result absorbs three percent only on TotalCoin revenue');

$again = tickex_repair_event15_ticket_packages($pdo, true);
repair_test_ok(!empty($again['already_applied']) && $again['summary']['issued'] === 22, 'repair is idempotent');
repair_test_ok((string)$pdo->query('PRAGMA integrity_check')->fetchColumn() === 'ok', 'database remains consistent');

$mailDb = $fixtureDb;
putenv('TICKEX_DB_FILE=' . $mailDb);
putenv('TICKEX_MAIL_TRANSPORT=fake');
putenv('TICKEX_SITE_URL=https://local.test');
require_once __DIR__ . '/../inc/db.php';
$mailPdo = db();
$mailResults = tickex_send_event15_package_repair_emails($mailPdo);
repair_test_ok(count($mailResults) === 3, 'one consolidated repair email is attempted per affected buyer/order');
repair_test_ok($mailResults[0]['quantity'] === 2 && $mailResults[1]['quantity'] === 8 && $mailResults[2]['quantity'] === 4, 'repair emails contain every QR in each package');
repair_test_ok((int)$mailPdo->query("SELECT COUNT(*) FROM email_logs WHERE context='entradas_reparacion_paquete' AND mail_ok=1")->fetchColumn() === 3, 'fake transport records all three consolidated repair emails');
$mailAgain = tickex_send_event15_package_repair_emails($mailPdo);
repair_test_ok($mailAgain[0]['status'] === 'already_sent' && $mailAgain[1]['status'] === 'already_sent' && $mailAgain[2]['status'] === 'already_sent', 'repair emails cannot be duplicated');
@unlink($mailDb);
echo "ALL LEGACY PACKAGE REPAIR TESTS PASSED\n";
