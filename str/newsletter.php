<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/event_newsletters.php';

try {
    $pdo = db();
    $adminId = isset($_GET['admin']) ? (int)$_GET['admin'] : 0;
    $latest = event_newsletters_latest_published($pdo, $adminId);
    if (!$latest) {
        http_response_code(404);
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Newsletter — Tickex</title></head><body style="margin:0;background:#000;color:#fff;font-family:Arial,sans-serif;"><main style="max-width:680px;margin:0 auto;padding:64px 24px;text-align:center;"><h1>Próximamente</h1><p style="color:#bbb;">Todavía no hay un newsletter publicado.</p></main></body></html>';
        exit;
    }
    $newsletter = $latest['newsletter'];
    $event = $latest['event'];
    $artists = event_newsletters_artists($pdo, (int)$newsletter['id']);
    $rendered = event_newsletters_render($event, $newsletter, $artists);
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo $rendered['body_html'];
} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'No se pudo cargar el newsletter.';
}
