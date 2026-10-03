<?php
function tickex_mobile_communication_history($pdo, $admin, $campaignId, $fetcher = null)
{
    if (!tickex_mobile_communication_allowed($admin)) return array('error' => 'forbidden');
    $isSuper = tickex_mobile_communication_super($admin);
    $adminId = (int)$admin['id'];
    $read = $pdo->prepare('SELECT id, name, status FROM communication_campaigns WHERE id=:id AND organization_id=1 AND removed_at IS NULL AND (CAST(:super AS INTEGER)=1 OR created_by_admin_id=:admin) LIMIT 1');
    $read->execute(array(':id' => (int)$campaignId, ':super' => $isSuper ? 1 : 0, ':admin' => $adminId));
    $campaign = $read->fetch(PDO::FETCH_ASSOC);
    if (!$campaign) return array('error' => 'not_found');
    if (!tickex_mobile_communication_table_exists($pdo, 'communication_campaign_runs')) return array('campaignId' => (string)$campaignId, 'campaignName' => $campaign['name'], 'campaignStatus' => $campaign['status'], 'runs' => array());
    if ($fetcher === null) {
        require_once __DIR__ . '/communication_ops.php';
        $history = communication_ops_fetch_run_history($pdo, 1, $adminId, $isSuper, (int)$campaignId);
    } else {
        $history = call_user_func($fetcher, $pdo, 1, $adminId, $isSuper, (int)$campaignId);
    }
    $runs = array();
    foreach (array_slice(isset($history['runs']) ? $history['runs'] : array(), 0, 50) as $run) {
        $runs[] = array(
            'id' => (string)$run['id'],
            'status' => (string)$run['status'],
            'startedAt' => isset($run['started_at']) ? $run['started_at'] : null,
            'finishedAt' => isset($run['finished_at']) ? $run['finished_at'] : null,
            'resolvedRecipients' => (int)$run['resolved_recipients'],
            'sent' => (int)$run['sent_count'],
            'failed' => (int)$run['failed_count'],
            'pending' => (int)$run['pending_count'],
            'opens' => (int)$run['unique_opens'],
            'clicks' => (int)$run['unique_clicks'],
            'confirmedOrders' => (int)$run['confirmed_orders'],
            'revenue' => (float)$run['revenue'],
        );
    }
    return array('campaignId' => (string)$campaignId, 'campaignName' => $campaign['name'], 'campaignStatus' => $campaign['status'], 'runs' => $runs);
}

function tickex_mobile_communication_run_detail($pdo, $admin, $runId, $limit, $offset, $fetcher = null)
{
    if (!tickex_mobile_communication_allowed($admin)) return array('error' => 'forbidden');
    if (!tickex_mobile_communication_table_exists($pdo, 'communication_campaign_runs')) return array('error' => 'not_found');
    $limit = max(1, min(100, (int)$limit));
    $offset = max(0, (int)$offset);
    $isSuper = tickex_mobile_communication_super($admin);
    if ($fetcher === null) {
        require_once __DIR__ . '/communication_ops.php';
        $detail = communication_ops_fetch_run_detail($pdo, 1, (int)$admin['id'], $isSuper, (int)$runId, $limit, $offset);
    } else {
        $detail = call_user_func($fetcher, $pdo, 1, (int)$admin['id'], $isSuper, (int)$runId, $limit, $offset);
    }
    if (empty($detail['run'])) return array('error' => 'not_found');
    $run = $detail['run'];
    $recipients = array();
    foreach ($detail['recipients'] as $row) {
        $recipients[] = array(
            'id' => (string)$row['id'],
            'email' => (string)$row['recipient_email'],
            'name' => isset($row['recipient_name']) ? $row['recipient_name'] : null,
            'status' => (string)$row['status'],
            'provider' => isset($row['provider_name']) ? $row['provider_name'] : null,
            'attempts' => (int)$row['attempt_count'],
            'lastAttemptAt' => isset($row['last_attempt_at']) ? $row['last_attempt_at'] : null,
            'processedAt' => isset($row['processed_at']) ? $row['processed_at'] : null,
        );
    }
    return array(
        'run' => array('id' => (string)$run['id'], 'campaignId' => (string)$run['campaign_id'], 'campaignName' => (string)$run['campaign_name'], 'status' => (string)$run['status'], 'startedAt' => isset($run['started_at']) ? $run['started_at'] : null, 'finishedAt' => isset($run['finished_at']) ? $run['finished_at'] : null),
        'recipients' => $recipients,
        'total' => (int)$detail['total'],
        'limit' => $limit,
        'offset' => $offset,
        'metrics' => $detail['metrics'],
    );
}

function tickex_mobile_communication_engine_state($pdo, $admin, $fetcher = null)
{
    if (!tickex_mobile_communication_allowed($admin)) return array('error' => 'forbidden');
    if (!tickex_mobile_communication_table_exists($pdo, 'communication_campaigns')) return array('counts' => array('campaigns_queued' => 0, 'campaigns_running' => 0, 'campaigns_finished' => 0, 'campaigns_failed' => 0, 'active_runs' => 0, 'queue_pending' => 0), 'recipientByStatus' => array(), 'runs' => array());
    if ($fetcher === null) {
        require_once __DIR__ . '/communication_ops.php';
        $state = communication_ops_fetch_engine_state($pdo, 1, (int)$admin['id'], tickex_mobile_communication_super($admin));
    } else {
        $state = call_user_func($fetcher, $pdo, 1, (int)$admin['id'], tickex_mobile_communication_super($admin));
    }
    return array(
        'counts' => isset($state['counts']) ? $state['counts'] : array(),
        'recipientByStatus' => isset($state['recipient_by_status']) ? $state['recipient_by_status'] : array(),
        'runs' => isset($state['run_progress']) ? $state['run_progress'] : array(),
    );
}
