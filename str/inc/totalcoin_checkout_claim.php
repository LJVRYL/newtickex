<?php

if (!function_exists('tickex_totalcoin_new_reference')) {
    function tickex_totalcoin_new_reference($seed)
    {
        $seed = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$seed);
        if ($seed === '') $seed = 'event';
        $nonce = '';
        if (function_exists('random_bytes')) {
            try {
                $nonce = bin2hex(random_bytes(4));
            } catch (Exception $e) {
                $nonce = '';
            }
        }
        if ($nonce === '') $nonce = substr(sha1(uniqid(mt_rand(), true)), 0, 8);
        return 'str-' . $seed . '-' . time() . '-' . $nonce;
    }
}

if (!function_exists('tickex_totalcoin_checkout_claim_ensure_schema')) {
    function tickex_totalcoin_checkout_claim_ensure_schema($pdo)
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS totalcoin_checkout_claims (
            ref TEXT PRIMARY KEY,
            fingerprint TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'creating',
            request_id TEXT,
            payment_url TEXT,
            last_error TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");
    }
}

if (!function_exists('tickex_totalcoin_checkout_fingerprint')) {
    function tickex_totalcoin_checkout_fingerprint($eventId, $amount, $email, $ticketsJson)
    {
        return hash('sha256', implode('|', array(
            (string)(int)$eventId,
            number_format((float)$amount, 2, '.', ''),
            strtolower(trim((string)$email)),
            (string)$ticketsJson,
        )));
    }
}

if (!function_exists('tickex_totalcoin_checkout_claim')) {
    function tickex_totalcoin_checkout_claim($pdo, $ref, $fingerprint, $staleSeconds)
    {
        tickex_totalcoin_checkout_claim_ensure_schema($pdo);
        $ref = trim((string)$ref);
        $fingerprint = trim((string)$fingerprint);
        $staleSeconds = max(30, (int)$staleSeconds);
        if ($ref === '' || $fingerprint === '') {
            return array('result' => 'invalid');
        }

        $stInsert = $pdo->prepare("INSERT OR IGNORE INTO totalcoin_checkout_claims (ref,fingerprint,status,created_at,updated_at) VALUES (:ref,:fp,'creating',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)");
        $stInsert->execute(array(':ref' => $ref, ':fp' => $fingerprint));
        if ($stInsert->rowCount() === 1) {
            return array('result' => 'acquired');
        }

        $stLoad = $pdo->prepare('SELECT * FROM totalcoin_checkout_claims WHERE ref = :ref LIMIT 1');
        $stLoad->execute(array(':ref' => $ref));
        $row = $stLoad->fetch(PDO::FETCH_ASSOC);
        if (!$row) return array('result' => 'pending');
        if (!hash_equals((string)$row['fingerprint'], $fingerprint)) {
            return array('result' => 'conflict');
        }
        if ((string)$row['status'] === 'ready' && !empty($row['payment_url'])) {
            return array('result' => 'ready', 'request_id' => (string)$row['request_id'], 'payment_url' => (string)$row['payment_url']);
        }

        $modifier = '-' . $staleSeconds . ' seconds';
        $stTake = $pdo->prepare("UPDATE totalcoin_checkout_claims SET status='creating',last_error=NULL,updated_at=CURRENT_TIMESTAMP WHERE ref=:ref AND fingerprint=:fp AND (status='failed' OR updated_at < datetime('now',:modifier))");
        $stTake->execute(array(':ref' => $ref, ':fp' => $fingerprint, ':modifier' => $modifier));
        if ($stTake->rowCount() === 1) return array('result' => 'acquired');
        return array('result' => 'pending');
    }
}

if (!function_exists('tickex_totalcoin_checkout_claim_ready')) {
    function tickex_totalcoin_checkout_claim_ready($pdo, $ref, $fingerprint, $requestId, $paymentUrl)
    {
        $st = $pdo->prepare("UPDATE totalcoin_checkout_claims SET status='ready',request_id=:rid,payment_url=:url,last_error=NULL,updated_at=CURRENT_TIMESTAMP WHERE ref=:ref AND fingerprint=:fp");
        $st->execute(array(':rid' => (string)$requestId, ':url' => (string)$paymentUrl, ':ref' => (string)$ref, ':fp' => (string)$fingerprint));
    }
}

if (!function_exists('tickex_totalcoin_checkout_claim_failed')) {
    function tickex_totalcoin_checkout_claim_failed($pdo, $ref, $fingerprint, $error)
    {
        $st = $pdo->prepare("UPDATE totalcoin_checkout_claims SET status='failed',last_error=:err,updated_at=CURRENT_TIMESTAMP WHERE ref=:ref AND fingerprint=:fp AND status='creating'");
        $st->execute(array(':err' => substr((string)$error, 0, 1000), ':ref' => (string)$ref, ':fp' => (string)$fingerprint));
    }
}
