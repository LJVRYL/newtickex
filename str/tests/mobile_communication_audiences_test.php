<?php
require_once __DIR__ . '/../inc/mobile_communication_catalog.php';
require_once __DIR__ . '/../inc/mobile_communication_audiences.php';
function audience_assert($ok, $message) { if (!$ok) { echo 'FAIL ' . $message . PHP_EOL; exit(1); } }
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE eventos (id INTEGER PRIMARY KEY, creado_por_admin_id INTEGER, borrado_en TEXT); INSERT INTO eventos VALUES (11, 2, NULL), (12, 3, NULL)');
$pdo->exec('CREATE TABLE communication_audiences (id INTEGER PRIMARY KEY AUTOINCREMENT, organization_id INTEGER, created_by_admin_id INTEGER, name TEXT, slug TEXT UNIQUE, description TEXT, filters_json TEXT, status TEXT, created_at TEXT, updated_at TEXT)');
$owner = array('id' => 2, 'tipo_global' => 'admin_evento');
$other = array('id' => 3, 'tipo_global' => 'admin_evento');
$body = array('name' => 'Compradores', 'filters' => array('buyer' => 'yes', 'event_id' => 11));
$created = tickex_mobile_communication_audience_save($pdo, $owner, $body, 0);
audience_assert(isset($created['id']) && $created['filters']['event_id'] === 11, 'owner creates scoped audience');
$id = (int)$created['id'];
audience_assert(tickex_mobile_communication_audience_save($pdo, $other, $body, $id)['error'] === 'not_found', 'other producer cannot edit');
audience_assert(tickex_mobile_communication_audience_save($pdo, $owner, array('name' => 'Ajena', 'filters' => array('event_id' => 12)), 0)['error'] === 'event_forbidden', 'foreign event filter rejected');
audience_assert(tickex_mobile_communication_audience_save($pdo, $owner, array('name' => 'Invalida', 'filters' => array('source' => 'unknown')), 0)['error'] === 'invalid_filters', 'invalid filters rejected');
$changed = tickex_mobile_communication_audience_save($pdo, $owner, array('name' => 'Compradores VIP', 'status' => 'archived', 'filters' => array('event_id' => 11)), $id);
audience_assert($changed['status'] === 'archived', 'owned audience can be archived');
audience_assert((int)$pdo->query('SELECT COUNT(*) FROM communication_audiences')->fetchColumn() === 1, 'invalid or cross-owner edits create no data');
echo 'mobile communication audiences PASS' . PHP_EOL;
