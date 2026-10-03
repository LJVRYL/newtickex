<?php
require_once __DIR__ . '/../inc/mobile_communication_catalog.php';
require_once __DIR__ . '/../inc/mobile_communication_history.php';
function history_assert($ok, $message) { if (!$ok) { echo 'FAIL ' . $message . PHP_EOL; exit(1); } }
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE communication_campaigns (id INTEGER PRIMARY KEY, organization_id INTEGER, created_by_admin_id INTEGER, name TEXT, status TEXT, removed_at TEXT); INSERT INTO communication_campaigns VALUES (9,1,2,"Avisos","draft",NULL)');
$pdo->exec('CREATE TABLE communication_campaign_runs (id INTEGER PRIMARY KEY)');
$owner = array('id' => 2, 'tipo_global' => 'admin_evento');
$other = array('id' => 3, 'tipo_global' => 'admin_evento');
$mock = function ($db, $org, $adminId, $isSuper, $campaignId) {
    history_assert($org === 1 && $adminId === 2 && !$isSuper && $campaignId === 9, 'history stays scoped');
    return array('runs' => array(array('id' => 14, 'status' => 'sent', 'started_at' => '2026-10-01', 'finished_at' => '2026-10-01', 'resolved_recipients' => 2, 'sent_count' => 2, 'failed_count' => 0, 'pending_count' => 0, 'unique_opens' => 1, 'unique_clicks' => 1, 'confirmed_orders' => 0, 'revenue' => 0, 'recipient_email' => 'private@example.invalid')));
};
$result = tickex_mobile_communication_history($pdo, $owner, 9, $mock);
history_assert(count($result['runs']) === 1 && $result['runs'][0]['sent'] === 2, 'scoped run summary returned');
history_assert(!isset($result['runs'][0]['recipient_email']), 'recipient email omitted from summary');
$super = array('id' => 99, 'tipo_global' => 'super_admin');
history_assert(tickex_mobile_communication_history($pdo, $super, 9, function ($db, $org, $adminId, $isSuper, $campaignId) { return array('runs' => array()); })['campaignId'] === '9', 'superadmin can view producer campaign history');
history_assert(tickex_mobile_communication_history($pdo, $other, 9, $mock)['error'] === 'not_found', 'foreign history hidden');
echo 'mobile communication history PASS' . PHP_EOL;
