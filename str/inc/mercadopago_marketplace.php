<?php

/* Mercado Pago Split Payments 1:1 (Checkout Pro) for Tickex. */

if (!function_exists('tickex_mp_base64url_encode')) {
    function tickex_mp_base64url_encode($value)
    {
        return rtrim(strtr(base64_encode((string)$value), '+/', '-_'), '=');
    }
}

if (!function_exists('tickex_mp_base64url_decode')) {
    function tickex_mp_base64url_decode($value)
    {
        $value = strtr((string)$value, '-_', '+/');
        $padding = strlen($value) % 4;
        if ($padding) $value .= str_repeat('=', 4 - $padding);
        return base64_decode($value, true);
    }
}

if (!function_exists('tickex_mp_config')) {
    function tickex_mp_config()
    {
        $config = array();
        $candidates = array();
        $fromEnv = getenv('TICKEX_MP_CONFIG_FILE');
        if (is_string($fromEnv) && $fromEnv !== '') $candidates[] = $fromEnv;
        $candidates[] = dirname(__DIR__, 2) . '/.secrets/mercadopago_marketplace.php';
        $candidates[] = dirname(__DIR__) . '/.secrets/mercadopago_marketplace.php';
        foreach ($candidates as $file) {
            if (!is_file($file)) continue;
            $loaded = require $file;
            if (is_array($loaded)) $config = array_merge($config, $loaded);
            break;
        }

        $envMap = array(
            'client_id' => 'TICKEX_MP_CLIENT_ID',
            'client_secret' => 'TICKEX_MP_CLIENT_SECRET',
            'redirect_uri' => 'TICKEX_MP_REDIRECT_URI',
            'webhook_secret' => 'TICKEX_MP_WEBHOOK_SECRET',
            'encryption_key' => 'TICKEX_MP_ENCRYPTION_KEY',
            'site_url' => 'TICKEX_SITE_URL',
            'sandbox' => 'TICKEX_MP_SANDBOX',
        );
        foreach ($envMap as $key => $envName) {
            $value = getenv($envName);
            if (is_string($value) && $value !== '') $config[$key] = $value;
        }
        if (empty($config['redirect_uri'])) $config['redirect_uri'] = 'https://str.tickex.com.ar/mercadopago_oauth_callback.php';
        if (empty($config['site_url'])) $config['site_url'] = 'https://str.tickex.com.ar';
        $config['site_url'] = rtrim((string)$config['site_url'], '/');
        $sandbox = isset($config['sandbox']) ? strtolower(trim((string)$config['sandbox'])) : '';
        $config['sandbox'] = in_array($sandbox, array('1', 'true', 'yes', 'on'), true);
        return $config;
    }
}

if (!function_exists('tickex_mp_configured')) {
    function tickex_mp_configured($config = null)
    {
        if (!is_array($config)) $config = tickex_mp_config();
        return !empty($config['client_id']) && !empty($config['client_secret']) && !empty($config['encryption_key']);
    }
}

