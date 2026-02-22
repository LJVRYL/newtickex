<?php
// Landing pública para un cliente de Tickex STR
// URL: site.php?slug=mi-slug

session_start();

if (!function_exists('e')) {
    function e($str) { return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8'); }
}

$slug = isset($_GET['slug']) ? strtolower(trim($_GET['slug'])) : '';
$eventSlug = isset($_GET['event']) ? trim($_GET['event']) : '';

if ($slug === '') {
    http_response_code(400);
    echo 'Falta el parámetro slug.';
    exit;
}

// Conexión a SQLite vía db() si existe, si no fallback manual
$dbFile = __DIR__ . '/save_the_rave.sqlite';
if (!file_exists($dbFile)) {
    http_response_code(500);
    echo 'Error: base de datos no encontrada.';
    exit;
}

try {
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    http_response_code(500);
    echo 'Error al conectar a la base de datos.';
    exit;
}

// Asegurar columna publicado_site si falta
$colsEv = $pdo->query("PRAGMA table_info(eventos)")->fetchAll(PDO::FETCH_ASSOC);
$hasPublicar = false; $hasCreadoPor = false; $hasFechaDesde = false; $hasFechaHasta = false;
foreach ($colsEv as $c) {
    if ($c['name'] === 'publicado_site') $hasPublicar = true;
    if ($c['name'] === 'creado_por_admin_id') $hasCreadoPor = true;
    if ($c['name'] === 'fecha_desde') $hasFechaDesde = true;
    if ($c['name'] === 'fecha_hasta') $hasFechaHasta = true;
}
if (!$hasPublicar) {
    try { $pdo->exec("ALTER TABLE eventos ADD COLUMN publicado_site INTEGER DEFAULT 0"); } catch (Exception $e) { /* ignore */ }
}

// Asegurar columnas extra en clientes_sites (compat DB existente)
try {
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
} catch (Exception $e) {
  // ignore
}

// Config del sitio
try {
  $stmt = $pdo->prepare('SELECT admin_id, nombre_publico, texto_hero, texto_intro, whatsapp, instagram_url, tiktok_url, facebook_url, youtube_url, visible FROM clientes_sites WHERE slug_publico = :slug LIMIT 1');
    $stmt->execute(array(':slug' => $slug));
    $site = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    http_response_code(500);
    echo 'Error al cargar la configuración del sitio.';
    exit;
}

if (!$site) { http_response_code(404); echo 'Sitio no encontrado.'; exit; }

if ((int)$site['visible'] !== 1) {
    http_response_code(503);
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
      <meta charset="utf-8">
      <title><?php echo e($site['nombre_publico']); ?> - Mantenimiento</title>
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <style>body { font-family: Arial, sans-serif; margin:0; padding:0; background:#111; color:#eee; display:flex; align-items:center; justify-content:center; min-height:100vh; } .box { max-width:480px; text-align:center; padding:24px; border-radius:8px; background:#1b1b1b; } h1 { margin:0 0 16px; } p { margin:8px 0; }</style>
    </head>
    <body>
      <div class="box">
        <h1><?php echo e($site['nombre_publico']); ?></h1>
        <p>Este sitio se encuentra en mantenimiento.</p>
        <p>Volvé a visitarnos más tarde.</p>
        <p style="margin-top:16px; font-size:12px; opacity:0.75;">Powered by Tickex</p>
      </div>
    </body>
    </html>
    <?php
    exit;
}

$nombre_publico = $site['nombre_publico'];
$url_publico = $slug . '.tickex.com.ar';
$texto_hero     = $site['texto_hero'] !== '' ? $site['texto_hero'] : 'Entradas oficiales para nuestros eventos';
$texto_intro    = $site['texto_intro'] !== '' ? $site['texto_intro'] : 'Encontrá acá todas las fechas disponibles.';

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

$socials = array();
$ig = isset($site['instagram_url']) ? _tickex_normalize_url($site['instagram_url']) : '';
$tt = isset($site['tiktok_url']) ? _tickex_normalize_url($site['tiktok_url']) : '';
$fb = isset($site['facebook_url']) ? _tickex_normalize_url($site['facebook_url']) : '';
$yt = isset($site['youtube_url']) ? _tickex_normalize_url($site['youtube_url']) : '';
if ($ig !== '' && $ig !== 'https://') $socials[] = array('label' => 'Instagram', 'url' => $ig);
if ($tt !== '' && $tt !== 'https://') $socials[] = array('label' => 'TikTok', 'url' => $tt);
if ($fb !== '' && $fb !== 'https://') $socials[] = array('label' => 'Facebook', 'url' => $fb);
if ($yt !== '' && $yt !== 'https://') $socials[] = array('label' => 'YouTube', 'url' => $yt);

$whatsappHref = isset($site['whatsapp']) ? _tickex_whatsapp_to_href($site['whatsapp']) : '';

// Cargar eventos publicados del admin
if ($hasCreadoPor) {
    $stmtE = $pdo->prepare('SELECT * FROM eventos WHERE publicado_site = 1 AND creado_por_admin_id = :aid ORDER BY fecha_desde ASC, id DESC');
    $stmtE->execute(array(':aid' => $site['admin_id']));
} else {
    $stmtE = $pdo->query('SELECT * FROM eventos WHERE publicado_site = 1 ORDER BY fecha_desde ASC, id DESC');
}
$eventos = $stmtE ? $stmtE->fetchAll(PDO::FETCH_ASSOC) : array();

// Filtrar por fecha (auto inactivos si ya pasaron)
$now = time();
$eventosVigentes = array();
foreach ($eventos as $ev) {
    $fdTs = ($hasFechaDesde && !empty($ev['fecha_desde'])) ? strtotime($ev['fecha_desde']) : false;
    $fhTs = ($hasFechaHasta && !empty($ev['fecha_hasta'])) ? strtotime($ev['fecha_hasta']) : false;
    $vigente = true;
    if ($fhTs !== false) {
        $vigente = ($fhTs >= $now);
    } elseif ($fdTs !== false) {
        $vigente = ($fdTs >= $now) || ($fdTs <= $now);
    }
    if ($vigente) {
        $eventosVigentes[] = $ev;
    }
}

// Si se pidió evento puntual por slug
$eventoDetalle = null;
if ($eventSlug !== '') {
    foreach ($eventosVigentes as $ev) {
        if (isset($ev['slug']) && $ev['slug'] === $eventSlug) {
            $eventoDetalle = $ev; break;
        }
    }
}

$isLogged = !empty($_SESSION['usuario_id']) || !empty($_SESSION['user_id']) || !empty($_SESSION['admin_id']);

function _tickex_site_checkout_url($siteSlug, $ev)
{
  $sid = strtolower(trim((string)$siteSlug));
  $eventId = isset($ev['id']) ? (int)$ev['id'] : 0;
  $eventSlug = isset($ev['slug']) ? strtolower(trim((string)$ev['slug'])) : '';
  if ($eventId <= 0) return '#';
  $concept = ($eventSlug !== '' ? $eventSlug : ('evento-' . $eventId));
  $refPrefix = ($sid !== '' ? $sid : 'tickex');
  $ref = $refPrefix . '-' . ($eventSlug !== '' ? $eventSlug : (string)$eventId) . '-' . time();

  $q = array(
    'event' => $eventId,
    'concept' => $concept,
    'ref' => $ref,
  );

  // Passthrough de afiliado si viene en la URL del sitio
  if (isset($_GET['aff']) && trim((string)$_GET['aff']) !== '') {
    $q['aff'] = (string)$_GET['aff'];
  }

  return 'checkout_totalcoin.php?' . http_build_query($q);
}

// Helpers de flyer
function flyer_url($ev) {
    if (!empty($ev['flyer_filename'])) {
        $path = __DIR__ . '/' . $ev['flyer_filename'];
        if (file_exists($path)) return $ev['flyer_filename'];
    }
    return null;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title><?php echo e($nombre_publico); ?> - Tickex</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    * { box-sizing: border-box; }
    body { margin:0; font-family: Arial, sans-serif; background:#05050a; color:#f5f5f5; }
    a { color:inherit; text-decoration:none; }
    header { padding:16px 24px; display:flex; align-items:center; justify-content:space-between; gap:14px; background:rgba(0,0,0,0.85); position:sticky; top:0; z-index:10; }
    .brand { display:flex; align-items:center; gap:10px; }
    .slug-chip { padding:6px 10px; border-radius:999px; border:1px solid rgba(255,255,255,0.2); font-size:13px; opacity:0.9; white-space:nowrap; }
    .header-actions { display:flex; align-items:center; gap:10px; }
    .header-actions a { padding:8px 14px; border-radius:20px; font-size:14px; border:1px solid rgba(255,255,255,0.25); }
    .hero { padding:40px 24px 24px; max-width:960px; margin:0 auto; }
    .hero-title { font-size:28px; margin:0 0 8px; }
    .hero-sub { font-size:16px; opacity:0.85; margin:0 0 16px; }
    .main { max-width:960px; margin:0 auto 32px; padding:0 24px 24px; }
    .event-list-title { margin:24px 0 12px; font-size:18px; }
    .events-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(260px,1fr)); grid-gap:16px; justify-content:center; }
    .event-card { background:#111; border-radius:12px; overflow:hidden; border:1px solid rgba(255,255,255,0.06); display:flex; flex-direction:column; max-width:380px; margin:0 auto; }
    .event-card img { width:100%; display:block; max-height:260px; object-fit:cover; }
    .event-body { padding:12px 14px 14px; flex:1; display:flex; flex-direction:column; }
    .event-title { font-size:16px; margin:0 0 4px; }
    .event-meta { font-size:13px; opacity:0.8; margin-bottom:10px; }
    .event-actions { margin-top:auto; display:flex; justify-content:space-between; align-items:center; }
    .btn { display:inline-block; padding:8px 12px; border-radius:20px; font-size:13px; border:1px solid rgba(255,255,255,0.25); text-align:center; }
    .btn-primary { background:#ff2e63; border-color:#ff2e63; }
    .btn-ghost { background:transparent; }
    .detail { max-width:960px; margin:0 auto 32px; padding:0 24px; }
    .detail-card { background:#111; border:1px solid rgba(255,255,255,0.06); border-radius:12px; padding:16px; display:grid; grid-template-columns:1fr 1.2fr; gap:16px; }
    .detail-card img { width:100%; border-radius:10px; object-fit:cover; }
    @media (max-width: 720px) { .detail-card { grid-template-columns:1fr; } }
    footer { border-top:1px solid rgba(255,255,255,0.08); padding:24px; font-size:13px; background:#05050a; }
    .footer-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); grid-gap:16px; margin-bottom:16px; }
    .footer-col-title { font-weight:bold; margin-bottom:8px; font-size:14px; }
    .footer-link { display:block; margin-bottom:4px; opacity:0.85; }
    .footer-bottom { opacity:0.7; display:flex; flex-wrap:wrap; justify-content:space-between; gap:8px; }
    .wa-float { position:fixed; right:16px; bottom:16px; bottom: calc(16px + env(safe-area-inset-bottom)); z-index:50; background:#ff2e63; border:1px solid #ff2e63; color:#fff; padding:12px 14px; border-radius:999px; font-size:14px; box-shadow:0 6px 18px rgba(0,0,0,0.35); }
    .wa-float:focus, .wa-float:hover { filter: brightness(1.05); }
    @media (max-width: 600px) { .main { padding:0 16px 24px; } .events-grid { grid-template-columns:1fr; } .event-card { max-width:360px; margin:0 auto; } .event-card img { max-height:none; object-fit:contain; } }
  </style>
</head>
<body>
  <header>
    <div class="brand">
      <a href="site.php?slug=<?php echo e($slug); ?>" style="display:flex;align-items:center;">
        <img src="tickex-logo_sobre_oscuro.svg" alt="Tickex" style="height:34px;display:block;">
      </a>
      <span class="slug-chip"><?php echo e($url_publico); ?></span>
    </div>
    <div class="header-actions">
      <a href="login.php" class="secondary">Ingresar</a>
      <a href="registro.php" class="primary">Registrarse</a>
    </div>
  </header>

  <section class="hero">
    <h1 class="hero-title"><?php echo e($texto_hero); ?></h1>
    <p class="hero-sub"><?php echo e($texto_intro); ?></p>
  </section>

  <?php if ($eventoDetalle): ?>
    <section class="detail">
      <div class="detail-card">
        <div>
          <?php $fly = flyer_url($eventoDetalle); ?>
          <?php if ($fly): ?><img src="<?php echo e($fly); ?>" alt="Flyer"><?php else: ?><div style="background:#0d0d0d;border:1px solid rgba(255,255,255,0.08);border-radius:10px;min-height:200px;display:flex;align-items:center;justify-content:center;">Sin flyer</div><?php endif; ?>
        </div>
        <div>
          <h2 style="margin:0 0 6px;"><?php echo e($eventoDetalle['nombre']); ?></h2>
          <div style="opacity:0.8; margin-bottom:8px;">Slug: <?php echo e($eventoDetalle['slug']); ?></div>
          <div style="opacity:0.85; margin-bottom:12px;">
            <?php
              $fd = $hasFechaDesde && isset($eventoDetalle['fecha_desde']) ? $eventoDetalle['fecha_desde'] : '';
              $fh = $hasFechaHasta && isset($eventoDetalle['fecha_hasta']) ? $eventoDetalle['fecha_hasta'] : '';
              if ($fd === '' && $fh === '') echo 'Fecha a confirmar';
              else { echo e($fd); if ($fh !== '') echo ' → '.e($fh); }
            ?>
          </div>
          <p style="margin:0 0 14px;">Adquirí tu entrada completando tus datos. Si ya tenés cuenta, seguí para iniciar sesión.</p>
          <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
            <a class="btn btn-primary" href="<?php echo e(_tickex_site_checkout_url($slug, $eventoDetalle)); ?>">Adquirir entrada</a>
            <a class="btn btn-ghost" href="site.php?slug=<?php echo e($slug); ?>">Volver a eventos</a>
          </div>
        </div>
      </div>
    </section>
  <?php endif; ?>

  <main class="main">
    <h2 class="event-list-title">Eventos activos</h2>

    <?php if (empty($eventosVigentes)): ?>
      <p>No hay eventos publicados todavía.</p>
    <?php else: ?>
      <div class="events-grid">
        <?php foreach ($eventosVigentes as $ev): ?>
          <article class="event-card">
            <?php $fly = flyer_url($ev); if ($fly): ?><img src="<?php echo e($fly); ?>" alt="<?php echo e($ev['nombre']); ?>"><?php endif; ?>
            <div class="event-body">
              <h3 class="event-title"><?php echo e($ev['nombre']); ?></h3>
              <div class="event-meta">
                <?php
                  $fd = $hasFechaDesde && isset($ev['fecha_desde']) ? $ev['fecha_desde'] : '';
                  $fh = $hasFechaHasta && isset($ev['fecha_hasta']) ? $ev['fecha_hasta'] : '';
                  if ($fd === '' && $fh === '') echo 'Fecha a confirmar';
                  else { echo e($fd); if ($fh !== '') echo ' → '.e($fh); }
                ?>
              </div>
              <div class="event-actions">
                <a href="<?php echo e(_tickex_site_checkout_url($slug, $ev)); ?>" class="btn btn-primary">Adquirir entrada</a>
                <a href="site.php?slug=<?php echo e($slug); ?>&event=<?php echo e($ev['slug']); ?>" class="btn btn-ghost">Ver detalle</a>
              </div>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </main>

  <footer>
    <div class="footer-grid">
      <div>
        <div class="footer-col-title">¿Sos productor o venue?</div>
        <a href="https://tickex.com.ar" class="footer-link">Vendé con Tickex</a>
        <a href="https://tickex.com.ar" class="footer-link">Crear evento</a>
      </div>
      <div>
        <div class="footer-col-title">Tickex</div>
        <span class="footer-link">Ayuda</span>
        <span class="footer-link">Términos y condiciones</span>
        <span class="footer-link">Política de privacidad</span>
      </div>
      <div>
        <div class="footer-col-title">Información</div>
        <span class="footer-link">Botón de arrepentimiento</span>
        <span class="footer-link">Contacto</span>
      </div>
      <?php if (!empty($socials)): ?>
        <div>
          <div class="footer-col-title">Redes</div>
          <?php foreach ($socials as $s): ?>
            <a class="footer-link" href="<?php echo e($s['url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo e($s['label']); ?></a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
    <div class="footer-bottom">
      <span>© Tickex <?php echo date('Y'); ?></span>
      <span>Hecho con &hearts; en Argentina</span>
    </div>
  </footer>

  <?php if ($whatsappHref !== ''): ?>
    <a class="wa-float" href="<?php echo e($whatsappHref); ?>" target="_blank" rel="noopener noreferrer">WhatsApp</a>
  <?php endif; ?>
</body>
</html>
