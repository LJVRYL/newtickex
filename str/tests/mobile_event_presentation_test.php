<?php
if (PHP_SAPI !== 'cli') exit(1);
require_once __DIR__ . '/../inc/mobile_event_presentation.php';
function presentation_assert($condition, $message) { if (!$condition) { fwrite(STDERR, "FAIL: $message\n"); exit(1); } }
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE eventos (id INTEGER PRIMARY KEY, nombre TEXT, slug TEXT, descripcion TEXT, fecha_desde TEXT, fecha_hasta TEXT, lugar TEXT, creado_por_admin_id INTEGER, actualizado_en TEXT, borrado_en TEXT)");
$pdo->exec("INSERT INTO eventos (id, nombre, lugar, creado_por_admin_id) VALUES (9, 'Concierto', 'Sala', 7)");
$owner = array('id' => 7, 'tipo_global' => 'admin_evento');
$other = array('id' => 8, 'tipo_global' => 'admin_evento');
$presentation = tickex_mobile_admin_update_presentation($pdo, $owner, 9, array('capacityTotal' => 100, 'doorsAt' => '20:00', 'showAt' => '22:00', 'address' => 'Calle 123', 'maxPerOrder' => 4));
presentation_assert($presentation['capacityTotal'] === 100 && $presentation['doorsAt'] === '20:00' && $presentation['address'] === 'Calle 123', 'producer saves full public event details');
presentation_assert($presentation['venue'] === 'Sala' && $presentation['maxPerOrder'] === 4, 'preserves venue and maximum per order');
presentation_assert(tickex_mobile_admin_event_presentation($pdo, $other, 9)['error'] === 'forbidden', 'other producer cannot read details');
presentation_assert(tickex_mobile_admin_update_presentation($pdo, $other, 9, array('capacityTotal' => 1))['error'] === 'forbidden', 'other producer cannot edit details');
presentation_assert(tickex_mobile_admin_update_presentation($pdo, $owner, 9, array('doorsAt' => '25:00'))['error'] === 'invalid_presentation', 'rejects invalid doors time');
presentation_assert(tickex_mobile_admin_update_presentation($pdo, $owner, 9, array('maxPerOrder' => '4abc'))['error'] === 'invalid_presentation', 'rejects malformed max per order');
$pdo->exec('CREATE TABLE entradas (id INTEGER PRIMARY KEY, evento_id INTEGER)');
$pdo->exec('INSERT INTO entradas (id, evento_id) VALUES (1, 9), (2, 9)');
presentation_assert(tickex_mobile_admin_update_presentation($pdo, $owner, 9, array('capacityTotal' => 1))['error'] === 'capacity_below_issued', 'cannot set capacity below issued tickets');
presentation_assert(tickex_mobile_admin_update_presentation($pdo, $owner, 9, array('capacityTotal' => 120))['capacityTotal'] === 120, 'can raise capacity without erasing details');
echo "mobile event presentation PASS\n";
