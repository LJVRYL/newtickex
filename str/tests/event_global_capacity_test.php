<?php
if (PHP_SAPI !== 'cli') die("CLI only\n");
require_once __DIR__ . '/../inc/event_capacity.php';
function capacity_ok($condition, $message) {
    if (!$condition) { fwrite(STDERR, 'FAIL: '.$message.PHP_EOL); exit(1); }
    echo 'PASS: '.$message.PHP_EOL;
}
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE eventos (id INTEGER PRIMARY KEY,nombre TEXT)');
$pdo->exec('CREATE TABLE tipos_entrada (id INTEGER PRIMARY KEY,evento_id INTEGER,cantidad_total INTEGER,cantidad_disponible INTEGER)');
$pdo->exec('CREATE TABLE entradas (id INTEGER PRIMARY KEY,evento_id INTEGER)');
$pdo->exec("INSERT INTO eventos VALUES (1,'Test')");
$pdo->exec('INSERT INTO tipos_entrada VALUES (1,1,60,60),(2,1,40,40)');
$pdo->exec('INSERT INTO entradas VALUES (1,1),(2,1)');
$legacy = tickex_event_capacity_status($pdo, 1);
capacity_ok($legacy['limit'] === 100 && $legacy['available'] === 98, 'legacy event derives global capacity from category totals');
$set = tickex_event_capacity_set($pdo, 1, 120);
capacity_ok($set['limit'] === 120 && $set['available'] === 118, 'explicit global capacity is independent from category totals');
tickex_event_capacity_assert_available($pdo, 1, 118);
$blocked = false;
try { tickex_event_capacity_assert_available($pdo, 1, 119); } catch (RuntimeException $e) { $blocked = true; }
capacity_ok($blocked, 'issuance is blocked when it exceeds remaining global capacity');
$tooLow = false;
try { tickex_event_capacity_set($pdo, 1, 1); } catch (InvalidArgumentException $e) { $tooLow = true; }
capacity_ok($tooLow, 'capacity cannot be lower than already issued QR entries');
capacity_ok($pdo->query('PRAGMA integrity_check')->fetchColumn() === 'ok', 'database remains consistent');
echo "ALL EVENT GLOBAL CAPACITY TESTS PASSED\n";
