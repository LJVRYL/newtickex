<?php
require_once __DIR__ . '/inc/security.php';
tickex_send_security_headers();
tickex_session_start();
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/unified_tickets.php';

if (!function_exists('e')) {
  function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

if (!isset($_SESSION['usuario_id']) || (int)$_SESSION['usuario_id'] <= 0) {
  header('Location: login.php');
  exit;
}

$pdo = db();
$usuarioId = (int)$_SESSION['usuario_id'];
$title = 'Actividad de check-ins';

$eventosStaff = array();
try {
  $stE = $pdo->prepare("SELECT e.id, e.nombre
    FROM staff_eventos se
    JOIN eventos e ON e.id = se.evento_id
    WHERE se.staff_id = :sid
    ORDER BY e.id DESC");
  $stE->execute(array(':sid' => $usuarioId));
  $eventosStaff = $stE->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
  $eventosStaff = array();
}

$staffEventIds = array();
foreach ($eventosStaff as $ev) $staffEventIds[] = (int)$ev['id'];

$activeEventId = isset($_GET['evento_id']) ? (int)$_GET['evento_id'] : 0;
if ($activeEventId <= 0 && !empty($staffEventIds)) $activeEventId = $staffEventIds[0];
if ($activeEventId > 0 && !in_array($activeEventId, $staffEventIds, true)) $activeEventId = 0;

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
if ($limit < 20) $limit = 20;
if ($limit > 300) $limit = 300;

$rows = array();
$hasAudit = false;
try {
  $hasAudit = (bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='checkin_audit_log' LIMIT 1")->fetchColumn();
} catch (Exception $e) {
  $hasAudit = false;
}

if ($hasAudit) {
  try {
    $where = array();
    $params = array();

    if ($activeEventId > 0) {
      $where[] = 'evento_id = :eid';
      $params[':eid'] = $activeEventId;
    } elseif (!empty($staffEventIds)) {
      $ph = array();
      foreach ($staffEventIds as $i => $eid) {
        $k = ':e' . $i;
        $ph[] = $k;
        $params[$k] = $eid;
      }
      $where[] = 'evento_id IN (' . implode(',', $ph) . ')';
    } else {
      $where[] = '1=0';
    }

    $sql = 'SELECT id, created_at, actor_user_id, evento_id, source, source_ticket_id, ticket_ref, attendee_name, action, result, detail
            FROM checkin_audit_log';
    if (!empty($where)) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY id DESC LIMIT ' . (int)$limit;

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
  } catch (Exception $e) {
    $rows = array();
  }
}

include __DIR__ . '/inc/layout_top.php';
?>
<style>
  .topbar{display:none !important}
  .nav,.nav-overlay{display:none !important}
  body{padding-left:0 !important}
  .wrap{max-width:980px;margin:0 auto}
  .staff-app{max-width:960px;margin:0 auto;padding-bottom:96px}
  .footer{display:none !important}
  .staff-head{display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:10px}
  .staff-brand{font-weight:800;letter-spacing:.06em}
  .staff-bottom-nav{position:fixed;left:0;right:0;bottom:0;z-index:140;background:rgba(11,16,32,.98);border-top:1px solid var(--line);padding:8px 8px calc(8px + env(safe-area-inset-bottom));display:grid;grid-template-columns:1fr 1fr auto 1fr 1fr;gap:6px;align-items:end}
  .staff-bottom-nav a{background:none;border:none;color:var(--muted);text-decoration:none;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:3px;font-size:11px;cursor:pointer}
  .staff-bottom-nav .i{font-size:18px;line-height:1}
  .staff-bottom-nav .center{width:58px;height:58px;border-radius:50%;margin-top:-26px;background:linear-gradient(135deg,#ffdd33,#ff8a00);color:#111;border:2px solid rgba(255,255,255,.18);box-shadow:0 8px 20px rgba(0,0,0,.35)}
  .staff-bottom-nav .center .i{font-size:22px}
  .table-wrap{overflow:auto;border:1px solid var(--line);border-radius:12px;background:var(--panel)}
  table{width:100%;border-collapse:collapse;min-width:780px}
  th,td{padding:10px;border-bottom:1px solid rgba(255,255,255,.07);text-align:left;font-size:13px;white-space:nowrap}
  th{font-size:12px;color:var(--muted);font-weight:700}
  .r-ok{color:#7df3a3}
  .r-dup{color:#f8d27c}
  .r-err{color:#ff9d9d}
  .filters{display:flex;gap:8px;flex-wrap:wrap;align-items:end;margin-bottom:10px}
  .filters .f{min-width:180px}
  @media (min-width:1024px){.staff-bottom-nav{max-width:960px;left:50%;right:auto;transform:translateX(-50%);width:100%}}
</style>

<div class="staff-app">
  <div class="staff-head">
    <div>
      <div class="staff-brand">TICKEX</div>
      <div class="muted">Actividad de check-ins (rápida)</div>
    </div>
  </div>

  <?php if (!$hasAudit): ?>
    <div class="flash warn">Todavía no hay tabla de auditoría creada. Se crea automáticamente al primer check-in/log.</div>
  <?php endif; ?>

  <form method="get" class="filters card">
    <div class="f">
      <label>Evento</label>
      <select name="evento_id">
        <option value="0" <?php echo $activeEventId <= 0 ? 'selected' : ''; ?>>Todos mis eventos</option>
        <?php foreach ($eventosStaff as $ev): ?>
          <option value="<?php echo (int)$ev['id']; ?>" <?php echo ((int)$ev['id'] === (int)$activeEventId ? 'selected' : ''); ?>><?php echo e((string)$ev['nombre']); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="f">
      <label>Límite</label>
      <select name="limit">
        <?php foreach (array(50,100,200,300) as $l): ?>
          <option value="<?php echo (int)$l; ?>" <?php echo ((int)$limit === (int)$l ? 'selected' : ''); ?>><?php echo (int)$l; ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <button class="btn secondary" type="submit">Actualizar</button>
    </div>
  </form>

  <div class="table-wrap">
    <table>
      <thead>
      <tr>
        <th>Fecha</th>
        <th>Evento</th>
        <th>Origen</th>
        <th>Ref</th>
        <th>Nombre</th>
        <th>Acción</th>
        <th>Resultado</th>
        <th>Detalle</th>
      </tr>
      </thead>
      <tbody>
      <?php if (empty($rows)): ?>
        <tr><td colspan="8" class="muted">Sin actividad para mostrar.</td></tr>
      <?php else: ?>
        <?php foreach ($rows as $r): ?>
          <?php
            $res = strtolower((string)($r['result'] ?? ''));
            $resClass = '';
            if ($res === 'ok') $resClass = 'r-ok';
            elseif ($res === 'duplicate') $resClass = 'r-dup';
            elseif ($res === 'error' || $res === 'denied') $resClass = 'r-err';
          ?>
          <tr>
            <td><?php echo e((string)($r['created_at'] ?? '')); ?></td>
            <td>#<?php echo (int)($r['evento_id'] ?? 0); ?></td>
            <td><?php echo e((string)($r['source'] ?? '')); ?></td>
            <td><?php echo e((string)($r['ticket_ref'] ?? '')); ?></td>
            <td><?php echo e((string)($r['attendee_name'] ?? '')); ?></td>
            <td><?php echo e((string)($r['action'] ?? '')); ?></td>
            <td class="<?php echo e($resClass); ?>"><?php echo e((string)($r['result'] ?? '')); ?></td>
            <td><?php echo e((string)($r['detail'] ?? '')); ?></td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<nav class="staff-bottom-nav" aria-label="Navegación staff">
  <a href="panel_usuario.php"><span class="i">🏠</span><span>Inicio</span></a>
  <a href="panel_staff.php<?php echo $activeEventId > 0 ? ('?evento_id=' . (int)$activeEventId) : ''; ?>"><span class="i">📋</span><span>Gestión</span></a>
  <a href="staff_scan_qr.php<?php echo $activeEventId > 0 ? ('?evento_id=' . (int)$activeEventId) : ''; ?>" class="center" aria-label="QR" title="QR"><span class="i">▣</span><span>QR</span></a>
  <a href="panel_staff_venta_puerta.php<?php echo $activeEventId > 0 ? ('?evento_id=' . (int)$activeEventId) : ''; ?>"><span class="i">💸</span><span>Venta</span></a>
  <a href="panel_staff_checkin_log.php<?php echo $activeEventId > 0 ? ('?evento_id=' . (int)$activeEventId) : ''; ?>"><span class="i">🧾</span><span>Log</span></a>
</nav>

<?php include __DIR__ . '/inc/layout_bottom.php'; ?>
