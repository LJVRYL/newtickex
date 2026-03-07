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
$title = 'Mis Tickex';

$staffUser = array('nombre' => '', 'email' => (string)($_SESSION['usuario_email'] ?? ''), 'apodo' => '');
try {
  $stU = $pdo->prepare('SELECT nombre, email, apodo FROM registro_pendientes WHERE id = :id LIMIT 1');
  $stU->execute(array(':id' => $usuarioId));
  $rowU = $stU->fetch(PDO::FETCH_ASSOC);
  if ($rowU) {
    $staffUser = array_merge($staffUser, $rowU);
  }
} catch (Exception $e) {
  // ignore
}

$emailUsuario = trim((string)($staffUser['email'] ?? ''));
$tickexRows = array();
if ($emailUsuario !== '') {
  try {
    $st = $pdo->prepare("SELECT e.id, e.codigo, e.tipo, e.monto_pagado, e.evento_id,
      COALESCE(e.checked_in,0) AS checked_in,
      COALESCE(e.fecha_registro, e.created_at, '') AS fecha_ticket,
      ev.nombre AS evento_nombre
      FROM entradas e
      LEFT JOIN eventos ev ON ev.id = e.evento_id
      WHERE lower(e.email) = lower(:email)
      ORDER BY e.id DESC
      LIMIT 120");
    $st->execute(array(':email' => $emailUsuario));
    $tickexRows = $st->fetchAll(PDO::FETCH_ASSOC);
  } catch (Exception $e) {
    $tickexRows = array();
  }
}

include __DIR__ . '/inc/layout_top.php';
?>

<style>
  .topbar{display:none !important}
  .nav,.nav-overlay{display:none !important}
  body{padding-left:0 !important}
  .wrap{max-width:980px;margin:0 auto}
  .tickets-app{max-width:860px;margin:0 auto;padding-bottom:96px}
  .footer{display:none !important}
  .tickets-head{display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:10px}
  .tickets-brand{font-weight:800;letter-spacing:.06em}
  .tickets-sub{font-size:14px;color:var(--muted)}
  .tickets-grid{display:grid;gap:10px;margin-top:10px}
  .ticket-card{border:1px solid var(--line);border-radius:14px;background:var(--panel);padding:12px}
  .ticket-title{font-weight:700}
  .ticket-meta{font-size:12px;color:var(--muted);margin-top:4px}
  .ticket-foot{margin-top:10px;display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap}
  .ticket-state{font-size:11px;padding:3px 8px;border-radius:999px;border:1px solid var(--line)}
  .ticket-state.ok{color:#7df3a3;border-color:rgba(34,197,94,.45)}
  .ticket-state.pending{color:#f8d27c;border-color:rgba(245,158,11,.45)}
  .staff-bottom-nav{position:fixed;left:0;right:0;bottom:0;z-index:140;background:rgba(11,16,32,.98);border-top:1px solid var(--line);padding:8px 8px calc(8px + env(safe-area-inset-bottom));display:grid;grid-template-columns:1fr 1fr auto 1fr 1fr;gap:6px;align-items:end}
  .staff-bottom-nav a,.staff-bottom-nav button{background:none;border:none;color:var(--muted);text-decoration:none;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:3px;font-size:11px;cursor:pointer}
  .staff-bottom-nav .i{font-size:18px;line-height:1}
  .staff-bottom-nav .center{width:58px;height:58px;border-radius:50%;margin-top:-26px;background:linear-gradient(135deg,#ffdd33,#ff8a00);color:#111;border:2px solid rgba(255,255,255,.18);box-shadow:0 8px 20px rgba(0,0,0,.35)}
  .staff-bottom-nav .center .i{font-size:22px}
  .qr-icon{display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px}
  .qr-icon svg{width:22px;height:22px;display:block;fill:#000}
  .staff-sheet{display:none;position:fixed;inset:0;z-index:180;background:rgba(0,0,0,.45)}
  .staff-sheet.open{display:block}
  .staff-sheet-box{position:absolute;left:0;right:0;bottom:0;background:var(--panel);border-top:1px solid var(--line);border-radius:16px 16px 0 0;padding:14px 14px calc(20px + env(safe-area-inset-bottom))}
  .staff-sheet-links{display:grid;gap:8px}
  .staff-sheet-links a{display:block;text-decoration:none;color:var(--ink);padding:10px 12px;border-radius:10px;border:1px solid var(--line);background:var(--panel-2)}
  @media (min-width:1024px){.staff-bottom-nav{max-width:860px;left:50%;right:auto;transform:translateX(-50%);width:100%}}
</style>

<div class="tickets-app">
  <div class="tickets-head">
    <div>
      <div class="tickets-brand">TICKEX</div>
      <div class="tickets-sub">Mis Tickex</div>
    </div>
  </div>

  <div class="tickets-grid">
    <?php if (empty($tickexRows)): ?>
      <div class="ticket-card">
        <div class="ticket-title">No tenés Tickex por ahora.</div>
        <div class="ticket-meta">Cuando compres o recibas entradas, van a aparecer acá.</div>
      </div>
    <?php else: ?>
      <?php foreach ($tickexRows as $t): ?>
        <?php
          $checked = isset($t['checked_in']) && (int)$t['checked_in'] === 1;
          $eventName = isset($t['evento_nombre']) && trim((string)$t['evento_nombre']) !== ''
            ? (string)$t['evento_nombre']
            : ('Evento #' . (int)$t['evento_id']);
          $ticketUrl = 'ticket.php?c=' . urlencode((string)$t['codigo']);
        ?>
        <div class="ticket-card">
          <div class="ticket-title"><?php echo e($eventName); ?></div>
          <div class="ticket-meta"><?php echo e((string)($t['tipo'] ?? 'Entrada')); ?> · Código: <?php echo e((string)$t['codigo']); ?></div>
          <div class="ticket-meta">Monto: $<?php echo number_format((float)($t['monto_pagado'] ?? 0), 0, ',', '.'); ?></div>
          <div class="ticket-foot">
            <span class="ticket-state <?php echo $checked ? 'ok' : 'pending'; ?>"><?php echo $checked ? 'Check-in OK' : 'Pendiente'; ?></span>
            <a class="btn secondary" href="<?php echo e($ticketUrl); ?>" target="_blank" rel="noopener">Ver Tickex</a>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<nav class="staff-bottom-nav" aria-label="Navegación staff">
  <a href="panel_usuario.php"><span class="i">🏠</span><span>Inicio</span></a>
  <a href="panel_staff.php"><span class="i">📋</span><span>Gestión</span></a>
  <a href="staff_scan_qr.php" class="center" aria-label="QR" title="QR">
    <span class="qr-icon" aria-hidden="true">
      <svg viewBox="0 0 24 24" role="img" focusable="false" aria-hidden="true">
        <path d="M3 3h7v7H3V3zm2 2v3h3V5H5zm9-2h7v7h-7V3zm2 2v3h3V5h-3zM3 14h7v7H3v-7zm2 2v3h3v-3H5zm11-2h2v2h-2v-2zm-2 2h2v2h-2v-2zm4 0h2v2h-2v-2zm-4 4h2v2h-2v-2zm2-2h2v2h-2v-2zm4 0h2v2h-2v-2z"></path>
      </svg>
    </span>
    <span>QR</span>
  </a>
  <a href="panel_staff_venta_puerta.php"><span class="i">💸</span><span>Venta</span></a>
  <button type="button" id="btnMore"><span class="i">☰</span><span>Más</span></button>
</nav>

<div id="staffSheet" class="staff-sheet" aria-hidden="true">
  <div class="staff-sheet-box">
    <div class="staff-sheet-links">
      <a href="panel_usuario_mi_perfil.php">Mi perfil</a>
      <a href="panel_usuario.php">Dashboard usuario</a>
      <a href="logout_usuario.php">Cerrar sesión</a>
    </div>
  </div>
</div>

<script>
  (function () {
    var sheet = document.getElementById('staffSheet');
    var btnMore = document.getElementById('btnMore');
    function openSheet() { if (sheet) sheet.classList.add('open'); }
    function closeSheet() { if (sheet) sheet.classList.remove('open'); }
    if (btnMore) btnMore.addEventListener('click', function (e) { e.preventDefault(); openSheet(); });
    if (sheet) sheet.addEventListener('click', function (e) { if (e.target === sheet) closeSheet(); });
  })();
</script>

<?php include __DIR__ . '/inc/layout_bottom.php'; ?>
