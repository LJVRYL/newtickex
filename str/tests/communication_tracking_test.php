<?php
require_once __DIR__ . '/../inc/communication_tracking.php';

function communication_tracking_test_assert($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }
    echo 'PASS: ' . $message . PHP_EOL;
}

$dbFile = tempnam(sys_get_temp_dir(), 'tickex-tracking-');
$pdo = new PDO('sqlite:' . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE tc_orders (id INTEGER PRIMARY KEY AUTOINCREMENT,request_id TEXT,state TEXT,amount REAL,payment_status TEXT)");
communication_tracking_ensure_schema($pdo);

$context = array(
    'organization_id' => 1,
    'admin_id' => 7,
    'campaign_id' => 10,
    'run_id' => 100,
    'recipient_id' => 500,
    'recipient_fingerprint' => sha1('persona@example.com'),
);
$checkout = 'https://str.tickex.com.ar/checkout_totalcoin.php?event=15';
$instagram = 'https://instagram.com/savetherave';
$html = '<html><body><a href="' . $checkout . '">Comprar entradas</a><a href="' . $instagram . '">Instagram</a></body></html>';
$text = 'Comprar entradas: ' . $checkout . "\nInstagram: " . $instagram;
$tracked = communication_tracking_instrument_message($pdo, $context, $html, $text);

communication_tracking_test_assert(strpos($tracked['body_html'], 'communication_click.php?t=') !== false, 'checkout CTA is replaced with a tracked redirect');
communication_tracking_test_assert(strpos($tracked['body_html'], 'communication_open.php?t=') !== false, 'open pixel is appended to the email');
communication_tracking_test_assert(strpos($tracked['body_html'], $instagram) !== false, 'unrelated links remain untouched');
communication_tracking_test_assert(strpos($tracked['body_text'], 'communication_click.php?t=') !== false, 'plain text checkout link is tracked');
communication_tracking_test_assert(strpos($tracked['body_html'], 'persona@example.com') === false, 'recipient email is never exposed in tracking URLs');
communication_tracking_test_assert(!communication_tracking_is_checkout_url('https://malicious.example/checkout_totalcoin.php?event=15'), 'external checkout lookalikes cannot become tracked redirects');
communication_tracking_test_assert((int)$pdo->query('SELECT COUNT(*) FROM communication_tracking_links')->fetchColumn() === 2, 'one open token and one click token are created');

$again = communication_tracking_instrument_message($pdo, $context, $html, $text);
communication_tracking_test_assert((int)$pdo->query('SELECT COUNT(*) FROM communication_tracking_links')->fetchColumn() === 2, 'reprocessing reuses tracking tokens');
communication_tracking_test_assert($again['body_html'] === $tracked['body_html'], 'reprocessed message keeps stable tracking URLs');

$open = $pdo->query("SELECT * FROM communication_tracking_links WHERE kind='open'")->fetch(PDO::FETCH_ASSOC);
$click = $pdo->query("SELECT * FROM communication_tracking_links WHERE kind='click'")->fetch(PDO::FETCH_ASSOC);
$server = array('REMOTE_ADDR' => '203.0.113.5', 'HTTP_USER_AGENT' => 'Test Browser', 'HTTP_REFERER' => 'https://mail.example/');
communication_tracking_record($pdo, $open, 'open', $server);
communication_tracking_record($pdo, $open, 'open', $server);
communication_tracking_record($pdo, $click, 'click', $server);
communication_tracking_record($pdo, $click, 'click', $server);
communication_tracking_test_assert((int)$pdo->query('SELECT COUNT(*) FROM communication_tracking_events')->fetchColumn() === 2, 'repeated automatic events are deduplicated within the hour');

$resolvedClick = communication_tracking_find_link($pdo, $click['token'], 'click');
$redirect = communication_tracking_append_attribution($resolvedClick['destination_url'], $resolvedClick['token']);
communication_tracking_test_assert(strpos($redirect, 'event=15&ct=') !== false, 'click redirect carries the anonymous attribution token');
$attribution = communication_tracking_attribution_for_event($pdo, $click['token'], 15);
communication_tracking_test_assert($attribution && (int)$attribution['campaign_id'] === 10 && (int)$attribution['run_id'] === 100, 'checkout resolves campaign and run attribution');
communication_tracking_test_assert(communication_tracking_attribution_for_event($pdo, $click['token'], 99) === null, 'tracking token cannot be reused for another event');

$stOrder = $pdo->prepare("INSERT INTO tc_orders(request_id,state,amount,payment_status,communication_campaign_id,communication_run_id,communication_recipient_fingerprint,communication_tracking_token) VALUES('order-1','success',1250,'confirmed',:campaign,:run,:fp,:token)");
$stOrder->execute(array(':campaign' => $attribution['campaign_id'], ':run' => $attribution['run_id'], ':fp' => $attribution['recipient_fingerprint'], ':token' => $attribution['tracking_token']));
$metrics = communication_tracking_run_metrics($pdo, 100);
communication_tracking_test_assert($metrics['unique_opens'] === 1 && $metrics['total_opens'] === 1, 'unique and total opens are calculated');
communication_tracking_test_assert($metrics['unique_clicks'] === 1 && $metrics['total_clicks'] === 1, 'unique and total clicks are calculated');
communication_tracking_test_assert($metrics['confirmed_orders'] === 1, 'confirmed purchase is attributed to the campaign run');
communication_tracking_test_assert(abs($metrics['revenue'] - 1250.0) < 0.01, 'confirmed revenue is attributed without duplication');
communication_tracking_test_assert((int)$pdo->query('SELECT admin_id FROM communication_tracking_links LIMIT 1')->fetchColumn() === 7, 'tracking remains scoped to the campaign administrator');

@unlink($dbFile);
echo 'ALL COMMUNICATION TRACKING TESTS PASSED' . PHP_EOL;
