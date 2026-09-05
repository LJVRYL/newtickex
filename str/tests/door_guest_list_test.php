<?php
require_once __DIR__ . '/../inc/door_guest_list.php';

function door_test_ok($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }
    echo 'PASS: ' . $message . PHP_EOL;
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE tipos_entrada (id INTEGER PRIMARY KEY,evento_id INTEGER,nombre TEXT,precio REAL,cantidad_disponible INTEGER)');
$pdo->exec("CREATE TABLE entradas (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    evento_id INTEGER NOT NULL,
    nombre TEXT NOT NULL,
    email TEXT NOT NULL,
    fecha_registro TEXT NOT NULL,
    codigo TEXT NOT NULL UNIQUE,
    checked_in INTEGER NOT NULL DEFAULT 0,
    checked_in_at TEXT,
    tipo TEXT NOT NULL,
    monto_pagado REAL NOT NULL DEFAULT 0,
    payment_method TEXT,
    issuance_key TEXT UNIQUE,
    oculto INTEGER NOT NULL DEFAULT 0
)");
$pdo->exec("INSERT INTO tipos_entrada (id,evento_id,nombre,precio,cantidad_disponible) VALUES (39,15,'General Puerta',15000,2),(40,16,'Ajena',10000,2)");

tickex_door_list_ensure_schema($pdo);
door_test_ok((int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name LIKE 'event_door_guest_%'")->fetchColumn() === 2, 'door list schema is created');

$listId = tickex_door_save_list($pdo, 15, 'Lista puerta $10.000', 10000, 39, 2);
$list = tickex_door_list_for_event($pdo, 15);
door_test_ok((int)$list['id'] === $listId && (float)$list['precio'] === 10000.0, 'event has a ten-thousand-peso door list');

$import = tickex_door_import_guests($pdo, $listId, "- Ana Perez\n2. Bruno Díaz\nAna Perez\n\n", 2);
door_test_ok($import['added'] === 2 && $import['skipped'] === 1, 'bulk import adds names and skips duplicates');
door_test_ok((int)$pdo->query('SELECT COUNT(*) FROM entradas')->fetchColumn() === 0, 'reservations do not issue tickets before payment');
door_test_ok((int)$pdo->query('SELECT cantidad_disponible FROM tipos_entrada WHERE id=39')->fetchColumn() === 2, 'reservations do not consume stock');

$reservationId = (int)$pdo->query("SELECT id FROM event_door_guest_reservations WHERE normalized_name='ana perez'")->fetchColumn();
$paid = tickex_door_confirm_paid_checkin($pdo, $reservationId, 15, 22);
door_test_ok(!empty($paid['ok']) && empty($paid['already_processed']), 'door confirms cash payment and check-in together');
$entry = $pdo->query('SELECT * FROM entradas WHERE id=' . (int)$paid['entrada_id'])->fetch(PDO::FETCH_ASSOC);
door_test_ok((float)$entry['monto_pagado'] === 10000.0 && (int)$entry['checked_in'] === 1, 'issued entry is paid and checked in');
door_test_ok($entry['payment_method'] === 'cash_door', 'door income is identified as cash');
door_test_ok((int)$pdo->query('SELECT cantidad_disponible FROM tipos_entrada WHERE id=39')->fetchColumn() === 1, 'paid admission consumes one stock unit');

$again = tickex_door_confirm_paid_checkin($pdo, $reservationId, 15, 22);
door_test_ok(!empty($again['already_processed']), 'repeated confirmation is idempotent');
door_test_ok((int)$pdo->query('SELECT COUNT(*) FROM entradas')->fetchColumn() === 1, 'repeated confirmation cannot duplicate the ticket');
door_test_ok((int)$pdo->query('SELECT cantidad_disponible FROM tipos_entrada WHERE id=39')->fetchColumn() === 1, 'repeated confirmation cannot consume stock twice');

$brunoId = (int)$pdo->query("SELECT id FROM event_door_guest_reservations WHERE normalized_name LIKE 'bruno%'")->fetchColumn();
door_test_ok(tickex_door_cancel_reservation($pdo, $brunoId, 15), 'administrator can remove an unpaid reservation');
door_test_ok((int)$pdo->query("SELECT COUNT(*) FROM entradas WHERE nombre LIKE 'Bruno%'")->fetchColumn() === 0, 'removed unpaid reservation never becomes revenue');

$blocked = false;
try {
    tickex_door_confirm_paid_checkin($pdo, $reservationId, 16, 22);
} catch (RuntimeException $e) {
    $blocked = true;
}
door_test_ok($blocked, 'reservation cannot be processed through another event');
door_test_ok($pdo->query('PRAGMA integrity_check')->fetchColumn() === 'ok', 'database remains consistent');
echo 'ALL DOOR GUEST LIST TESTS PASSED' . PHP_EOL;