if (!function_exists('tickex_mp_ensure_schema')) {
    function tickex_mp_ensure_schema($pdo)
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS mercadopago_marketplace_accounts (
            admin_id INTEGER PRIMARY KEY,
            mp_user_id TEXT,
            access_token_enc TEXT,
            refresh_token_enc TEXT,
            public_key TEXT,
            expires_at TEXT,
            status TEXT NOT NULL DEFAULT 'disconnected',
            last_error TEXT,
            connected_at TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS mercadopago_event_configs (
            event_id INTEGER PRIMARY KEY,
            admin_id INTEGER NOT NULL,
            provider TEXT NOT NULL DEFAULT 'totalcoin',
            marketplace_fee_percent REAL NOT NULL DEFAULT 0,
            enabled INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_mp_event_configs_admin ON mercadopago_event_configs(admin_id, provider)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS mercadopago_platform_settings (
            id INTEGER PRIMARY KEY CHECK (id = 1),
            enforcement_enabled INTEGER NOT NULL DEFAULT 0,
            total_cost_target_percent REAL NOT NULL DEFAULT 10,
            mp_cost_estimate_percent REAL NOT NULL DEFAULT 0,
            updated_by_admin_id INTEGER,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");
        $pdo->exec("INSERT OR IGNORE INTO mercadopago_platform_settings (id,enforcement_enabled,total_cost_target_percent,mp_cost_estimate_percent) VALUES (1,0,10,0)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS mercadopago_admin_policies (
            admin_id INTEGER PRIMARY KEY,
            account_type TEXT NOT NULL DEFAULT 'client',
            platform_fee_override_percent REAL,
            updated_by_admin_id INTEGER,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS mercadopago_oauth_states (
            state_hash TEXT PRIMARY KEY,
            admin_id INTEGER NOT NULL,
            expires_at TEXT NOT NULL,
            used_at TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS mercadopago_webhook_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            event_key TEXT NOT NULL UNIQUE,
            action TEXT,
            payment_id TEXT,
            order_id INTEGER,
            status TEXT NOT NULL,
            payload_json TEXT,
            error_text TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");
        tickex_mp_ensure_order_columns($pdo);
    }
}

if (!function_exists('tickex_mp_ensure_order_columns')) {
    function tickex_mp_ensure_order_columns($pdo)
    {
        $columns = array();
        $rows = $pdo->query("PRAGMA table_info(tc_orders)")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) $columns[(string)$row['name']] = true;
        $wanted = array(
            'payment_provider' => 'TEXT',
            'provider_payment_id' => 'TEXT',
            'provider_preference_id' => 'TEXT',
            'marketplace_fee' => 'REAL',
            'seller_admin_id' => 'INTEGER',
            'ticket_subtotal' => 'REAL',
            'service_fee_amount' => 'REAL',
            'service_fee_percent' => 'REAL',
            'mp_cost_estimate_percent' => 'REAL',
        );
        foreach ($wanted as $name => $type) {
            if (!isset($columns[$name])) $pdo->exec('ALTER TABLE tc_orders ADD COLUMN ' . $name . ' ' . $type);
        }
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_tc_orders_provider_payment ON tc_orders(payment_provider, provider_payment_id)");
    }
}

if (!function_exists('tickex_mp_encrypt')) {
    function tickex_mp_encrypt($plain, $config = null)
    {
        if (!is_array($config)) $config = tickex_mp_config();
        if (empty($config['encryption_key'])) throw new RuntimeException('Falta la clave de cifrado de Mercado Pago.');
        if (!function_exists('openssl_encrypt')) throw new RuntimeException('OpenSSL no esta disponible.');
        $key = hash('sha256', (string)$config['encryption_key'], true);
        $iv = random_bytes(16);
        $cipher = openssl_encrypt((string)$plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        if ($cipher === false) throw new RuntimeException('No se pudo cifrar la credencial.');
        $mac = hash_hmac('sha256', $iv . $cipher, $key, true);
        return 'v1.' . tickex_mp_base64url_encode($iv) . '.' . tickex_mp_base64url_encode($cipher) . '.' . tickex_mp_base64url_encode($mac);
    }
}

if (!function_exists('tickex_mp_decrypt')) {
    function tickex_mp_decrypt($encoded, $config = null)
    {
        if (!is_array($config)) $config = tickex_mp_config();
        if (empty($config['encryption_key'])) throw new RuntimeException('Falta la clave de cifrado de Mercado Pago.');
        $parts = explode('.', (string)$encoded);
        if (count($parts) !== 4 || $parts[0] !== 'v1') throw new RuntimeException('Credencial cifrada invalida.');
        $iv = tickex_mp_base64url_decode($parts[1]);
        $cipher = tickex_mp_base64url_decode($parts[2]);
        $mac = tickex_mp_base64url_decode($parts[3]);
        if ($iv === false || $cipher === false || $mac === false) throw new RuntimeException('Credencial cifrada invalida.');
        $key = hash('sha256', (string)$config['encryption_key'], true);
        $expected = hash_hmac('sha256', $iv . $cipher, $key, true);
        if (!hash_equals($expected, $mac)) throw new RuntimeException('La credencial no supera la verificacion de integridad.');
        $plain = openssl_decrypt($cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        if ($plain === false) throw new RuntimeException('No se pudo descifrar la credencial.');
        return $plain;
    }
}

if (!function_exists('tickex_mp_event_owner_id')) {
    function tickex_mp_event_owner_id($pdo, $eventId)
    {
        $st = $pdo->prepare('SELECT creado_por_admin_id FROM eventos WHERE id = :id LIMIT 1');
        $st->execute(array(':id' => (int)$eventId));
        $value = $st->fetchColumn();
        return $value === false ? 0 : (int)$value;
    }
}

if (!function_exists('tickex_mp_account')) {
    function tickex_mp_account($pdo, $adminId, $withSecrets = false)
    {
        tickex_mp_ensure_schema($pdo);
        $st = $pdo->prepare('SELECT * FROM mercadopago_marketplace_accounts WHERE admin_id = :id LIMIT 1');
        $st->execute(array(':id' => (int)$adminId));
        $account = $st->fetch(PDO::FETCH_ASSOC);
        if (!$account) return null;
        if ($withSecrets) {
            $account['access_token'] = !empty($account['access_token_enc']) ? tickex_mp_decrypt($account['access_token_enc']) : '';
            $account['refresh_token'] = !empty($account['refresh_token_enc']) ? tickex_mp_decrypt($account['refresh_token_enc']) : '';
        }
        unset($account['access_token_enc'], $account['refresh_token_enc']);
        return $account;
    }
}

if (!function_exists('tickex_mp_save_account_tokens')) {
    function tickex_mp_save_account_tokens($pdo, $adminId, array $tokens)
    {
        tickex_mp_ensure_schema($pdo);
        $accessToken = isset($tokens['access_token']) ? trim((string)$tokens['access_token']) : '';
        $refreshToken = isset($tokens['refresh_token']) ? trim((string)$tokens['refresh_token']) : '';
        $userId = isset($tokens['user_id']) ? trim((string)$tokens['user_id']) : '';
        if ($accessToken === '' || $userId === '') throw new RuntimeException('Mercado Pago no devolvio las credenciales esperadas.');
        $expiresIn = isset($tokens['expires_in']) ? max(300, (int)$tokens['expires_in']) : 15552000;
        $expiresAt = gmdate('Y-m-d H:i:s', time() + $expiresIn);
        $existing = tickex_mp_account($pdo, $adminId, true);
        if ($refreshToken === '' && $existing && !empty($existing['refresh_token'])) $refreshToken = $existing['refresh_token'];
        $st = $pdo->prepare("INSERT OR REPLACE INTO mercadopago_marketplace_accounts
            (admin_id, mp_user_id, access_token_enc, refresh_token_enc, public_key, expires_at, status, last_error, connected_at, created_at, updated_at)
            VALUES (:admin, :user, :access, :refresh, :public, :expires, 'connected', NULL, COALESCE((SELECT connected_at FROM mercadopago_marketplace_accounts WHERE admin_id=:admin), CURRENT_TIMESTAMP), COALESCE((SELECT created_at FROM mercadopago_marketplace_accounts WHERE admin_id=:admin), CURRENT_TIMESTAMP), CURRENT_TIMESTAMP)");
        $st->execute(array(
            ':admin' => (int)$adminId,
            ':user' => $userId,
            ':access' => tickex_mp_encrypt($accessToken),
            ':refresh' => $refreshToken !== '' ? tickex_mp_encrypt($refreshToken) : null,
            ':public' => isset($tokens['public_key']) ? (string)$tokens['public_key'] : null,
            ':expires' => $expiresAt,
        ));
        return tickex_mp_account($pdo, $adminId, false);
    }
}

if (!function_exists('tickex_mp_http_json')) {
    function tickex_mp_http_json($method, $url, array $headers, $body = null)
    {
        if (!function_exists('curl_init')) throw new RuntimeException('cURL no esta disponible.');
        $ch = curl_init((string)$url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 25);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper((string)$method));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($errno) throw new RuntimeException('Mercado Pago no respondio: ' . $error);
        $decoded = json_decode((string)$raw, true);
        if ($status < 200 || $status >= 300) {
            $message = is_array($decoded) && isset($decoded['message']) ? (string)$decoded['message'] : ('HTTP ' . $status);
            throw new RuntimeException('Mercado Pago rechazo la solicitud: ' . $message);
        }
        if (!is_array($decoded)) throw new RuntimeException('Mercado Pago devolvio una respuesta invalida.');
        return $decoded;
    }
}

if (!function_exists('tickex_mp_begin_oauth')) {
    function tickex_mp_begin_oauth($pdo, $adminId)
    {
        $config = tickex_mp_config();
        if (!tickex_mp_configured($config)) throw new RuntimeException('La aplicacion Mercado Pago todavia no esta configurada.');
        tickex_mp_ensure_schema($pdo);
        $state = tickex_mp_base64url_encode(random_bytes(32));
        $hash = hash('sha256', $state);
        $pdo->prepare("DELETE FROM mercadopago_oauth_states WHERE expires_at < CURRENT_TIMESTAMP OR admin_id = :admin")->execute(array(':admin' => (int)$adminId));
        $st = $pdo->prepare("INSERT INTO mercadopago_oauth_states (state_hash, admin_id, expires_at) VALUES (:hash, :admin, datetime('now', '+15 minutes'))");
        $st->execute(array(':hash' => $hash, ':admin' => (int)$adminId));
        return 'https://auth.mercadopago.com.ar/authorization?' . http_build_query(array(
            'client_id' => (string)$config['client_id'],
            'response_type' => 'code',
            'platform_id' => 'mp',
            'redirect_uri' => (string)$config['redirect_uri'],
            'state' => $state,
        ), '', '&', PHP_QUERY_RFC3986);
    }
}

if (!function_exists('tickex_mp_consume_oauth_state')) {
    function tickex_mp_consume_oauth_state($pdo, $state)
    {
        tickex_mp_ensure_schema($pdo);
        $hash = hash('sha256', (string)$state);
        $st = $pdo->prepare("SELECT admin_id FROM mercadopago_oauth_states WHERE state_hash=:hash AND used_at IS NULL AND expires_at >= CURRENT_TIMESTAMP LIMIT 1");
        $st->execute(array(':hash' => $hash));
        $adminId = $st->fetchColumn();
        if ($adminId === false) return 0;
        $up = $pdo->prepare('UPDATE mercadopago_oauth_states SET used_at=CURRENT_TIMESTAMP WHERE state_hash=:hash AND used_at IS NULL');
        $up->execute(array(':hash' => $hash));
        return $up->rowCount() === 1 ? (int)$adminId : 0;
    }
}

if (!function_exists('tickex_mp_exchange_oauth_code')) {
    function tickex_mp_exchange_oauth_code($code, $state, $http = null)
    {
        $config = tickex_mp_config();
        $payload = http_build_query(array(
            'client_id' => (string)$config['client_id'],
            'client_secret' => (string)$config['client_secret'],
            'grant_type' => 'authorization_code',
            'code' => (string)$code,
            'redirect_uri' => (string)$config['redirect_uri'],
            'state' => (string)$state,
        ), '', '&', PHP_QUERY_RFC3986);
        if (is_callable($http)) return call_user_func($http, $payload);
        return tickex_mp_http_json('POST', 'https://api.mercadopago.com/oauth/token', array('Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'), $payload);
    }
}

if (!function_exists('tickex_mp_refresh_account')) {
    function tickex_mp_refresh_account($pdo, $adminId, $force = false)
    {
        $account = tickex_mp_account($pdo, $adminId, true);
        if (!$account || $account['status'] !== 'connected') throw new RuntimeException('El organizador no conecto Mercado Pago.');
        $expires = !empty($account['expires_at']) ? strtotime($account['expires_at'] . ' UTC') : 0;
        if (!$force && $expires > time() + 86400) return $account;
        if (empty($account['refresh_token'])) throw new RuntimeException('La vinculacion con Mercado Pago debe renovarse.');
        $config = tickex_mp_config();
        $payload = http_build_query(array(
            'client_id' => (string)$config['client_id'],
            'client_secret' => (string)$config['client_secret'],
            'grant_type' => 'refresh_token',
            'refresh_token' => (string)$account['refresh_token'],
        ), '', '&', PHP_QUERY_RFC3986);
        $tokens = tickex_mp_http_json('POST', 'https://api.mercadopago.com/oauth/token', array('Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'), $payload);
        tickex_mp_save_account_tokens($pdo, $adminId, $tokens);
        return tickex_mp_account($pdo, $adminId, true);
    }
}

if (!function_exists('tickex_mp_event_config')) {
    function tickex_mp_event_config($pdo, $eventId)
    {
        tickex_mp_ensure_schema($pdo);
        $ownerId = tickex_mp_event_owner_id($pdo, $eventId);
        $settings = tickex_mp_platform_settings($pdo);
        $policy = tickex_mp_admin_policy($pdo, $ownerId);
        $st = $pdo->prepare('SELECT * FROM mercadopago_event_configs WHERE event_id=:event LIMIT 1');
        $st->execute(array(':event' => (int)$eventId));
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) $row = array('event_id' => (int)$eventId, 'admin_id' => $ownerId, 'provider' => 'totalcoin', 'enabled' => 1);
        $provider = isset($row['provider']) && $row['provider'] === 'mercadopago' ? 'mercadopago' : 'totalcoin';
        // Los organizadores cliente nunca pueden heredar TotalCoin. La bandera
        // global habilita o pausa las ventas, pero no cambia su proveedor.
        if ($policy['account_type'] !== 'str_owner') $provider = 'mercadopago';
        $row['provider'] = $provider;
        $row['service_charge_percent'] = tickex_mp_effective_service_charge_percent($settings, $policy);
        $row['marketplace_fee_percent'] = tickex_mp_effective_platform_fee_percent($settings, $policy);
        $row['total_cost_target_percent'] = (float)$settings['total_cost_target_percent'];
        $row['mp_cost_estimate_percent'] = (float)$settings['mp_cost_estimate_percent'];
        $row['account_type'] = $policy['account_type'];
        $row['enforcement_enabled'] = (int)$settings['enforcement_enabled'];
        return $row;
    }
}

if (!function_exists('tickex_mp_save_event_config')) {
    function tickex_mp_save_event_config($pdo, $eventId, $adminId, $provider, $feePercent)
    {
        tickex_mp_ensure_schema($pdo);
        $ownerId = tickex_mp_event_owner_id($pdo, $eventId);
        if ($ownerId <= 0 || $ownerId !== (int)$adminId) throw new RuntimeException('No podes modificar el medio de pago de este evento.');
        $settings = tickex_mp_platform_settings($pdo);
        $policy = tickex_mp_admin_policy($pdo, $adminId);
        $provider = $provider === 'mercadopago' ? 'mercadopago' : 'totalcoin';
        if ($policy['account_type'] !== 'str_owner') $provider = 'mercadopago';
        $feePercent = tickex_mp_effective_platform_fee_percent($settings, $policy);
        if ($provider === 'mercadopago') {
            $account = tickex_mp_account($pdo, $adminId, false);
            if (!$account || $account['status'] !== 'connected') throw new RuntimeException('Primero conecta la cuenta de Mercado Pago del organizador.');
        }
        $st = $pdo->prepare("INSERT OR REPLACE INTO mercadopago_event_configs (event_id, admin_id, provider, marketplace_fee_percent, enabled, created_at, updated_at)
            VALUES (:event, :admin, :provider, :fee, 1, COALESCE((SELECT created_at FROM mercadopago_event_configs WHERE event_id=:event), CURRENT_TIMESTAMP), CURRENT_TIMESTAMP)");
        $st->execute(array(':event' => (int)$eventId, ':admin' => (int)$adminId, ':provider' => $provider, ':fee' => $feePercent));
        return tickex_mp_event_config($pdo, $eventId);
    }
}

if (!function_exists('tickex_mp_platform_settings')) {
    function tickex_mp_platform_settings($pdo)
    {
        tickex_mp_ensure_schema($pdo);
        $row = $pdo->query('SELECT * FROM mercadopago_platform_settings WHERE id=1 LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        if (!$row) return array('enforcement_enabled' => 0, 'total_cost_target_percent' => 10.0, 'mp_cost_estimate_percent' => 0.0);
        $row['enforcement_enabled'] = !empty($row['enforcement_enabled']) ? 1 : 0;
        $row['total_cost_target_percent'] = max(0, min(100, (float)$row['total_cost_target_percent']));
        $row['mp_cost_estimate_percent'] = max(0, min(100, (float)$row['mp_cost_estimate_percent']));
        return $row;
    }
}

if (!function_exists('tickex_mp_save_platform_settings')) {
    function tickex_mp_save_platform_settings($pdo, $totalTarget, $mpEstimate, $enabled, $updatedBy)
    {
        tickex_mp_ensure_schema($pdo);
        $totalTarget = max(0, min(100, round((float)$totalTarget, 4)));
        $mpEstimate = max(0, min(100, round((float)$mpEstimate, 4)));
        if ($enabled && $mpEstimate <= 0) throw new RuntimeException('Carga una estimacion de la comision de Mercado Pago antes de activar la politica.');
        $serviceShareOfCheckout = $totalTarget > 0 ? ($totalTarget / (100 + $totalTarget)) * 100 : 0;
        if ($enabled && $mpEstimate >= $serviceShareOfCheckout) throw new RuntimeException('La comision estimada de Mercado Pago no deja margen dentro del costo de servicio configurado.');
        $st = $pdo->prepare('UPDATE mercadopago_platform_settings SET enforcement_enabled=:enabled,total_cost_target_percent=:total,mp_cost_estimate_percent=:mp,updated_by_admin_id=:admin,updated_at=CURRENT_TIMESTAMP WHERE id=1');
        $st->execute(array(':enabled' => $enabled ? 1 : 0, ':total' => $totalTarget, ':mp' => $mpEstimate, ':admin' => (int)$updatedBy));
        return tickex_mp_platform_settings($pdo);
    }
}

if (!function_exists('tickex_mp_admin_policy')) {
    function tickex_mp_admin_policy($pdo, $adminId)
    {
        tickex_mp_ensure_schema($pdo);
        $st = $pdo->prepare('SELECT * FROM mercadopago_admin_policies WHERE admin_id=:admin LIMIT 1');
        $st->execute(array(':admin' => (int)$adminId));
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return array('admin_id' => (int)$adminId, 'account_type' => 'client', 'platform_fee_override_percent' => null);
        $row['account_type'] = isset($row['account_type']) && $row['account_type'] === 'str_owner' ? 'str_owner' : 'client';
        if ($row['platform_fee_override_percent'] !== null && $row['platform_fee_override_percent'] !== '') {
            $row['platform_fee_override_percent'] = max(0, min(100, (float)$row['platform_fee_override_percent']));
        } else {
            $row['platform_fee_override_percent'] = null;
        }
        return $row;
    }
}

if (!function_exists('tickex_mp_save_admin_policy')) {
    function tickex_mp_save_admin_policy($pdo, $adminId, $accountType, $feeOverride, $updatedBy)
    {
        tickex_mp_ensure_schema($pdo);
        $accountType = $accountType === 'str_owner' ? 'str_owner' : 'client';
        $override = trim((string)$feeOverride) === '' ? null : max(0, min(100, round((float)$feeOverride, 4)));
        if ($accountType === 'client' && $override !== null) {
            $settings = tickex_mp_platform_settings($pdo);
            $serviceShareOfCheckout = $override > 0 ? ($override / (100 + $override)) * 100 : 0;
            if (!empty($settings['enforcement_enabled']) && $serviceShareOfCheckout <= (float)$settings['mp_cost_estimate_percent']) {
                throw new RuntimeException('El costo de servicio especial no alcanza para cubrir la comision estimada de Mercado Pago.');
            }
        }
        $st = $pdo->prepare("INSERT OR REPLACE INTO mercadopago_admin_policies (admin_id,account_type,platform_fee_override_percent,updated_by_admin_id,created_at,updated_at) VALUES (:admin,:type,:fee,:updated_by,COALESCE((SELECT created_at FROM mercadopago_admin_policies WHERE admin_id=:admin),CURRENT_TIMESTAMP),CURRENT_TIMESTAMP)");
        $st->execute(array(':admin' => (int)$adminId, ':type' => $accountType, ':fee' => $override, ':updated_by' => (int)$updatedBy));
        return tickex_mp_admin_policy($pdo, $adminId);
    }
}

if (!function_exists('tickex_mp_effective_platform_fee_percent')) {
    function tickex_mp_effective_platform_fee_percent(array $settings, array $policy)
    {
        $servicePercent = tickex_mp_effective_service_charge_percent($settings, $policy);
        $serviceShareOfCheckout = $servicePercent > 0 ? ($servicePercent / (100 + $servicePercent)) * 100 : 0;
        return max(0, round($serviceShareOfCheckout - (float)$settings['mp_cost_estimate_percent'], 4));
    }
}

if (!function_exists('tickex_mp_effective_service_charge_percent')) {
    function tickex_mp_effective_service_charge_percent(array $settings, array $policy)
    {
        if (array_key_exists('platform_fee_override_percent', $policy) && $policy['platform_fee_override_percent'] !== null && $policy['platform_fee_override_percent'] !== '') {
            return max(0, min(100, (float)$policy['platform_fee_override_percent']));
        }
        return max(0, min(100, (float)$settings['total_cost_target_percent']));
    }
}

if (!function_exists('tickex_mp_checkout_breakdown')) {
    function tickex_mp_checkout_breakdown($ticketSubtotal, $servicePercent, $mpCostPercent)
    {
        $subtotal = max(0, round((float)$ticketSubtotal, 2));
        $servicePercent = max(0, min(100, (float)$servicePercent));
        $mpCostPercent = max(0, min(100, (float)$mpCostPercent));
        $serviceFee = round($subtotal * $servicePercent / 100, 2);
        $checkoutTotal = round($subtotal + $serviceFee, 2);
        $mpCost = round($checkoutTotal * $mpCostPercent / 100, 2);
        $platformFee = max(0, round($checkoutTotal - $subtotal - $mpCost, 2));
        $platformPercent = $checkoutTotal > 0 ? round($platformFee * 100 / $checkoutTotal, 4) : 0;
        return array(
            'ticket_subtotal' => $subtotal,
            'service_charge_percent' => $servicePercent,
            'service_fee' => $serviceFee,
            'checkout_total' => $checkoutTotal,
            'mp_cost_estimate_percent' => $mpCostPercent,
            'mp_cost_estimate' => $mpCost,
            'marketplace_fee_percent' => $platformPercent,
            'marketplace_fee' => $platformFee,
            'organizer_net_estimate' => round($checkoutTotal - $mpCost - $platformFee, 2),
        );
    }
}

if (!function_exists('tickex_mp_marketplace_fee')) {
    function tickex_mp_marketplace_fee($amount, $percent)
    {
        $amount = max(0, (float)$amount);
        $percent = max(0, min(100, (float)$percent));
        return round($amount * $percent / 100, 2);
    }
}

if (!function_exists('tickex_mp_create_preference')) {
    function tickex_mp_create_preference($pdo, $adminId, array $params, $http = null)
    {
        $account = tickex_mp_refresh_account($pdo, $adminId, false);
        $amount = round((float)$params['amount'], 2);
        if ($amount <= 0) throw new RuntimeException('El monto de Mercado Pago es invalido.');
        $fee = isset($params['marketplace_fee'])
            ? max(0, round((float)$params['marketplace_fee'], 2))
            : tickex_mp_marketplace_fee($amount, isset($params['fee_percent']) ? $params['fee_percent'] : 0);
        $config = tickex_mp_config();
        $ref = (string)$params['reference'];
        $site = $config['site_url'];
        $payload = array(
            'items' => array(array(
                'id' => 'tickex-' . (int)$params['event_id'],
                'title' => (string)$params['concept'],
                'currency_id' => 'ARS',
                'quantity' => 1,
                'unit_price' => $amount,
            )),
            'payer' => array(
                'name' => (string)$params['first_name'],
                'surname' => (string)$params['last_name'],
                'email' => (string)$params['email'],
                'identification' => array('type' => 'DNI', 'number' => (string)$params['dni']),
            ),
            'external_reference' => $ref,
            'marketplace_fee' => $fee,
            'back_urls' => array(
                'success' => $site . '/mercadopago_return.php?state=success&ref=' . rawurlencode($ref),
                'pending' => $site . '/mercadopago_return.php?state=pending&ref=' . rawurlencode($ref),
                'failure' => $site . '/mercadopago_return.php?state=failure&ref=' . rawurlencode($ref),
            ),
            'auto_return' => 'approved',
            'notification_url' => $site . '/mercadopago_webhook.php',
            'statement_descriptor' => 'TICKEX',
        );
        if (is_callable($http)) {
            $response = call_user_func($http, $payload, $account);
        } else {
            $response = tickex_mp_http_json('POST', 'https://api.mercadopago.com/checkout/preferences', array(
                'Authorization: Bearer ' . $account['access_token'],
                'Content-Type: application/json',
                'X-Idempotency-Key: ' . hash('sha256', 'tickex-mp-' . $ref),
            ), json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }
        $preferenceId = isset($response['id']) ? (string)$response['id'] : '';
        $urlKey = $config['sandbox'] ? 'sandbox_init_point' : 'init_point';
        $paymentUrl = isset($response[$urlKey]) ? (string)$response[$urlKey] : '';
        if ($paymentUrl === '' && isset($response['init_point'])) $paymentUrl = (string)$response['init_point'];
        if ($preferenceId === '' || $paymentUrl === '') throw new RuntimeException('Mercado Pago no devolvio la preferencia de pago.');
        $requestId = 'mp-' . $preferenceId;
        $paymentUrl .= (strpos($paymentUrl, '?') === false ? '?' : '&') . 'requestId=' . rawurlencode($requestId);
        return array('payment_url' => $paymentUrl, 'request_id' => $requestId, 'preference_id' => $preferenceId, 'marketplace_fee' => $fee, 'payload' => $payload);
    }
}

if (!function_exists('tickex_mp_get_payment')) {
    function tickex_mp_get_payment($pdo, $adminId, $paymentId, $http = null)
    {
        $account = tickex_mp_refresh_account($pdo, $adminId, false);
        if (is_callable($http)) return call_user_func($http, $paymentId, $account);
        return tickex_mp_http_json('GET', 'https://api.mercadopago.com/v1/payments/' . rawurlencode((string)$paymentId), array('Authorization: Bearer ' . $account['access_token'], 'Accept: application/json'));
    }
}

if (!function_exists('tickex_mp_verify_webhook_signature')) {
    function tickex_mp_verify_webhook_signature($xSignature, $xRequestId, $dataId, $secret = null)
    {
        if ($secret === null) {
            $config = tickex_mp_config();
            $secret = isset($config['webhook_secret']) ? (string)$config['webhook_secret'] : '';
        }
        if ((string)$secret === '') return false;
        $parts = array();
        foreach (explode(',', (string)$xSignature) as $piece) {
            $pair = explode('=', trim($piece), 2);
            if (count($pair) === 2) $parts[$pair[0]] = $pair[1];
        }
        if (empty($parts['ts']) || empty($parts['v1']) || (string)$dataId === '' || (string)$xRequestId === '') return false;
        $manifest = 'id:' . strtolower((string)$dataId) . ';request-id:' . (string)$xRequestId . ';ts:' . (string)$parts['ts'] . ';';
        $expected = hash_hmac('sha256', $manifest, (string)$secret);
        return hash_equals($expected, (string)$parts['v1']);
    }
}

if (!function_exists('tickex_mp_confirm_payment')) {
    function tickex_mp_confirm_payment($pdo, array $order, array $payment)
    {
        $status = isset($payment['status']) ? strtolower((string)$payment['status']) : '';
        $externalRef = isset($payment['external_reference']) ? (string)$payment['external_reference'] : '';
        $amount = isset($payment['transaction_amount']) ? (float)$payment['transaction_amount'] : -1;
        $paymentId = isset($payment['id']) ? (string)$payment['id'] : '';
        $collectorId = isset($payment['collector_id']) ? (string)$payment['collector_id'] : '';
        if ($externalRef === '' || $externalRef !== (string)$order['ref']) return array('confirmed' => false, 'result' => 'reference_mismatch');
        if (abs($amount - (float)$order['amount']) > 0.01) return array('confirmed' => false, 'result' => 'amount_mismatch');
        $account = tickex_mp_account($pdo, (int)$order['seller_admin_id'], false);
        if (!$account || $collectorId === '' || $collectorId !== (string)$account['mp_user_id']) return array('confirmed' => false, 'result' => 'collector_mismatch');
        $mappedState = $status === 'approved' ? 'success' : ($status === 'rejected' || $status === 'cancelled' ? 'failed' : 'inprocess');
        if ($status !== 'approved') {
            $st = $pdo->prepare('UPDATE tc_orders SET state=:state, provider_payment_id=:payment, updated_at=CURRENT_TIMESTAMP WHERE id=:id');
            $st->execute(array(':state' => $mappedState, ':payment' => $paymentId, ':id' => (int)$order['id']));
            return array('confirmed' => false, 'result' => $status === '' ? 'unknown' : $status);
        }
        $st = $pdo->prepare("UPDATE tc_orders SET payment_status='confirmed', payment_confirmed_at=COALESCE(payment_confirmed_at,CURRENT_TIMESTAMP), state='success', provider_payment_id=:payment, updated_at=CURRENT_TIMESTAMP WHERE id=:id AND (payment_status IS NULL OR payment_status IN ('pending','created','confirmed'))");
        $st->execute(array(':payment' => $paymentId, ':id' => (int)$order['id']));
        return array('confirmed' => true, 'result' => 'confirmed', 'payment_id' => $paymentId);
    }
}
