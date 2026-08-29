<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/event_newsletters.php';

require_login();
$cu = current_user();
$role = isset($cu['tipo_global']) ? (string)$cu['tipo_global'] : (isset($_SESSION['tipo_global']) ? (string)$_SESSION['tipo_global'] : '');
$isSuper = event_newsletters_is_super($role);
if (!is_admin() || (!$isSuper && $role !== 'admin_evento')) {
    http_response_code(403);
    include __DIR__ . '/inc/layout_top.php';
    echo '<div class="card"><h2>Acceso restringido</h2><p>Solo para administradores.</p></div>';
    include __DIR__ . '/inc/layout_bottom.php';
    exit;
}

$pdo = db();
$adminId = isset($_SESSION['admin_id']) ? (int)$_SESSION['admin_id'] : (isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : (isset($cu['id']) ? (int)$cu['id'] : 0));
$csrf = function_exists('tickex_csrf_token') ? (string)tickex_csrf_token() : '';
$ok = '';
$error = '';
$preview = null;

try {
    event_newsletters_ensure_schema($pdo);
    communication_templates_ensure_schema($pdo);
    communication_campaigns_ensure_schema($pdo);
} catch (Exception $e) {
    $error = 'No se pudo preparar el constructor: ' . $e->getMessage();
}

$events = event_newsletters_visible_events($pdo, $adminId, $isSuper);
$eventId = $_SERVER['REQUEST_METHOD'] === 'POST' ? (int)(isset($_POST['event_id']) ? $_POST['event_id'] : 0) : (int)(isset($_GET['event_id']) ? $_GET['event_id'] : 0);
$event = $eventId > 0 ? event_newsletters_find_event($pdo, $eventId, $adminId, $isSuper) : null;
if ($eventId > 0 && !$event && $error === '') $error = 'No se encontró el evento o no tenés permiso para administrarlo.';

$defaults = $event ? event_newsletters_event_defaults($pdo, $event) : array();
$newsletter = $event ? event_newsletters_find($pdo, $eventId) : null;
$form = array(
    'subject'=>isset($defaults['subject']) ? $defaults['subject'] : '',
    'edition'=>isset($defaults['edition']) ? $defaults['edition'] : '',
    'location_text'=>isset($defaults['location_text']) ? $defaults['location_text'] : '',
    'intro_text'=>isset($defaults['intro_text']) ? $defaults['intro_text'] : '',
    'about_text'=>isset($defaults['about_text']) ? $defaults['about_text'] : '', 'cta_label'=>'Comprar entradas',
    'checkout_url'=>isset($defaults['checkout_url']) ? $defaults['checkout_url'] : '',
    'instagram_url'=>isset($defaults['instagram_url']) ? $defaults['instagram_url'] : '',
    'lineup_image_path'=>'', 'template_id'=>0, 'campaign_id'=>0,
);
if ($newsletter) foreach ($form as $key=>$unused) if (array_key_exists($key, $newsletter)) $form[$key] = $newsletter[$key];
$form['lineup_image_path'] = trim((string)$form['lineup_image_path']);
if (trim((string)$form['about_text']) === '') $form['about_text'] = event_newsletters_default_about_text();
$artists = $newsletter ? event_newsletters_artists($pdo, (int)$newsletter['id']) : ($event ? event_newsletters_default_artists($pdo, $eventId) : array());
if ($event && empty($artists)) $artists[] = array('artist_name'=>'','review_text'=>'','image_path'=>'');

if (!function_exists('event_newsletter_collect_artists')) {
    function event_newsletter_collect_artists($eventId, &$error)
    {
        $names = isset($_POST['artist_name']) && is_array($_POST['artist_name']) ? $_POST['artist_name'] : array();
        $reviews = isset($_POST['artist_review']) && is_array($_POST['artist_review']) ? $_POST['artist_review'] : array();
        $count = min(8, max(count($names), count($reviews)));
        $out = array();
        for ($i=0; $i<$count; $i++) {
            $name = trim((string)(isset($names[$i]) ? $names[$i] : ''));
            $review = trim((string)(isset($reviews[$i]) ? $reviews[$i] : ''));
            if ($name === '' && $review === '') continue;
            if ($name === '') { $error = 'Cada reseña debe tener el nombre del artista.'; return $out; }
            $out[] = array('artist_name'=>$name,'review_text'=>$review,'image_path'=>'','sort_order'=>count($out));
        }
        return $out;
    }
}

