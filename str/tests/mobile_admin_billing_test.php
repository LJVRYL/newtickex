<?php
require_once __DIR__ . '/../inc/mobile_events.php';
require_once __DIR__ . '/../inc/mobile_admin.php';
require_once __DIR__ . '/../inc/billing_financials.php';
require_once __DIR__ . '/../inc/mobile_admin_billing.php';
function billing_api_assert($ok, $message) { if (!$ok) { echo 'FAIL ' . $message . PHP_EOL; exit(1); } }
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE eventos(id INTEGER PRIMARY KEY,nombre TEXT,creado_por_admin_id INTEGER,borrado_en TEXT);CREATE TABLE tc_orders(id INTEGER PRIMARY KEY,request_id TEXT,state TEXT,evento_id INTEGER,amount REAL,buyer_first TEXT,buyer_last TEXT,buyer_email TEXT,created_at TEXT,payment_status TEXT,payment_provider TEXT,marketplace_fee REAL,ticket_subtotal REAL,service_fee_amount REAL,mp_cost_estimate_percent REAL)");
$pdo->exec("INSERT INTO eventos VALUES(1,'Evento A',10,NULL),(2,'Evento B',20,NULL);INSERT INTO tc_orders VALUES(1,'a','success',1,115,'SecretBuyer','QA','secret@example.invalid','2026-10-01','confirmed','mercadopago',5,100,15,3),(2,'b','success',2,500,'OtherBuyer','QA','other@example.invalid','2026-10-02','confirmed','mercadopago',10,450,50,3)");
$owner = array('id'=>10,'tipo_global'=>'admin_evento');
$result = tickex_mobile_admin_billing_report($pdo,$owner,array());
billing_api_assert($result['estimateOnly'] === true && $result['summary']['gross'] === 115.0, 'summary scoped to producer');
billing_api_assert(count($result['recent']) === 1 && $result['recent'][0]['orderId'] === '1', 'only owned order listed');
billing_api_assert(strpos(json_encode($result), 'secret@example.invalid') === false && strpos(json_encode($result), 'SecretBuyer') === false, 'no buyer PII in report');
billing_api_assert(tickex_mobile_admin_billing_report($pdo,$owner,array('eventId'=>2))['error'] === 'forbidden', 'foreign event filter denied');
billing_api_assert(tickex_mobile_admin_billing_report($pdo,$owner,array('dateFrom'=>'2026-02-30'))['error'] === 'invalid_filter', 'invalid date rejected');
billing_api_assert(tickex_mobile_admin_billing_report($pdo,$owner,array('provider'=>'other'))['error'] === 'invalid_filter', 'invalid provider rejected');
$super = array('id'=>99,'tipo_global'=>'super_admin');
billing_api_assert(tickex_mobile_admin_billing_report($pdo,$super,array())['summary']['gross'] === 615.0, 'superadmin sees all events');
echo 'mobile admin billing PASS' . PHP_EOL;
