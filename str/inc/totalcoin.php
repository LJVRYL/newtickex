<?php
// Helpers para consumir TotalCoin directo desde Tickex (sin pasar por UI de SenForms)
// Cubre login (token Bearer), checkout y armado de URLs de callbacks.

if (!function_exists('tc__sanitize_log_value')) {
    function tc__sanitize_log_value($s)
    {
        $txt = (string)$s;
        // Evitar loguear tokens/credenciales por si aparecen en respuestas.
        $txt = preg_replace('/("(?:Token|token|access_token)"\s*:\s*")([^"]+)(")/i', '$1***$3', $txt);
        $txt = preg_replace('/("(?:Password|password)"\s*:\s*")([^"]+)(")/i', '$1***$3', $txt);
        $txt = preg_replace('/(Authorization:\s*Bearer\s+)(\S+)/i', '$1***', $txt);
        return $txt;
    }
}

if (!function_exists('tc__debug_log_path')) {
    function tc__debug_log_path()
    {
        // Devuelve lista de candidatos (no valida permisos acá).
        // __DIR__ aquí es .../str/inc
        $candidates = array();

        // Repo root esperado: .../web/uploads
        $candidates[] = dirname(__DIR__, 2) . '/uploads/totalcoin_debug.log';
        // Fallback: por si la estructura cambia
        $candidates[] = dirname(__DIR__, 3) . '/web/uploads/totalcoin_debug.log';

        $cwd = @getcwd();
        if (is_string($cwd) && $cwd !== '') {
            $candidates[] = rtrim($cwd, '/\\') . '/uploads/totalcoin_debug.log';
        }

        $tmp = rtrim((string)sys_get_temp_dir(), '/\\');
        if ($tmp !== '') {
            $candidates[] = $tmp . '/totalcoin_debug.log';
        }

        // Devolver el primero (la escritura real la decide tc_debug_log)
        return $candidates;
    }
}

if (!function_exists('tc_debug_log')) {
    function tc_debug_log($debugId, $message, $context = array())
    {
        $paths = tc__debug_log_path();
        if (!$paths || !is_array($paths) || empty($paths)) return array('ok' => false, 'path' => null, 'error' => 'no_paths');
        $line = array(
            'ts' => date('c'),
            'id' => (string)$debugId,
            'msg' => tc__sanitize_log_value($message),
            'ctx' => $context,
        );
        // Sanitizar context de forma defensiva
        $json = json_encode($line, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            $json = '{"ts":"' . date('c') . '","id":"' . tc__sanitize_log_value($debugId) . '","msg":"' . tc__sanitize_log_value($message) . '"}';
        }

        foreach ($paths as $path) {
            $dir = dirname((string)$path);
            if (!@is_dir($dir)) {
                continue;
            }
            $ok = @file_put_contents((string)$path, $json . "\n", FILE_APPEND | LOCK_EX);
            if ($ok !== false) {
                return array('ok' => true, 'path' => (string)$path, 'error' => null);
            }
        }
        return array('ok' => false, 'path' => null, 'error' => 'write_failed');
    }
}

if (!function_exists('tc_credentials')) {
    function tc_credentials()
    {
        $username = getenv('TOTALCOIN_USER');
        $password = getenv('TOTALCOIN_PASS');
        $username = ($username === false) ? '' : trim((string)$username);
        $password = ($password === false) ? '' : (string)$password;

        $configuredFile = getenv('TOTALCOIN_CREDENTIALS_FILE');
        $candidates = array();
        if (is_string($configuredFile) && trim($configuredFile) !== '') {
            $candidates[] = trim($configuredFile);
        }
        // __DIR__ = .../web/str/inc; el archivo seguro vive fuera del DocumentRoot de STR.
        $candidates[] = dirname(dirname(__DIR__)) . '/.secrets/totalcoin_credentials.php';

        foreach (array_unique($candidates) as $credentialsFile) {
            if ($username !== '' && $password !== '') break;
            if (!is_file($credentialsFile) || !is_readable($credentialsFile)) continue;
            $loaded = include $credentialsFile;
            if (!is_array($loaded)) continue;
            if ($username === '' && isset($loaded['username'])) {
                $username = trim((string)$loaded['username']);
            }
            if ($password === '' && isset($loaded['password'])) {
                $password = (string)$loaded['password'];
            }
        }

        if ($username === '' || $password === '') {
            throw new RuntimeException('TotalCoin: credenciales no configuradas.');
        }

        return array('username' => $username, 'password' => $password);
    }
}

