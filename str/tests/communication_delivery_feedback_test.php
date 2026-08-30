<?php
require_once __DIR__ . '/../inc/communication_delivery_feedback.php';

function delivery_feedback_assert($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }
    echo 'PASS: ' . $message . PHP_EOL;
}

$dbFile = tempnam(sys_get_temp_dir(), 'tickex-delivery-');
$logFile = tempnam(sys_get_temp_dir(), 'tickex-exim-');
$pdo = new PDO('sqlite:' . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE email_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, trace_id TEXT, to_email TEXT, mail_ok INTEGER DEFAULT 0)");
$pdo->exec("CREATE TABLE communication_campaigns (id INTEGER PRIMARY KEY, created_by_admin_id INTEGER)");
$pdo->exec("CREATE TABLE communication_campaign_runs (id INTEGER PRIMARY KEY, campaign_id INTEGER)");
$pdo->exec("CREATE TABLE communication_campaign_run_recipients (id INTEGER PRIMARY KEY AUTOINCREMENT, run_id INTEGER, campaign_id INTEGER, provider_message_id TEXT, updated_at TEXT)");
$pdo->exec("INSERT INTO email_logs(trace_id,to_email,mail_ok) VALUES('abc123','persona@example.com',1),('dead999','inexistente@example.com',1)");
$pdo->exec("INSERT INTO communication_campaigns(id,created_by_admin_id) VALUES(10,7),(20,8)");
$pdo->exec("INSERT INTO communication_campaign_runs(id,campaign_id) VALUES(100,10),(200,20)");
$pdo->exec("INSERT INTO communication_campaign_run_recipients(run_id,campaign_id,provider_message_id,updated_at) VALUES(100,10,'abc123',CURRENT_TIMESTAMP),(200,20,'dead999',CURRENT_TIMESTAMP)");
communication_delivery_feedback_ensure_schema($pdo);

$first = implode("\n", array(
    '2026-08-29 21:49:20 1xTEST-000001-AA <= servicio@tickex.com.ar U=apache P=local S=605 id=tickex-abc123@tickex.com.ar',
    '2026-08-29 21:49:21 1xTEST-000001-AA == persona@example.com R=send_to_smarthost T=remote_smtp defer (110): Connection timed out',
    '2026-08-29 22:28:40 1xTEST-000001-AA => persona@example.com R=send_to_smarthost T=remote_smtp C="250 OK id=remote-123"',
    '2026-08-29 22:28:40 1xTEST-000001-AA Completed',
    '2026-08-29 22:30:00 1xTEST-000002-BB <= servicio@tickex.com.ar U=apache P=local S=605 id=tickex-dead999@tickex.com.ar',
    '2026-08-29 22:30:02 1xTEST-000002-BB ** inexistente@example.com R=dnslookup T=remote_smtp: SMTP error 550 5.1.1 user unknown',
    '2026-08-29 22:30:02 1xTEST-000002-BB Completed',
)) . "\n";
file_put_contents($logFile, $first);

$result = communication_delivery_feedback_process_log($pdo, $logFile);
delivery_feedback_assert($result['delivered'] === 1, 'delivery is detected');
delivery_feedback_assert($result['deferred'] === 1, 'temporary deferral is detected');
delivery_feedback_assert($result['bounced'] === 1, 'permanent bounce is detected');
delivery_feedback_assert((string)$pdo->query("SELECT delivery_status FROM email_logs WHERE trace_id='abc123'")->fetchColumn() === 'delivered', 'delivery replaces previous deferral');
delivery_feedback_assert((string)$pdo->query("SELECT delivery_status FROM communication_campaign_run_recipients WHERE provider_message_id='abc123'")->fetchColumn() === 'delivered', 'campaign recipient receives real delivery status');
delivery_feedback_assert((string)$pdo->query("SELECT delivery_status FROM email_logs WHERE trace_id='dead999'")->fetchColumn() === 'bounced', 'bounce is persisted on email log');
delivery_feedback_assert((int)$pdo->query('SELECT COUNT(*) FROM communication_delivery_events')->fetchColumn() === 3, 'all unique delivery events are stored');

$second = communication_delivery_feedback_process_log($pdo, $logFile);
delivery_feedback_assert($second['lines'] === 0, 'cursor prevents rereading an unchanged log');
delivery_feedback_assert((int)$pdo->query('SELECT COUNT(*) FROM communication_delivery_events')->fetchColumn() === 3, 'reprocessing is idempotent');

file_put_contents($logFile, '2026-08-29 22:40:00 1xTEST-000001-AA == persona@example.com R=send_to_smarthost T=remote_smtp defer (-53): retry time not reached' . "\n", FILE_APPEND);
communication_delivery_feedback_process_log($pdo, $logFile);
delivery_feedback_assert((string)$pdo->query("SELECT delivery_status FROM email_logs WHERE trace_id='abc123'")->fetchColumn() === 'delivered', 'late deferral cannot downgrade a delivered message');

$metrics = communication_delivery_feedback_metrics($pdo, 365);
delivery_feedback_assert($metrics['delivered'] === 1 && $metrics['deferred'] === 2 && $metrics['bounced'] === 1, 'delivery metrics are calculated');
delivery_feedback_assert($metrics['hard_bounces'] === 1, 'invalid mailbox bounce is classified for review');
$ownMetrics = communication_delivery_feedback_metrics($pdo, 365, 7, false);
delivery_feedback_assert($ownMetrics['delivered'] === 1 && $ownMetrics['deferred'] === 2 && $ownMetrics['bounced'] === 0, 'administrator metrics include only own campaigns');

@unlink($logFile);
@unlink($dbFile);
echo 'ALL DELIVERY FEEDBACK TESTS PASSED' . PHP_EOL;
