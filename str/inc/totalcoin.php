<?php
// Helpers para consumir TotalCoin directo desde Tickex (sin pasar por UI de SenForms)
// Cubre login (token Bearer), checkout y armado de URLs de callbacks.

if (!function_exists('tc_config')) {
    function tc_config()
    {
        $prod = getenv('TOTALCOIN_PROD');
        $useProd = ($prod === false) ? true : ($prod !== '0' && strtolower($prod) !== 'false');
        $cfg = array(
            'use_prod'        => $useProd,
            'login_url'       => $useProd ? 'https://apicobranzas.totalcoin.com/api/auth/login'
                                           : 'https://apicobranzastest.totalcoin.com/api/auth/login',
            'checkout_url'    => $useProd ? 'https://apicobranzas.totalcoin.com/api/v2/checkout'
                                           : 'https://apicobranzastest.totalcoin.com/api/v2/checkout',
            'payment_page'    => $useProd ? 'https://ar.totalcoin.com/workspace/checkout/receptor?requestId='
                                           : 'https://test.totalcoin.com/workspace/checkout/receptor?requestId=',
            'username'        => getenv('TOTALCOIN_USER') ?: 'toketera.api',
            'password'        => getenv('TOTALCOIN_PASS') ?: '*CogE7WC2w21bZQP',
            'merchant_prod'   => getenv('TOTALCOIN_MERCHANT_PROD') ?: 'E4FDEE3A-5976-4C66-BEE2-DB72DE84ACC4',
            'merchant_test'   => getenv('TOTALCOIN_MERCHANT_TEST') ?: '9D5E791A-4AF8-40CE-974A-7B1F38E580ED',
            'logo_url'        => getenv('TOTALCOIN_LOGO_URL') ?: null,
            'send_callbacks'  => (getenv('TOTALCOIN_SEND_CALLBACKS') === false) ? true : (getenv('TOTALCOIN_SEND_CALLBACKS') !== '0'),
            'callback_base'   => rtrim(getenv('TOTALCOIN_CALLBACK_BASE') ?: tc_guess_base_url(), '/'),
        );
        $cfg['merchant'] = $cfg['use_prod'] ? $cfg['merchant_prod'] : $cfg['merchant_test'];
        return $cfg;
    }
}

if (!function_exists('tc_guess_base_url')) {
    function tc_guess_base_url()
    {
        $isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] === '443');
        $scheme = $isHttps ? 'https' : 'http';
        $host = !empty($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
        return $scheme . '://' . $host;
    }
}

if (!function_exists('tc_http_post_json')) {
    function tc_http_post_json($url, $payload)
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => array('Content-Type: application/json'),
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 20,
        ));
        $resp = curl_exec($ch);
        if ($resp === false) {
            $errno = curl_errno($ch);
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('TotalCoin login cURL error (errno=' . $errno . '): ' . $err);
        }
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return array($status, $resp);
    }
}

if (!function_exists('tc_http_post_form')) {
    function tc_http_post_form($url, $fields, $bearer)
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => array('Authorization: Bearer ' . $bearer),
            CURLOPT_POSTFIELDS     => http_build_query($fields),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 20,
        ));
        $resp = curl_exec($ch);
        if ($resp === false) {
            $errno = curl_errno($ch);
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('TotalCoin checkout cURL error (errno=' . $errno . '): ' . $err);
        }
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return array($status, $resp);
    }
}

if (!function_exists('tc_authenticate')) {
    function tc_authenticate($cfg, &$state)
    {
        $now = time();
        if (!empty($state['token']) && !empty($state['expires']) && $state['expires'] > ($now + 60)) {
            return $state['token'];
        }
        list($status, $body) = tc_http_post_json($cfg['login_url'], array(
            'Username' => $cfg['username'],
            'Password' => $cfg['password'],
            'DeviceId' => 'tickex',
            'IsNotFull' => true,
        ));
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('TotalCoin login failed HTTP ' . $status . ' body=' . $body);
        }
        $data = json_decode($body, true);
        // Aceptar mayúsculas y minúsculas: Token/token, Expires_in/expires_in
        $tok = $data['Token'] ?? $data['token'] ?? null;
        $exp = $data['Expires_in'] ?? $data['expires_in'] ?? null;
        if (!$data || empty($tok) || empty($exp)) {
            throw new RuntimeException('TotalCoin login parse error: ' . $body);
        }
        $state['token'] = $tok;
        $state['expires'] = $now + (int)$exp;
        return $state['token'];
    }
}

