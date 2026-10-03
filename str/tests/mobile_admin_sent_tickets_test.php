<?php
require_once __DIR__ . '/../inc/mobile_admin.php';
function sent_assert($ok, $message) { if (!$ok) { fwrite(STDERR, $message . "\n"); exit(1); } }
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE eventos (id INTEGER PRIMARY KEY, nombre TEXT, creado_por_admin_id INTEGER, borrado_en TEXT)');
$pdo->exec("INSERT INTO eventos VALUES (1,'Evento A',7,NULL),(2,'Evento B',8,NULL)");
$pdo->exec('CREATE TABLE entradas (id INTEGER PRIMARY KEY, evento_id INTEGER, nombre TEXT, email TEXT, codigo TEXT, tipo TEXT, fecha_registro TEXT, checked_in INTEGER, tc_order_request_id TEXT)');
$pdo->exec("INSERT INTO entradas VALUES (1,1,'Ana','ana@example.invalid','AAA','General','2026-10-01',0,'manual-1'),(2,1,'Beto','beto@example.invalid','BBB','VIP','2026-10-02',1,'mobile-manual-2'),(3,1,'Cami','cami@example.invalid','CCC','General','2026-10-03',0,'payment-3'),(4,2,'Dani','dani@example.invalid','DDD','General','2026-10-04',0,'mobile-manual-4')");
$owner = array('id' => 7, 'tipo_global' => 'admin_evento');
$other = array('id' => 8, 'tipo_global' => 'admin_evento');
$first = tickex_mobile_admin_sent_tickets($pdo, $owner, 1, '', 0, 1);
sent_assert(count($first['items']) === 1 && $first['items'][0]['channel'] === 'mobile' && $first['nextBeforeId'] === '2', 'mobile history has cursor');
sent_assert(!isset($first['items'][0]['codigo']), 'QR code omitted from history');
$second = tickex_mobile_admin_sent_tickets($pdo, $owner, 1, 'AAA', $first['nextBeforeId'], 20);
sent_assert(count($second['items']) === 1 && $second['items'][0]['channel'] === 'web', 'search by code without returning it');
sent_assert(tickex_mobile_admin_sent_tickets($pdo, $other, 1, '', 0, 20)['error'] === 'forbidden', 'foreign producer denied');
sent_assert(tickex_mobile_admin_sent_tickets($pdo, $owner, 1, '', 0, 100)['error'] === 'invalid_filter', 'limit capped');
echo "mobile sent tickets PASS\n";
