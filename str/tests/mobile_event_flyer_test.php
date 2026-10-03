<?php
require_once __DIR__ . '/../inc/mobile_admin.php';
require_once __DIR__ . '/../inc/mobile_event_flyer.php';
function flyer_assert($ok, $message) { if (!$ok) { fwrite(STDERR, 'FAIL ' . $message . PHP_EOL); exit(1); } }
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE eventos(id INTEGER PRIMARY KEY,creado_por_admin_id INTEGER,borrado_en TEXT,flyer_filename TEXT)');
$pdo->exec("INSERT INTO eventos VALUES(8,3,NULL,'event_flyers/old.jpg'),(9,4,NULL,'event_flyers/other.jpg')");
$owner = array('id' => 3, 'tipo_global' => 'admin_evento');
$file = array('error' => UPLOAD_ERR_OK, 'size' => 1024, 'tmp_name' => '/tmp/synthetic.jpg');
$newPath = 'event_flyers/wizard_' . str_repeat('a', 32) . '.jpg';
$upload = function ($input) use ($newPath) { flyer_assert($input['size'] === 1024, 'validated upload passed to image handler'); return $newPath; };
flyer_assert(tickex_mobile_admin_event_flyer_upload($pdo, $owner, 9, $file, $upload)['error'] === 'not_found', 'foreign event hidden');
flyer_assert(tickex_mobile_admin_event_flyer_upload($pdo, $owner, 8, null, $upload)['error'] === 'flyer_required', 'file required');
flyer_assert(tickex_mobile_admin_event_flyer_upload($pdo, $owner, 8, array('error' => UPLOAD_ERR_OK, 'size' => 2097153, 'tmp_name' => '/tmp/large.jpg'), $upload)['error'] === 'invalid_flyer', 'size limited');
flyer_assert(tickex_mobile_admin_event_flyer_upload($pdo, $owner, 8, $file, function () { return '../bad.jpg'; })['error'] === 'invalid_flyer', 'path restricted');
$result = tickex_mobile_admin_event_flyer_upload($pdo, $owner, 8, $file, $upload);
flyer_assert($result['imageUrl'] === 'https://str.tickex.com.ar/' . $newPath, 'public image URL returned');
flyer_assert($pdo->query('SELECT flyer_filename FROM eventos WHERE id=8')->fetchColumn() === $newPath, 'own event flyer updated');
flyer_assert($pdo->query('SELECT flyer_filename FROM eventos WHERE id=9')->fetchColumn() === 'event_flyers/other.jpg', 'foreign flyer unchanged');
echo 'mobile event flyer PASS' . PHP_EOL;
