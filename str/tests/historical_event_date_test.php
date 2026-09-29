<?php

require_once __DIR__ . '/../inc/historical_event_repair.php';

function historical_event_assert($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }
    echo 'PASS: ' . $message . PHP_EOL;
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE eventos (
    id INTEGER PRIMARY KEY,
    slug TEXT,
    fecha_desde TEXT,
    fecha_hasta TEXT,
    actualizado_en TEXT
)');
$pdo->exec("INSERT INTO eventos (id,slug) VALUES (1,'str')");
$pdo->exec("INSERT INTO eventos (id,slug) VALUES (2,'otro')");

historical_event_assert(tickex_repair_original_event_date($pdo), 'the undated original event is repaired');
$event = $pdo->query("SELECT fecha_desde,fecha_hasta FROM eventos WHERE id=1")->fetch(PDO::FETCH_ASSOC);
historical_event_assert($event['fecha_desde'] === '2025-11-15', 'the start date follows the historical check-in evidence');
historical_event_assert($event['fecha_hasta'] === '2025-11-16', 'the original event closes the following day');
historical_event_assert(!tickex_repair_original_event_date($pdo), 'the repair is idempotent');

$pdo->exec("UPDATE eventos SET fecha_desde='2024-01-01',fecha_hasta='2024-01-02' WHERE id=1");
historical_event_assert(!tickex_repair_original_event_date($pdo), 'an existing date is never overwritten');
$preserved = $pdo->query("SELECT fecha_desde FROM eventos WHERE id=1")->fetchColumn();
historical_event_assert($preserved === '2024-01-01', 'the existing date remains intact');

echo 'ALL HISTORICAL EVENT DATE TESTS PASSED' . PHP_EOL;

