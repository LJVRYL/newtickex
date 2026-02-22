<?php
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/bootstrap.php';

// Tipo global (super_admin, admin_evento)
$tg = isset($_SESSION['tipo_global']) ? $_SESSION['tipo_global'] : '';
if (!in_array($tg, array('admin_evento','super_admin','superadmin'), true)) {
    header('Location: login.php');
    exit;
}

// ID admin
$admin_id = 0;
if (isset($_SESSION['user_id'])) {
    $admin_id = (int) $_SESSION['user_id'];
} elseif (isset($_SESSION['usuario_id'])) {
    $admin_id = (int) $_SESSION['usuario_id'];
} elseif (isset($_SESSION['admin_id'])) {
    $admin_id = (int) $_SESSION['admin_id'];
}
if ($admin_id <= 0) {
    die('No se pudo determinar el ID de administrador actual.');
}

$pdo = db();

// Asegurar tabla clientes_sites (por si falta) y unicidad del slug
$pdo->exec("CREATE TABLE IF NOT EXISTS clientes_sites (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    admin_id INTEGER NOT NULL,
    slug_publico TEXT NOT NULL,
    nombre_publico TEXT NOT NULL,
    texto_hero TEXT,
    texto_intro TEXT,
  whatsapp TEXT,
  instagram_url TEXT,
  tiktok_url TEXT,
  facebook_url TEXT,
  youtube_url TEXT,
    visible INTEGER DEFAULT 0,
    created_at TEXT,
    updated_at TEXT
);");
$pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_clientes_sites_slug ON clientes_sites(slug_publico);");

// Asegurar columnas extra en clientes_sites (compat DB existente)
$colsSites = $pdo->query("PRAGMA table_info(clientes_sites)")->fetchAll(PDO::FETCH_ASSOC);
$hasWhatsapp = false; $hasIg = false; $hasTt = false; $hasFb = false; $hasYt = false;
foreach ($colsSites as $c) {
  if ($c['name'] === 'whatsapp') $hasWhatsapp = true;
  if ($c['name'] === 'instagram_url') $hasIg = true;
  if ($c['name'] === 'tiktok_url') $hasTt = true;
  if ($c['name'] === 'facebook_url') $hasFb = true;
  if ($c['name'] === 'youtube_url') $hasYt = true;
}
if (!$hasWhatsapp) { try { $pdo->exec("ALTER TABLE clientes_sites ADD COLUMN whatsapp TEXT"); } catch (Exception $e) { /* ignore */ } }
if (!$hasIg) { try { $pdo->exec("ALTER TABLE clientes_sites ADD COLUMN instagram_url TEXT"); } catch (Exception $e) { /* ignore */ } }
if (!$hasTt) { try { $pdo->exec("ALTER TABLE clientes_sites ADD COLUMN tiktok_url TEXT"); } catch (Exception $e) { /* ignore */ } }
if (!$hasFb) { try { $pdo->exec("ALTER TABLE clientes_sites ADD COLUMN facebook_url TEXT"); } catch (Exception $e) { /* ignore */ } }
if (!$hasYt) { try { $pdo->exec("ALTER TABLE clientes_sites ADD COLUMN youtube_url TEXT"); } catch (Exception $e) { /* ignore */ } }

// Asegurar columna publicado_site en eventos (flag de publicación)
$colsEv = $pdo->query("PRAGMA table_info(eventos)")->fetchAll(PDO::FETCH_ASSOC);
$hasPublicar = false; $hasCreadoPor = false; $hasFechaDesde = false; $hasFechaHasta = false;
foreach ($colsEv as $c) {
    if ($c['name'] === 'publicado_site') $hasPublicar = true;
    if ($c['name'] === 'creado_por_admin_id') $hasCreadoPor = true;
    if ($c['name'] === 'fecha_desde') $hasFechaDesde = true;
    if ($c['name'] === 'fecha_hasta') $hasFechaHasta = true;
}
if (!$hasPublicar) {
    try { $pdo->exec("ALTER TABLE eventos ADD COLUMN publicado_site INTEGER DEFAULT 0"); } catch (Exception $e) { /* ignorar si ya existe */ }
}

// Helper e()
if (!function_exists('e')) {
    function e($str) { return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8'); }
}

$page_title = 'Mi sitio';
$errors = array();
$saved  = false;

// Cargar config actual
$config = array(
    'slug_publico'   => '',
    'nombre_publico' => '',
    'texto_hero'     => '',
    'texto_intro'    => '',
  'whatsapp'       => '',
  'instagram_url'  => '',
  'tiktok_url'     => '',
  'facebook_url'   => '',
  'youtube_url'    => '',
    'visible'        => 0,
);

try {
  $stmt = $pdo->prepare('SELECT slug_publico, nombre_publico, texto_hero, texto_intro, whatsapp, instagram_url, tiktok_url, facebook_url, youtube_url, visible FROM clientes_sites WHERE admin_id = :admin_id LIMIT 1');
    $stmt->execute(array(':admin_id' => $admin_id));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) { $config = $row; }
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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (!isset($_POST['action']) || ($_POST['action'] !== 'toggle_event' && $_POST['action'] !== 'save_extras'))) {
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
        $slug_publico = strtolower($slug_publico);
        $slug_publico = preg_replace('/[^a-z0-9\-]/', '-', $slug_publico);
        $slug_publico = trim($slug_publico, '-');
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
    $now = time();
    $fd = ($hasFechaDesde && !empty($ev['fecha_desde'])) ? strtotime($ev['fecha_desde']) : false;
    $fh = ($hasFechaHasta && !empty($ev['fecha_hasta'])) ? strtotime($ev['fecha_hasta']) : false;
    if ($fh !== false) return $fh >= $now;
    if ($fd !== false) return $fd >= $now;
    return true; // sin fechas, lo consideramos vigente
}

