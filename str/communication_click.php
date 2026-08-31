<?php
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/communication_tracking.php';

$token = isset($_GET['t']) ? (string)$_GET['t'] : '';
$destination = communication_tracking_base_url();
try {
    $pdo = db();
    $link = communication_tracking_find_link($pdo, $token, 'click');
    if ($link && communication_tracking_is_checkout_url($link['destination_url'])) {
        communication_tracking_record($pdo, $link, 'click', $_SERVER);
        $destination = communication_tracking_append_attribution((string)$link['destination_url'], (string)$link['token']);
    }
} catch (Exception $e) {
    // Si falla la medicion, redirigir a una pagina segura del sitio.
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Location: ' . $destination, true, 302);
exit;

