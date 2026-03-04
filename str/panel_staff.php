<?php
require_once __DIR__ . '/inc/security.php';
tickex_send_security_headers();
tickex_session_start();
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/staff_roles.php';

if (!isset($_SESSION['usuario_id']) || (int)$_SESSION['usuario_id'] <= 0) {
  header('Location: login.php');
  exit;
}

$usuarioId = (int)$_SESSION['usuario_id'];
$pdo = db();
$title = 'Panel staff';

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

// Traer asignaciones staff activas para este cliente
$asignaciones = array();
try {
  $st = $pdo->prepare("SELECT sa.id, sa.owner_admin_id, sa.rol_staff, sa.created_at,
      ua.apodo AS admin_apodo, ua.username AS admin_username, ua.email AS admin_email
    FROM staff_admins sa
    LEFT JOIN usuarios_admin ua ON ua.id = sa.owner_admin_id
    WHERE sa.cliente_id = :cid AND sa.activo = 1
    ORDER BY sa.id DESC
    LIMIT 50");
  $st->execute(array(':cid' => $usuarioId));
  $asignaciones = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
  $asignaciones = array();
}

$permCatalog = tickex_staff_roles_permissions_catalog();

function _tickex_staff_perm_labels($catalog, $keys)
{
  $out = array();
  if (!is_array($keys)) return $out;
  foreach ($keys as $k) {
    $kk = (string)$k;
    if (isset($catalog[$kk])) $out[] = (string)$catalog[$kk];
  }
  return $out;
}

$eventosStaff = array();
try {
  $stE = $pdo->prepare("SELECT e.id, e.nombre, e.slug,
    (SELECT COUNT(*) FROM entradas en WHERE en.evento_id = e.id) AS total,
    (SELECT COUNT(*) FROM entradas en WHERE en.evento_id = e.id AND COALESCE(en.checked_in,0)=1) AS checkins
    FROM staff_eventos se
    JOIN eventos e ON e.id = se.evento_id
    WHERE se.staff_id = :sid
    ORDER BY e.id DESC");
  $stE->execute(array(':sid' => $usuarioId));
  $eventosStaff = $stE->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
  $eventosStaff = array();
}

$activeEventId = isset($_GET['evento_id']) ? (int)$_GET['evento_id'] : 0;
if ($activeEventId <= 0 && !empty($eventosStaff)) {
  $activeEventId = (int)$eventosStaff[0]['id'];
}

$activeEvent = null;
foreach ($eventosStaff as $ev) {
  if ((int)$ev['id'] === $activeEventId) {
    $activeEvent = $ev;
    break;
  }
}
if (!$activeEvent && !empty($eventosStaff)) {
  $activeEvent = $eventosStaff[0];
  $activeEventId = (int)$activeEvent['id'];
}

$canScan = false;
foreach ($asignaciones as $a) {
  $roleCode = isset($a['rol_staff']) ? (string)$a['rol_staff'] : 'puerta';
  $permKeys = tickex_staff_role_permissions($pdo, (int)$a['owner_admin_id'], $roleCode);
  if (in_array('checkin_scan', $permKeys, true)) {
    $canScan = true;
    break;
  }
}

$helloName = trim((string)($staffUser['nombre'] ?? ''));
if ($helloName === '') {
  $helloName = trim((string)($staffUser['apodo'] ?? ''));
}
if ($helloName === '') {
  $helloName = trim((string)($staffUser['email'] ?? 'Staff'));
}

include __DIR__ . '/inc/layout_top.php';
?>

<style>
  .staff-app{max-width:860px;margin:0 auto;padding-bottom:92px}
  .footer{display:none !important}
  .staff-head{display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:10px}
  .staff-brand{font-weight:800;letter-spacing:.06em}
  .staff-hello{font-size:14px;color:var(--muted)}
  .staff-notif{display:inline-flex;align-items:center;justify-content:center;width:40px;height:40px;border-radius:50%;border:1px solid var(--line);background:var(--panel-2);text-decoration:none;color:var(--ink)}
  .staff-hero{padding:14px;border:1px solid var(--line);border-radius:14px;background:var(--panel)}
  .staff-stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin-top:10px}
  .staff-stat{background:var(--panel-2);border:1px solid var(--line);border-radius:12px;padding:10px}
  .staff-stat .k{color:var(--muted);font-size:12px}
  .staff-stat .v{font-size:20px;font-weight:800;margin-top:3px}
  .staff-tools{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;margin-top:12px}
  .staff-tool{border:1px solid var(--line);border-radius:12px;padding:12px;background:var(--panel-2);text-decoration:none;color:var(--ink);display:block}
  .staff-tool strong{display:block;font-size:14px}
  .staff-tool span{color:var(--muted);font-size:12px}
  .staff-events{display:flex;gap:8px;overflow:auto;padding-bottom:2px;margin:12px 0}
  .staff-events a{white-space:nowrap;text-decoration:none;padding:7px 10px;border-radius:999px;border:1px solid var(--line);background:var(--panel-2);color:var(--ink);font-size:12px}
  .staff-events a.active{border-color:var(--acc);box-shadow:0 0 0 1px var(--acc) inset}
  .staff-bottom-nav{position:fixed;left:0;right:0;bottom:0;z-index:140;background:rgba(11,16,32,.98);border-top:1px solid var(--line);padding:8px 8px calc(8px + env(safe-area-inset-bottom));display:grid;grid-template-columns:1fr 1fr auto 1fr 1fr;gap:6px;align-items:end}
  .staff-bottom-nav a,.staff-bottom-nav button{background:none;border:none;color:var(--muted);text-decoration:none;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:3px;font-size:11px;cursor:pointer}
  .staff-bottom-nav .i{font-size:18px;line-height:1}
  .staff-bottom-nav .center{width:58px;height:58px;border-radius:50%;margin-top:-26px;background:linear-gradient(135deg,#ffdd33,#ff8a00);color:#111;border:2px solid rgba(255,255,255,.18);box-shadow:0 8px 20px rgba(0,0,0,.35)}
  .staff-bottom-nav .center .i{font-size:22px}
  .qr-icon{display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px}
  .qr-icon svg{width:22px;height:22px;display:block;fill:#000}
  .staff-sheet{display:none;position:fixed;inset:0;z-index:180;background:rgba(0,0,0,.45)}
  .staff-sheet.open{display:block}
  .staff-sheet-box{position:absolute;left:0;right:0;bottom:0;background:var(--panel);border-top:1px solid var(--line);border-radius:16px 16px 0 0;padding:14px}
  .staff-sheet-links{display:grid;gap:8px}
  .staff-sheet-links a{display:block;text-decoration:none;color:var(--ink);padding:10px 12px;border-radius:10px;border:1px solid var(--line);background:var(--panel-2)}
  .scan-modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.75);z-index:190;align-items:center;justify-content:center;padding:16px}
  .scan-modal.open{display:flex}
  .scan-box{width:min(420px,96vw);background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:12px}
  #qr-reader-staff{width:100%}
  @media (min-width:1024px){.staff-bottom-nav{max-width:860px;margin:0 auto;left:260px;right:0}}
</style>

<div class="staff-app">
  <div class="staff-head">
    <div>
      <div class="staff-brand">TICKEX</div>
      <div class="staff-hello">Hola, <strong><?php echo e($helloName); ?></strong></div>
    </div>
    <a class="staff-notif" href="panel_usuario_mi_perfil.php" title="Notificaciones">🔔</a>
  </div>

  <?php if (empty($asignaciones)): ?>
    <div class="flash err">No tenés asignaciones de staff activas.</div>
  <?php else: ?>
    <?php if (!empty($eventosStaff)): ?>
      <div class="staff-events">
        <?php foreach ($eventosStaff as $ev): ?>
          <?php $isAct = ((int)$ev['id'] === (int)$activeEventId); ?>
          <a href="panel_staff.php?evento_id=<?php echo (int)$ev['id']; ?>" class="<?php echo $isAct ? 'active' : ''; ?>">
            <?php echo e($ev['nombre']); ?>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div class="staff-hero">
      <h2 style="margin:0;">Dashboard de Puerta</h2>
      <div class="muted" style="margin-top:4px;">
        <?php if ($activeEvent): ?>Evento actual: <strong><?php echo e($activeEvent['nombre']); ?></strong><?php else: ?>Sin evento seleccionado<?php endif; ?>
      </div>

      <?php if ($activeEvent): ?>
      <div class="staff-stats">
        <div class="staff-stat"><div class="k">Entradas</div><div class="v"><?php echo (int)$activeEvent['total']; ?></div></div>
        <div class="staff-stat"><div class="k">Check-ins</div><div class="v"><?php echo (int)$activeEvent['checkins']; ?></div></div>
        <div class="staff-stat"><div class="k">Pendientes</div><div class="v"><?php echo max(0, (int)$activeEvent['total'] - (int)$activeEvent['checkins']); ?></div></div>
      </div>
      <?php endif; ?>

      <div class="staff-tools">
        <a class="staff-tool" href="<?php echo $activeEventId > 0 ? ('puerta.php?evento_id=' . (int)$activeEventId) : 'puerta.php'; ?>">
          <strong>Control de acceso</strong><span>Listado, búsqueda y estado de entradas</span>
        </a>
        <a class="staff-tool" href="panel_usuario.php">
          <strong>Gestión</strong><span>Volver al dashboard de usuario</span>
        </a>
        <a class="staff-tool" href="panel_usuario.php">
          <strong>Mis Tickex</strong><span>Mis entradas y utilidades</span>
        </a>
        <a class="staff-tool" href="panel_usuario_mi_perfil.php">
          <strong>Más opciones</strong><span>Perfil, invitaciones y configuración</span>
        </a>
      </div>
    </div>

    <div class="card" style="margin-top:12px;">
      <h3 style="margin-top:0;">Mis roles y permisos</h3>
      <div style="overflow:auto;">
        <table class="table" style="min-width:620px;">
          <thead>
            <tr>
              <th style="width:220px;">Admin</th>
              <th style="width:160px;">Rol</th>
              <th>Permisos</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($asignaciones as $a): ?>
              <?php
                $adminLabel = '';
                if (isset($a['admin_apodo']) && trim((string)$a['admin_apodo']) !== '') $adminLabel = (string)$a['admin_apodo'];
                elseif (isset($a['admin_username']) && trim((string)$a['admin_username']) !== '') $adminLabel = (string)$a['admin_username'];
                else $adminLabel = '#' . (int)$a['owner_admin_id'];

                $roleCode = isset($a['rol_staff']) ? (string)$a['rol_staff'] : 'puerta';
                $roleName = tickex_staff_role_label($pdo, (int)$a['owner_admin_id'], $roleCode);
                $permKeys = tickex_staff_role_permissions($pdo, (int)$a['owner_admin_id'], $roleCode);
                $permLabels = _tickex_staff_perm_labels($permCatalog, $permKeys);
              ?>
              <tr>
                <td><?php echo e($adminLabel); ?></td>
                <td><?php echo e($roleName); ?></td>
                <td><?php echo e(!empty($permLabels) ? implode(', ', $permLabels) : 'Sin permisos definidos'); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>
</div>

<nav class="staff-bottom-nav" aria-label="Navegación staff">
  <a href="panel_staff.php<?php echo $activeEventId > 0 ? ('?evento_id=' . (int)$activeEventId) : ''; ?>"><span class="i">🏠</span><span>Inicio</span></a>
  <a href="panel_usuario.php"><span class="i">📋</span><span>Gestión</span></a>
  <button type="button" id="btnOpenScan" class="center" aria-label="QR" title="QR">
    <span class="qr-icon" aria-hidden="true">
      <svg viewBox="0 0 24 24" role="img" focusable="false" aria-hidden="true">
        <path d="M3 3h7v7H3V3zm2 2v3h3V5H5zm9-2h7v7h-7V3zm2 2v3h3V5h-3zM3 14h7v7H3v-7zm2 2v3h3v-3H5zm11-2h2v2h-2v-2zm-2 2h2v2h-2v-2zm4 0h2v2h-2v-2zm-4 4h2v2h-2v-2zm2-2h2v2h-2v-2zm4 0h2v2h-2v-2z"></path>
      </svg>
    </span>
    <span>QR</span>
  </button>
  <a href="panel_usuario.php"><span class="i">🎫</span><span>Mis Tickex</span></a>
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
    var btnOpenScan = document.getElementById('btnOpenScan');

    function openSheet() { if (sheet) sheet.classList.add('open'); }
    function closeSheet() { if (sheet) sheet.classList.remove('open'); }

    if (btnMore) btnMore.addEventListener('click', function (e) { e.preventDefault(); openSheet(); });
    if (sheet) sheet.addEventListener('click', function (e) { if (e.target === sheet) closeSheet(); });
    if (btnOpenScan) btnOpenScan.addEventListener('click', function (e) { e.preventDefault(); });
  })();
</script>

<?php include __DIR__ . '/inc/layout_bottom.php'; ?>
