<?php
require_once __DIR__ . '/../inc/mobile_staff_gate.php';

function tickex_mobile_datetime($value) { return $value; }
function tickex_mobile_staff_events($pdo, $clientId) {
    return array(array('id' => '1', 'permissions' => $clientId === 10 ? array('dashboard_view', 'reports_view') : array('dashboard_view')));
}
function activity_assert($condition, $message) {
    if (!$condition) { fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL); exit(1); }
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
activity_assert(tickex_mobile_gate_permission($pdo, 10, 1, 'reports_view'), 'report role may view its event');
activity_assert(!tickex_mobile_gate_permission($pdo, 11, 1, 'reports_view'), 'other role may not view reports');
activity_assert(!tickex_mobile_gate_permission($pdo, 10, 2, 'reports_view'), 'report role may not view another event');
activity_assert(tickex_mobile_gate_activity($pdo, 1) === array(), 'missing audit table is empty');
$pdo->exec('CREATE TABLE checkin_audit_log (id INTEGER PRIMARY KEY, evento_id INTEGER, created_at TEXT, source TEXT, ticket_ref TEXT, attendee_name TEXT, action TEXT, result TEXT, detail TEXT)');
$pdo->exec("INSERT INTO checkin_audit_log VALUES (1, 1, '2026-09-30 12:00:00', 'STR', 'A', 'Ana', 'checkin', 'ok', '')");
$pdo->exec("INSERT INTO checkin_audit_log VALUES (2, 2, '2026-09-30 12:01:00', 'STR', 'B', 'Beto', 'checkin', 'ok', '')");
$pdo->exec("INSERT INTO checkin_audit_log VALUES (3, 1, '2026-09-30 12:02:00', 'STR', 'A', 'Ana', 'undo', 'ok', '')");
$rows = tickex_mobile_gate_activity($pdo, 1);
activity_assert(count($rows) === 2, 'activity is scoped to one event');
activity_assert($rows[0]['id'] === '3' && $rows[1]['id'] === '1', 'newest entries come first');
activity_assert($rows[0]['action'] === 'undo' && $rows[1]['attendeeName'] === 'Ana', 'activity projects the event records');
echo "ALL MOBILE STAFF ACTIVITY TESTS PASSED\n";
