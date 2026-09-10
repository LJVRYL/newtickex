<?php
require_once __DIR__ . '/../inc/event_presentation.php';
function ep_assert($condition, $message) { if (!$condition) { fwrite(STDERR, "FAIL: $message\n"); exit(1); } echo "PASS: $message\n"; }
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE eventos (id INTEGER PRIMARY KEY, nombre TEXT)');
tickex_event_presentation_ensure_schema($pdo);
tickex_event_presentation_ensure_schema($pdo);
$cols = array(); foreach ($pdo->query('PRAGMA table_info(eventos)')->fetchAll(PDO::FETCH_ASSOC) as $r) $cols[$r['name']] = true;
ep_assert(isset($cols['hora_puertas']) && isset($cols['hora_show']) && isset($cols['direccion']), 'public event details schema is created idempotently');
$data = tickex_event_presentation_post(array('hora_puertas'=>'19:00','hora_show'=>'21:30','direccion'=>'Humboldt 450','max_entradas_compra'=>'8'));
ep_assert(tickex_event_presentation_validate($data, true) === '', 'valid public event details are accepted');
$missing = $data; $missing['hora_puertas'] = '';
ep_assert(tickex_event_presentation_validate($missing, true) !== '', 'door time is required for a new event');
$bad = $data; $bad['hora_show'] = '29:70';
ep_assert(tickex_event_presentation_validate($bad, true) !== '', 'invalid show time is rejected');
$maps = tickex_event_map_urls('Humboldt 450, CABA');
ep_assert(strpos($maps['link'], 'google.com/maps/search') !== false && strpos($maps['embed'], 'output=embed') !== false, 'Google Maps links need no API key');
$checkout = file_get_contents(__DIR__ . '/../checkout_totalcoin.php');
ep_assert(strpos($checkout, 'data-event-countdown') !== false && strpos($checkout, 'Faltan') !== false, 'checkout includes the event countdown');
ep_assert(strpos($checkout, 'Cómo llegar') !== false && strpos($checkout, 'Transporte público') !== false, 'checkout includes conditional arrival information');
ep_assert(strpos($checkout, 'tickex_event_map_urls') !== false && strpos($checkout, 'maps/embed/v1') === false, 'checkout does not expose a Google Maps API key');
ep_assert(strpos($checkout, '$eventMaxPurchase') !== false && strpos($checkout, '_tickex_parse_selection($optionMap, $selIds, $selQty, $errors, $eventMaxPurchase)') !== false, 'purchase limit is enforced by the server');
echo "ALL EVENT PRESENTATION TESTS PASSED\n";
