<?php
require_once __DIR__.'/inc/bootstrap.php';
require_once __DIR__.'/inc/secure_links.php';
date_default_timezone_set('America/Argentina/Buenos_Aires');

$pdo = db();

$cu = current_user();
$tipoGlobal = isset($cu['tipo_global']) ? $cu['tipo_global'] : (isset($_SESSION['tipo_global']) ? $_SESSION['tipo_global'] : '');
$rol = isset($cu['rol']) ? $cu['rol'] : (isset($_SESSION['rol']) ? $_SESSION['rol'] : '');
$canManage = !empty($cu) && in_array($tipoGlobal ?: $rol, array('super_admin','superadmin','admin_evento','admin'), true);

// Soportar URL segura (?t=TOKEN) y URL legacy (?c=codigo)
$codigo = '';
$entradaFromToken = null;
if (!empty($_GET['t'])) {
    $tok = trim((string)$_GET['t']);
    $eid = tickex_secure_entry_id_from_token($pdo, $tok);
    if ($eid > 0) {
        $stTok = $pdo->prepare('SELECT * FROM entradas WHERE id = :id LIMIT 1');
        $stTok->execute(array(':id' => $eid));
        $entradaFromToken = $stTok->fetch(PDO::FETCH_ASSOC);
        if ($entradaFromToken) {
            $codigo = (string)$entradaFromToken['codigo'];
        }
    }
    if (!$entradaFromToken) {
        http_response_code(404);
        echo 'Entrada no encontrada.';
        exit;
    }
} else {
    $codigo = isset($_GET['c']) ? trim($_GET['c']) : '';
    if ($codigo === '') {
        http_response_code(400);
        echo 'Falta el código (parámetro c).';
        exit;
    }
}

if ($entradaFromToken) {
    $entrada = $entradaFromToken;
} else {
    $stmt = $pdo->prepare('SELECT * FROM entradas WHERE codigo = :codigo LIMIT 1');
    $stmt->execute(array(':codigo' => $codigo));
    $entrada = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$entrada) {
    http_response_code(404);
    echo 'Entrada no encontrada.';
    exit;
}

// Helper tipo legacy-friendly
function pretty_tipo($tipoRaw) {
    $tipo = $tipoRaw;
    if ($tipo === null || $tipo === '') $tipo = 'desconocido';

    switch ($tipo) {
        case 'ANTICIPADA':    return 'Anticipada';
        case 'FREE':          return 'FREE (lista)';
        case 'PUERTA_10000':  return 'Lista puerta $10.000';
        case 'PUERTA_15000':  return 'Lista puerta $15.000';
        case 'OTRO_NOMBRE':   return 'Otro nombre';
        default:              return ucfirst(strtolower($tipo));
    }
}

$nombre   = isset($entrada['nombre']) ? $entrada['nombre'] : '';
$tipoDesc = pretty_tipo(isset($entrada['tipo']) ? $entrada['tipo'] : '');
$checked  = ((int)$entrada['checked_in'] === 1);
$eventoId = isset($entrada['evento_id']) ? (int)$entrada['evento_id'] : 0;

// Traer datos del evento (si hay)
$eventoNombre = '';
$eventoSlug   = '';
$eventoDesde  = '';
$eventoHasta  = '';
$eventoFechaLbl = '';

if ($eventoId > 0) {
    $stmtEv = $pdo->prepare("SELECT nombre, slug, fecha_desde, fecha_hasta FROM eventos WHERE id=? LIMIT 1");
    $stmtEv->execute(array($eventoId));
    $ev = $stmtEv->fetch(PDO::FETCH_ASSOC);
    if ($ev) {
        $eventoNombre = isset($ev['nombre']) ? $ev['nombre'] : '';
        $eventoSlug   = isset($ev['slug']) ? $ev['slug'] : '';
        $eventoDesde  = isset($ev['fecha_desde']) ? $ev['fecha_desde'] : '';
        $eventoHasta  = isset($ev['fecha_hasta']) ? $ev['fecha_hasta'] : '';
      if ($eventoDesde !== '' && $eventoHasta !== '') {
        $eventoFechaLbl = $eventoDesde." → ".$eventoHasta;
      } else {
        $eventoFechaLbl = $eventoDesde.$eventoHasta;
      }
    }
}

