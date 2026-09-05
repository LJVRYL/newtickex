<?php
require_once dirname(__DIR__) . '/inc/event_newsletters.php';

function newsletter_test_assert($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }
    echo 'PASS: ' . $message . PHP_EOL;
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE eventos (id INTEGER PRIMARY KEY, nombre TEXT, slug TEXT, descripcion TEXT, flyer_filename TEXT, fecha_desde TEXT, creado_por_admin_id INTEGER, borrado_en TEXT)');
$pdo->exec("INSERT INTO eventos VALUES (1,'SAVE THE RAVE','str','Una noche especial','event_flyers/str.jpg','2026-09-12 23:59:00',7,NULL)");
event_newsletters_ensure_schema($pdo);

newsletter_test_assert(count(event_newsletters_visible_events($pdo, 7, false)) === 1, 'event owner can select the event');
newsletter_test_assert(count(event_newsletters_visible_events($pdo, 8, false)) === 0, 'another admin cannot select the event');

$event = event_newsletters_find_event($pdo, 1, 7, false);
$newsletter = array(
    'subject'=>'STR — Nueva fecha', 'edition'=>'SAVE THE RAVE — NUEVA FECHA',
    'location_text'=>'Rincón 1330', 'intro_text'=>'Nos encontramos nuevamente.',
    'about_text'=>event_newsletters_default_about_text(), 'cta_label'=>'Comprar entradas',
    'checkout_url'=>'https://str.tickex.com.ar/checkout_totalcoin.php?event=1',
    'instagram_url'=>'https://instagram.com/saveth3rave',
    'lineup_image_path'=>'newsletter_uploads/event_1/lineup.jpg',
);
$artists = array(
    array('artist_name'=>'Artista Uno','review_text'=>'Primera reseña.','image_path'=>'newsletter_uploads/event_1/uno.jpg'),
    array('artist_name'=>'Artista Dos','review_text'=>'Segunda reseña.','image_path'=>''),
);
$rendered = event_newsletters_render($event, $newsletter, $artists);
newsletter_test_assert($rendered['subject'] === 'STR — Nueva fecha', 'configured subject is preserved');
newsletter_test_assert(strpos($rendered['body_html'], 'event_flyers/str.jpg') !== false, 'event flyer is reused');
newsletter_test_assert(strpos($rendered['body_html'], 'Artista Uno') !== false && strpos($rendered['body_html'], 'Artista Dos') !== false, 'all artists are rendered');
newsletter_test_assert(strpos($rendered['body_html'], 'Primera reseña.') !== false, 'artist reviews are rendered');
newsletter_test_assert(strpos($rendered['body_html'], 'newsletter_uploads/event_1/lineup.jpg') !== false, 'one combined lineup image is rendered');
newsletter_test_assert(substr_count($rendered['body_html'], 'newsletter_uploads/event_1/lineup.jpg') === 1, 'combined lineup image is not duplicated per artist');
newsletter_test_assert(strpos($rendered['body_html'], 'Primera reseña.') < strpos($rendered['body_html'], 'newsletter_uploads/event_1/lineup.jpg'), 'principal artist is rendered above the lineup image');
newsletter_test_assert(strpos($rendered['body_html'], 'Segunda reseña.') > strpos($rendered['body_html'], 'newsletter_uploads/event_1/lineup.jpg'), 'secondary artists are rendered below the lineup image');
newsletter_test_assert(substr_count($rendered['body_html'], 'SAVE THE RAVE — NUEVA FECHA') === 1, 'event edition is rendered only once');
newsletter_test_assert(strpos($rendered['body_html'], 'sobre-fiesta.jpg') !== false && strpos($rendered['body_html'], 'final-abajo.jpg') !== false, 'original fixed visual assets are preserved');
newsletter_test_assert(strpos($rendered['body_html'], 'SAVE THE RAVE es un ciclo') !== false, 'fixed SAVE THE RAVE description is included');
newsletter_test_assert(strpos($rendered['body_html'], 'Comprar entradas') !== false, 'checkout CTA is rendered');
newsletter_test_assert(strpos($rendered['body_text'], 'Artista Dos') !== false, 'plain text alternative includes artists');

