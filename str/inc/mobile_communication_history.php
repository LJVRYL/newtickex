<?php
function tickex_mobile_communication_history($pdo, $admin, $campaignId, $fetcher = null)
{
    if (!tickex_mobile_communication_allowed($admin)) return array('error' => 'forbidden');
    $isSuper = tickex_mobile_communication_super($admin);
    $adminId = (int)$admin['id'];
    $read = $pdo->prepare('SELECT id, name, status FROM communication_campaigns WHERE id=:id AND organization_id=1 AND removed_at IS NULL AND (:super=1 OR created_by_admin_id=:admin) LIMIT 1');
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
