<?php
require_once __DIR__.'/inc/bootstrap.php';
date_default_timezone_set('America/Argentina/Buenos_Aires');

$pdo = db();

$cu = current_user();
$tipoGlobal = isset($cu['tipo_global']) ? $cu['tipo_global'] : (isset($_SESSION['tipo_global']) ? $_SESSION['tipo_global'] : '');
$rol = isset($cu['rol']) ? $cu['rol'] : (isset($_SESSION['rol']) ? $_SESSION['rol'] : '');
$canManage = !empty($cu) && in_array($tipoGlobal ?: $rol, array('super_admin','superadmin','admin_evento','admin'), true);

$codigo = isset($_GET['c']) ? trim($_GET['c']) : '';
if ($codigo === '') {
    http_response_code(400);
    echo "Falta el código (parámetro c).";
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM entradas WHERE codigo = :codigo LIMIT 1");
$stmt->execute(array(':codigo' => $codigo));
$entrada = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$entrada) {
    http_response_code(404);
    echo "Entrada no encontrada.";
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

// QR apunta a checkin (que SOLO checkinea con sesión puerta)
$baseUrl    = 'https://str.tickex.com.ar';
$checkinUrl = $baseUrl . '/checkin.php?c=' . urlencode($codigo);
// QR externo por ahora (simple)
$qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=320x320&data=' . urlencode($checkinUrl);

$ticketLink = (isset($_SERVER['HTTP_HOST']) ? 'http://'.$_SERVER['HTTP_HOST'] : $baseUrl).'/ticket.php?c='.urlencode($codigo);
$mensajeBase = "Hola, aqui tienes tu entrada:\nEvento: " . ($eventoNombre ?: 'TICKEX') . "\nNombre: " . $nombre . "\nTipo: " . $tipoDesc . "\nCódigo: " . $codigo . "\nVer ticket: " . $ticketLink;
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
  <link rel="stylesheet" href="assets/str.css">
  <style>
    body{
      display:flex;align-items:center;justify-content:center;min-height:100vh;
      background:var(--bg);
    }
    .ticket-wrap{max-width:520px;width:100%;}
    .subtitle{color:var(--muted);font-size:14px;margin-top:-2px;}
    .chip{
      display:inline-block;padding:4px 10px;border-radius:999px;
      background:var(--panel-2);border:1px solid var(--line);
      font-size:12px;letter-spacing:.06em;text-transform:uppercase;font-weight:800;
      margin:6px 0 10px;
    }
    .status{
      display:inline-block;padding:4px 10px;border-radius:999px;
      font-size:12px;letter-spacing:.06em;text-transform:uppercase;font-weight:800;
      margin:8px 0 12px;
      border:1px solid var(--line);
    }
    .status.ok{border-color:var(--ok);color:var(--ok);background:#0c2416;}
    .status.pend{border-color:var(--warn);color:var(--warn);background:#2a1b0b;}
    .qr img{
      width:320px;max-width:100%;height:auto;border-radius:14px;background:#fff;padding:8px;
    }
    .hint{color:var(--muted);font-size:13px;margin-top:10px;}
  </style>
</head>
<body>
  <div class="wrap ticket-wrap">
    <div class="card" style="text-align:center;">
      <h2>Entrada digital</h2>

      <?php if($eventoNombre !== ''): ?>
        <div class="subtitle"><?php echo e($eventoNombre); ?></div>
        <?php if($eventoDesde!=='' || $eventoHasta!==''): ?>
          <div class="subtitle" style="margin-top:4px;">
            <?php echo e($eventoFechaLbl); ?>
          </div>
        <?php endif; ?>
      <?php else: ?>
        <div class="subtitle">TICKEX</div>
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