if (!function_exists('event_newsletter_save_form')) {
    function event_newsletter_save_form($pdo, $eventId, $adminId, $form, $artists)
    {
        $pdo->beginTransaction();
        try {
            $existing = event_newsletters_find($pdo, $eventId);
            $params = array(':subject'=>$form['subject'],':edition'=>$form['edition'],':location'=>$form['location_text'],':intro'=>$form['intro_text'],':about'=>$form['about_text'],':cta'=>$form['cta_label'],':checkout'=>$form['checkout_url'],':instagram'=>$form['instagram_url'],':lineup'=>$form['lineup_image_path']);
            if ($existing) {
                $params[':id'] = (int)$existing['id'];
                $st = $pdo->prepare('UPDATE communication_event_newsletters SET subject=:subject,edition=:edition,location_text=:location,intro_text=:intro,about_text=:about,cta_label=:cta,checkout_url=:checkout,instagram_url=:instagram,lineup_image_path=:lineup,updated_at=CURRENT_TIMESTAMP WHERE id=:id');
                $st->execute($params);
                $newsletterId = (int)$existing['id'];
            } else {
                $params[':event'] = (int)$eventId;
                $params[':admin'] = (int)$adminId;
                $st = $pdo->prepare('INSERT INTO communication_event_newsletters (event_id,created_by_admin_id,subject,edition,location_text,intro_text,about_text,cta_label,checkout_url,instagram_url,lineup_image_path) VALUES (:event,:admin,:subject,:edition,:location,:intro,:about,:cta,:checkout,:instagram,:lineup)');
                $st->execute($params);
                $newsletterId = (int)$pdo->lastInsertId();
            }
            $pdo->prepare('DELETE FROM communication_event_newsletter_artists WHERE newsletter_id=:id')->execute(array(':id'=>$newsletterId));
            $ins = $pdo->prepare('INSERT INTO communication_event_newsletter_artists (newsletter_id,artist_name,review_text,image_path,sort_order) VALUES (:id,:name,:review,:image,:sort)');
            foreach ($artists as $artist) $ins->execute(array(':id'=>$newsletterId,':name'=>$artist['artist_name'],':review'=>$artist['review_text'] !== '' ? $artist['review_text'] : null,':image'=>$artist['image_path'] !== '' ? $artist['image_path'] : null,':sort'=>(int)$artist['sort_order']));
            $pdo->commit();
            return $newsletterId;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}

$audiences = array();
try { $audiences = communication_campaigns_fetch_audiences($pdo, 1, $adminId, $isSuper); }
catch (Exception $e) { if ($error === '') $error = 'No se pudieron cargar las audiencias: ' . $e->getMessage(); }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $event) {
    $provided = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
    if (function_exists('tickex_csrf_verify') && !tickex_csrf_verify($provided)) $error = 'CSRF inválido. Recargá la página.';
    else {
        $action = isset($_POST['action']) ? (string)$_POST['action'] : 'save';
        foreach (array('subject','edition','location_text','intro_text','about_text','cta_label','checkout_url','instagram_url') as $key) $form[$key] = trim((string)(isset($_POST[$key]) ? $_POST[$key] : ''));
        $form['lineup_image_path'] = trim((string)(isset($_POST['lineup_existing_image']) ? $_POST['lineup_existing_image'] : ''));
        $lineupPrefix = 'newsletter_uploads/event_' . (int)$eventId . '/';
        if ($form['lineup_image_path'] !== '' && strpos($form['lineup_image_path'], $lineupPrefix) !== 0) $form['lineup_image_path'] = '';
        if (isset($_POST['remove_lineup_image']) && (string)$_POST['remove_lineup_image'] === '1') $form['lineup_image_path'] = '';
        if ($error === '' && isset($_FILES['lineup_image']) && (int)$_FILES['lineup_image']['error'] !== UPLOAD_ERR_NO_FILE) {
            $lineupUpload = event_newsletters_upload_artist_image($_FILES['lineup_image'], $eventId);
            if (empty($lineupUpload['ok'])) $error = isset($lineupUpload['error']) ? $lineupUpload['error'] : 'No se pudo subir la imagen del lineup.';
            else $form['lineup_image_path'] = (string)$lineupUpload['path'];
        }
        if ($form['subject'] === '' || $form['edition'] === '') $error = 'Completá el asunto y el título/edición.';
        foreach (array('checkout_url','instagram_url') as $key) if ($error === '' && $form[$key] !== '' && !filter_var($form[$key], FILTER_VALIDATE_URL)) $error = 'Revisá las URLs ingresadas.';
        $artists = event_newsletter_collect_artists($eventId, $error);
        if ($error === '') {
            try {
                $newsletterId = event_newsletter_save_form($pdo, $eventId, $adminId, $form, $artists);
                $newsletter = event_newsletters_find($pdo, $eventId);
                $artists = event_newsletters_artists($pdo, $newsletterId);
                $preview = event_newsletters_render($event, $newsletter, $artists);
                $ok = $action === 'preview' ? 'Borrador guardado y vista previa actualizada.' : 'Newsletter guardado.';
                if ($action === 'publish') {
                    if (!event_newsletters_publish($pdo, $newsletterId, $adminId)) throw new Exception('No se pudo marcar el newsletter como publicado.');
                    $newsletter = event_newsletters_find($pdo, $eventId);
                    $ok = 'Newsletter publicado en el enlace y QR permanentes.';
                }
                if ($action === 'prepare_campaign') {
                    $audienceId = isset($_POST['audience_id']) ? (int)$_POST['audience_id'] : 0;
                    $audience = communication_campaigns_find_audience($pdo, 1, $adminId, $isSuper, $audienceId);
                    if (!$audience) { $error = 'Seleccioná una audiencia válida.'; $ok = ''; }
                    else {
                        $templateId = event_newsletters_sync_template($pdo, $newsletter, $event, $artists, $adminId);
                        $campaignId = isset($newsletter['campaign_id']) ? (int)$newsletter['campaign_id'] : 0;
                        $campaign = null;
                        if ($campaignId > 0) {
                            $scopeSql = communication_campaigns_scope_sql($isSuper);
                            $st = $pdo->prepare('SELECT * FROM communication_campaigns WHERE id=:id AND ' . $scopeSql . ' LIMIT 1');
                            $st->execute(array(':id'=>$campaignId) + communication_campaigns_scope_params(1, $adminId, $isSuper));
                            $campaign = $st->fetch(PDO::FETCH_ASSOC);
                        }
                        $name = 'Newsletter — ' . $event['nombre'];
                        if ($campaign && in_array($campaign['status'], array('draft','archived'), true)) {
                            $st = $pdo->prepare('UPDATE communication_campaigns SET name=:name,description=:description,status="draft",audience_id=:audience,template_id=:template,subject_override=:subject,notes_internal=:notes,updated_at=CURRENT_TIMESTAMP WHERE id=:id');
                            $st->execute(array(':name'=>$name,':description'=>'Newsletter del evento #'.$eventId,':audience'=>$audienceId,':template'=>$templateId,':subject'=>$newsletter['subject'],':notes'=>'Generada desde el constructor para evento #'.$eventId,':id'=>$campaignId));
                        } else {
                            $slug = communication_campaigns_unique_slug($pdo, 1, 'newsletter-evento-'.$eventId, 0);
                            $st = $pdo->prepare('INSERT INTO communication_campaigns (organization_id,created_by_admin_id,name,slug,description,status,audience_id,template_id,subject_override,notes_internal) VALUES (1,:admin,:name,:slug,:description,"draft",:audience,:template,:subject,:notes)');
                            $st->execute(array(':admin'=>$adminId,':name'=>$name,':slug'=>$slug,':description'=>'Newsletter del evento #'.$eventId,':audience'=>$audienceId,':template'=>$templateId,':subject'=>$newsletter['subject'],':notes'=>'Generada desde el constructor para evento #'.$eventId));
                            $campaignId = (int)$pdo->lastInsertId();
                        }
                        $pdo->prepare('UPDATE communication_event_newsletters SET template_id=:template,campaign_id=:campaign,updated_at=CURRENT_TIMESTAMP WHERE id=:id')->execute(array(':template'=>$templateId,':campaign'=>$campaignId,':id'=>$newsletterId));
                        header('Location: comunicacion_campanas.php?id=' . $campaignId);
                        exit;
                    }
                }
            } catch (Exception $e) { $error = 'No se pudo guardar: ' . $e->getMessage(); $ok = ''; }
        }
    }
}

$publicNewsletterUrl = event_newsletters_public_url($adminId);
$publicNewsletterQrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=260x260&data=' . urlencode($publicNewsletterUrl);
$latestPublished = null;
try { $latestPublished = event_newsletters_latest_published($pdo, $adminId); }
catch (Exception $e) { if ($error === '') $error = 'No se pudo consultar el newsletter público: ' . $e->getMessage(); }

if ($event && isset($_GET['preview']) && (int)$_GET['preview'] === 1) {
    $saved = event_newsletters_find($pdo, $eventId);
    if (!$saved) { http_response_code(404); exit('Newsletter no guardado.'); }
    $rendered = event_newsletters_render($event, $saved, event_newsletters_artists($pdo, (int)$saved['id']));
    header('Content-Type: text/html; charset=UTF-8');
    echo $rendered['body_html'];
    exit;
}

$title = 'Mi newsletter';
include __DIR__ . '/inc/layout_top.php';
?>
<style>
.nl-grid{display:grid;grid-template-columns:minmax(0,1.15fr) minmax(320px,.85fr);gap:16px;align-items:start}.nl-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.nl-artist{border:1px solid var(--line);background:var(--panel-2);border-radius:12px;padding:14px;margin-top:12px}.nl-preview{width:100%;min-height:720px;border:1px solid var(--line);border-radius:12px;background:#05050b}@media(max-width:900px){.nl-grid{grid-template-columns:1fr}.nl-form-grid{grid-template-columns:1fr}.nl-preview{min-height:560px}}
</style>
<div class="card" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;"><a class="btn secondary" href="<?php echo $event ? 'panel_evento.php?evento_id='.(int)$eventId : 'panel_admin.php'; ?>">Volver</a><div><div class="muted">📣 Comunicación</div><h2 style="margin:0;">Mi newsletter</h2></div><span class="muted">Cada administrador tiene su propio enlace y QR; el evento completa la base y vos agregás el contenido editorial.</span></div>
<div class="card" style="display:flex;gap:8px;flex-wrap:wrap;"><a class="btn secondary" href="superadmin_emails_db.php">Contactos</a><a class="btn secondary" href="comunicacion_audiencias.php">Audiencias</a><a class="btn" href="comunicacion_newsletter.php<?php echo $event ? '?event_id='.(int)$eventId : ''; ?>">Newsletter</a><a class="btn secondary" href="comunicacion_plantillas.php">Plantillas</a><a class="btn secondary" href="comunicacion_campanas.php">Campañas</a><a class="btn secondary" href="comunicacion_estado_motor.php">Estado Motor</a><a class="btn secondary" href="comunicacion_historial.php">Historial</a></div>
<div class="card" style="display:flex;gap:18px;align-items:center;flex-wrap:wrap;"><div style="flex:1 1 360px;"><h3 style="margin:0 0 8px;">Tu enlace y QR permanentes</h3><p class="muted" style="margin:0 0 12px;">Son exclusivos de tu usuario administrador y siempre muestran el último newsletter que publiques. Nunca se mezclan con los de otros administradores.</p><label>Enlace público del administrador #<?php echo (int)$adminId; ?></label><input readonly value="<?php echo e($publicNewsletterUrl); ?>" onclick="this.select()"><div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px;"><a class="btn secondary" href="<?php echo e($publicNewsletterUrl); ?>" target="_blank" rel="noopener">Abrir newsletter público</a></div><?php if($latestPublished): ?><p style="margin:12px 0 0;">Publicado actualmente: <strong><?php echo e($latestPublished['event']['nombre']); ?></strong></p><?php else: ?><p class="muted" style="margin:12px 0 0;">Todavía no publicaste ningún newsletter en este enlace.</p><?php endif; ?></div><a href="<?php echo e($publicNewsletterUrl); ?>" target="_blank" rel="noopener"><img src="<?php echo e($publicNewsletterQrUrl); ?>" width="180" height="180" alt="QR del último newsletter" style="display:block;width:180px;height:180px;background:#fff;padding:8px;border-radius:10px;"></a></div>
<?php if ($error !== ''): ?><div class="card" style="border-color:var(--warn);color:var(--warn);">⚠ <?php echo e($error); ?></div><?php endif; ?>
<?php if ($ok !== ''): ?><div class="card" style="border-color:var(--ok);color:var(--ok);">✓ <?php echo e($ok); ?></div><?php endif; ?>
<div class="card"><form method="get" style="display:flex;gap:10px;align-items:end;flex-wrap:wrap;"><div style="flex:1 1 320px;"><label>Evento</label><select name="event_id" required><option value="">Seleccioná un evento…</option><?php foreach($events as $row): ?><option value="<?php echo (int)$row['id']; ?>" <?php echo $eventId===(int)$row['id']?'selected':''; ?>>#<?php echo (int)$row['id']; ?> — <?php echo e($row['nombre']); ?></option><?php endforeach; ?></select></div><button class="btn" type="submit">Ir al newsletter</button></form></div>
<?php if ($event): ?>
<form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?php echo e($csrf); ?>"><input type="hidden" name="event_id" value="<?php echo (int)$eventId; ?>">
<div class="nl-grid"><div>
<div class="card"><h3 style="margin-top:0;">Datos del evento</h3><p class="muted">Flyer, fecha y venue se toman automáticamente. El texto sigue siendo editable.</p><div class="nl-form-grid">
<div style="grid-column:1/-1;"><label>Asunto del email</label><input name="subject" maxlength="180" required value="<?php echo e($form['subject']); ?>"></div><div><label>Título / edición</label><input name="edition" maxlength="160" required value="<?php echo e($form['edition']); ?>"></div><div><label>Lugar</label><input name="location_text" maxlength="220" value="<?php echo e($form['location_text']); ?>"></div><div style="grid-column:1/-1;"><label>Introducción</label><textarea name="intro_text" rows="5" maxlength="3000"><?php echo e($form['intro_text']); ?></textarea></div><div style="grid-column:1/-1;"><label>Sobre el evento / ciclo</label><textarea name="about_text" rows="5" maxlength="5000"><?php echo e($form['about_text']); ?></textarea></div><div><label>Texto del botón</label><input name="cta_label" maxlength="80" value="<?php echo e($form['cta_label']); ?>"></div><div><label>Instagram</label><input type="url" name="instagram_url" value="<?php echo e($form['instagram_url']); ?>"></div><div style="grid-column:1/-1;"><label>Enlace de compra</label><input type="url" name="checkout_url" value="<?php echo e($form['checkout_url']); ?>"></div></div>
<?php if(!empty($defaults['flyer_url'])): ?><div style="margin-top:14px;"><div class="muted">Flyer del evento</div><img src="<?php echo e($defaults['flyer_url']); ?>" alt="Flyer" style="max-width:260px;width:100%;border-radius:10px;"></div><?php else: ?><p class="muted">Este evento no tiene flyer cargado.</p><?php endif; ?></div>
<div class="card"><div style="display:flex;justify-content:space-between;gap:10px;align-items:center;"><div><h3 style="margin:0;">Textos de los DJs</h3><div class="muted">El primer DJ se muestra arriba de la imagen conjunta. Los demás se muestran debajo y podés agregar o quitar los que necesites.</div></div><button class="btn secondary" type="button" id="addArtist">+ Agregar DJ secundario</button></div>
<div style="margin-top:14px;padding:12px;border:1px dashed var(--line);border-radius:10px;"><label>Imagen conjunta del artista o lineup (máx. 5 MB)</label><input type="file" name="lineup_image" accept="image/jpeg,image/png,image/webp"><input type="hidden" name="lineup_existing_image" value="<?php echo e($form['lineup_image_path']); ?>"><?php if($form['lineup_image_path']!==''): ?><div style="display:flex;gap:10px;align-items:center;margin-top:10px;"><img src="<?php echo e(event_newsletters_absolute_url($form['lineup_image_path'])); ?>" alt="Lineup" style="width:180px;height:110px;object-fit:cover;border-radius:8px;"><label><input type="checkbox" name="remove_lineup_image" value="1"> Quitar imagen actual</label></div><?php endif; ?></div><div id="artistList">
<?php foreach($artists as $i=>$artist): ?><div class="nl-artist"><div style="display:flex;justify-content:space-between;"><strong class="artist-role"><?php echo $i===0?'DJ principal':'DJ secundario '.(int)$i; ?></strong><button type="button" class="btn danger remove-artist">Quitar</button></div><div style="margin-top:10px;"><label>Nombre</label><input name="artist_name[]" maxlength="160" value="<?php echo e($artist['artist_name']); ?>"><label style="margin-top:10px;">Descripción / reseña</label><textarea name="artist_review[]" rows="5" maxlength="5000"><?php echo e(isset($artist['review_text'])?$artist['review_text']:''); ?></textarea></div></div><?php endforeach; ?>
</div></div>
<div class="card"><h3 style="margin-top:0;">Preparar campaña</h3><p class="muted">Guardar no envía emails. Publicar actualiza solamente el enlace y QR permanentes. Para enviar por email, elegí una audiencia y confirmá el envío desde Campañas.</p><label>Audiencia</label><select name="audience_id"><option value="">Seleccioná una audiencia…</option><?php foreach($audiences as $audience): ?><option value="<?php echo (int)$audience['id']; ?>"><?php echo e($audience['name']); ?></option><?php endforeach; ?></select><?php if(empty($audiences)): ?><p class="muted"><a href="comunicacion_audiencias.php">Crear audiencia</a></p><?php endif; ?><div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:14px;"><button class="btn secondary" name="action" value="save">Guardar borrador</button><button class="btn secondary" name="action" value="preview">Guardar y previsualizar</button><button class="btn secondary" name="action" value="publish">Publicar en enlace y QR</button><button class="btn" name="action" value="prepare_campaign">Crear/actualizar campaña</button><?php if($newsletter): ?><a class="btn secondary" href="comunicacion_newsletter.php?event_id=<?php echo $eventId; ?>&preview=1" target="_blank">Abrir preview</a><?php endif; ?></div></div>
</div><div class="card" style="position:sticky;top:12px;"><h3 style="margin-top:0;">Vista previa</h3><?php if($preview): ?><iframe class="nl-preview" title="Preview" srcdoc="<?php echo e($preview['body_html']); ?>"></iframe><?php elseif($newsletter): ?><iframe class="nl-preview" title="Preview" src="comunicacion_newsletter.php?event_id=<?php echo $eventId; ?>&preview=1"></iframe><?php else: ?><div class="muted">Guardá el borrador para generar la vista previa.</div><?php endif; ?></div></div></form>
<template id="artistTemplate"><div class="nl-artist"><div style="display:flex;justify-content:space-between;"><strong class="artist-role"></strong><button type="button" class="btn danger remove-artist">Quitar</button></div><div style="margin-top:10px;"><label>Nombre</label><input name="artist_name[]" maxlength="160"><label style="margin-top:10px;">Descripción / reseña</label><textarea name="artist_review[]" rows="5" maxlength="5000"></textarea></div></div></template>
<script>(function(){var list=document.getElementById('artistList'),add=document.getElementById('addArtist');function renumber(){var b=list.querySelectorAll('.nl-artist');for(var i=0;i<b.length;i++){b[i].querySelector('.artist-role').textContent=i===0?'DJ principal':'DJ secundario '+i;}}add.addEventListener('click',function(){if(list.querySelectorAll('.nl-artist').length>=8){alert('Máximo 8 DJs.');return;}list.appendChild(document.getElementById('artistTemplate').content.cloneNode(true));renumber();});list.addEventListener('click',function(e){var btn=e.target.closest('.remove-artist');if(!btn)return;btn.closest('.nl-artist').remove();if(!list.querySelector('.nl-artist'))add.click();renumber();});renumber();})();</script>
<?php endif; ?>
<?php include __DIR__ . '/inc/layout_bottom.php'; ?>