// QR apunta a checkin (que SOLO checkinea con sesión puerta).
// Tanto el QR como el enlace compartible mantienen opaco el código interno.
$configuredBaseUrl = getenv('TICKEX_SITE_URL');
if (is_string($configuredBaseUrl) && trim($configuredBaseUrl) !== '') {
    $baseUrl = tickex_public_base_url($configuredBaseUrl);
} elseif (!empty($_SERVER['HTTP_HOST'])) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $baseUrl = tickex_public_base_url($scheme . '://' . $_SERVER['HTTP_HOST']);
} else {
    $baseUrl = tickex_public_base_url('');
}
$checkinUrl = tickex_secure_checkin_url($pdo, $baseUrl, (int)$entrada['id'], $codigo);
// QR externo por ahora (simple)
$qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=320x320&data=' . urlencode($checkinUrl);

$ticketLink = tickex_secure_ticket_url($pdo, $baseUrl, (int)$entrada['id'], $codigo);
$mensajeBase = "Hola, aquí tenés tu entrada:\nEvento: " . ($eventoNombre ?: 'TICKEX') . "\nNombre: " . $nombre . "\nTipo: " . $tipoDesc . "\nVer ticket: " . $ticketLink;
$waMensaje = $mensajeBase;
$mailSubject = 'Tu entrada - ' . ($eventoNombre ?: 'TICKEX');
$mailBody = $mensajeBase . "\n\nMostrá el QR en la puerta.";

