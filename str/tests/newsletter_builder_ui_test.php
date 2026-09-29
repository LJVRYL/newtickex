<?php
$page = file_get_contents(__DIR__ . '/../comunicacion_newsletter.php');
$view = file_get_contents(__DIR__ . '/../inc/newsletter_builder_view.php');
function newsletter_ui_assert($condition, $message) { if (!$condition) { fwrite(STDERR, "FAIL: $message\n"); exit(1); } echo "PASS: $message\n"; }
newsletter_ui_assert(strpos($page, "auth_context") !== false && strpos($page, "['id']") !== false, 'newsletter uses the current administrator identity');
newsletter_ui_assert(strpos($view, 'Identidad de la edición') !== false && strpos($view, 'Historia y llamados a la acción') !== false, 'newsletter content is separated into clear sections');
newsletter_ui_assert(strpos($view, 'Artistas y lineup') !== false && strpos($view, 'Publicación y campaña') !== false, 'lineup and publishing have distinct hierarchy');
newsletter_ui_assert(strpos($view, 'Guardar no envía correos') !== false, 'sending expectations remain explicit');
newsletter_ui_assert(strpos($view, 'name="action" value="save"') !== false && strpos($view, 'name="action" value="preview"') !== false && strpos($view, 'name="action" value="publish"') !== false && strpos($view, 'name="action" value="prepare_campaign"') !== false, 'all newsletter operations remain available');
newsletter_ui_assert(strpos($view, 'newsletterArtistTemplate') !== false && strpos($view, 'Máximo 8 artistas') !== false, 'dynamic artist editor remains available');
newsletter_ui_assert(strpos($view, 'newsletter-preview') !== false, 'email preview remains visible beside the editor');
echo "ALL NEWSLETTER BUILDER UI TESTS PASSED\n";
