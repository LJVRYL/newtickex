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
$runMock = function ($db, $org, $adminId, $isSuper, $runId, $limit, $offset) {
    history_assert($org === 1 && $adminId === 2 && !$isSuper && $runId === 14, 'run detail stays scoped');
    history_assert($limit === 100 && $offset === 0, 'pagination bounded');
    return array('run' => array('id' => 14, 'campaign_id' => 9, 'campaign_name' => 'Avisos', 'status' => 'completed'), 'recipients' => array(array('id' => 3, 'recipient_email' => 'qa@example.invalid', 'recipient_name' => 'QA', 'status' => 'sent', 'provider_name' => 'test', 'attempt_count' => 1, 'last_response_message' => 'secret')), 'total' => 1, 'metrics' => array('unique_opens' => 0));
};
$detail = tickex_mobile_communication_run_detail($pdo, $owner, 14, 500, -1, $runMock);
history_assert($detail['run']['campaignId'] === '9' && $detail['recipients'][0]['email'] === 'qa@example.invalid', 'run and recipient returned');
history_assert(!isset($detail['recipients'][0]['last_response_message']), 'provider response omitted');
history_assert(tickex_mobile_communication_run_detail($pdo, array('id' => 4, 'tipo_global' => 'staff'), 14, 10, 0, $runMock)['error'] === 'forbidden', 'staff denied');
history_assert(tickex_mobile_communication_run_detail($pdo, $owner, 14, 10, 0, function () { return array('run' => null); })['error'] === 'not_found', 'foreign run hidden');
$engineMock = function ($db, $org, $adminId, $isSuper) {
    history_assert($org === 1 && $adminId === 2 && !$isSuper, 'engine stays scoped');
    return array('counts' => array('campaigns_queued' => 1), 'recipient_by_status' => array(array('status' => 'sent', 'count' => 2)), 'run_progress' => array(array('run_id' => 14, 'status' => 'running')));
};
$engine = tickex_mobile_communication_engine_state($pdo, $owner, $engineMock);
history_assert($engine['counts']['campaigns_queued'] === 1 && $engine['runs'][0]['run_id'] === 14, 'engine state returned');
history_assert(tickex_mobile_communication_engine_state($pdo, array('id' => 4, 'tipo_global' => 'staff'), $engineMock)['error'] === 'forbidden', 'staff engine denied');
echo 'mobile communication history PASS' . PHP_EOL;
