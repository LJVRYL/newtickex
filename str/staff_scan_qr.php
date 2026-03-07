<?php
require_once __DIR__ . '/inc/security.php';
tickex_send_security_headers();
tickex_session_start();
require_once __DIR__ . '/inc/db.php';

if (!isset($_SESSION['usuario_id']) || (int)$_SESSION['usuario_id'] <= 0) {
  header('Location: login.php');
  exit;
}

$usuarioId = (int)$_SESSION['usuario_id'];
$pdo = db();

$eventoId = isset($_GET['evento_id']) ? (int)$_GET['evento_id'] : 0;
if ($eventoId <= 0) {
  try {
    $st = $pdo->prepare('SELECT evento_id FROM staff_eventos WHERE staff_id = :sid ORDER BY evento_id DESC LIMIT 1');
    $st->execute(array(':sid' => $usuarioId));
    $eventoId = (int)$st->fetchColumn();
  } catch (Exception $e) {
    $eventoId = 0;
  }
}

$title = 'Escanear QR';
include __DIR__ . '/inc/layout_top.php';
?>
<style>
  .topbar{display:none !important}
  .nav,.nav-overlay{display:none !important}
  body{padding-left:0 !important}
  .wrap{max-width:980px;margin:0 auto}
  .scan-page{max-width:680px;margin:0 auto;padding-bottom:20px}
  .scan-card{border:1px solid var(--line);border-radius:14px;background:var(--panel);padding:14px}
  #qr-reader-page{width:100%}
  .footer{display:none !important}
  .staff-bottom-nav{position:fixed;left:0;right:0;bottom:0;z-index:140;background:rgba(11,16,32,.98);border-top:1px solid var(--line);padding:8px 8px calc(8px + env(safe-area-inset-bottom));display:grid;grid-template-columns:1fr 1fr auto 1fr 1fr;gap:6px;align-items:end}
  .staff-bottom-nav a{background:none;border:none;color:var(--muted);text-decoration:none;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:3px;font-size:11px;cursor:pointer}
  .staff-bottom-nav .i{font-size:18px;line-height:1}
  .staff-bottom-nav .center{width:58px;height:58px;border-radius:50%;margin-top:-26px;background:linear-gradient(135deg,#ffdd33,#ff8a00);color:#111;border:2px solid rgba(255,255,255,.18);box-shadow:0 8px 20px rgba(0,0,0,.35)}
  .staff-bottom-nav .center .i{font-size:22px}
  .scan-page{padding-bottom:98px}
  @media (min-width:1024px){.staff-bottom-nav{max-width:860px;left:50%;right:auto;transform:translateX(-50%);width:100%}}
</style>

<div class="scan-page">
  <div class="card" style="display:flex;justify-content:space-between;align-items:center;gap:10px;">
    <h2 style="margin:0;">Escanear QR</h2>
    <a class="btn secondary" href="panel_staff.php<?php echo $eventoId > 0 ? ('?evento_id=' . (int)$eventoId) : ''; ?>">Volver</a>
  </div>

  <div class="scan-card">
    <div id="qr-reader-page"></div>
    <div id="scan-status" class="muted" style="font-size:12px;margin-top:10px;">Permití cámara y enfocá el código QR del ingreso.</div>
  </div>
</div>

<nav class="staff-bottom-nav" aria-label="Navegación staff">
  <a href="panel_usuario.php"><span class="i">🏠</span><span>Inicio</span></a>
  <a href="panel_staff.php<?php echo $eventoId > 0 ? ('?evento_id=' . (int)$eventoId) : ''; ?>"><span class="i">📋</span><span>Gestión</span></a>
  <a href="staff_scan_qr.php<?php echo $eventoId > 0 ? ('?evento_id=' . (int)$eventoId) : ''; ?>" class="center" aria-label="QR" title="QR"><span class="i">▣</span><span>QR</span></a>
  <a href="panel_staff_venta_puerta.php<?php echo $eventoId > 0 ? ('?evento_id=' . (int)$eventoId) : ''; ?>"><span class="i">💸</span><span>Venta</span></a>
  <a href="panel_staff_checkin_log.php<?php echo $eventoId > 0 ? ('?evento_id=' . (int)$eventoId) : ''; ?>"><span class="i">🧾</span><span>Log</span></a>
</nav>

<script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>
<script>
  (function () {
    var eventoId = <?php echo (int)$eventoId; ?>;
    var qrScanner = new Html5Qrcode('qr-reader-page');
    var statusEl = document.getElementById('scan-status');

    function setStatus(msg) {
      if (statusEl) statusEl.textContent = msg;
    }

    function extractCode(text) {
      try {
        if (/^https?:\/\//i.test(text)) {
          var u = new URL(text);
          var c = u.searchParams.get('c');
          if (c) return c;
        }
      } catch (e) {}
      return text;
    }

    function onScan(decodedText) {
      var code = extractCode(decodedText || '');
      if (!code) return;
      qrScanner.stop().then(function () {
        var target = 'checkin.php?c=' + encodeURIComponent(code);
        if (eventoId > 0) target += '&evento_id=' + encodeURIComponent(String(eventoId));
        window.location.href = target;
      });
    }

    function tryStartWith(config) {
      return qrScanner.start(config, { fps: 10, qrbox: 260 }, onScan);
    }

    setStatus('Iniciando cámara...');
    tryStartWith({ facingMode: 'environment' })
      .catch(function () {
        return Html5Qrcode.getCameras().then(function (cameras) {
          if (cameras && cameras.length > 0) {
            return tryStartWith({ deviceId: { exact: cameras[0].id } });
          }
          throw new Error('No se detectaron cámaras disponibles.');
        });
      })
      .then(function () {
        setStatus('Cámara activa. Enfocá el QR del ingreso.');
      })
      .catch(function (err) {
        var msg = (err && err.message) ? err.message : String(err || 'Error desconocido');
        setStatus('No se pudo iniciar la cámara. ' + msg + ' Si estás en la app, habilitá permiso de cámara de la app en Android.');
      });
  })();
</script>

<?php include __DIR__ . '/inc/layout_bottom.php'; ?>
