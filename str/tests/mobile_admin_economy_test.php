<?php
require_once __DIR__ . '/../inc/mobile_events.php';
require_once __DIR__ . '/../inc/mobile_admin.php';
require_once __DIR__ . '/../inc/mobile_admin_economy.php';
function economy_assert($ok, $message) { if (!$ok) { echo 'FAIL ' . $message . PHP_EOL; exit(1); } }
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE eventos(id INTEGER PRIMARY KEY,creado_por_admin_id INTEGER,borrado_en TEXT);INSERT INTO eventos VALUES(1,10,NULL),(2,20,NULL);CREATE TABLE econ_movimientos(id INTEGER PRIMARY KEY AUTOINCREMENT,tipo TEXT,concepto TEXT,categoria TEXT,monto REAL,estado TEXT,evento_id INTEGER,notas TEXT,creado_por INTEGER,incluye_en_totales INTEGER NOT NULL DEFAULT 1,created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
$owner = array('id'=>10,'tipo_global'=>'admin_evento');
$other = array('id'=>20,'tipo_global'=>'admin_evento');
$body = array('type'=>'gasto','concept'=>'Luces','category'=>'Producción','amount'=>100,'state'=>'Pendiente','eventId'=>1,'notes'=>'','requestId'=>'test-economy-0001');
$created = tickex_mobile_economy_create($pdo,$owner,$body);
economy_assert(isset($created['id']) && $created['amount'] === 100.0, 'movement created');
$same = tickex_mobile_economy_create($pdo,$owner,$body);
economy_assert($same['id'] === $created['id'] && (int)$pdo->query('SELECT COUNT(*) FROM econ_movimientos')->fetchColumn() === 1, 'idempotent retry');
$changed = $body; $changed['amount'] = 200;
economy_assert(tickex_mobile_economy_create($pdo,$owner,$changed)['error'] === 'request_conflict', 'reused request id with changed payload rejected');
$foreign = $body; $foreign['requestId'] = 'test-economy-0002'; $foreign['eventId'] = 2;
economy_assert(tickex_mobile_economy_create($pdo,$owner,$foreign)['error'] === 'forbidden', 'foreign event rejected');
economy_assert(tickex_mobile_economy_list($pdo,$owner,array())['items'][0]['id'] === $created['id'], 'own movement listed');
economy_assert(count(tickex_mobile_economy_list($pdo,$other,array())['items']) === 0, 'other producer cannot list movement');
economy_assert(tickex_mobile_economy_set_included($pdo,$owner,(int)$created['id'],true,false)['included'] === false, 'include flag changed');
economy_assert(tickex_mobile_economy_set_included($pdo,$owner,(int)$created['id'],true,true)['error'] === 'conflict', 'stale include state rejected');
economy_assert(tickex_mobile_economy_delete($pdo,$owner,(int)$created['id'],false)['error'] === 'confirmation_required', 'deletion needs confirmation');
economy_assert(tickex_mobile_economy_delete($pdo,$other,(int)$created['id'],true)['error'] === 'not_found', 'foreign deletion denied');
economy_assert(tickex_mobile_economy_delete($pdo,$owner,(int)$created['id'],true)['deleted'] === true, 'owner can delete confirmed movement');
echo 'mobile admin economy PASS' . PHP_EOL;
