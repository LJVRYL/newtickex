<?php
require_once __DIR__ . '/../inc/event_capacity.php';

function hold_assert($condition, $message)
{
    if (!$condition) { fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL); exit(1); }
    echo 'PASS: ' . $message . PHP_EOL;
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE eventos(id INTEGER PRIMARY KEY, capacidad_total INTEGER)');
$pdo->exec('CREATE TABLE tipos_entrada(id INTEGER PRIMARY KEY, evento_id INTEGER, cantidad_total INTEGER, cantidad_disponible INTEGER)');
$pdo->exec('CREATE TABLE entradas(id INTEGER PRIMARY KEY, evento_id INTEGER)');
$pdo->exec('INSERT INTO eventos(id,capacidad_total) VALUES(1,3)');
$pdo->exec('INSERT INTO tipos_entrada(id,evento_id,cantidad_total,cantidad_disponible) VALUES(7,1,4,4)');
$tickets = array(array('id' => 7, 'qty' => 1, 'qr_quantity' => 2));
$deadline = date('c', time() + 900);

$first = tickex_capacity_holds_reserve($pdo, 1, 'ref-1', $tickets, $deadline);
hold_assert($first['result'] === 'reserved' && $first['quantity'] === 2, 'two QR are reserved atomically');
hold_assert((int)$pdo->query('SELECT cantidad_disponible FROM tipos_entrada WHERE id=7')->fetchColumn() === 2, 'other channels see reduced ticket stock');
hold_assert(tickex_event_capacity_status($pdo, 1)['available'] === 1, 'global capacity includes active holds');
$lowered = false;
try { tickex_event_capacity_set($pdo, 1, 1); } catch (InvalidArgumentException $e) { $lowered = true; }
hold_assert($lowered, 'producer cannot reduce physical capacity below active holds');
hold_assert(tickex_capacity_holds_reserve($pdo, 1, 'ref-1', $tickets, $deadline)['result'] === 'existing', 'retry is idempotent');
$conflict = false;
try { tickex_capacity_holds_reserve($pdo, 1, 'ref-1', array(array('id'=>7,'qty'=>1,'qr_quantity'=>1)), $deadline); } catch (RuntimeException $e) { $conflict = $e->getMessage() === 'hold_conflict'; }
hold_assert($conflict, 'same reference cannot change quantity');
$full = false;
try { tickex_capacity_holds_reserve($pdo, 1, 'ref-2', $tickets, $deadline); } catch (RuntimeException $e) { $full = $e->getMessage() === 'event_full'; if (!$full) fwrite(STDERR, $e->getMessage() . PHP_EOL); }
hold_assert($full, 'second checkout cannot exceed physical capacity');
hold_assert((int)$pdo->query('SELECT cantidad_disponible FROM tipos_entrada WHERE id=7')->fetchColumn() === 2, 'rejected reservation rolls back stock');

$pdo->beginTransaction();
hold_assert(tickex_capacity_holds_consume($pdo, 1, 'ref-1', $tickets), 'issuance consumes its own hold');
$pdo->rollBack();
hold_assert(tickex_capacity_holds_active_count($pdo, 1) === 2, 'failed issuance restores the hold');
$pdo->beginTransaction();
hold_assert(tickex_capacity_holds_consume($pdo, 1, 'ref-1', $tickets), 'issuance retry can consume the hold');
$pdo->exec('INSERT INTO entradas(evento_id) VALUES(1),(1)');
$pdo->commit();
hold_assert(tickex_event_capacity_status($pdo, 1)['available'] === 1, 'issued QR replace the consumed hold without double counting');
hold_assert((int)$pdo->query('SELECT cantidad_disponible FROM tipos_entrada WHERE id=7')->fetchColumn() === 2, 'issuance must not subtract stock twice');
$consumedRelease = false;
try { tickex_capacity_holds_release($pdo, 'ref-1', 'rejected'); } catch (RuntimeException $e) { $consumedRelease = true; }
hold_assert($consumedRelease, 'issued stock cannot be released');

$one = array(array('id'=>7,'qty'=>1,'qr_quantity'=>1));
tickex_capacity_holds_reserve($pdo, 1, 'ref-3', $one, $deadline);
$pdo->exec('CREATE TABLE tc_orders(ref TEXT,payment_status TEXT)');
$pdo->exec("INSERT INTO tc_orders VALUES('ref-3','confirmed')");
$paidGuard = false;
try { tickex_capacity_holds_release($pdo, 'ref-3', 'expired_verified'); } catch (RuntimeException $e) { $paidGuard = $e->getMessage() === 'paid_hold_cannot_release'; }
hold_assert($paidGuard, 'a newly confirmed local order cannot lose its hold during reconciliation');
$pdo->exec("DELETE FROM tc_orders WHERE ref='ref-3'");
$unverified = false;
try { tickex_capacity_holds_release($pdo, 'ref-3', 'time_elapsed'); } catch (InvalidArgumentException $e) { $unverified = true; }
hold_assert($unverified, 'clock expiry alone cannot release a possible payment');
hold_assert(tickex_capacity_holds_release($pdo, 'ref-3', 'expired_verified'), 'verified terminal payment releases hold');
hold_assert(!tickex_capacity_holds_release($pdo, 'ref-3', 'expired_verified'), 'release retry is idempotent');
hold_assert(tickex_event_capacity_status($pdo, 1)['available'] === 1, 'released hold restores capacity');
hold_assert((int)$pdo->query('SELECT cantidad_disponible FROM tipos_entrada WHERE id=7')->fetchColumn() === 2, 'released hold restores type stock');
tickex_capacity_holds_reserve($pdo, 1, 'ref-4', $one, $deadline);
$pdo->exec('UPDATE tipos_entrada SET cantidad_total=3 WHERE id=7');
$changedStock = false;
try { tickex_capacity_holds_release($pdo, 'ref-4', 'expired_verified'); } catch (RuntimeException $e) { $changedStock = $e->getMessage() === 'hold_stock_changed'; }
hold_assert($changedStock, 'producer stock changes require review before releasing an old hold');
$pdo->exec('UPDATE tipos_entrada SET cantidad_total=4 WHERE id=7');
hold_assert(tickex_capacity_holds_release($pdo, 'ref-4', 'expired_verified'), 'hold can release after stock is reconciled');
echo "ALL CHECKOUT CAPACITY HOLD TESTS PASSED\n";
