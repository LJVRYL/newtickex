<?php
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/event_lifecycle.php';
require_once __DIR__ . '/inc/organizer_site.php';

// Tipo global (super_admin, admin_evento)
$tg = isset($_SESSION['tipo_global']) ? $_SESSION['tipo_global'] : '';
if (!in_array($tg, array('admin_evento','super_admin','superadmin'), true)) {
    $next = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '/mi_sitio.php';
    header('Location: /login_admin.php?next=' . urlencode($next), true, 302);
    exit;
}

$identity = current_user();
$admin_id = isset($identity['id']) ? (int)$identity['id'] : 0;
if ($admin_id <= 0) {
    die('No se pudo determinar el ID de administrador actual.');
}

$pdo = db();
tickex_organizer_site_ensure_schema($pdo);

// Asegurar columna publicado_site en eventos (flag de publicación)
$colsEv = $pdo->query("PRAGMA table_info(eventos)")->fetchAll(PDO::FETCH_ASSOC);
$hasPublicar = false; $hasCreadoPor = false; $hasFechaDesde = false; $hasFechaHasta = false;
foreach ($colsEv as $c) {
    if ($c['name'] === 'publicado_site') $hasPublicar = true;
    if ($c['name'] === 'creado_por_admin_id') $hasCreadoPor = true;
    if ($c['name'] === 'fecha_desde') $hasFechaDesde = true;
    if ($c['name'] === 'fecha_hasta') $hasFechaHasta = true;
}

// Helper e()
if (!function_exists('e')) {
    function e($str) { return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8'); }
}

$page_title = 'Mi sitio';
$errors = array();
$saved  = false;
$csrf = tickex_csrf_token();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !tickex_csrf_verify(isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '')) {
    http_response_code(403);
    exit('Solicitud vencida o inválida.');
}

// Cargar config actual
$config = tickex_organizer_site_defaults();

try {
    $config = tickex_organizer_site_by_admin($pdo, $admin_id);
} catch (Exception $e) {
    $errors[] = 'Error al cargar la configuración actual: ' . $e->getMessage();
}

function _tickex_normalize_url($value)
{
  $value = trim((string)$value);
  if ($value === '') return '';
  if (preg_match('~^https?://~i', $value)) return $value;
  return 'https://' . $value;
}

function _tickex_whatsapp_to_href($value)
{
  $value = trim((string)$value);
  if ($value === '') return '';

  if (preg_match('~^https?://~i', $value)) return $value;
  if (stripos($value, 'wa.me/') !== false) return (preg_match('~^https?://~i', $value) ? $value : ('https://' . $value));

  $digits = preg_replace('/\D+/', '', $value);
  if ($digits === '') return '';
  return 'https://wa.me/' . $digits;
}

// Toggle publicación de evento
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_event') {
    $eid = isset($_POST['event_id']) ? (int)$_POST['event_id'] : 0;
    $to  = isset($_POST['to']) ? (int)$_POST['to'] : 0;
    if ($eid > 0) {
        try {
            if ($hasCreadoPor) {
                $st = $pdo->prepare('UPDATE eventos SET publicado_site = :to WHERE id = :id AND creado_por_admin_id = :aid');
                $st->execute(array(':to'=>$to, ':id'=>$eid, ':aid'=>$admin_id));
            } else {
                $st = $pdo->prepare('UPDATE eventos SET publicado_site = :to WHERE id = :id');
                $st->execute(array(':to'=>$to, ':id'=>$eid));
            }
            $saved = true;
        } catch (Exception $e) {
            $errors[] = 'No se pudo actualizar el evento: ' . $e->getMessage();
        }
    }
}

