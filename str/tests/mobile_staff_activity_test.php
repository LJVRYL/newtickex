<?php
require_once __DIR__ . '/../inc/mobile_events.php';
require_once __DIR__ . '/../inc/mobile_staff_gate.php';
function activity_assert($ok, $message) { if (!$ok) { echo 'FAIL ' . $message . PHP_EOL; exit(1); } }
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
activity_assert(tickex_mobile_gate_activity($pdo, 1) === array(), 'missing audit table gives empty history');
$pdo->exec("CREATE TABLE checkin_audit_log(id INTEGER PRIMARY KEY, created_at TEXT, evento_id INTEGER, source TEXT, ticket_ref TEXT, attendee_name TEXT, action TEXT, result TEXT, detail TEXT);INSERT INTO checkin_audit_log VALUES(1,'2026-10-01',1,'STR','secret-qr-code','Ana','mobile_checkin','ok','correcto'),(2,'2026-10-02',2,'STR','other-secret','Otro','mobile_checkin','ok','correcto')");
$rows = tickex_mobile_gate_activity($pdo, 1);
activity_assert(count($rows) === 1 && $rows[0]['attendeeName'] === 'Ana', 'history scoped to event');
activity_assert($rows[0]['ticketRef'] === '' && strpos(json_encode($rows), 'secret-qr-code') === false, 'QR payload excluded');
echo 'mobile staff activity PASS' . PHP_EOL;
