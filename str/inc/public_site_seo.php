<?php
// Public identity only. Never derive canonical authority from request Host.
function tickex_public_site_canonical($site, $eventSlug = '') {
    $slug = isset($site['slug_publico']) ? (string)$site['slug_publico'] : '';
    if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug)) return '';
    $domain = isset($site['custom_domain']) ? strtolower(trim((string)$site['custom_domain'])) : '';
    $verified = isset($site['custom_domain_status']) && $site['custom_domain_status'] === 'verified';
    if ($verified && preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/D', $domain)
        && $domain !== 'tickex.com.ar' && $domain !== 'www.tickex.com.ar' && $domain !== 'str.tickex.com.ar') {
        $url = 'https://' . $domain . '/';
    } else {
        // Reserved str host serves a different application; do not invent its root.
        $url = 'https://www.tickex.com.ar/site.php?slug=' . rawurlencode($slug);
    }
    if ($eventSlug !== '') $url .= (strpos($url, '?') === false ? '?' : '&') . 'event=' . rawurlencode($eventSlug);
    return $url;
}

function tickex_public_event_eligible($event, $now = null) {
    return isset($event['publicado_site']) && (int)$event['publicado_site'] === 1
        && empty($event['borrado_en']) && !empty($event['slug'])
        && tickex_event_is_current($event, $now);
}