// Guardar config del sitio
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (!isset($_POST['action']) || $_POST['action'] === 'save_identity')) {
    $nombre_publico = isset($_POST['nombre_publico']) ? trim($_POST['nombre_publico']) : '';
    $slug_publico   = isset($_POST['slug_publico']) ? trim($_POST['slug_publico']) : '';
    $texto_hero     = isset($_POST['texto_hero']) ? trim($_POST['texto_hero']) : '';
    $texto_intro    = isset($_POST['texto_intro']) ? trim($_POST['texto_intro']) : '';
    $visible        = isset($_POST['visible']) ? 1 : 0;

    if ($nombre_publico === '') {
        $errors[] = 'El nombre público del sitio es obligatorio.';
    }
    if ($slug_publico === '') {
        $errors[] = 'El slug público es obligatorio.';
    } else {
        $slug_publico = tickex_organizer_site_slug($slug_publico);
        if ($slug_publico === '') {
            $errors[] = 'El slug público no puede quedar vacío luego de normalizarlo.';
        }
    }

    // Unicidad de slug
    if (empty($errors) && $slug_publico !== '') {
        $st = $pdo->prepare('SELECT id FROM clientes_sites WHERE slug_publico = :slug AND admin_id != :aid LIMIT 1');
        $st->execute(array(':slug'=>$slug_publico, ':aid'=>$admin_id));
        if ($st->fetch(PDO::FETCH_ASSOC)) {
            $errors[] = 'Ese slug ya está en uso por otro sitio.';
        }
    }

    if (empty($errors)) {
        $now = date('c');
        try {
            $stmt = $pdo->prepare('SELECT id FROM clientes_sites WHERE admin_id = :admin_id LIMIT 1');
            $stmt->execute(array(':admin_id' => $admin_id));
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                $stmt = $pdo->prepare('
                    UPDATE clientes_sites
                    SET slug_publico = :slug_publico,
                        nombre_publico = :nombre_publico,
                        texto_hero = :texto_hero,
                        texto_intro = :texto_intro,
                        visible = :visible,
                        updated_at = :updated_at
                    WHERE admin_id = :admin_id
                ');
                $stmt->execute(array(
                    ':slug_publico'   => $slug_publico,
                    ':nombre_publico' => $nombre_publico,
                    ':texto_hero'     => $texto_hero,
                    ':texto_intro'    => $texto_intro,
                    ':visible'        => $visible,
                    ':updated_at'     => $now,
                    ':admin_id'       => $admin_id,
                ));
            } else {
                $stmt = $pdo->prepare('
                    INSERT INTO clientes_sites
                        (admin_id, slug_publico, nombre_publico, texto_hero, texto_intro, visible, created_at, updated_at)
                    VALUES
                        (:admin_id, :slug_publico, :nombre_publico, :texto_hero, :texto_intro, :visible, :created_at, :updated_at)
                ');
                $stmt->execute(array(
                    ':admin_id'       => $admin_id,
                    ':slug_publico'   => $slug_publico,
                    ':nombre_publico' => $nombre_publico,
                    ':texto_hero'     => $texto_hero,
                    ':texto_intro'    => $texto_intro,
                    ':visible'        => $visible,
                    ':created_at'     => $now,
                    ':updated_at'     => $now,
                ));
            }

            $saved = true;
            $config = array_merge($config, compact('slug_publico','nombre_publico','texto_hero','texto_intro','visible'));
        } catch (Exception $e) {
            $errors[] = 'Error al guardar la configuración: ' . $e->getMessage();
        }
    } else {
        $config['slug_publico']   = $slug_publico;
        $config['nombre_publico'] = $nombre_publico;
        $config['texto_hero']     = $texto_hero;
        $config['texto_intro']    = $texto_intro;
        $config['visible']        = $visible;
    }
}

      // Guardar redes/whatsapp
      if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_extras') {
        if (empty($config['slug_publico'])) {
          $errors[] = 'Primero guardá un slug público para habilitar redes y QR.';
        } else {
          $whatsapp = isset($_POST['whatsapp']) ? trim($_POST['whatsapp']) : '';
          $instagram_url = isset($_POST['instagram_url']) ? _tickex_normalize_url($_POST['instagram_url']) : '';
          $tiktok_url = isset($_POST['tiktok_url']) ? _tickex_normalize_url($_POST['tiktok_url']) : '';
          $facebook_url = isset($_POST['facebook_url']) ? _tickex_normalize_url($_POST['facebook_url']) : '';
          $youtube_url = isset($_POST['youtube_url']) ? _tickex_normalize_url($_POST['youtube_url']) : '';

          if ($whatsapp !== '' && _tickex_whatsapp_to_href($whatsapp) === '') {
            $errors[] = 'El WhatsApp ingresado no parece válido. Poné un número (con código de país) o un link.';
          }

          if (empty($errors)) {
            $now = date('c');
            try {
              $stmt = $pdo->prepare('SELECT id FROM clientes_sites WHERE admin_id = :admin_id LIMIT 1');
              $stmt->execute(array(':admin_id' => $admin_id));
              $existing = $stmt->fetch(PDO::FETCH_ASSOC);
              if (!$existing) {
                $errors[] = 'Primero guardá la configuración del sitio (nombre + slug) para poder cargar redes.';
              } else {
                $stmt = $pdo->prepare('UPDATE clientes_sites SET whatsapp = :whatsapp, instagram_url = :ig, tiktok_url = :tt, facebook_url = :fb, youtube_url = :yt, updated_at = :updated_at WHERE admin_id = :admin_id');
                $stmt->execute(array(
                  ':whatsapp' => $whatsapp,
                  ':ig' => $instagram_url,
                  ':tt' => $tiktok_url,
                  ':fb' => $facebook_url,
                  ':yt' => $youtube_url,
                  ':updated_at' => $now,
                  ':admin_id' => $admin_id,
                ));
                $saved = true;
                $config['whatsapp'] = $whatsapp;
                $config['instagram_url'] = $instagram_url;
                $config['tiktok_url'] = $tiktok_url;
                $config['facebook_url'] = $facebook_url;
                $config['youtube_url'] = $youtube_url;
              }
            } catch (Exception $e) {
              $errors[] = 'Error al guardar redes/WhatsApp: ' . $e->getMessage();
            }
          } else {
            $config['whatsapp'] = $whatsapp;
            $config['instagram_url'] = $instagram_url;
            $config['tiktok_url'] = $tiktok_url;
            $config['facebook_url'] = $facebook_url;
            $config['youtube_url'] = $youtube_url;
          }
        }
      }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_brand') {
    try {
        if (empty($config['slug_publico'])) throw new RuntimeException('Primero guardá la identidad del sitio.');
        $primary = tickex_organizer_site_color(isset($_POST['primary_color']) ? $_POST['primary_color'] : '', '#7c5cff');
        $accent = tickex_organizer_site_color(isset($_POST['accent_color']) ? $_POST['accent_color'] : '', '#47d7ea');
        $background = tickex_organizer_site_color(isset($_POST['background_color']) ? $_POST['background_color'] : '', '#070914');
        $logo = tickex_organizer_site_asset_url(isset($_POST['logo_url']) ? $_POST['logo_url'] : '');
        $st = $pdo->prepare('UPDATE clientes_sites SET primary_color=:primary,accent_color=:accent,background_color=:background,logo_url=:logo,updated_at=:updated WHERE admin_id=:admin');
        $st->execute(array(':primary'=>$primary,':accent'=>$accent,':background'=>$background,':logo'=>$logo,':updated'=>date('c'),':admin'=>$admin_id));
        $config = array_merge($config,array('primary_color'=>$primary,'accent_color'=>$accent,'background_color'=>$background,'logo_url'=>$logo));
        $saved = true;
    } catch (Exception $e) {
        $errors[] = $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_domain') {
    try {
        if (empty($config['slug_publico'])) throw new RuntimeException('Primero guardá la identidad del sitio.');
        $domain = tickex_organizer_site_domain(isset($_POST['custom_domain']) ? $_POST['custom_domain'] : '');
        if ($domain !== '') {
            $st = $pdo->prepare('SELECT 1 FROM clientes_sites WHERE custom_domain=:domain AND admin_id<>:admin LIMIT 1');
            $st->execute(array(':domain'=>$domain,':admin'=>$admin_id));
            if ($st->fetchColumn()) throw new RuntimeException('Ese dominio ya está asociado a otro organizador.');
        }
        $status = $domain === '' ? 'not_configured' : ($domain === $config['custom_domain'] ? $config['custom_domain_status'] : 'pending');
        $st = $pdo->prepare('UPDATE clientes_sites SET custom_domain=:domain,custom_domain_status=:status,updated_at=:updated WHERE admin_id=:admin');
        $st->execute(array(':domain'=>$domain!==''?$domain:null,':status'=>$status,':updated'=>date('c'),':admin'=>$admin_id));
        $config['custom_domain']=$domain;$config['custom_domain_status']=$status;$saved=true;
    } catch (Exception $e) {
        $errors[] = $e->getMessage();
    }
}

