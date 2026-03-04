<?php
require_once __DIR__ . '/inc/security.php';
tickex_send_security_headers();
tickex_session_start();
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/staff_roles.php';
require_once __DIR__ . '/inc/notificaciones.php';
require_once __DIR__ . '/inc/unified_tickets.php';

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
  $stE = $pdo->prepare("SELECT e.id, e.nombre, e.slug
    FROM staff_eventos se
    JOIN eventos e ON e.id = se.evento_id
    WHERE se.staff_id = :sid
    ORDER BY e.id DESC");
  $stE->execute(array(':sid' => $usuarioId));
  $eventosStaff = $stE->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
  $eventosStaff = array();
}

if (!empty($eventosStaff)) {
  foreach ($eventosStaff as &$ev) {
    $stats = get_unified_stats($pdo, (int)$ev['id']);
    $ev['total'] = isset($stats['total']) ? (int)$stats['total'] : 0;
    $ev['checkins'] = isset($stats['checkins']) ? (int)$stats['checkins'] : 0;
  }
  unset($ev);
}

$activeEventId = isset($_GET['evento_id']) ? (int)$_GET['evento_id'] : 0;
if ($activeEventId <= 0 && !empty($eventosStaff)) {
  $activeEventId = (int)$eventosStaff[0]['id'];
}

$activeEvent = null;
$staffEventIds = array();
foreach ($eventosStaff as $ev) {
  $staffEventIds[] = (int)$ev['id'];
  if ((int)$ev['id'] === $activeEventId) {
    $activeEvent = $ev;
    break;
  }
}
if (!$activeEvent && !empty($eventosStaff)) {
  $activeEvent = $eventosStaff[0];
  $activeEventId = (int)$activeEvent['id'];
}

$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$flashOk = '';
$flashErr = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'staff_checkin') {
  $provided = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
  if (function_exists('tickex_csrf_verify') && !tickex_csrf_verify($provided)) {
    $flashErr = 'CSRF inválido.';
  } else {
    $entryId = isset($_POST['entry_id']) ? (int)$_POST['entry_id'] : 0;
    $entrySource = isset($_POST['entry_source']) ? strtoupper(trim((string)$_POST['entry_source'])) : 'STR';
    $eidPost = isset($_POST['evento_id']) ? (int)$_POST['evento_id'] : 0;
    if ($entryId <= 0 || $eidPost <= 0) {
      $flashErr = 'Entrada inválida.';
    } elseif (!in_array($eidPost, $staffEventIds, true)) {
      $flashErr = 'No tenés permiso para operar ese evento.';
    } else {
      try {
        if ($entrySource === 'TICKEX') {
          $allowedTickex = false;
          $entriesEvent = get_unified_entries($pdo, $eidPost);
          foreach ($entriesEvent as $ue) {
            $srcU = isset($ue['source']) ? strtoupper((string)$ue['source']) : 'STR';
            $idU = isset($ue['ticket_id']) ? (int)$ue['ticket_id'] : 0;
            if ($srcU === 'TICKEX' && $idU === $entryId) {
              $allowedTickex = true;
              break;
            }
          }
          if (!$allowedTickex) {
            $flashErr = 'La entrada no corresponde al evento seleccionado.';
          } else {
          $stCheckTbl = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='senforms_bridge_tickets' LIMIT 1");
          if ($stCheckTbl && $stCheckTbl->fetch(PDO::FETCH_ASSOC)) {
            $st = $pdo->prepare('UPDATE senforms_bridge_tickets SET is_checked_in = 1, checked_in_at = datetime(\'now\') WHERE id = :id AND COALESCE(is_checked_in,0)=0');
            $st->execute(array(':id' => $entryId));
            if ($st->rowCount() > 0) {
              $flashOk = 'Check-in realizado.';
            } else {
              $flashErr = 'La entrada ya estaba checkeada o no se encontró en bridge.';
            }
          } else {
            $flashErr = 'No se puede checkear esta entrada en este entorno.';
          }
          }
        } else {
          $colCheck = get_checkin_column($pdo);
          $st = $pdo->prepare("UPDATE entradas SET $colCheck = 1, checked_in_at = datetime('now') WHERE id = :id AND evento_id = :eid AND COALESCE($colCheck,0)=0");
          $st->execute(array(':id' => $entryId, ':eid' => $eidPost));
          if ($st->rowCount() > 0) {
            $flashOk = 'Check-in realizado.';
          } else {
            $flashErr = 'La entrada ya estaba checkeada o no corresponde al evento.';
          }
        }
      } catch (Exception $e) {
        $flashErr = 'No se pudo realizar el check-in.';
      }
    }
  }
}

