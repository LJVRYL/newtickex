<?php
if (!function_exists('tickex_google_login_origin_redirect')) {
    function tickex_google_login_origin_redirect($config, $currentHost, $context, $next)
    {
        // Start on the registered callback origin BEFORE creating session-bound state.
        // Never share cookies across subdomains or move OAuth state between browsers.
        if (empty($config['redirect_uri'])) return '';
        $uri = parse_url((string)$config['redirect_uri']);
        if (!is_array($uri) || empty($uri['host'])) throw new RuntimeException('El retorno de Google no está configurado correctamente.');
        $host = strtolower($uri['host']);
        if (strtolower((string)$currentHost) === $host) return '';
        if (!isset($uri['scheme']) || $uri['scheme'] !== 'https'
            || !in_array($host, array('str.tickex.com.ar','www.tickex.com.ar','tickex.com.ar'), true)
            || isset($uri['port']) || isset($uri['user']) || isset($uri['pass'])
            || !isset($uri['path']) || $uri['path'] !== '/google_oauth_callback.php') {
            throw new RuntimeException('El dominio de retorno de Google no está habilitado.');
        }
        $context = in_array($context, array('admin','buyer'), true) ? $context : 'unified';
        return 'https://'.$host.'/google_login.php?'.http_build_query(array(
            'context'=>$context, 'next'=>tickex_google_safe_next($next)
        ), '', '&', PHP_QUERY_RFC3986);
    }
}
