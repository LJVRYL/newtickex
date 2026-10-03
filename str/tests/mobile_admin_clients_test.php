<?php
require_once __DIR__ . '/../inc/mobile_events.php';
require_once __DIR__ . '/../inc/mobile_admin.php';
require_once __DIR__ . '/../inc/billing_financials.php';
require_once __DIR__ . '/../inc/unified_tickets.php';
require_once __DIR__ . '/../inc/mobile_admin_clients.php';
function clients_assert($ok, $message) { if (!$ok) { echo 'FAIL ' . $message . PHP_EOL; exit(1); } }
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE eventos(id INTEGER PRIMARY KEY,nombre TEXT,creado_por_admin_id INTEGER,borrado_en TEXT); INSERT INTO eventos VALUES(1,'Evento A',10,NULL),(2,'Evento B',20,NULL); CREATE TABLE usuarios(id INTEGER PRIMARY KEY,email TEXT); INSERT INTO usuarios VALUES(1,'ana@example.invalid'); CREATE TABLE entradas(id INTEGER PRIMARY KEY,nombre TEXT,email TEXT,fecha_registro TEXT,codigo TEXT,checked_in INTEGER,tipo TEXT,monto_pagado REAL,evento_id INTEGER,oculto INTEGER); INSERT INTO entradas VALUES(1,'Ana','ana@example.invalid','2026-10-01','qr-a1',0,'PAGA',100,1,0),(2,'Ana','ana@example.invalid','2026-10-02','qr-a2',0,'PAGA',100,1,0),(3,'Beto','beto@example.invalid','2026-10-03','qr-b1',0,'FREE',0,1,0),(4,'Otro','otro@example.invalid','2026-10-04','qr-o1',0,'PAGA',100,2,0)");
$owner = array('id'=>10,'tipo_global'=>'admin_evento');
$page = tickex_mobile_admin_clients($pdo,$owner,array('limit'=>1));
clients_assert($page['total'] === 2 && count($page['items']) === 1 && $page['nextOffset'] === 1, 'producer scope and pagination');
$ana = tickex_mobile_admin_clients($pdo,$owner,array('q'=>'ana'));
clients_assert($ana['total'] === 1 && $ana['items'][0]['ticketCount'] === 2 && $ana['items'][0]['registered'] === true, 'group by email and registered state');
clients_assert(tickex_mobile_admin_clients($pdo,$owner,array('q'=>'otro'))['total'] === 0, 'foreign customer hidden');
clients_assert(tickex_mobile_admin_clients($pdo,$owner,array('eventId'=>2))['error'] === 'forbidden', 'foreign event filter denied');
clients_assert(tickex_mobile_admin_clients($pdo,$owner,array('group'=>'guests'))['total'] === 1, 'guest filter');
clients_assert(tickex_mobile_admin_clients($pdo,$owner,array('limit'=>100))['error'] === 'invalid_filter', 'page size capped');
$super = array('id'=>99,'tipo_global'=>'super_admin');
clients_assert(tickex_mobile_admin_can_event($pdo,$super,2) === true, 'superadmin numeric permission works');
clients_assert(tickex_mobile_admin_clients($pdo,$super,array('eventId'=>2))['total'] === 1, 'superadmin can read selected event');
echo 'mobile admin clients PASS' . PHP_EOL;
