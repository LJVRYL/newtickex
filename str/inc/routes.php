<?php

if (!function_exists('tickex_is_local_host')) {
    function tickex_is_local_host($host)
    {
        $host = strtolower(preg_replace('/:\\d+$/', '', trim((string)$host)));
        return $host === 'localhost' || $host === '127.0.0.1' || $host === '::1';
    }
}

if (!function_exists('tickex_public_base_url')) {
    function tickex_public_base_url($suggested)
    {
        $configured = getenv('TICKEX_PUBLIC_URL');
        if (is_string($configured) && trim($configured) !== '') return rtrim(trim($configured), '/');

        $candidate = rtrim(trim((string)$suggested), '/');
        if ($candidate !== '' && tickex_is_local_host(parse_url($candidate, PHP_URL_HOST))) return $candidate;

        $requestHost = isset($_SERVER['HTTP_HOST']) ? (string)$_SERVER['HTTP_HOST'] : '';
        if (tickex_is_local_host($requestHost)) {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            return $scheme . '://' . $requestHost;
        }
        return 'https://www.tickex.com.ar';
    }
}

if (!function_exists('tickex_route')) {
    function tickex_route($name, $params)
    {
        $params = is_array($params) ? $params : array();
        $routes = array(
            'login' => '/ingresar',
            'customer_home' => '/mi-cuenta',
            'customer_profile' => '/mi-cuenta/perfil',
            'staff_home' => '/mi-cuenta/staff',
            'staff_scan' => '/mi-cuenta/staff/escanear',
            'staff_sales' => '/mi-cuenta/staff/puerta',
            'staff_activity' => '/mi-cuenta/staff/actividad',
            'reseller_home' => '/mi-cuenta/revendedor',
            'logout' => '/salir',
            'admin_home' => '/administrar',
        );
        if ($name === 'ticket' && !empty($params['token'])) return '/entrada/' . rawurlencode((string)$params['token']);
        if ($name === 'checkin' && !empty($params['token'])) return '/validar/' . rawurlencode((string)$params['token']);
        return isset($routes[$name]) ? $routes[$name] : '/';
    }
}
