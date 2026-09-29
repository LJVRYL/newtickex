<?php
$GLOBALS['communication_visibility_pdo'] = new PDO('sqlite::memory:');
$GLOBALS['communication_visibility_pdo']->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function db()
{
    return $GLOBALS['communication_visibility_pdo'];
}

require_once dirname(__DIR__) . '/inc/communication_ops.php';

function communication_visibility_assert($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }
    echo 'PASS: ' . $message . PHP_EOL;
}

$pdo = db();
communication_campaigns_ensure_schema($pdo);
communication_execution_ensure_schema($pdo);
communication_ops_ensure_schema($pdo);

$pdo->exec("INSERT INTO communication_campaigns (organization_id,created_by_admin_id,name,slug,status) VALUES (1,7,'Terminada','terminada','sent')");
$campaignId = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO communication_campaign_runs (organization_id,campaign_id,status,resolved_recipients,processed_count,accepted_count) VALUES (1," . $campaignId . ",'completed',3,3,3)");
$completedRunId = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO communication_campaign_run_recipients (run_id,campaign_id,recipient_email,recipient_fingerprint,status) VALUES (" . $completedRunId . "," . $campaignId . ",'uno@example.com','fp-uno','accepted')");
$pdo->exec("INSERT INTO communication_campaign_runs (organization_id,campaign_id,status) VALUES (1," . $campaignId . ",'running')");
$runningRunId = (int)$pdo->lastInsertId();

$removed = communication_ops_action_remove_run($pdo, 1, 7, false, $completedRunId, 'test');
communication_visibility_assert(!empty($removed['ok']), 'a completed execution can be removed from the view');
communication_visibility_assert((string)$pdo->query('SELECT removed_at FROM communication_campaign_runs WHERE id=' . $completedRunId)->fetchColumn() !== '', 'the removal is recorded as a soft delete');
communication_visibility_assert((int)$pdo->query('SELECT COUNT(*) FROM communication_campaign_run_recipients WHERE run_id=' . $completedRunId)->fetchColumn() === 1, 'recipient audit data is preserved');

$state = communication_ops_fetch_engine_state($pdo, 1, 7, false);
$visibleRunIds = array_map(function ($row) { return (int)$row['run_id']; }, $state['run_progress']);
communication_visibility_assert(!in_array($completedRunId, $visibleRunIds, true), 'removed execution disappears from recent executions');
communication_visibility_assert(in_array($runningRunId, $visibleRunIds, true), 'active execution remains visible');

$activeRemoval = communication_ops_action_remove_run($pdo, 1, 7, false, $runningRunId, 'test');
communication_visibility_assert(empty($activeRemoval['ok']), 'an active execution cannot be removed');
communication_visibility_assert($pdo->query('SELECT removed_at FROM communication_campaign_runs WHERE id=' . $runningRunId)->fetchColumn() === null, 'blocked removal does not change the active execution');

$foreignRemoval = communication_ops_action_remove_run($pdo, 1, 8, false, $runningRunId, 'test');
communication_visibility_assert(empty($foreignRemoval['ok']), 'another administrator cannot remove the execution');

echo "ALL COMMUNICATION RUN VISIBILITY TESTS PASSED\n";
