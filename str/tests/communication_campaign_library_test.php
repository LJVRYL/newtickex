<?php
$GLOBALS['campaign_library_pdo'] = new PDO('sqlite::memory:');
$GLOBALS['campaign_library_pdo']->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function db()
{
    return $GLOBALS['campaign_library_pdo'];
}

require_once dirname(__DIR__) . '/inc/communication_execution_engine.php';

function campaign_library_assert($condition, $message)
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

$pdo->exec("INSERT INTO communication_campaigns (organization_id,created_by_admin_id,name,slug,status) VALUES (1,7,'Reutilizable','reutilizable','draft')");
$favoriteId = (int)$pdo->lastInsertId();
$favorite = communication_campaigns_toggle_favorite($pdo, 1, 7, false, $favoriteId);
campaign_library_assert(!empty($favorite['ok']) && !empty($favorite['is_favorite']), 'a campaign can be marked as favorite');
$notFavorite = communication_campaigns_toggle_favorite($pdo, 1, 7, false, $favoriteId);
campaign_library_assert(!empty($notFavorite['ok']) && empty($notFavorite['is_favorite']), 'a favorite campaign can be unmarked');

$pdo->exec("INSERT INTO communication_campaigns (organization_id,created_by_admin_id,name,slug,status) VALUES (1,7,'Un solo uso','un-solo-uso','sent')");
$singleUseId = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO communication_campaign_runs (organization_id,campaign_id,status,resolved_recipients,processed_count,accepted_count) VALUES (1," . $singleUseId . ",'completed',1,1,1)");
$runId = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO communication_campaign_run_recipients (run_id,campaign_id,recipient_email,recipient_fingerprint,status) VALUES (" . $runId . "," . $singleUseId . ",'persona@example.com','fp-campaign-library','accepted')");
$removed = communication_campaigns_remove($pdo, 1, 7, false, $singleUseId);
campaign_library_assert(!empty($removed['ok']), 'a completed one-use campaign can be removed from the library');
campaign_library_assert((string)$pdo->query('SELECT removed_at FROM communication_campaigns WHERE id=' . $singleUseId)->fetchColumn() !== '', 'campaign removal is recorded as a soft delete');
campaign_library_assert((int)$pdo->query('SELECT COUNT(*) FROM communication_campaign_runs WHERE campaign_id=' . $singleUseId)->fetchColumn() === 1, 'historical execution is preserved');
campaign_library_assert((int)$pdo->query('SELECT COUNT(*) FROM communication_campaign_run_recipients WHERE campaign_id=' . $singleUseId)->fetchColumn() === 1, 'historical recipients are preserved');

$pdo->exec("INSERT INTO communication_campaigns (organization_id,created_by_admin_id,name,slug,status) VALUES (1,7,'En curso','en-curso','sending')");
$activeId = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO communication_campaign_runs (organization_id,campaign_id,status) VALUES (1," . $activeId . ",'running')");
$activeRemoval = communication_campaigns_remove($pdo, 1, 7, false, $activeId);
campaign_library_assert(empty($activeRemoval['ok']), 'a sending campaign cannot be removed');
campaign_library_assert($pdo->query('SELECT removed_at FROM communication_campaigns WHERE id=' . $activeId)->fetchColumn() === null, 'blocked removal leaves active campaign unchanged');

$foreignFavorite = communication_campaigns_toggle_favorite($pdo, 1, 8, false, $favoriteId);
campaign_library_assert(empty($foreignFavorite['ok']), 'another administrator cannot manage the campaign');

echo "ALL COMMUNICATION CAMPAIGN LIBRARY TESTS PASSED\n";