?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <title>Entrada – TICKEX</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Manrope:wght@700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/str.css?v=20260219_2">
  <link rel="stylesheet" href="/assets/str-theme.css?v=20260828_1">
  <link rel="stylesheet" href="/assets/tickex-v2.css?v=20260908_1">
  <style>
    body{display:flex;align-items:center;justify-content:center;min-height:100vh;padding:24px;background:radial-gradient(circle at 50% 0,rgba(118,87,255,.2),transparent 38%),#070913;color:#f7f8fc;font-family:Inter,sans-serif}
    .ticket-wrap{max-width:560px;width:100%;padding:0}.ticket-card{position:relative;overflow:hidden;padding:28px;border:1px solid rgba(255,255,255,.11);border-radius:24px;background:linear-gradient(155deg,rgba(21,27,46,.98),rgba(9,13,28,.98));box-shadow:0 28px 80px rgba(0,0,0,.45)}
    .ticket-card:before{content:"";position:absolute;width:240px;height:240px;right:-130px;top:-130px;border:1px solid rgba(54,216,245,.18);border-radius:50%}.ticket-brand{display:block;width:152px;height:44px;margin:0 auto 22px;object-fit:contain}.ticket-kicker{color:#36d8f5;font-size:11px;font-weight:900;text-transform:uppercase;letter-spacing:.15em}.ticket-card h1{margin:7px 0 4px;font:800 clamp(25px,7vw,38px)/1.08 Manrope,Inter,sans-serif;letter-spacing:-.035em}.subtitle{color:#aeb5c8;font-size:14px;margin-top:4px}
    .chip{
      display:inline-block;padding:4px 10px;border-radius:999px;
      background:rgba(118,87,255,.14);border:1px solid rgba(148,125,255,.35);
      font-size:12px;letter-spacing:.06em;text-transform:uppercase;font-weight:800;
      margin:6px 0 10px;
    }
    .status{
      display:inline-block;padding:4px 10px;border-radius:999px;
      font-size:12px;letter-spacing:.06em;text-transform:uppercase;font-weight:800;
      margin:8px 0 12px;
      border:1px solid var(--line);
    }
    .status.ok{border-color:#55df98;color:#65e6a5;background:rgba(22,111,68,.2)}
    .status.pend{border-color:#ffd167;color:#ffd167;background:rgba(135,89,13,.17)}
    .qr{margin:8px auto 0;max-width:336px;padding:8px;border-radius:22px;background:linear-gradient(135deg,#7458ff,#36d8f5)}.qr img{display:block;width:320px;max-width:100%;height:auto;border-radius:16px;background:#fff;padding:10px}
    .hint{color:var(--muted);font-size:13px;margin-top:12px}.ticket-security{display:flex;align-items:center;justify-content:center;gap:7px;margin-top:16px;color:#929aaf;font-size:12px}.ticket-security:before{content:"";width:7px;height:7px;border-radius:50%;background:#55df98;box-shadow:0 0 12px #55df98}@media(max-width:520px){body{padding:12px}.ticket-card{padding:22px 16px;border-radius:20px}.ticket-brand{width:132px;height:38px}.qr{max-width:294px}.qr img{width:278px}}
    .ticket-brand-lockup{display:flex;align-items:center;justify-content:center;gap:10px;margin:0 auto 22px}.ticket-brand-lockup .ticket-brand{width:48px;height:48px;margin:0}.ticket-brand-lockup strong{font:800 17px/1 Manrope,Inter,sans-serif;letter-spacing:.13em}
  </style>
</head>
<body>
  <div class="wrap ticket-wrap">
    <div class="ticket-card" style="text-align:center;">
      <div class="ticket-brand-lockup"><img class="ticket-brand" src="/tickex-isotipo.svg" alt=""><strong>TICKEX</strong></div>
      <div class="ticket-kicker">Entrada digital</div>

      <?php if($eventoNombre !== ''): ?>
        <h1><?php echo e($eventoNombre); ?></h1>
        <?php if($eventoDesde!=='' || $eventoHasta!==''): ?>
          <div class="subtitle" style="margin-top:4px;">
            <?php echo e($eventoFechaLbl); ?>
          </div>
        <?php endif; ?>
      <?php else: ?>
        <h1>Tu entrada Tickex</h1>
      <?php endif; ?>

      <div class="chip"><?php echo e($tipoDesc); ?></div>

      <div style="margin-top:6px;">
        <div class="subtitle">Nombre en lista</div>
        <div style="font-size:18px;font-weight:800;margin-top:2px;"><?php echo e($nombre); ?></div>
      </div>

      <div class="status <?php echo $checked ? 'ok' : 'pend'; ?>">
        <?php echo $checked ? 'Check-in OK' : 'Pendiente de ingreso'; ?>
      </div>

      <div class="qr">
        <img src="<?php echo e($qrUrl); ?>" alt="QR de entrada">
      </div>

      <div class="hint">
        Mostrá este QR en puerta para ingresar.<br>
        No lo compartas con terceros.
      </div>
      <div class="ticket-security">Enlace privado y validación única</div>

      <?php if ($canManage): ?>
        <div style="margin-top:16px;text-align:left;">
          <h3 style="margin:0 0 8px;">Acciones rápidas (solo admins)</h3>
          <div style="display:flex;flex-direction:column;gap:10px;">
            <div style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;">
              <input type="email" id="resendEmail" placeholder="correo@ejemplo.com" style="min-width:220px;" value="<?php echo e(isset($entrada['email']) ? $entrada['email'] : ''); ?>">
              <button class="btn" type="button" onclick="sendEmailTicket()">Reenviar ticket por email</button>
            </div>
            <div style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;">
              <input type="tel" id="waPhone" placeholder="54911XXXXXXXX" style="min-width:220px;">
              <button class="btn secondary" type="button" onclick="sendWhatsAppTicket()">Reenviar ticket por WhatsApp</button>
            </div>
            <div class="muted" style="font-size:12px;">Estos enlaces no se muestran al usuario final.</div>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($canManage): ?>
  <script>
    function sendEmailTicket(){
      var email = document.getElementById('resendEmail').value.trim();
      if(!email){ alert('Ingresá un email destino'); return; }
      var subject = <?php echo json_encode($mailSubject); ?>;
      var body = <?php echo json_encode($mailBody); ?>;
      var link = 'mailto:' + encodeURIComponent(email) + '?subject=' + encodeURIComponent(subject) + '&body=' + encodeURIComponent(body);
      window.location.href = link;
    }
    function sendWhatsAppTicket(){
      var phone = document.getElementById('waPhone').value.trim();
      if(!phone){ alert('Ingresá un número con prefijo (ej: 54911...)'); return; }
      var text = <?php echo json_encode($waMensaje); ?>;
      var link = 'https://wa.me/' + encodeURIComponent(phone) + '?text=' + encodeURIComponent(text);
      window.open(link, '_blank');
    }
  </script>
  <?php endif; ?>
</body>
</html>
