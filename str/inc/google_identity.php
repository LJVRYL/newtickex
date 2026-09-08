<?php

if (!function_exists('tickex_google_config')) {
    function tickex_google_config()
    {
        $config = array(
            'client_id' => (string)getenv('TICKEX_GOOGLE_CLIENT_ID'),
            'client_secret' => (string)getenv('TICKEX_GOOGLE_CLIENT_SECRET'),
            'redirect_uri' => (string)getenv('TICKEX_GOOGLE_REDIRECT_URI'),
        );
        $paths = array(
            dirname(__DIR__, 2) . '/.secrets/google_identity.php',
            dirname(__DIR__) . '/.secrets/google_identity.php',
        );
        foreach ($paths as $path) {
            if (!is_file($path)) continue;
            $fileConfig = require $path;
            if (is_array($fileConfig)) $config = array_merge($config, $fileConfig);
            break;
        }
        return $config;
    }
}

if (!function_exists('tickex_google_is_configured')) {
    function tickex_google_is_configured()
    {
        $config = tickex_google_config();
        return !empty($config['client_id']) && !empty($config['client_secret']);
    }
}

if (!function_exists('tickex_google_redirect_uri')) {
    function tickex_google_redirect_uri($config)
    {
        if (!empty($config['redirect_uri'])) return (string)$config['redirect_uri'];
        $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) $https = strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https';
        $host = !empty($_SERVER['HTTP_HOST']) ? (string)$_SERVER['HTTP_HOST'] : 'str.tickex.com.ar';
        $dir = str_replace('\\', '/', dirname(isset($_SERVER['SCRIPT_NAME']) ? (string)$_SERVER['SCRIPT_NAME'] : '/'));
        if ($dir === '/' || $dir === '.') $dir = '';
        return ($https ? 'https' : 'http') . '://' . $host . $dir . '/google_oauth_callback.php';
    }
}

if (!function_exists('tickex_google_safe_next')) {
    function tickex_google_safe_next($value)
    {
        $value = trim((string)$value);
        if ($value === '' || strpos($value, '://') !== false || substr($value, 0, 2) === '//') return '';
        if ($value[0] === '/') return $value;
        return preg_match('/^[a-zA-Z0-9_\-\/\.]+\.php(\?.*)?$/', $value) ? $value : '';
    }
}

if (!function_exists('tickex_google_begin')) {
    function tickex_google_begin($context, $next)
    {
        $context = $context === 'admin' ? 'admin' : 'buyer';
        $config = tickex_google_config();
        if (empty($config['client_id']) || empty($config['client_secret'])) {
            throw new RuntimeException('El acceso con Google todavía no está configurado.');
        }
        if (session_id() === '') tickex_session_start();
        $state = bin2hex(random_bytes(24));
        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        if (empty($_SESSION['_google_oauth']) || !is_array($_SESSION['_google_oauth'])) $_SESSION['_google_oauth'] = array();
        $_SESSION['_google_oauth'][hash('sha256', $state)] = array(
            'context' => $context,
            'next' => tickex_google_safe_next($next),
            'verifier' => $verifier,
            'expires' => time() + 600,
        );
        foreach ($_SESSION['_google_oauth'] as $key => $entry) {
            if (empty($entry['expires']) || (int)$entry['expires'] < time()) unset($_SESSION['_google_oauth'][$key]);
        }
        $params = array(
            'client_id' => (string)$config['client_id'],
            'redirect_uri' => tickex_google_redirect_uri($config),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'prompt' => 'select_account',
        );
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }
}

if (!function_exists('tickex_google_consume_state')) {
    function tickex_google_consume_state($state)
    {
        if (session_id() === '') tickex_session_start();
        $key = hash('sha256', (string)$state);
        $entry = isset($_SESSION['_google_oauth'][$key]) ? $_SESSION['_google_oauth'][$key] : null;
        unset($_SESSION['_google_oauth'][$key]);
        if (!is_array($entry) || empty($entry['expires']) || (int)$entry['expires'] < time()) {
            throw new RuntimeException('La solicitud de Google venció o ya fue utilizada.');
        }
        return $entry;
    }
}

