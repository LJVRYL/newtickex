<?php
if (PHP_SAPI !== 'cli') exit(1);
require_once __DIR__ . '/../inc/event_sales_control.php';
function sales_assert($condition, $message) { if (!$condition) { fwrite(STDERR, "FAIL: $message\n"); exit(1); } }
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
sales_assert(!tickex_event_sales_paused($pdo, 7), 'new events are open');
$paused = tickex_event_sales_set_paused($pdo, 7, 11, true, 'Aforo completo');
sales_assert($paused['paused'] && tickex_event_sales_paused($pdo, 7), 'event is paused');
sales_assert(!tickex_event_sales_paused($pdo, 8), 'other event remains open');
tickex_event_sales_set_paused($pdo, 7, 11, false, 'Reapertura autorizada');
sales_assert(!tickex_event_sales_paused($pdo, 7), 'event is reopened');
sales_assert((int)$pdo->query('SELECT COUNT(*) FROM event_sales_control_log')->fetchColumn() === 2, 'both changes audited');
echo "event sales control PASS\n";