// Eventos del admin
if ($hasCreadoPor) {
    $stEv = $pdo->prepare('SELECT * FROM eventos WHERE creado_por_admin_id = :aid ORDER BY id DESC');
    $stEv->execute(array(':aid'=>$admin_id));
} else {
    $stEv = $pdo->query('SELECT * FROM eventos ORDER BY id DESC');
}
$eventos = $stEv ? $stEv->fetchAll(PDO::FETCH_ASSOC) : array();

// Función para saber si el evento sigue vigente por fecha
function evento_vigente($ev, $hasFechaDesde, $hasFechaHasta) {
    if (!$hasFechaDesde) $ev['fecha_desde'] = '';
    if (!$hasFechaHasta) $ev['fecha_hasta'] = '';
    return tickex_event_is_current($ev);
}

$eventosPublicados = 0;
$eventosVigentes = 0;
foreach ($eventos as $eventoResumen) {
    if (!empty($eventoResumen['publicado_site'])) $eventosPublicados++;
    if (evento_vigente($eventoResumen, $hasFechaDesde, $hasFechaHasta)) $eventosVigentes++;
}
$canalesConectados = 0;
foreach (array('whatsapp','instagram_url','tiktok_url','facebook_url','youtube_url') as $canalCampo) {
    if (!empty($config[$canalCampo])) $canalesConectados++;
}
$sitioConfigurado = !empty($config['slug_publico']) && !empty($config['nombre_publico']);
$publicPreviewUrl = tickex_organizer_site_public_url($config, false);
$publicCanonicalUrl = tickex_organizer_site_public_url($config, true);

