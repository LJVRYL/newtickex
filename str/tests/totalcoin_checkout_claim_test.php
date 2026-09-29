<?php
require_once __DIR__ . '/../inc/totalcoin_checkout_claim.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function claim_assert($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: " . $message . PHP_EOL);
        exit(1);
    }
    echo "PASS: " . $message . PHP_EOL;
}

$refA = tickex_totalcoin_new_reference('event-16');
$refB = tickex_totalcoin_new_reference('event-16');
claim_assert($refA !== $refB, 'generated references are unique');

$tickets = json_encode(array(array('id' => 1, 'qty' => 1, 'price' => 1000)));
$fp = tickex_totalcoin_checkout_fingerprint(16, 1000, 'buyer@example.com', $tickets);
$first = tickex_totalcoin_checkout_claim($pdo, $refA, $fp, 120);
claim_assert($first['result'] === 'acquired', 'first request acquires checkout claim');

$second = tickex_totalcoin_checkout_claim($pdo, $refA, $fp, 120);
claim_assert($second['result'] === 'pending', 'duplicate request cannot call gateway while creating');

tickex_totalcoin_checkout_claim_ready($pdo, $refA, $fp, 'request-123', 'https://checkout.invalid/?requestId=request-123');
$third = tickex_totalcoin_checkout_claim($pdo, $refA, $fp, 120);
claim_assert($third['result'] === 'ready', 'duplicate request reuses completed checkout');
claim_assert($third['request_id'] === 'request-123', 'reused checkout keeps original request id');

$otherFp = tickex_totalcoin_checkout_fingerprint(16, 2000, 'buyer@example.com', $tickets);
$conflict = tickex_totalcoin_checkout_claim($pdo, $refA, $otherFp, 120);
claim_assert($conflict['result'] === 'conflict', 'same reference cannot represent different purchase');

$count = (int)$pdo->query('SELECT COUNT(*) FROM totalcoin_checkout_claims')->fetchColumn();
claim_assert($count === 1, 'exactly one gateway claim exists for the reference');

$retryAttempts = 0;
$retryResult = tickex_sqlite_write_with_retry(function () use (&$retryAttempts) {
    $retryAttempts++;
    if ($retryAttempts < 3) throw new PDOException('SQLSTATE[HY000]: General error: 5 database is locked');
    return 'written';
}, 5, 0);
claim_assert($retryResult === 'written' && $retryAttempts === 3, 'temporary SQLite locks are retried');

$nonLockAttempts = 0;
try {
    tickex_sqlite_write_with_retry(function () use (&$nonLockAttempts) {
        $nonLockAttempts++;
        throw new PDOException('SQLSTATE[HY000]: General error: 1 no such table');
    }, 5, 0);
    claim_assert(false, 'non-lock database errors are not retried');
} catch (PDOException $e) {
    claim_assert($nonLockAttempts === 1, 'non-lock database errors are not retried');
}

$buySource = file_get_contents(__DIR__ . '/../comprar.php');
claim_assert(strpos($buySource, "inc/totalcoin_checkout_claim.php") !== false, 'public checkout loads the reference generator');
claim_assert(strpos($buySource, 'Cache-Control: no-store') !== false, 'public checkout redirect is never cached');
claim_assert(strpos($buySource, 'tickex_totalcoin_new_reference') !== false, 'public checkout redirect creates a random reference');
