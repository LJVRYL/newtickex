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
  .scan-page{max-width:680px;margin:0 auto;padding-bottom:20px}
  .scan-card{border:1px solid var(--line);border-radius:14px;background:var(--panel);padding:14px}
  #qr-reader-page{width:100%}
</style>

<div class="scan-page">
  <div class="card" style="display:flex;justify-content:space-between;align-items:center;gap:10px;">
    <h2 style="margin:0;">Escanear QR</h2>
    <a class="btn secondary" href="panel_staff.php<?php echo $eventoId > 0 ? ('?evento_id=' . (int)$eventoId) : ''; ?>">Volver</a>
  </div>

  <div class="scan-card">
    <div id="qr-reader-page"></div>
    <div class="muted" style="font-size:12px;margin-top:10px;">Permití cámara y enfocá el código QR del ingreso.</div>
  </div>
</div>

<script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>
<script>
  (function () {
    var eventoId = <?php echo (int)$eventoId; ?>;
    var qrScanner = new Html5Qrcode('qr-reader-page');

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

    qrScanner.start(
      { facingMode: 'environment' },
      { fps: 10, qrbox: 260 },
      function (decodedText) {
        var code = extractCode(decodedText || '');
        if (!code) return;
        qrScanner.stop().then(function () {
          var target = 'checkin.php?c=' + encodeURIComponent(code);
          if (eventoId > 0) target += '&evento_id=' + encodeURIComponent(String(eventoId));
          window.location.href = target;
        });
      }
    ).catch(function () {
      alert('No se pudo iniciar la cámara. Revisá permisos del navegador.');
    });
  })();
</script>

<?php include __DIR__ . '/inc/layout_bottom.php'; ?>
