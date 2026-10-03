<?php
require_once __DIR__ . '/../inc/mobile_events.php';
require_once __DIR__ . '/../inc/mobile_admin.php';
require_once __DIR__ . '/../inc/mobile_admin_sales_detail.php';
function sales_assert($ok, $message) { if (!$ok) { echo 'FAIL ' . $message . PHP_EOL; exit(1); } }
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE eventos (id INTEGER PRIMARY KEY, creado_por_admin_id INTEGER, borrado_en TEXT); INSERT INTO eventos VALUES (11,2,NULL),(12,3,NULL)');
$pdo->exec('CREATE TABLE tc_orders (id INTEGER PRIMARY KEY, request_id TEXT, evento_id INTEGER, amount REAL, ticket_subtotal REAL, service_fee_amount REAL, buyer_first TEXT, buyer_last TEXT, buyer_email TEXT, payment_status TEXT, processing_status TEXT, payment_provider TEXT, created_at TEXT, payment_confirmed_at TEXT)');
$pdo->exec("INSERT INTO tc_orders VALUES (1,'a',11,115,100,15,'Ana','QA','ana@example.invalid','confirmed','issued','mercadopago','2026-10-01',NULL),(2,'b',11,115,100,15,'Beto','QA','beto@example.invalid','pending','pending','mercadopago','2026-10-02',NULL),(3,'c',11,115,100,15,'Cami','QA','cami@example.invalid','confirmed','issued','mercadopago','2026-10-03',NULL),(4,'d',12,115,100,15,'Dani','QA','dani@example.invalid','confirmed','issued','mercadopago','2026-10-04',NULL)");
$pdo->exec("CREATE TABLE entradas (id INTEGER PRIMARY KEY, evento_id INTEGER, tc_order_request_id TEXT); INSERT INTO entradas VALUES (1,11,'c'),(2,11,'c')");
$owner = array('id' => 2, 'tipo_global' => 'admin_evento');
$other = array('id' => 3, 'tipo_global' => 'admin_evento');
$page = tickex_mobile_admin_sales_page($pdo, $owner, 11, 0, 2, 'all');
sales_assert(count($page['items']) === 2 && $page['items'][0]['orderId'] === '3' && $page['nextBeforeId'] === '2', 'first page and cursor scoped to event');
$next = tickex_mobile_admin_sales_page($pdo, $owner, 11, $page['nextBeforeId'], 2, 'all');
sales_assert(count($next['items']) === 1 && $next['items'][0]['orderId'] === '1' && $next['nextBeforeId'] === null, 'second page finishes');
$filtered = tickex_mobile_admin_sales_page($pdo, $owner, 11, 0, 20, 'confirmed');
sales_assert(count($filtered['items']) === 2, 'payment filter applied');
sales_assert(tickex_mobile_admin_sales_page($pdo, $other, 11, 0, 20, 'all')['error'] === 'forbidden', 'foreign event denied');
sales_assert(tickex_mobile_admin_sales_page($pdo, $owner, 11, 0, 100, 'all')['error'] === 'invalid_filter', 'page size capped');
$detail = tickex_mobile_admin_sale_detail($pdo, $owner, 11, 3);
sales_assert($detail['ticketCount'] === 2 && $detail['serviceFee'] === 15.0 && $detail['buyerEmail'] === 'cami@example.invalid', 'owned order detail and ticket count');
sales_assert(tickex_mobile_admin_sale_detail($pdo, $owner, 11, 4)['error'] === 'not_found', 'other event order hidden');
sales_assert(tickex_mobile_admin_sale_detail($pdo, $other, 11, 3)['error'] === 'forbidden', 'foreign producer detail denied');
echo 'mobile admin sales detail PASS' . PHP_EOL;
