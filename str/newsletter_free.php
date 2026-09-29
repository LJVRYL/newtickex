<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/event_newsletters.php';
require_once __DIR__ . '/inc/free_checkout.php';

try {
    $pdo = db();
    $slug = isset($_GET['evento']) ? trim((string)$_GET['evento']) : '';
    $event = tickex_free_checkout_find_event($pdo, 0, $slug);

    if (!$event) {
        throw new RuntimeException('Evento no encontrado.');
    }

    $config = tickex_free_checkout_load_config($pdo, (int)$event['id']);
    if (!$config || empty($config['enabled'])) {
        throw new RuntimeException('Checkout gratuito no disponible.');
    }

    event_newsletters_ensure_schema($pdo);
    $st = $pdo->prepare('SELECT * FROM communication_event_newsletters WHERE event_id = :event_id AND published_at IS NOT NULL LIMIT 1');
    $st->execute(array(':event_id' => (int)$event['id']));
    $newsletter = $st->fetch(PDO::FETCH_ASSOC);
    if (!$newsletter) {
        throw new RuntimeException('Newsletter no publicado.');
    }

    $shareTitle = 'Canjeá tu QR Free | SAVE THE RAVE';
    $shareDescription = 'Reservá tu entrada gratuita para SAVE THE RAVE. Completá tus datos y recibí tu QR por email.';
    $shareUrl = event_newsletters_absolute_url('newsletter_free.php?evento=' . rawurlencode((string)$event['slug']));
    $shareImage = '';
    if (!empty($event['flyer_filename'])) {
        $shareImage = event_newsletters_absolute_url((string)$event['flyer_filename']);
    } elseif (!empty($event['flyer'])) {
        $shareImage = event_newsletters_absolute_url((string)$event['flyer']);
    }

    $newsletter['subject'] = $shareTitle;
    $newsletter['cta_label'] = 'Canje QR Free';
    $newsletter['checkout_url'] = event_newsletters_absolute_url(
        'acceso.php?slug=' . rawurlencode((string)$event['slug'])
    );

    $artists = event_newsletters_artists($pdo, (int)$newsletter['id']);
    $rendered = event_newsletters_render($event, $newsletter, $artists);
    $socialMeta = '<meta property="og:type" content="website">'
        . '<meta property="og:title" content="' . event_newsletters_escape($shareTitle) . '">'
        . '<meta property="og:description" content="' . event_newsletters_escape($shareDescription) . '">'
        . '<meta property="og:url" content="' . event_newsletters_escape($shareUrl) . '">'
        . ($shareImage !== '' ? '<meta property="og:image" content="' . event_newsletters_escape($shareImage) . '">' : '')
        . '<meta name="twitter:card" content="summary_large_image">';
    $rendered['body_html'] = str_replace('</head>', $socialMeta . '</head>', $rendered['body_html']);

    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo $rendered['body_html'];
} catch (Exception $e) {
    http_response_code(404);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Invitación — Tickex</title></head><body style="margin:0;background:#000;color:#fff;font-family:Arial,sans-serif"><main style="max-width:680px;margin:0 auto;padding:64px 24px;text-align:center"><h1>Invitación no disponible</h1><p style="color:#bbb">Este enlace gratuito no está disponible en este momento.</p></main></body></html>';
}