$rowsEntradas = array();
if ($activeEventId > 0) {
  try {
    $filters = array();
    if ($q !== '') $filters['q'] = $q;
    $rowsEntradas = get_unified_entries($pdo, $activeEventId, $filters);
    if (count($rowsEntradas) > 300) {
      $rowsEntradas = array_slice($rowsEntradas, 0, 300);
    }
  } catch (Exception $e) {
    $rowsEntradas = array();
  }
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

$notifs = get_user_notifications($usuarioId, $pdo);
$unreadCount = 0;
foreach ($notifs as $n) {
  if (empty($n['leida']) || (int)$n['leida'] === 0) $unreadCount++;
}

include __DIR__ . '/inc/layout_top.php';
?>

<style>
  .topbar{display:none !important}
  .nav,.nav-overlay{display:none !important}
  body{padding-left:0 !important}
  .wrap{max-width:980px;margin:0 auto}
  .staff-app{max-width:860px;margin:0 auto;padding-bottom:96px}
  .footer{display:none !important}
  .staff-head{display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:10px}
  .staff-brand{font-weight:800;letter-spacing:.06em}
  .staff-hello{font-size:14px;color:var(--muted)}
  .staff-notif{display:inline-flex;align-items:center;justify-content:center;width:40px;height:40px;border-radius:50%;border:1px solid var(--line);background:var(--panel-2);text-decoration:none;color:var(--ink);position:relative}
  .staff-notif-badge{position:absolute;top:-5px;right:-5px;min-width:16px;height:16px;border-radius:999px;background:#d22;color:#fff;font-size:10px;line-height:16px;text-align:center;padding:0 4px}
  .staff-hero{padding:14px;border:1px solid var(--line);border-radius:14px;background:var(--panel)}
  .staff-stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin-top:10px}
  .staff-stat{background:var(--panel-2);border:1px solid var(--line);border-radius:12px;padding:10px}
  .staff-stat .k{color:var(--muted);font-size:12px}
  .staff-stat .v{font-size:20px;font-weight:800;margin-top:3px}
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
  .staff-sheet-box{position:absolute;left:0;right:0;bottom:0;background:var(--panel);border-top:1px solid var(--line);border-radius:16px 16px 0 0;padding:14px 14px calc(20px + env(safe-area-inset-bottom))}
  .staff-sheet-links{display:grid;gap:8px}
  .staff-sheet-links a{display:block;text-decoration:none;color:var(--ink);padding:10px 12px;border-radius:10px;border:1px solid var(--line);background:var(--panel-2)}
  .staff-notifs{display:none;position:fixed;top:60px;right:12px;z-index:185;width:min(360px,94vw);max-height:62vh;overflow:auto;background:var(--panel);border:1px solid var(--line);border-radius:12px;box-shadow:0 12px 22px rgba(0,0,0,.35)}
  .staff-notifs.open{display:block}
  .staff-notifs-head{padding:10px 12px;border-bottom:1px solid var(--line);font-weight:700}
  .staff-notif-item{padding:10px 12px;border-bottom:1px solid rgba(255,255,255,.06)}
  .staff-notif-item.unread{background:rgba(255,255,255,.04)}
  .staff-list{margin-top:12px;border:1px solid var(--line);border-radius:14px;background:var(--panel)}
  .staff-list-head{padding:12px;border-bottom:1px solid var(--line)}
  .staff-search{display:grid;grid-template-columns:1fr auto;gap:8px;align-items:end}
  .entry-list{display:flex;flex-direction:column}
  .entry-item{padding:12px;border-bottom:1px solid rgba(255,255,255,.07);display:grid;grid-template-columns:1fr auto;gap:10px;align-items:center}
  .entry-main strong{display:block}
  .entry-meta{font-size:12px;color:var(--muted)}
  .entry-state{font-size:11px;padding:3px 8px;border-radius:999px;border:1px solid var(--line)}
  .entry-state.ok{color:#7df3a3;border-color:rgba(34,197,94,.45)}
  .entry-state.pending{color:#f8d27c;border-color:rgba(245,158,11,.45)}
  .swipe-wrap{width:112px;height:36px;border-radius:999px;background:#151f31;border:1px solid var(--line);position:relative;overflow:hidden}
  .swipe-fill{position:absolute;inset:0;width:0;background:linear-gradient(90deg,#0f6,#2dd4bf);opacity:.35;transition:width .12s}
  .swipe-knob{position:absolute;left:2px;top:2px;width:30px;height:30px;border-radius:50%;background:#fff;color:#111;display:flex;align-items:center;justify-content:center;font-weight:800;touch-action:none;user-select:none;cursor:grab}
  .roles-mini{margin-top:10px;padding:10px 12px;border:1px solid var(--line);border-radius:12px;background:var(--panel-2)}
  .roles-mini details{margin:0}
  .roles-mini summary{cursor:pointer;font-size:13px;color:var(--muted)}
  .roles-mini-list{margin-top:8px;display:grid;gap:8px}
  .roles-mini-item{font-size:12px;color:var(--muted)}
  @media (min-width:1024px){.staff-bottom-nav{max-width:860px;left:50%;right:auto;transform:translateX(-50%);width:100%}}
</style>

<div class="staff-app">
  <div class="staff-head">
    <div>
      <div class="staff-brand">TICKEX</div>
      <div class="staff-hello">Hola, <strong><?php echo e($helloName); ?></strong></div>
    </div>
    <button class="staff-notif" id="btnNotifStaff" type="button" title="Notificaciones">🔔
      <?php if ($unreadCount > 0): ?><span class="staff-notif-badge"><?php echo (int)$unreadCount; ?></span><?php endif; ?>
    </button>
  </div>

  <div id="notifPanelStaff" class="staff-notifs" aria-hidden="true">
    <div class="staff-notifs-head">Notificaciones</div>
    <?php if (empty($notifs)): ?>
      <div class="staff-notif-item">Sin notificaciones por ahora.</div>
    <?php else: ?>
      <?php foreach ($notifs as $n): ?>
        <div class="staff-notif-item <?php echo (empty($n['leida']) ? 'unread' : ''); ?>">
          <div><?php echo e((string)$n['mensaje']); ?></div>
          <div class="entry-meta" style="margin-top:4px;"><?php echo e(date('d/m H:i', strtotime((string)$n['created_at']))); ?></div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
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

      <div class="roles-mini">
        <details>
          <summary>Mis roles y permisos</summary>
          <div class="roles-mini-list">
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
              <div class="roles-mini-item"><strong><?php echo e($adminLabel); ?></strong> — <?php echo e($roleName); ?><?php if (!empty($permLabels)): ?>: <?php echo e(implode(', ', $permLabels)); ?><?php endif; ?></div>
            <?php endforeach; ?>
          </div>
        </details>
      </div>
    </div>

    <div class="staff-list">
      <div class="staff-list-head">
        <h3 style="margin:0 0 6px 0;">Lista de ingresos</h3>
        <form method="get" class="staff-search">
          <input type="hidden" name="evento_id" value="<?php echo (int)$activeEventId; ?>">
          <div>
            <label>Buscar por nombre o código</label>
            <input type="text" name="q" value="<?php echo e($q); ?>" placeholder="Ej: Juan Pérez">
          </div>
          <button class="btn secondary" type="submit">Buscar</button>
        </form>
      </div>

      <?php if ($flashOk !== ''): ?><div class="flash ok" style="margin:10px 12px;"><?php echo e($flashOk); ?></div><?php endif; ?>
      <?php if ($flashErr !== ''): ?><div class="flash err" style="margin:10px 12px;"><?php echo e($flashErr); ?></div><?php endif; ?>

      <div class="entry-list">
        <?php if (empty($rowsEntradas)): ?>
          <div class="entry-item"><div class="entry-main">No hay ingresos para mostrar.</div></div>
        <?php else: ?>
          <?php foreach ($rowsEntradas as $r): ?>
            <?php
              $src = isset($r['source']) ? (string)$r['source'] : 'STR';
              $checked = !empty($r['is_checked_in']);
              $entryId = isset($r['ticket_id']) ? (int)$r['ticket_id'] : 0;
              $codigo = isset($r['ticket_ref']) ? (string)$r['ticket_ref'] : '';
            ?>
            <div class="entry-item">
              <div class="entry-main">
                <strong><?php echo e((string)($r['nombre'] ?? 'Sin nombre')); ?></strong>
                <div class="entry-meta"><?php echo e((string)($r['tipo'] ?? 'Entrada')); ?> · Código: <?php echo e($codigo); ?> · <?php echo e($src); ?></div>
              </div>
              <?php if ($checked): ?>
                <span class="entry-state ok">Check-in OK</span>
              <?php else: ?>
                <form method="post" class="swipe-form" style="margin:0;">
                  <input type="hidden" name="csrf" value="<?php echo e(tickex_csrf_token()); ?>">
                  <input type="hidden" name="action" value="staff_checkin">
                  <input type="hidden" name="entry_id" value="<?php echo (int)$entryId; ?>">
                  <input type="hidden" name="entry_source" value="<?php echo e($src); ?>">
                  <input type="hidden" name="evento_id" value="<?php echo (int)$activeEventId; ?>">
                  <input type="hidden" name="q" value="<?php echo e($q); ?>">
                  <div class="swipe-wrap" data-swipe>
                    <div class="swipe-fill"></div>
                    <div class="swipe-knob">→</div>
                  </div>
                </form>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>
</div>

<nav class="staff-bottom-nav" aria-label="Navegación staff">
  <a href="panel_usuario.php"><span class="i">🏠</span><span>Inicio</span></a>
  <a href="panel_staff.php<?php echo $activeEventId > 0 ? ('?evento_id=' . (int)$activeEventId) : ''; ?>"><span class="i">📋</span><span>Gestión</span></a>
  <a href="staff_scan_qr.php<?php echo $activeEventId > 0 ? ('?evento_id=' . (int)$activeEventId) : ''; ?>" id="btnOpenScan" class="center" aria-label="QR" title="QR">
    <span class="qr-icon" aria-hidden="true">
      <svg viewBox="0 0 24 24" role="img" focusable="false" aria-hidden="true">
        <path d="M3 3h7v7H3V3zm2 2v3h3V5H5zm9-2h7v7h-7V3zm2 2v3h3V5h-3zM3 14h7v7H3v-7zm2 2v3h3v-3H5zm11-2h2v2h-2v-2zm-2 2h2v2h-2v-2zm4 0h2v2h-2v-2zm-4 4h2v2h-2v-2zm2-2h2v2h-2v-2zm4 0h2v2h-2v-2z"></path>
      </svg>
    </span>
    <span>QR</span>
  </a>
  <a href="panel_staff_mis_tickex.php"><span class="i">🎫</span><span>Ingresos</span></a>
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
    var btnNotif = document.getElementById('btnNotifStaff');
    var notifPanel = document.getElementById('notifPanelStaff');
    var swipeAreas = document.querySelectorAll('[data-swipe]');

    function openSheet() { if (sheet) sheet.classList.add('open'); }
    function closeSheet() { if (sheet) sheet.classList.remove('open'); }
    function toggleNotif() { if (notifPanel) notifPanel.classList.toggle('open'); }

    if (btnMore) btnMore.addEventListener('click', function (e) { e.preventDefault(); openSheet(); });
    if (sheet) sheet.addEventListener('click', function (e) { if (e.target === sheet) closeSheet(); });

    if (btnNotif) {
      btnNotif.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        toggleNotif();
      });
    }
    document.addEventListener('click', function () {
      if (notifPanel) notifPanel.classList.remove('open');
    });
    if (notifPanel) notifPanel.addEventListener('click', function (e) { e.stopPropagation(); });

    function bindSwipe(el) {
      var knob = el.querySelector('.swipe-knob');
      var fill = el.querySelector('.swipe-fill');
      if (!knob || !fill) return;

      var dragging = false;
      var startX = 0;
      var current = 2;
      var max = el.clientWidth - knob.clientWidth - 2;

      function setPos(x) {
        current = Math.max(2, Math.min(max, x));
        knob.style.left = current + 'px';
        fill.style.width = (current + knob.clientWidth/2) + 'px';
      }

      function reset() { setPos(2); }

      knob.addEventListener('pointerdown', function (e) {
        dragging = true;
        startX = e.clientX - current;
        knob.setPointerCapture(e.pointerId);
      });

      knob.addEventListener('pointermove', function (e) {
        if (!dragging) return;
        setPos(e.clientX - startX);
      });

      function endDrag() {
        if (!dragging) return;
        dragging = false;
        if (current >= max * 0.78) {
          var form = el.closest('form');
          if (form) form.submit();
        } else {
          reset();
        }
      }

      knob.addEventListener('pointerup', endDrag);
      knob.addEventListener('pointercancel', endDrag);
      reset();
    }

    swipeAreas.forEach(bindSwipe);
  })();
</script>

<?php include __DIR__ . '/inc/layout_bottom.php'; ?>