require __DIR__ . '/inc/layout_top.php';
?>
<style>
  .site-admin-hero{position:relative;overflow:hidden;padding:28px;background:linear-gradient(135deg,rgba(15,118,110,.24),rgba(40,27,93,.88))}
  .site-admin-hero:after{content:"";position:absolute;width:290px;height:290px;right:-100px;bottom:-190px;border:1px solid rgba(55,210,190,.22);border-radius:50%}
  .site-admin-eyebrow{color:#4de0ca;font-size:11px;font-weight:800;letter-spacing:.13em;text-transform:uppercase}
  .site-admin-hero h1{margin:7px 0 6px;font-size:clamp(28px,4vw,44px)}
  .site-admin-hero p{margin:0;color:var(--muted);max-width:650px}
  .site-admin-toolbar{position:relative;z-index:1;display:flex;justify-content:space-between;align-items:flex-end;gap:18px;flex-wrap:wrap}
  .site-admin-actions{display:flex;gap:8px;flex-wrap:wrap}
  .site-admin-stats{position:relative;z-index:1;display:grid;grid-template-columns:repeat(4,minmax(110px,1fr));gap:10px;margin-top:24px}
  .site-admin-stat{padding:13px 15px;border:1px solid rgba(255,255,255,.08);border-radius:14px;background:rgba(6,10,22,.38)}
  .site-admin-stat span{display:block;color:var(--muted);font-size:11px;font-weight:750;text-transform:uppercase;letter-spacing:.06em}
  .site-admin-stat strong{display:block;margin-top:3px;font-size:20px}
  .site-admin-section{padding:0}
  .site-admin-section>summary{list-style:none;cursor:pointer;display:flex;justify-content:space-between;align-items:center;gap:16px;padding:20px 22px}
  .site-admin-section>summary::-webkit-details-marker{display:none}
  .site-admin-section>summary:after{content:"+";display:grid;place-items:center;width:32px;height:32px;flex:0 0 auto;border:1px solid var(--line);border-radius:10px;color:#6ee7d5;font-size:22px}
  .site-admin-section[open]>summary:after{content:"−"}
  .site-admin-section-title{font-size:18px;font-weight:800}
  .site-admin-section-subtitle{margin-top:3px;color:var(--muted);font-size:13px}
  .site-admin-section-body{border-top:1px solid var(--line);padding:22px}
  .site-admin-events-head{display:flex;justify-content:space-between;align-items:flex-end;gap:14px;flex-wrap:wrap;margin-bottom:16px}
  .site-admin-events-head h2{margin:0}.site-admin-events-head p{margin:4px 0 0;color:var(--muted);font-size:13px}
  .site-status{display:inline-flex;align-items:center;gap:6px;padding:5px 9px;border:1px solid var(--line);border-radius:999px;background:var(--panel-2);font-size:11px;font-weight:800}
  .site-status:before{content:"";width:6px;height:6px;border-radius:50%;background:currentColor}
  .site-status.on{color:var(--ok)}.site-status.off{color:var(--muted)}.site-status.past{color:var(--warn)}
  .site-event-card{margin:0!important;padding:16px!important;transition:border-color .2s ease,transform .2s ease}
  .site-event-card:hover{border-color:rgba(118,94,255,.42);transform:translateY(-1px)}
  .site-event-meta{display:flex;gap:7px;flex-wrap:wrap;margin-top:10px}
  .site-brand-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(280px,.72fr);gap:18px;align-items:start}
  .site-color-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}.site-color-grid input[type=color]{width:100%;height:48px;padding:4px;cursor:pointer}
  .site-brand-preview{min-height:230px;padding:22px;border:1px solid rgba(255,255,255,.1);border-radius:17px;background:var(--preview-bg);display:flex;flex-direction:column;justify-content:space-between;overflow:hidden}
  .site-brand-preview-logo{height:42px;max-width:180px;object-fit:contain;object-position:left center}.site-brand-preview-name{font-size:13px;font-weight:900;letter-spacing:.08em;text-transform:uppercase}
  .site-brand-preview h3{font-size:28px;line-height:1.05;margin:34px 0 8px}.site-brand-preview p{color:#bcc1d2;margin:0}.site-brand-preview .demo-btn{align-self:flex-start;margin-top:18px;padding:9px 13px;border-radius:10px;background:var(--preview-primary);color:#fff;font-weight:850}.site-domain-row{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:9px;align-items:end}.site-domain-note{padding:13px;border:1px solid var(--line);border-radius:13px;background:rgba(255,255,255,.025)}
  @media(max-width:720px){.site-admin-hero{padding:22px}.site-admin-stats{grid-template-columns:repeat(2,minmax(0,1fr))}.site-admin-toolbar{align-items:stretch}.site-admin-actions,.site-admin-actions .btn{width:100%}.site-admin-actions .btn{text-align:center}}
  @media(max-width:820px){.site-brand-grid{grid-template-columns:1fr}.site-domain-row{grid-template-columns:1fr}.site-color-grid{grid-template-columns:1fr}}
</style>
<div class="page">
  <div class="card site-admin-hero">
    <div class="site-admin-toolbar">
      <div>
        <div class="site-admin-eyebrow">Presencia pública</div>
        <h1>Mi sitio</h1>
        <p>Configurá tu página, conectá tus canales y elegí qué eventos querés mostrar.</p>
      </div>
      <div class="site-admin-actions">
        <a class="btn secondary" href="#eventos-sitio">Administrar eventos</a>
      <?php if (!empty($config['slug_publico'])): ?>
          <a class="btn" href="<?php echo e($publicPreviewUrl); ?>" target="_blank">Ver sitio público</a>
      <?php else: ?>
          <a class="btn" href="#identidad-sitio">Configurar sitio</a>
      <?php endif; ?>
      </div>
    </div>
    <div class="site-admin-stats">
      <div class="site-admin-stat"><span>Estado</span><strong><?php echo !empty($config['visible']) ? 'Publicado' : 'Oculto'; ?></strong></div>
      <div class="site-admin-stat"><span>Eventos visibles</span><strong><?php echo (int)$eventosPublicados; ?></strong></div>
      <div class="site-admin-stat"><span>Eventos vigentes</span><strong><?php echo (int)$eventosVigentes; ?></strong></div>
      <div class="site-admin-stat"><span>Canales</span><strong><?php echo (int)$canalesConectados; ?>/5</strong></div>
    </div>
  </div>

  <?php if (!empty($errors)): ?>
    <div class="alert error"><ul><?php foreach ($errors as $err): ?><li><?php echo e($err); ?></li><?php endforeach; ?></ul></div>
  <?php elseif ($saved): ?>
    <div class="alert success">Cambios guardados.</div>
  <?php endif; ?>

  <details class="card site-admin-section" id="identidad-sitio"<?php echo (!$sitioConfigurado || !empty($errors)) ? ' open' : ''; ?>>
    <summary>
      <div>
        <div class="site-admin-section-title">Identidad y portada</div>
        <div class="site-admin-section-subtitle">Nombre, dirección pública, textos principales y estado del sitio.</div>
      </div>
    </summary>
    <div class="site-admin-section-body">
      <form method="post" action="mi_sitio.php">
        <input type="hidden" name="_csrf" value="<?php echo e($csrf); ?>">
        <input type="hidden" name="action" value="save_identity">
        <div class="form-group">
          <label for="nombre_publico">Nombre público del sitio</label>
          <input type="text" id="nombre_publico" name="nombre_publico" class="form-control" value="<?php echo e($config['nombre_publico']); ?>" required>
          <small class="form-text text-muted">Ej: “Save The Rave”, “Teatro Central”.</small>
        </div>

        <div class="form-group">
          <label for="slug_publico">Slug público</label>
          <input type="text" id="slug_publico" name="slug_publico" class="form-control" value="<?php echo e($config['slug_publico']); ?>" required>
          <small class="form-text text-muted">Minúsculas, números y guiones. Dirección prevista: <code><?php echo e($publicCanonicalUrl); ?></code></small>
        </div>

        <div class="form-group">
          <label for="texto_hero">Texto principal (hero)</label>
          <input type="text" id="texto_hero" name="texto_hero" class="form-control" value="<?php echo e($config['texto_hero']); ?>">
        </div>

        <div class="form-group">
          <label for="texto_intro">Texto introductorio</label>
          <textarea id="texto_intro" name="texto_intro" class="form-control" rows="3"><?php echo e($config['texto_intro']); ?></textarea>
        </div>

        <div class="form-group">
          <label><input type="checkbox" name="visible" value="1" <?php echo ($config['visible'] ? 'checked' : ''); ?>> Sitio público activo</label>
        </div>

        <div class="form-actions">
          <button type="submit" class="btn primary">Guardar cambios</button>
          <?php if (!empty($config['slug_publico'])): ?>
            <a class="btn secondary" href="<?php echo e($publicPreviewUrl); ?>" target="_blank">Ver sitio público</a>
          <?php endif; ?>
        </div>
      </form>
    </div>
  </details>

  <details class="card site-admin-section" id="marca-sitio">
    <summary><div><div class="site-admin-section-title">Marca visual</div><div class="site-admin-section-subtitle">Colores y logo propios, con una vista previa responsive.</div></div></summary>
    <div class="site-admin-section-body">
      <div class="site-brand-grid">
        <form method="post" action="mi_sitio.php">
          <input type="hidden" name="_csrf" value="<?php echo e($csrf); ?>"><input type="hidden" name="action" value="save_brand">
          <div class="site-color-grid">
            <div class="form-group"><label for="primary_color">Color principal</label><input type="color" id="primary_color" name="primary_color" value="<?php echo e($config['primary_color']); ?>"></div>
            <div class="form-group"><label for="accent_color">Acento</label><input type="color" id="accent_color" name="accent_color" value="<?php echo e($config['accent_color']); ?>"></div>
            <div class="form-group"><label for="background_color">Fondo</label><input type="color" id="background_color" name="background_color" value="<?php echo e($config['background_color']); ?>"></div>
          </div>
          <div class="form-group"><label for="logo_url">Logo de la productora</label><input class="form-control" type="text" id="logo_url" name="logo_url" value="<?php echo e($config['logo_url']); ?>" placeholder="https://... o /uploads/..."><small class="form-text text-muted">Debe ser HTTPS o una imagen alojada dentro de Tickex. Si queda vacío, mostramos el nombre.</small></div>
          <button class="btn primary" type="submit">Guardar identidad visual</button>
        </form>
        <div class="site-brand-preview" style="--preview-bg:<?php echo e($config['background_color']); ?>;--preview-primary:<?php echo e($config['primary_color']); ?>">
          <div><?php if (!empty($config['logo_url'])): ?><img class="site-brand-preview-logo" src="<?php echo e($config['logo_url']); ?>" alt=""><?php else: ?><div class="site-brand-preview-name"><?php echo e($config['nombre_publico'] ?: 'Tu productora'); ?></div><?php endif; ?><h3><?php echo e($config['texto_hero'] ?: 'Tus eventos, en un solo lugar'); ?></h3><p><?php echo e($config['texto_intro'] ?: 'Una experiencia simple para descubrir fechas y comprar entradas.'); ?></p></div><span class="demo-btn">Ver entradas</span>
        </div>
      </div>
    </div>
  </details>

  <details class="card site-admin-section" id="dominio-sitio">
    <summary><div><div class="site-admin-section-title">Dominio y marca blanca</div><div class="site-admin-section-subtitle">Prepará una dirección propia sin alterar todavía tu sitio activo.</div></div></summary>
    <div class="site-admin-section-body">
      <form method="post" action="mi_sitio.php">
        <input type="hidden" name="_csrf" value="<?php echo e($csrf); ?>"><input type="hidden" name="action" value="save_domain">
        <div class="site-domain-row"><div class="form-group"><label for="custom_domain">Dominio propio</label><input class="form-control" type="text" id="custom_domain" name="custom_domain" value="<?php echo e($config['custom_domain']); ?>" placeholder="entradas.tuproductora.com"><small class="form-text text-muted">Sin https ni rutas. Guardarlo crea una solicitud; no cambia el tráfico hasta que Tickex verifique DNS y certificado.</small></div><button class="btn primary" type="submit">Guardar solicitud</button></div>
      </form>
      <div class="site-domain-note"><strong>Estado: <?php echo $config['custom_domain_status']==='verified'?'Verificado':($config['custom_domain_status']==='pending'?'Pendiente de verificación':'Sin configurar'); ?></strong><div class="muted" style="margin-top:5px">Tu dirección estable es <code><?php echo e($publicCanonicalUrl); ?></code>. El subdominio <code><?php echo e(!empty($config['slug_publico'])?$config['slug_publico'].'.tickex.com.ar':'pendiente'); ?></code> queda reservado para la etapa de DNS. La marca blanca total requiere habilitación comercial; hasta entonces se mantiene “Powered by Tickex”.</div></div>
    </div>
  </details>

  <details class="card site-admin-section" id="canales-sitio">
    <summary>
      <div>
        <div class="site-admin-section-title">Canales y QR</div>
        <div class="site-admin-section-subtitle"><?php echo (int)$canalesConectados; ?> de 5 canales conectados · QR permanente del sitio.</div>
      </div>
    </summary>
    <div class="site-admin-section-body">
      <p class="muted" style="margin-top:0;">WhatsApp y redes se muestran en tu página pública. El QR siempre apunta a tu dirección.</p>

      <form method="post" action="mi_sitio.php">
        <input type="hidden" name="_csrf" value="<?php echo e($csrf); ?>">
        <input type="hidden" name="action" value="save_extras">

        <div class="form-group">
          <label for="whatsapp">WhatsApp (opcional)</label>
          <input type="text" id="whatsapp" name="whatsapp" class="form-control" value="<?php echo e($config['whatsapp']); ?>" placeholder="Ej: 54911XXXXXXXX o https://wa.me/54911XXXXXXXX">
          <small class="form-text text-muted">Si lo dejás vacío, no se muestra el botón flotante de WhatsApp.</small>
        </div>

        <div class="form-group">
          <label for="instagram_url">Instagram (opcional)</label>
          <input type="text" id="instagram_url" name="instagram_url" class="form-control" value="<?php echo e($config['instagram_url']); ?>" placeholder="https://instagram.com/tuusuario">
        </div>

        <div class="form-group">
          <label for="tiktok_url">TikTok (opcional)</label>
          <input type="text" id="tiktok_url" name="tiktok_url" class="form-control" value="<?php echo e($config['tiktok_url']); ?>" placeholder="https://tiktok.com/@tuusuario">
        </div>

        <div class="form-group">
          <label for="facebook_url">Facebook (opcional)</label>
          <input type="text" id="facebook_url" name="facebook_url" class="form-control" value="<?php echo e($config['facebook_url']); ?>" placeholder="https://facebook.com/tupagina">
        </div>

        <div class="form-group">
          <label for="youtube_url">YouTube (opcional)</label>
          <input type="text" id="youtube_url" name="youtube_url" class="form-control" value="<?php echo e($config['youtube_url']); ?>" placeholder="https://youtube.com/@tuusuario">
        </div>

        <div class="form-actions">
          <button type="submit" class="btn primary">Guardar redes y WhatsApp</button>
        </div>
      </form>

      <div style="margin-top:16px;border-top:1px solid var(--line);padding-top:16px;">
        <h4 style="margin:0 0 8px;">QR del sitio</h4>
        <?php if (empty($config['slug_publico'])): ?>
          <div class="muted">Guardá un slug para generar el QR.</div>
        <?php else: ?>
          <?php $qrUrl = $publicCanonicalUrl; ?>
          <?php $qrImg = 'https://api.qrserver.com/v1/create-qr-code/?size=320x320&margin=10&format=png&data=' . rawurlencode($qrUrl); ?>
          <div style="display:flex;gap:16px;flex-wrap:wrap;align-items:flex-start;">
            <div style="width:260px;max-width:100%;background:#fff;border-radius:12px;padding:10px;">
              <img src="<?php echo e($qrImg); ?>" alt="QR" style="width:100%;height:auto;display:block;">
            </div>
            <div style="min-width:220px;flex:1;">
              <div style="font-weight:700;">URL del QR</div>
              <div style="margin-top:6px;word-break:break-word;"><code><?php echo e($qrUrl); ?></code></div>
              <div style="margin-top:10px;">
                <a class="btn secondary" href="<?php echo e($qrImg); ?>" target="_blank" rel="noopener noreferrer">Abrir QR</a>
                <a class="btn secondary" href="<?php echo e($qrUrl); ?>" target="_blank" rel="noopener noreferrer">Abrir sitio</a>
              </div>
              <div class="muted" style="font-size:12px;margin-top:10px;">Este QR es fijo para tu slug (ideal para afiches, volantes y stickers).</div>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </details>

  <div class="card" id="eventos-sitio">
    <div class="card-body">
      <div class="site-admin-events-head">
        <div>
          <h2>Eventos del sitio</h2>
          <p>Elegí qué eventos aparecen públicamente. Los finalizados se retiran automáticamente.</p>
        </div>
        <span class="site-status <?php echo $eventosPublicados > 0 ? 'on' : 'off'; ?>"><?php echo (int)$eventosPublicados; ?> publicados</span>
      </div>

      <?php if (empty($eventos)): ?>
        <div class="muted">Todavía no tenés eventos.</div>
      <?php else: ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px;">
          <?php foreach ($eventos as $ev): ?>
            <?php $vigente = evento_vigente($ev, $hasFechaDesde, $hasFechaHasta); ?>
            <div class="card site-event-card">
              <div style="display:flex;gap:10px;">
                <div style="width:76px;height:76px;border:1px solid var(--line);border-radius:8px;overflow:hidden;background:#000;flex-shrink:0;display:flex;align-items:center;justify-content:center;">
                  <?php $fly = isset($ev['flyer_filename']) ? $ev['flyer_filename'] : ''; ?>
                  <?php if ($fly && file_exists(__DIR__ . '/' . $fly)): ?>
                    <img src="<?php echo e($fly); ?>" alt="Flyer" style="width:100%;height:100%;object-fit:cover;">
                  <?php else: ?><span class="muted" style="font-size:12px;">Sin flyer</span><?php endif; ?>
                </div>
                <div style="flex:1;min-width:0;">
                  <div style="font-weight:700;"><?php echo e($ev['nombre']); ?></div>
                  <div class="muted" style="font-size:12px;">Slug: <?php echo e($ev['slug']); ?></div>
                  <div class="muted" style="font-size:12px;margin-top:4px;">
                    <?php
                      $fd = $hasFechaDesde && isset($ev['fecha_desde']) ? $ev['fecha_desde'] : '';
                      $fh = $hasFechaHasta && isset($ev['fecha_hasta']) ? $ev['fecha_hasta'] : '';
                      if ($fd === '' && $fh === '') {
                        echo 'Sin fecha cargada';
                      } else {
                        echo e($fd);
                        if ($fh !== '') echo ' → '.e($fh);
                      }
                    ?>
                  </div>
                </div>
              </div>

              <div class="site-event-meta">
                <span class="site-status <?php echo !empty($ev['publicado_site']) ? 'on' : 'off'; ?>"><?php echo !empty($ev['publicado_site']) ? 'Publicado' : 'Oculto'; ?></span>
                <span class="site-status <?php echo $vigente ? 'on' : 'past'; ?>"><?php echo $vigente ? 'Vigente' : 'Finalizado'; ?></span>
              </div>

              <form method="post" style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                <input type="hidden" name="_csrf" value="<?php echo e($csrf); ?>">
                <input type="hidden" name="action" value="toggle_event">
                <input type="hidden" name="event_id" value="<?php echo (int)$ev['id']; ?>">
                <input type="hidden" name="to" value="<?php echo (!empty($ev['publicado_site']) ? 0 : 1); ?>">
                <?php if (!$vigente && empty($ev['publicado_site'])): ?>
                  <button class="btn secondary" type="button" disabled title="Los eventos finalizados no pueden volver a publicarse.">Evento finalizado</button>
                <?php else: ?>
                  <button class="btn <?php echo (!empty($ev['publicado_site']) ? 'secondary' : 'primary'); ?>" type="submit">
                    <?php echo (!empty($ev['publicado_site']) ? 'Ocultar del sitio' : 'Publicar en el sitio'); ?>
                  </button>
                <?php endif; ?>
                <?php if (!empty($config['slug_publico'])): ?>
                  <a class="btn secondary" href="<?php echo e($publicPreviewUrl); ?>" target="_blank">Ver sitio</a>
                <?php endif; ?>
              </form>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php require __DIR__ . '/inc/layout_bottom.php';