if (!function_exists('tc_build_callbacks')) {
    function tc_build_callbacks($cfg)
    {
        $base = $cfg['callback_base'];
        return array(
            'success' => $base . '/totalcoin_callback.php?state=success',
            'inproc'  => $base . '/totalcoin_callback.php?state=inprocess',
            'failed'  => $base . '/totalcoin_callback.php?state=failed',
        );
    }
}

if (!function_exists('tc_checkout')) {
    function tc_checkout($amount, $concepto, $dni, $referencia, $apellido, $nombre, $email, $logoUrl = null, $callbacks = null)
    {
        static $state = array();
        $cfg = tc_config();
        $token = tc_authenticate($cfg, $state);
        $cb = $callbacks ?: tc_build_callbacks($cfg);

        $value = number_format((float)$amount, 0, '.', '');
        $fields = array(
            'ME' => $cfg['merchant'],
            'AM' => $value,
            'CA' => (string)$concepto,
            'DI' => (string)$dni,
            'ER' => (string)$referencia,
            'NA' => (string)$apellido,
            'NC' => (string)$nombre,
            'PM' => 'CREDITCARD|CASH',
            'EMC'=> (string)$email,
        );
        if (!empty($logoUrl)) {
            $fields['LG'] = $logoUrl;
        }
        if (!empty($cfg['send_callbacks'])) {
            $fields['CS'] = $cb['success'];
            $fields['CP'] = $cb['inproc'];
            $fields['CF'] = $cb['failed'];
        }

        list($status, $body) = tc_http_post_form($cfg['checkout_url'], $fields, $token);
        if ($status === 403 || stripos($body, '403 Forbidden') !== false) {
            // Reintento automático en modo test si estaba en prod
            if ($cfg['use_prod']) {
                $cfg['use_prod'] = false;
                $cfg['checkout_url'] = 'https://apicobranzastest.totalcoin.com/api/v2/checkout';
                $cfg['payment_page'] = 'https://test.totalcoin.com/workspace/checkout/receptor?requestId=';
                $cfg['merchant'] = $cfg['merchant_test'] ?? '9D5E791A-4AF8-40CE-974A-7B1F38E580ED';
                $token = tc_authenticate($cfg, $state);
                $fields['ME'] = $cfg['merchant'];
                list($status, $body) = tc_http_post_form($cfg['checkout_url'], $fields, $token);
                if ($status === 403 || stripos($body, '403 Forbidden') !== false) {
                    throw new RuntimeException('TotalCoin forbidden (403) for referencia ' . $referencia);
                }
            } else {
                throw new RuntimeException('TotalCoin forbidden (403) for referencia ' . $referencia);
            }
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('TotalCoin checkout failed HTTP ' . $status . ' body=' . $body);
        }
        $requestId = '';
        $bodyTrim = trim((string)$body);
        // A veces devuelve JSON (por ejemplo {"requestId":"..."})
        if ($bodyTrim !== '' && ($bodyTrim[0] === '{' || $bodyTrim[0] === '[')) {
            $data = json_decode($bodyTrim, true);
            if (is_array($data)) {
                if (isset($data['requestId'])) $requestId = (string)$data['requestId'];
                if ($requestId === '' && isset($data['RequestId'])) $requestId = (string)$data['RequestId'];
            }
        }
        if ($requestId === '') {
            // Fallback: body crudo o string quoted
            $requestId = trim($bodyTrim, "\"\n\r\t ");
        }
        if ($requestId === '') {
            throw new RuntimeException('TotalCoin checkout empty requestId: ' . $body);
        }
        return $cfg['payment_page'] . $requestId;
    }
}

?>