if (!function_exists('tickex_google_http_json')) {
    function tickex_google_http_json($method, $url, $headers, $body)
    {
        if (!function_exists('curl_init')) throw new RuntimeException('El servidor no tiene disponible la extensión cURL.');
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if ($status < 200 || $status >= 300 || !is_array($data)) {
            throw new RuntimeException('Google no pudo validar el acceso' . ($error !== '' ? ': ' . $error : '.'));
        }
        return $data;
    }
}

if (!function_exists('tickex_google_exchange_code')) {
    function tickex_google_exchange_code($code, $verifier)
    {
        $config = tickex_google_config();
        $payload = http_build_query(array(
            'code' => (string)$code,
            'client_id' => (string)$config['client_id'],
            'client_secret' => (string)$config['client_secret'],
            'redirect_uri' => tickex_google_redirect_uri($config),
            'grant_type' => 'authorization_code',
            'code_verifier' => (string)$verifier,
        ), '', '&', PHP_QUERY_RFC3986);
        $token = tickex_google_http_json('POST', 'https://oauth2.googleapis.com/token', array('Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'), $payload);
        if (empty($token['access_token'])) throw new RuntimeException('Google no devolvió una credencial válida.');
        return tickex_google_http_json('GET', 'https://openidconnect.googleapis.com/v1/userinfo', array('Authorization: Bearer ' . $token['access_token'], 'Accept: application/json'), null);
    }
}

if (!function_exists('tickex_google_ensure_schema')) {
    function tickex_google_ensure_schema($pdo)
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS auth_identities (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            provider TEXT NOT NULL,
            provider_subject TEXT NOT NULL,
            account_type TEXT NOT NULL,
            account_id INTEGER NOT NULL,
            email_at_link TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_login_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_auth_identity_subject ON auth_identities(provider,provider_subject,account_type)');
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_auth_identity_account ON auth_identities(provider,account_type,account_id)');
    }
}

