<?php
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/communication_tracking.php';

$token = isset($_GET['t']) ? (string)$_GET['t'] : '';
$token = preg_replace('/\.gif$/i', '', $token);
try {
    $pdo = db();
    $link = communication_tracking_find_link($pdo, $token, 'open');
    if ($link) communication_tracking_record($pdo, $link, 'open', $_SERVER);
} catch (Exception $e) {
    // La medicion nunca debe romper la carga del correo.
}

header('Content-Type: image/gif');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
echo base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==');

