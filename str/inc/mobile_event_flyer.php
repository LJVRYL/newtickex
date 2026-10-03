<?php
require_once __DIR__ . '/event_wizard_drafts.php';

function tickex_mobile_admin_event_flyer_upload($pdo, $admin, $eventId, $file, $uploader = null)
{
    if (!tickex_mobile_admin_can_event($pdo, $admin, (int)$eventId)) return array('error' => 'not_found');
    if (!is_array($file) || !isset($file['error']) || (int)$file['error'] === UPLOAD_ERR_NO_FILE) return array('error' => 'flyer_required');
    if (!is_int($file['error']) || !isset($file['size'], $file['tmp_name']) || !is_numeric($file['size']) || !is_string($file['tmp_name'])) return array('error' => 'invalid_flyer');
    if ($file['error'] !== UPLOAD_ERR_OK || (int)$file['size'] <= 0 || (int)$file['size'] > 2097152) return array('error' => 'invalid_flyer');
    try {
        $path = $uploader === null ? tickex_wizard_upload($file) : call_user_func($uploader, $file);
        if (!is_string($path) || !preg_match('~^event_flyers/(?:wizard_)?[a-f0-9]{32}\.(?:jpg|png)$~', $path)) return array('error' => 'invalid_flyer');
        $save = $pdo->prepare('UPDATE eventos SET flyer_filename=:flyer WHERE id=:event AND (borrado_en IS NULL OR borrado_en="")');
        $save->execute(array(':flyer' => $path, ':event' => (int)$eventId));
        if ($save->rowCount() !== 1) return array('error' => 'not_found');
        return array('eventId' => (string)$eventId, 'imageUrl' => 'https://str.tickex.com.ar/' . $path);
    } catch (Exception $e) {
        return array('error' => 'invalid_flyer');
    }
}
