<?php
// Firma los retornos visuales de TotalCoin para asociarlos a una orden local.
// Replica el PaymentToken de SenForms sin guardar secretos en el repositorio.

if (!function_exists('tickex_totalcoin_callback_secret')) {
    function tickex_totalcoin_callback_secret()
    {
        foreach (array('TOTALCOIN_CALLBACK_KEY', 'TOTALCOIN_WEBHOOK_KEY') as $envName) {
            $value = getenv($envName);
            if (is_string($value) && trim($value) !== '') return trim($value);
        }

        $secretFile = dirname(__DIR__, 2) . '/.secrets/totalcoin_webhook_key';
        if (is_readable($secretFile)) {
            $value = trim((string)file_get_contents($secretFile));
            if ($value !== '') return $value;
        }
        return '';
    }
}

if (!function_exists('tickex_totalcoin_callback_signature')) {
    function tickex_totalcoin_callback_signature($reference, $state, $secret)
    {
        return hash_hmac('sha256', 'v1|' . strtolower(trim((string)$state)) . '|' . trim((string)$reference), (string)$secret);
    }
}

if (!function_exists('tickex_totalcoin_build_callbacks')) {
    function tickex_totalcoin_build_callbacks($baseUrl, $reference)
    {
        $secret = tickex_totalcoin_callback_secret();
        if ($secret === '') {
            throw new RuntimeException('TotalCoin callback key is not configured');
        }

        $base = rtrim((string)$baseUrl, '/') . '/totalcoin_callback.php';
        $callbacks = array();
        foreach (array('success' => 'success', 'inproc' => 'inprocess', 'failed' => 'failed') as $key => $state) {
            $callbacks[$key] = $base
                . '?state=' . rawurlencode($state)
                . '&ref=' . rawurlencode((string)$reference)
                . '&token=' . rawurlencode(tickex_totalcoin_callback_signature($reference, $state, $secret));
        }
        return $callbacks;
    }
}

if (!function_exists('tickex_totalcoin_callback_is_valid')) {
    function tickex_totalcoin_callback_is_valid($reference, $state, $providedToken)
    {
        $secret = tickex_totalcoin_callback_secret();
        if ($secret === '' || trim((string)$reference) === '' || trim((string)$providedToken) === '') return false;
        $expected = tickex_totalcoin_callback_signature($reference, $state, $secret);
        return function_exists('hash_equals')
            ? hash_equals($expected, (string)$providedToken)
            : ((strlen($expected) === strlen((string)$providedToken)) && !strcmp($expected, (string)$providedToken));
    }
}