$templateId = event_newsletters_sync_template($pdo, array('template_id'=>0) + $newsletter, $event, $artists, 7);
newsletter_test_assert($templateId > 0, 'newsletter becomes a communication template');
$stored = $pdo->query('SELECT * FROM communication_templates WHERE id=' . (int)$templateId)->fetch(PDO::FETCH_ASSOC);
newsletter_test_assert($stored && strpos($stored['body_html_template'], 'Artista Uno') !== false, 'stored template contains final rendered content');

$pdo->exec("INSERT INTO communication_event_newsletters (event_id,created_by_admin_id,subject,edition) VALUES (1,7,'Primero','Primero')");
$firstNewsletterId = (int)$pdo->lastInsertId();
newsletter_test_assert(event_newsletters_publish($pdo, $firstNewsletterId, 7), 'newsletter can be published to the permanent link');
$latest = event_newsletters_latest_published($pdo, 7);
newsletter_test_assert($latest && (int)$latest['event']['id'] === 1, 'permanent link resolves the published newsletter');
$pdo->exec("INSERT INTO eventos VALUES (2,'SEGUNDO EVENTO','segundo','Otra fecha','event_flyers/segundo.jpg','2026-10-10 23:59:00',7,NULL)");
$pdo->exec("INSERT INTO communication_event_newsletters (event_id,created_by_admin_id,subject,edition) VALUES (2,7,'Segundo','Segundo')");
$secondNewsletterId = (int)$pdo->lastInsertId();
event_newsletters_publish($pdo, $secondNewsletterId, 7);
$latest = event_newsletters_latest_published($pdo, 7);
newsletter_test_assert($latest && (int)$latest['event']['id'] === 2, 'permanent link always resolves the latest publication');
$pdo->exec("INSERT INTO eventos VALUES (3,'EVENTO DE OTRO ADMIN','tercero','No mezclar','event_flyers/tercero.jpg','2026-11-10 23:59:00',8,NULL)");
$pdo->exec("INSERT INTO communication_event_newsletters (event_id,created_by_admin_id,subject,edition) VALUES (3,8,'Tercero','Tercero')");
$otherAdminNewsletterId = (int)$pdo->lastInsertId();
event_newsletters_publish($pdo, $otherAdminNewsletterId, 8);
$latest = event_newsletters_latest_published($pdo, 7);
newsletter_test_assert($latest && (int)$latest['event']['id'] === 2, 'another administrator publication never replaces this administrator newsletter');
newsletter_test_assert(event_newsletters_latest_published($pdo, 8)['event']['id'] == 3, 'each administrator resolves only their own latest newsletter');
newsletter_test_assert(substr(event_newsletters_public_url(7), -22) === 'newsletter.php?admin=7', 'public newsletter URL remains stable per administrator');

$freeVariant = $newsletter;
$freeVariant['cta_label'] = 'Canje QR Free';
$freeVariant['checkout_url'] = 'https://str.tickex.com.ar/acceso.php?slug=evento-uno';
$freeRendered = event_newsletters_render($event, $freeVariant, $artists);
newsletter_test_assert(strpos($freeRendered['body_html'], 'Canje QR Free') !== false, 'free newsletter uses an independent CTA label');
newsletter_test_assert(strpos($freeRendered['body_html'], 'acceso.php?slug=evento-uno') !== false, 'free newsletter points to the free checkout');
newsletter_test_assert(strpos($rendered['body_html'], 'checkout_totalcoin.php?event=1') !== false, 'paid newsletter remains unchanged');
echo 'ALL EVENT NEWSLETTER TESTS PASSED' . PHP_EOL;
