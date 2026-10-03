<?php
require_once __DIR__ . '/../inc/mobile_events.php';
require_once __DIR__ . '/../inc/event_sales_control.php';
function free_offer_assert($ok, $message) { if (!$ok) { fwrite(STDERR, "FAIL: $message\n"); exit(1); } }
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE eventos (id INTEGER PRIMARY KEY, nombre TEXT, slug TEXT, fecha_desde TEXT, fecha_hasta TEXT, lugar TEXT, publicado_site INTEGER, borrado_en TEXT, capacidad_total INTEGER)");
$pdo->exec("INSERT INTO eventos VALUES (1, 'FREE QA', 'free-qa', '2026-11-01 20:00:00', '2026-11-02 04:00:00', 'Sala', 1, NULL, 8)");
$pdo->exec("CREATE TABLE tipos_entrada (id INTEGER PRIMARY KEY, evento_id INTEGER, nombre TEXT, tipo TEXT, precio REAL, cantidad_disponible INTEGER, cantidad_total INTEGER, visible_publico INTEGER, qr_quantity INTEGER)");
$pdo->exec("INSERT INTO tipos_entrada VALUES (3, 1, 'Acceso FREE', 'FREE', 0, 7, 7, 0, 1)");
$pdo->exec("CREATE TABLE entradas (id INTEGER PRIMARY KEY, evento_id INTEGER, payment_method TEXT)");
$pdo->exec("CREATE TABLE event_free_checkout_configs (evento_id INTEGER PRIMARY KEY, enabled INTEGER, ticket_type_id INTEGER, max_uses INTEGER)");
$pdo->exec('INSERT INTO event_free_checkout_configs VALUES (1, 1, 3, 5)');
$event = tickex_mobile_public_events($pdo, 1)[0];
$global = tickex_mobile_global_available($pdo, $event);
$offer = tickex_mobile_free_offer($pdo, 1, $global);
free_offer_assert($offer['available'] === 5, 'FREE offer respects max uses');
$view = tickex_mobile_event($event, tickex_mobile_ticket_types($pdo, 1, $global), $offer);
free_offer_assert($view['status'] === 'on_sale' && count($view['ticketTypes']) === 0 && $view['freeCheckout']['checkoutUrl'] === 'https://str.tickex.com.ar/acceso.php?evento_id=1', 'FREE-only event advertises the web checkout without paid types');
$pdo->exec("INSERT INTO entradas VALUES (1, 1, 'free'), (2, 1, 'free')");
free_offer_assert(tickex_mobile_free_offer($pdo, 1, tickex_mobile_global_available($pdo, $event))['available'] === 3, 'FREE offer reflects issuance');
$pdo->exec('CREATE TABLE event_sales_control (event_id INTEGER PRIMARY KEY, paused INTEGER, reason TEXT, updated_by_admin_id INTEGER, updated_at TEXT)');
$pdo->exec("INSERT INTO event_sales_control VALUES (1, 1, '', 7, 'now')");
free_offer_assert(tickex_mobile_free_offer($pdo, 1, 6)['available'] === 0, 'sales pause blocks FREE offer');
$pdo->exec('UPDATE event_free_checkout_configs SET enabled = 0 WHERE evento_id = 1');
free_offer_assert(tickex_mobile_free_offer($pdo, 1, 6) === null, 'disabled FREE checkout is hidden');
echo "mobile free offer PASS\n";