if (!function_exists('tickex_google_find_or_create_account')) {
    function tickex_google_find_or_create_account($pdo, $profile, $context)
    {
        $subject = isset($profile['sub']) ? trim((string)$profile['sub']) : '';
        $email = isset($profile['email']) ? strtolower(trim((string)$profile['email'])) : '';
        $verified = isset($profile['email_verified']) && ($profile['email_verified'] === true || $profile['email_verified'] === 'true' || (int)$profile['email_verified'] === 1);
        if ($subject === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !$verified) throw new RuntimeException('Google no pudo confirmar el email de la cuenta.');
        if (function_exists('tickex_is_email_blocked') && tickex_is_email_blocked($pdo, $email)) throw new RuntimeException('Tu acceso está suspendido. Contactá a soporte.');
        $type = $context === 'admin' ? 'admin' : 'buyer';
        tickex_google_ensure_schema($pdo);
        $mapped = $pdo->prepare('SELECT account_id FROM auth_identities WHERE provider=:provider AND provider_subject=:subject AND account_type=:type LIMIT 1');
        $mapped->execute(array(':provider'=>'google', ':subject'=>$subject, ':type'=>$type));
        $accountId = (int)$mapped->fetchColumn();
        if ($type === 'admin') {
            if ($accountId > 0) {
                $st = $pdo->prepare('SELECT * FROM usuarios_admin WHERE id=:id AND activo=1 LIMIT 1');
                $st->execute(array(':id'=>$accountId));
            } else {
                $st = $pdo->prepare('SELECT * FROM usuarios_admin WHERE lower(email)=lower(:email) AND activo=1 LIMIT 1');
                $st->execute(array(':email'=>$email));
            }
            $account = $st->fetch(PDO::FETCH_ASSOC);
            if (!$account) throw new RuntimeException('Esta cuenta de Google no corresponde a un administrador activo de Tickex.');
        } else {
            if ($accountId > 0) {
                $st = $pdo->prepare('SELECT * FROM registro_pendientes WHERE id=:id LIMIT 1');
                $st->execute(array(':id'=>$accountId));
            } else {
                $st = $pdo->prepare('SELECT * FROM registro_pendientes WHERE lower(email)=lower(:email) ORDER BY id DESC LIMIT 1');
                $st->execute(array(':email'=>$email));
            }
            $account = $st->fetch(PDO::FETCH_ASSOC);
            if (!$account) {
                $token = bin2hex(random_bytes(24));
                $insert = $pdo->prepare('INSERT INTO registro_pendientes(email,token,nombre,apellido,creado_en,completado_en,password_hash) VALUES(:email,:token,:name,:surname,:created,:completed,NULL)');
                $now = date('Y-m-d H:i:s');
                $insert->execute(array(':email'=>$email, ':token'=>$token, ':name'=>(string)($profile['given_name'] ?? ''), ':surname'=>(string)($profile['family_name'] ?? ''), ':created'=>$now, ':completed'=>$now));
                $accountId = (int)$pdo->lastInsertId();
                $st = $pdo->prepare('SELECT * FROM registro_pendientes WHERE id=:id');
                $st->execute(array(':id'=>$accountId));
                $account = $st->fetch(PDO::FETCH_ASSOC);
            }
        }
        $accountId = (int)$account['id'];
        $pdo->beginTransaction();
        try {
            $insertIdentity = $pdo->prepare('INSERT OR IGNORE INTO auth_identities(provider,provider_subject,account_type,account_id,email_at_link) VALUES(:provider,:subject,:type,:account,:email)');
            $insertIdentity->execute(array(':provider'=>'google', ':subject'=>$subject, ':type'=>$type, ':account'=>$accountId, ':email'=>$email));
            $updateIdentity = $pdo->prepare("UPDATE auth_identities SET last_login_at=datetime('now'),email_at_link=:email WHERE provider=:provider AND provider_subject=:subject AND account_type=:type AND account_id=:account");
            $updateIdentity->execute(array(':email'=>$email, ':provider'=>'google', ':subject'=>$subject, ':type'=>$type, ':account'=>$accountId));
            if ($updateIdentity->rowCount() !== 1) throw new RuntimeException('La cuenta de Google ya está vinculada a otro perfil.');
            $pdo->commit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return array($type, $account);
    }
}

if (!function_exists('tickex_google_establish_session')) {
    function tickex_google_establish_session($pdo, $type, $account)
    {
        if (function_exists('session_regenerate_id')) @session_regenerate_id(true);
        tickex_clear_identity_session();
        if ($type === 'admin') {
            $_SESSION['usuario'] = (string)$account['username'];
            $_SESSION['username'] = (string)$account['username'];
            $_SESSION['usuario_email'] = (string)$account['email'];
            $_SESSION['email'] = (string)$account['email'];
            $_SESSION['admin_id'] = (int)$account['id'];
            $_SESSION['user_id'] = (int)$account['id'];
            $_SESSION['es_admin'] = true;
            $_SESSION['is_admin'] = true;
            $_SESSION['auth_context'] = 'admin';
            $_SESSION['rol'] = (string)$account['rol'];
            $_SESSION['tipo_global'] = (string)$account['tipo_global'];
            if (in_array((string)$account['tipo_global'], array('super_admin','superadmin'), true)) return 'panel_superadmin.php';
            if ((string)$account['tipo_global'] === 'staff_evento') {
                $events = array();
                try {
                    $st = $pdo->prepare('SELECT evento_id FROM staff_eventos WHERE staff_id=:staff');
                    $st->execute(array(':staff'=>(int)$account['id']));
                    $events = $st->fetchAll(PDO::FETCH_COLUMN);
                } catch (Exception $e) {
                    $events = array();
                }
                if (count($events) === 1) {
                    $_SESSION['evento_id'] = (int)$events[0];
                    return 'puerta.php?evento_id=' . (int)$events[0];
                }
                return 'puerta.php';
            }
            return 'panel_admin.php';
        }
        $name = trim((string)($account['nombre'] ?? '') . ' ' . (string)($account['apellido'] ?? ''));
        $_SESSION['usuario_id'] = (int)$account['id'];
        $_SESSION['auth_context'] = 'user';
        $_SESSION['usuario_email'] = (string)$account['email'];
        $_SESSION['usuario_nombre'] = $name;
        $_SESSION['first_name'] = (string)($account['nombre'] ?? '');
        $_SESSION['last_name'] = (string)($account['apellido'] ?? '');
        $_SESSION['dni'] = (string)($account['dni'] ?? '');
        $_SESSION['usuario_rol'] = 'cliente';
        $_SESSION['rol'] = 'cliente';
        $_SESSION['usuario'] = (string)$account['email'];
        $_SESSION['nombre'] = $name;
        $_SESSION['email'] = (string)$account['email'];
        $_SESSION['tipo_global'] = '';
        return 'panel_usuario.php';
    }
}
