<?php
require_once __DIR__ . '/mobile_communication_catalog.php';

function tickex_mobile_communication_queue($pdo, $admin, $campaignId, $confirmed, $requestId, $enqueue = null)
{
    if (!tickex_mobile_communication_allowed($admin)) return array('error' => 'forbidden');
    if ($confirmed !== true) return array('error' => 'confirmation_required');
    if (!is_string($requestId) || !preg_match('/^[A-Za-z0-9_-]{16,100}$/', $requestId)) return array('error' => 'idempotency_required');
    $isSuper = tickex_mobile_communication_super($admin);
    $read = $pdo->prepare('SELECT status,audience_id,template_id FROM communication_campaigns WHERE id=:id AND organization_id=1 AND removed_at IS NULL AND (CAST(:super AS INTEGER)=1 OR created_by_admin_id=:admin) LIMIT 1');
    $read->execute(array(':id' => (int)$campaignId, ':super' => $isSuper ? 1 : 0, ':admin' => (int)$admin['id']));
    $campaign = $read->fetch(PDO::FETCH_ASSOC);
    if (!$campaign) return array('error' => 'not_found');
    if (in_array((string)$campaign['status'], array('archived', 'cancelled'), true)) return array('error' => 'campaign_locked');
    if ((int)$campaign['audience_id'] <= 0 || (int)$campaign['template_id'] <= 0) return array('error' => 'campaign_incomplete');
    $key = hash('sha256', 'mobile_campaign|' . (int)$admin['id'] . '|' . (int)$campaignId . '|' . $requestId);
    if ($enqueue === null) {
        require_once __DIR__ . '/communication_execution_engine.php';
        $result = communication_execution_enqueue_campaign($pdo, 1, (int)$campaignId, (int)$admin['id'], $isSuper, array('request_key' => $key));
    } else {
        $result = call_user_func($enqueue, $pdo, 1, (int)$campaignId, (int)$admin['id'], $isSuper, array('request_key' => $key));
    }
    if (empty($result['ok'])) return array('error' => 'queue_unavailable');
    return array('commandId' => (string)$result['command_id'], 'status' => (string)$result['status'], 'reused' => !empty($result['reused']));
}

function tickex_mobile_communication_cancel_run($pdo, $admin, $runId, $confirmed, $cancel = null)
{
    if (!tickex_mobile_communication_allowed($admin)) return array('error' => 'forbidden');
    if ($confirmed !== true) return array('error' => 'confirmation_required');
    $isSuper = tickex_mobile_communication_super($admin);
    $read = $pdo->prepare('SELECT r.status FROM communication_campaign_runs r JOIN communication_campaigns c ON c.id=r.campaign_id WHERE r.id=:id AND c.organization_id=1 AND c.removed_at IS NULL AND (CAST(:super AS INTEGER)=1 OR c.created_by_admin_id=:admin) LIMIT 1');
    $read->execute(array(':id' => (int)$runId, ':super' => $isSuper ? 1 : 0, ':admin' => (int)$admin['id']));
    $run = $read->fetch(PDO::FETCH_ASSOC);
    if (!$run) return array('error' => 'not_found');
    if (in_array((string)$run['status'], array('completed', 'cancelled'), true)) return array('error' => 'run_finalized');
    if ($cancel === null) {
        require_once __DIR__ . '/communication_ops.php';
        $result = communication_ops_action_cancel_run($pdo, 1, (int)$admin['id'], $isSuper, (int)$runId, 'mobile_api');
    } else {
        $result = call_user_func($cancel, $pdo, 1, (int)$admin['id'], $isSuper, (int)$runId, 'mobile_api');
    }
    return !empty($result['ok']) ? array('cancelled' => true, 'runId' => (string)$runId) : array('error' => 'cancel_unavailable');
}
