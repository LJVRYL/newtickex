<?php
if (!function_exists('communication_audience_table_exists')) {
    function communication_audience_table_exists($pdo, $table)
    {
        $st = $pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=:name");
        $st->execute(array(':name'=>(string)$table));
        return (int)$st->fetchColumn() > 0;
    }
}

if (!function_exists('communication_audience_campaign_counts')) {
    function communication_audience_campaign_counts($pdo, $rows)
    {
        $counts = array();
        foreach ($rows as $row) $counts[(int)$row['id']] = 0;
        if (!$counts || !communication_audience_table_exists($pdo, 'communication_campaigns')) return $counts;
        $ids = array_keys($counts);
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare('SELECT audience_id, COUNT(*) AS n FROM communication_campaigns WHERE audience_id IN (' . $marks . ') GROUP BY audience_id');
        $st->execute($ids);
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) $counts[(int)$row['audience_id']] = (int)$row['n'];
        return $counts;
    }
}

if (!function_exists('communication_audience_delete')) {
    function communication_audience_delete($pdo, $organizationId, $adminId, $isSuper, $audienceId)
    {
        $scope = 'organization_id = :org';
        $params = array(':id'=>(int)$audienceId, ':org'=>(int)$organizationId);
        if (!$isSuper) {
            $scope .= ' AND created_by_admin_id = :admin';
            $params[':admin'] = (int)$adminId;
        }
        $st = $pdo->prepare('SELECT id FROM communication_audiences WHERE id=:id AND ' . $scope . ' LIMIT 1');
        $st->execute($params);
        if (!$st->fetch(PDO::FETCH_ASSOC)) return array('status'=>'not_found', 'campaign_count'=>0);

        $campaignCount = 0;
        if (communication_audience_table_exists($pdo, 'communication_campaigns')) {
            $count = $pdo->prepare('SELECT COUNT(*) FROM communication_campaigns WHERE audience_id=:id');
            $count->execute(array(':id'=>(int)$audienceId));
            $campaignCount = (int)$count->fetchColumn();
        }
        if ($campaignCount > 0) {
            $softDelete = $pdo->prepare("UPDATE communication_audiences SET status='deleted', slug=slug || '-deleted-' || id || '-' || strftime('%s','now'), updated_at=datetime('now') WHERE id=:id AND " . $scope);
            $softDelete->execute($params);
            return array('status'=>$softDelete->rowCount() > 0 ? 'deleted_preserved' : 'not_found', 'campaign_count'=>$campaignCount);
        }

        $delete = $pdo->prepare('DELETE FROM communication_audiences WHERE id=:id AND ' . $scope);
        $delete->execute($params);
        return array('status'=>$delete->rowCount() > 0 ? 'deleted' : 'not_found', 'campaign_count'=>0);
    }
}
