<?php
require_once __DIR__ . '/../inc/mobile_inventory.php';
function inventory_assert($value, $message) { if (!$value) { fwrite(STDERR, $message . "\n"); exit(1); } }
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$owner = array('id' => 7, 'tipo_global' => 'admin_evento');
$other = array('id' => 8, 'tipo_global' => 'admin_evento');
$super = array('id' => 1, 'tipo_global' => 'super_admin');
inventory_assert(tickex_mobile_inventory_list($pdo, $owner, '', '')['items'] === array(), 'missing inventory starts empty');
$created = tickex_mobile_inventory_create($pdo, $owner, array('name' => 'Scanner', 'description' => 'Equipo de puerta', 'owner' => 'Productor', 'status' => 'OK'));
$id = (int)$created['item']['id'];
inventory_assert($id > 0 && $created['item']['owner_admin_id'] == 7, 'item owned by producer');
inventory_assert(count(tickex_mobile_inventory_list($pdo, $owner, 'Scan', '')['items']) === 1, 'search finds owned item');
inventory_assert(count(tickex_mobile_inventory_list($pdo, $other, '', '')['items']) === 0, 'other producer sees no item');
inventory_assert(tickex_mobile_inventory_detail($pdo, $other, $id)['error'] === 'not_found', 'other producer cannot read detail');
inventory_assert(tickex_mobile_inventory_add_log($pdo, $other, $id, array('note' => 'Cambio'))['error'] === 'not_found', 'other producer cannot mutate item');
$logged = tickex_mobile_inventory_add_log($pdo, $owner, $id, array('note' => 'Revisión', 'status' => 'Mantenimiento'));
inventory_assert($logged['item']['estado'] === 'Mantenimiento' && count($logged['logs']) === 1, 'log changes status atomically');
inventory_assert(count(tickex_mobile_inventory_list($pdo, $super, '', 'Mantenimiento')['items']) === 1, 'superadmin can read inventory');
$uploadCalled = false;
$uploader = function ($file) use (&$uploadCalled) { $uploadCalled = true; return 'uploads_inventario/inv_' . str_repeat('a', 32) . '.jpg'; };
inventory_assert(tickex_mobile_inventory_add_photo_log($pdo, $other, $id, array(), array(), $uploader)['error'] === 'not_found' && !$uploadCalled, 'other producer cannot upload');
$photoLog = tickex_mobile_inventory_add_photo_log($pdo, $owner, $id, array(), array(), $uploader);
inventory_assert($uploadCalled && count($photoLog['logs']) === 2 && $photoLog['logs'][0]['foto_path'] === 'uploads_inventario/inv_' . str_repeat('a', 32) . '.jpg', 'photo-only log keeps safe path');
inventory_assert(tickex_mobile_inventory_add_photo_log($pdo, $owner, $id, array(), array(), function () { return 'uploads_inventario/unsafe.php'; })['error'] === 'invalid_photo', 'unsafe upload path rejected');
inventory_assert(tickex_mobile_inventory_upload_photo(array('error' => UPLOAD_ERR_OK, 'size' => 10, 'tmp_name' => __FILE__, 'name' => 'attack.php')) === '', 'local file cannot impersonate an upload');
inventory_assert(tickex_mobile_inventory_upload_photo(array('error' => UPLOAD_ERR_INI_SIZE, 'size' => 10, 'tmp_name' => __FILE__, 'name' => 'photo.jpg')) === '', 'oversized upload rejected');
inventory_assert(tickex_mobile_inventory_create($pdo, $owner, array('name' => ''))['error'] === 'invalid_item', 'invalid item rejected');
echo "mobile inventory PASS\n";