if (!function_exists('tc_config')) {
    function tc_config()
    {
        $credentials = tc_credentials();
        $prod = getenv('TOTALCOIN_PROD');
        $useProd = ($prod === false) ? true : ($prod !== '0' && strtolower($prod) !== 'false');
        $cfg = array(
            'use_prod'        => $useProd,
            'login_url'       => $useProd ? 'https://apicobranzas.totalcoin.com/api/auth/login'
                                           : 'https://apicobranzastest.totalcoin.com/api/auth/login',
            'checkout_url'    => $useProd ? 'https://apicobranzas.totalcoin.com/api/v2/checkout'
                                           : 'https://apicobranzastest.totalcoin.com/api/v2/checkout',
            'status_url'      => $useProd ? 'https://checkoutbackend.totalcoin.com/api/v1/intention/status/'
                                           : 'https://checkoutbackend.ltest.totalcoin.com/api/v1/intention/status/',
            'payment_page'    => $useProd ? 'https://ar.totalcoin.com/workspace/checkout/receptor?requestId='
                                           : 'https://test.totalcoin.com/workspace/checkout/receptor?requestId=',
            'username'        => $credentials['username'],
            'password'        => $credentials['password'],
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
        if (!function_exists('curl_init')) {
            throw new RuntimeException('TotalCoin: cURL no está disponible en el servidor (extensión curl no instalada/habilitada).');
        }
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
        if (!function_exists('curl_init')) {
            throw new RuntimeException('TotalCoin: cURL no está disponible en el servidor (extensión curl no instalada/habilitada).');
        }
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

if (!function_exists('tc_http_get')) {
    function tc_http_get($url, $bearer)
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('TotalCoin: cURL no esta disponible en el servidor.');
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => array('Authorization: Bearer ' . $bearer, 'Accept: application/json'),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
        ));
        $resp = curl_exec($ch);
        if ($resp === false) {
            $errno = curl_errno($ch);
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('TotalCoin status cURL error (errno=' . $errno . '): ' . $err);
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
        $tok = isset($data['Token']) ? $data['Token'] : (isset($data['token']) ? $data['token'] : null);
        $exp = isset($data['Expires_in']) ? $data['Expires_in'] : (isset($data['expires_in']) ? $data['expires_in'] : null);
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

if (!function_exists('tc_checkout_status')) {
    function tc_checkout_status($reference)
    {
        static $state = array();
        $reference = trim((string)$reference);
        if ($reference === '') throw new InvalidArgumentException('TotalCoin reference is required');

        $cfg = tc_config();
        $token = tc_authenticate($cfg, $state);
        list($status, $body) = tc_http_get($cfg['status_url'] . rawurlencode($reference), $token);
        if ($status === 404) {
            return array('found' => false, 'http_status' => 404, 'data' => null);
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('TotalCoin status failed HTTP ' . $status . ' body=' . tc__sanitize_log_value($body));
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new RuntimeException('TotalCoin status returned invalid JSON');
        }
        return array('found' => true, 'http_status' => $status, 'data' => $data);
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

        $debugId = 'TC-' . date('Ymd-His') . '-' . substr(sha1($cfg['checkout_url'] . '|' . $referencia . '|' . microtime(true)), 0, 8);
        $debugResultPre = null;
        try {
            if (function_exists('tc_debug_log')) {
                $debugResultPre = tc_debug_log($debugId, 'TotalCoin checkout request', array(
                    'callback_base' => $cfg['callback_base'],
                    'checkout_url' => $cfg['checkout_url'],
                    'fields' => $fields,
                    'CS' => isset($fields['CS']) ? $fields['CS'] : null,
                    'CP' => isset($fields['CP']) ? $fields['CP'] : null,
                    'CF' => isset($fields['CF']) ? $fields['CF'] : null,
                ));
            }
        } catch (Exception $_e) {
            // ignore
        }

        list($status, $body) = tc_http_post_form($cfg['checkout_url'], $fields, $token);
        if ($status === 403 || stripos($body, '403 Forbidden') !== false) {
            // Reintento automático en modo test si estaba en prod
            if ($cfg['use_prod']) {
                $cfg['use_prod'] = false;
                $cfg['checkout_url'] = 'https://apicobranzastest.totalcoin.com/api/v2/checkout';
                $cfg['payment_page'] = 'https://test.totalcoin.com/workspace/checkout/receptor?requestId=';
                $cfg['merchant'] = isset($cfg['merchant_test']) ? $cfg['merchant_test'] : '9D5E791A-4AF8-40CE-974A-7B1F38E580ED';
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

        try {
            if (function_exists('tc_debug_log')) {
                tc_debug_log($debugId, 'TotalCoin checkout response', array(
                    'status' => $status,
                    'body' => tc__sanitize_log_value($body),
                    'requestId' => $requestId,
                    'callback_base' => $cfg['callback_base'],
                    'checkout_url' => $cfg['checkout_url'],
                    'log_result' => $debugResultPre,
                ));
            }
        } catch (Exception $_e) {
            // ignore
        }
        return $cfg['payment_page'] . $requestId;
    }
}

?>
