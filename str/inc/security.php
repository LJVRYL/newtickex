<?php
// inc/security.php — hardening básico (headers + sesión) sin depender del resto del sistema.
// Mantenerlo compatible con PHP viejo cuando sea posible.

if (!function_exists('tickex_is_https')) {
    function tickex_is_https()
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') return true;
        if (!empty($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) return true;
        return false;
    }
}

if (!function_exists('tickex_send_security_headers')) {
    function tickex_send_security_headers()
    {
        if (headers_sent()) return;

        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header("Permissions-Policy: geolocation=(), microphone=(), camera=(self)");
        header("Content-Security-Policy: base-uri 'self'; frame-ancestors 'self'; form-action 'self'");
        header('X-Permitted-Cross-Domain-Policies: none');

        if (tickex_is_https()) {
            // No incluimos subdominios por defecto para no romper otros hosts.
            header('Strict-Transport-Security: max-age=15552000');
        }
    }
}

if (!function_exists('tickex_harden_php_ini')) {
    function tickex_harden_php_ini()
    {
        // No filtrar errores al cliente.
        @ini_set('display_errors', '0');
        @ini_set('display_startup_errors', '0');
        @ini_set('log_errors', '1');
        // No forzamos error_log (depende de hosting). Mantener error_reporting alto para log.
        @error_reporting(E_ALL);

        // Sesiones (lo demás se setea en tickex_session_start).
        @ini_set('session.use_only_cookies', '1');
        @ini_set('session.use_strict_mode', '1');
        @ini_set('session.cookie_httponly', '1');
    }
}

if (!function_exists('tickex_session_start')) {
    function tickex_session_start()
    {
        tickex_harden_php_ini();

        // Asegurar path de sesiones escribible (WSL suele bloquear /var/lib/php/sessions)
        $sp = @ini_get('session.save_path');
        if (!$sp || !@is_writable($sp)) {
            $tmp = @sys_get_temp_dir();
            if (@is_dir($tmp) && @is_writable($tmp)) {
                @session_save_path($tmp);
            }
        }

        if (session_id() !== '') {
            return;
        }

        // Configurar cookie params ANTES del session_start
        $secure = tickex_is_https();
        $httponly = true;
        $samesite = 'Lax';

        $params = @session_get_cookie_params();
        $path = !empty($params['path']) ? $params['path'] : '/';
        $domain = isset($params['domain']) ? $params['domain'] : '';

        if (defined('PHP_VERSION_ID') && PHP_VERSION_ID >= 70300) {
            @session_set_cookie_params(array(
                'lifetime' => 0,
                'path' => $path,
                'domain' => $domain,
                'secure' => $secure,
                'httponly' => $httponly,
                'samesite' => $samesite,
            ));
        } else {
            // PHP < 7.3: no seteamos SameSite desde acá (evita romper Path de la cookie).
            @session_set_cookie_params(0, $path, $domain, $secure, $httponly);
        }

        if ($secure) {
            @ini_set('session.cookie_secure', '1');
        }

        @session_start();
    }
}

// ---------------------------------------------------------------------
// CSRF mínimo (token en sesión)
// ---------------------------------------------------------------------
if (!function_exists('tickex_csrf_token')) {
    function tickex_csrf_token()
    {
        if (session_id() === '') {
            // si la página usa bootstrap, ya hay sesión; si no, iniciamos una mínima
            tickex_session_start();
        }

        if (empty($_SESSION['_csrf'])) {
            if (function_exists('random_bytes')) {
                $_SESSION['_csrf'] = bin2hex(random_bytes(16));
            } else {
                $_SESSION['_csrf'] = sha1(uniqid('', true));
            }
        }
        return (string)$_SESSION['_csrf'];
    }
}

if (!function_exists('tickex_csrf_verify')) {
    function tickex_csrf_verify($provided)
    {
        $tok = tickex_csrf_token();
        $p = (string)$provided;
        if ($p === '') return false;
        if (function_exists('hash_equals')) {
            return hash_equals($tok, $p);
        }
        return $tok === $p;
    }
}

// ---------------------------------------------------------------------
// Cookies helper (compat PHP < 7.3)
// ---------------------------------------------------------------------
if (!function_exists('tickex_set_cookie')) {
    /**
     * Setea una cookie con defaults seguros.
     * Nota: en PHP < 7.3 no se puede setear SameSite sin hacks.
     */
    function tickex_set_cookie($name, $value, $days = 30, $path = '/')
    {
        if (headers_sent()) return false;

        $secure = tickex_is_https();
        $httponly = true;
        $expires = 0;
        if ($days !== null) {
            $d = (int)$days;
            if ($d > 0) {
                $expires = time() + ($d * 86400);
            }
        }

        if (defined('PHP_VERSION_ID') && PHP_VERSION_ID >= 70300) {
            return @setcookie((string)$name, (string)$value, array(
                'expires' => $expires,
                'path' => (string)$path,
                'secure' => $secure,
                'httponly' => $httponly,
                'samesite' => 'Lax',
            ));
        }

        // PHP < 7.3: no SameSite.
        return @setcookie((string)$name, (string)$value, $expires, (string)$path, '', $secure, $httponly);
    }
}