require __DIR__ . '/inc/layout_top.php';
?>
<div class="page">
  <div class="page-header">
    <h1>Mi sitio</h1>
    <p class="lead">Configurá el sitio público donde tus clientes ven y compran entradas.</p>
    <div style="margin-top:8px;">
      <?php if (!empty($config['slug_publico'])): ?>
        <a class="btn" href="site.php?slug=<?php echo e($config['slug_publico']); ?>" target="_blank">Ver mi sitio</a>
      <?php else: ?>
        <span class="muted" style="font-size:12px;">Guardá un slug para habilitar "Ver mi sitio".</span>
      <?php endif; ?>
    </div>
  </div>

  <?php if (!empty($errors)): ?>
    <div class="alert error"><ul><?php foreach ($errors as $err): ?><li><?php echo e($err); ?></li><?php endforeach; ?></ul></div>
  <?php elseif ($saved): ?>
    <div class="alert success">Cambios guardados.</div>
  <?php endif; ?>

  <div class="card">
    <div class="card-body">
      <form method="post" action="mi_sitio.php">
        <div class="form-group">
          <label for="nombre_publico">Nombre público del sitio</label>
          <input type="text" id="nombre_publico" name="nombre_publico" class="form-control" value="<?php echo e($config['nombre_publico']); ?>" required>
          <small class="form-text text-muted">Ej: “Save The Rave”, “Teatro Central”.</small>
        </div>

        <div class="form-group">
          <label for="slug_publico">Slug público</label>
          <input type="text" id="slug_publico" name="slug_publico" class="form-control" value="<?php echo e($config['slug_publico']); ?>" required>
          <small class="form-text text-muted">Minúsculas/números/guiones. URL: <code><?php echo 'https://' . e($config['slug_publico']) . '.tickex.com.ar/site.php?slug=' . e($config['slug_publico']); ?></code></small>
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
            <a class="btn secondary" href="site.php?slug=<?php echo e($config['slug_publico']); ?>" target="_blank">Ver sitio público</a>
          <?php endif; ?>
        </div>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="card-body">
      <h3 style="margin-top:0;">Redes, WhatsApp y QR</h3>
      <p class="muted" style="margin-top:4px;">Si cargás WhatsApp o redes, se muestran en tu sitio público. El QR siempre apunta a tu slug.</p>

      <form method="post" action="mi_sitio.php">
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
          <?php $qrUrl = 'https://' . $config['slug_publico'] . '.tickex.com.ar/site.php?slug=' . $config['slug_publico']; ?>
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
  </div>

  <div class="card">
    <div class="card-body">
      <h3 style="margin-top:0;">Eventos publicados en tu sitio</h3>
      <p class="muted" style="margin-top:4px;">Publicá o quitá eventos. Si la fecha ya pasó, se muestran como inactivos aunque estén publicados.</p>

      <?php if (empty($eventos)): ?>
        <div class="muted">Todavía no tenés eventos.</div>
      <?php else: ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px;">
          <?php foreach ($eventos as $ev): ?>
            <?php $vigente = evento_vigente($ev, $hasFechaDesde, $hasFechaHasta); ?>
            <div class="card" style="margin:0;">
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

              <div style="margin-top:8px;font-size:12px;line-height:1.5;">
                <div>Publicado en sitio: <strong><?php echo (!empty($ev['publicado_site']) ? 'Sí' : 'No'); ?></strong></div>
                <div>Estado por fecha: <strong><?php echo $vigente ? 'Activo' : 'Inactivo (evento pasado)'; ?></strong></div>
              </div>

              <form method="post" style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                <input type="hidden" name="action" value="toggle_event">
                <input type="hidden" name="event_id" value="<?php echo (int)$ev['id']; ?>">
                <input type="hidden" name="to" value="<?php echo (!empty($ev['publicado_site']) ? 0 : 1); ?>">
                <button class="btn <?php echo (!empty($ev['publicado_site']) ? 'secondary' : 'primary'); ?>" type="submit">
                  <?php echo (!empty($ev['publicado_site']) ? 'Ocultar del sitio' : 'Publicar en el sitio'); ?>
                </button>
                <?php if (!empty($config['slug_publico'])): ?>
                  <a class="btn secondary" href="site.php?slug=<?php echo e($config['slug_publico']); ?>" target="_blank">Ver sitio</a>
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
