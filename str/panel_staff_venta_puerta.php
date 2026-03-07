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
$title = 'Venta en puerta';

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

$activeEventId = isset($_GET['evento_id']) ? (int)$_GET['evento_id'] : 0;
if ($activeEventId <= 0 && !empty($eventosStaff)) {
  $activeEventId = (int)$eventosStaff[0]['id'];
}

$activeEvent = null;
$staffEventIds = array();
foreach ($eventosStaff as $ev) {
  $staffEventIds[] = (int)$ev['id'];
  if ((int)$ev['id'] === $activeEventId) $activeEvent = $ev;
}
if (!$activeEvent && !empty($eventosStaff)) {
  $activeEvent = $eventosStaff[0];
  $activeEventId = (int)$activeEvent['id'];
}

$tiposPuerta = array();
if ($activeEventId > 0) {
  try {
    $stT = $pdo->prepare("SELECT id, nombre, tipo_venta, precio, cantidad_disponible
      FROM tipos_entrada
      WHERE evento_id = :eid
      ORDER BY nombre ASC");
    $stT->execute(array(':eid' => $activeEventId));
    $allTipos = $stT->fetchAll(PDO::FETCH_ASSOC);
    foreach ($allTipos as $t) {
      $tv = strtoupper(trim((string)($t['tipo_venta'] ?? '')));
      $nom = strtoupper(trim((string)($t['nombre'] ?? '')));
      if ($tv === 'PUERTA' || strpos($nom, 'PUERTA') !== false) {
        $tiposPuerta[] = $t;
      }
    }
  } catch (Exception $e) {
    $tiposPuerta = array();
  }
}

function staff_door_code($eventoId)
{
  $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
  $len = 10;
  $out = '';
  if (function_exists('random_bytes')) {
    $bytes = random_bytes($len);
    for ($i=0; $i<$len; $i++) $out .= $alphabet[ord($bytes[$i]) % strlen($alphabet)];
  } else {
    for ($i=0; $i<$len; $i++) $out .= $alphabet[mt_rand(0, strlen($alphabet)-1)];
  }
  return 'P' . (int)$eventoId . '-' . $out;
}

$flashOk = '';
$flashErr = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'venta_puerta') {
  $provided = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
  if (function_exists('tickex_csrf_verify') && !tickex_csrf_verify($provided)) {
    $flashErr = 'CSRF inválido.';
  } else {
    $eventoPost = isset($_POST['evento_id']) ? (int)$_POST['evento_id'] : 0;
    $modo = isset($_POST['modo']) ? trim((string)$_POST['modo']) : 'tipo';
    $nombre = trim((string)($_POST['nombre'] ?? ''));
    if ($nombre === '') $nombre = 'Puerta';

    if (!in_array($eventoPost, $staffEventIds, true) || $eventoPost <= 0) {
      $flashErr = 'Evento inválido para tu usuario.';
    } else {
      $tipoFinal = 'PUERTA';
      $montoFinal = 0;
      $tipoSeleccionadoId = isset($_POST['tipo_id']) ? (int)$_POST['tipo_id'] : 0;

      if ($modo === 'monto') {
        $montoFinal = (int)round((float)str_replace(',', '.', (string)($_POST['monto_variable'] ?? '0')));
        if ($montoFinal < 0) $montoFinal = 0;
        $tipoFinal = trim((string)($_POST['tipo_variable'] ?? 'PUERTA_VARIABLE'));
        if ($tipoFinal === '') $tipoFinal = 'PUERTA_VARIABLE';
      } else {
        $tipoMap = array();
        foreach ($tiposPuerta as $tp) $tipoMap[(int)$tp['id']] = $tp;
        if (!isset($tipoMap[$tipoSeleccionadoId])) {
          $flashErr = 'Seleccioná un tipo PUERTA válido.';
        } else {
          $sel = $tipoMap[$tipoSeleccionadoId];
          $tipoFinal = (string)($sel['nombre'] ?? 'PUERTA');
          $montoFinal = (int)round((float)($sel['precio'] ?? 0));
        }
      }

      if ($flashErr === '') {
        try {
          $cols = detect_table_columns($pdo, 'entradas');
          $colCheck = get_checkin_column($pdo);
          $codigo = '';
          $stChk = $pdo->prepare("SELECT 1 FROM entradas WHERE codigo = :c LIMIT 1");
          for ($i = 0; $i < 30; $i++) {
            $codigo = staff_door_code($eventoPost);
            $stChk->execute(array(':c' => $codigo));
            if (!$stChk->fetchColumn()) break;
            $codigo = '';
          }
          if ($codigo === '') throw new Exception('No se pudo generar código único.');

          $insertCols = array('nombre', 'email', 'fecha_registro', 'codigo', 'tipo', 'monto_pagado');
          $insertVals = array(':nombre', ':email', "datetime('now')", ':codigo', ':tipo', ':monto');
          $params = array(
            ':nombre' => $nombre,
            ':email' => '',
            ':codigo' => $codigo,
            ':tipo' => $tipoFinal,
            ':monto' => $montoFinal,
          );

          if (isset($cols['evento_id'])) {
            $insertCols[] = 'evento_id';
            $insertVals[] = ':evento_id';
            $params[':evento_id'] = $eventoPost;
          }
          if (isset($cols[$colCheck])) {
            $insertCols[] = $colCheck;
            $insertVals[] = '1';
          }
          if (isset($cols['checked_in_at'])) {
            $insertCols[] = 'checked_in_at';
            $insertVals[] = "datetime('now')";
          }

          $sql = 'INSERT INTO entradas (' . implode(',', $insertCols) . ') VALUES (' . implode(',', $insertVals) . ')';
          $pdo->beginTransaction();
          $stIns = $pdo->prepare($sql);
          $stIns->execute($params);

          if ($modo !== 'monto' && $tipoSeleccionadoId > 0) {
            $stUpd = $pdo->prepare('UPDATE tipos_entrada SET cantidad_disponible = CASE WHEN cantidad_disponible > 0 THEN cantidad_disponible - 1 ELSE 0 END WHERE id = :id');
            $stUpd->execute(array(':id' => $tipoSeleccionadoId));
          }

          log_checkin_audit($pdo, array(
            'actor_user_id' => $usuarioId,
            'evento_id' => $eventoPost,
            'source' => 'STR',
            'source_ticket_id' => (int)$pdo->lastInsertId(),
            'ticket_ref' => $codigo,
            'attendee_name' => $nombre,
            'action' => 'venta_puerta',
            'result' => 'ok',
            'detail' => 'tipo=' . $tipoFinal . ';monto=' . (int)$montoFinal,
          ));

          $pdo->commit();
          $flashOk = 'Venta registrada. Código: ' . $codigo;
        } catch (Exception $e) {
          if ($pdo->inTransaction()) $pdo->rollBack();
          $flashErr = 'No se pudo registrar la venta en puerta.';
        }
      }
    }
  }
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
  .staff-bottom-nav{position:fixed;left:0;right:0;bottom:0;z-index:140;background:rgba(11,16,32,.98);border-top:1px solid var(--line);padding:8px 8px calc(8px + env(safe-area-inset-bottom));display:grid;grid-template-columns:1fr 1fr auto 1fr 1fr;gap:6px;align-items:end}
  .staff-bottom-nav a,.staff-bottom-nav button{background:none;border:none;color:var(--muted);text-decoration:none;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:3px;font-size:11px;cursor:pointer}
  .staff-bottom-nav .i{font-size:18px;line-height:1}
  .staff-bottom-nav .center{width:58px;height:58px;border-radius:50%;margin-top:-26px;background:linear-gradient(135deg,#ffdd33,#ff8a00);color:#111;border:2px solid rgba(255,255,255,.18);box-shadow:0 8px 20px rgba(0,0,0,.35)}
  .staff-bottom-nav .center .i{font-size:22px}
  .card h3{margin:0 0 10px 0}
  .grid-2{display:grid;grid-template-columns:1fr 1fr;gap:10px}
  @media (max-width:760px){.grid-2{grid-template-columns:1fr}}
  @media (min-width:1024px){.staff-bottom-nav{max-width:860px;left:50%;right:auto;transform:translateX(-50%);width:100%}}
</style>

<div class="staff-app">
  <div class="staff-head">
    <div>
      <div class="staff-brand">TICKEX</div>
      <div class="muted">Venta en puerta</div>
    </div>
  </div>

  <?php if (empty($eventosStaff)): ?>
    <div class="flash err">No tenés eventos asignados.</div>
  <?php else: ?>
    <div class="card" style="margin-bottom:12px;">
      <div class="muted">Evento actual</div>
      <div><strong><?php echo e((string)($activeEvent['nombre'] ?? 'Sin evento')); ?></strong></div>
    </div>

    <?php if ($flashOk !== ''): ?><div class="flash ok" style="margin-bottom:12px;"><?php echo e($flashOk); ?></div><?php endif; ?>
    <?php if ($flashErr !== ''): ?><div class="flash err" style="margin-bottom:12px;"><?php echo e($flashErr); ?></div><?php endif; ?>

    <div class="grid-2">
      <div class="card">
        <h3>Venta por tipo PUERTA</h3>
        <form method="post">
          <input type="hidden" name="csrf" value="<?php echo e(tickex_csrf_token()); ?>">
          <input type="hidden" name="action" value="venta_puerta">
          <input type="hidden" name="modo" value="tipo">
          <input type="hidden" name="evento_id" value="<?php echo (int)$activeEventId; ?>">

          <label>Nombre / alias</label>
          <input type="text" name="nombre" placeholder="Ej: Venta puerta">

          <label>Tipo</label>
          <select name="tipo_id" required>
            <option value="">Seleccionar...</option>
            <?php foreach ($tiposPuerta as $tp): ?>
              <option value="<?php echo (int)$tp['id']; ?>">
                <?php echo e((string)$tp['nombre']); ?> · $<?php echo number_format((float)($tp['precio'] ?? 0), 0, ',', '.'); ?> · disp <?php echo (int)($tp['cantidad_disponible'] ?? 0); ?>
              </option>
            <?php endforeach; ?>
          </select>

          <button class="btn" type="submit" style="margin-top:10px;">Registrar venta</button>
        </form>
      </div>

      <div class="card">
        <h3>Monto variable</h3>
        <form method="post">
          <input type="hidden" name="csrf" value="<?php echo e(tickex_csrf_token()); ?>">
          <input type="hidden" name="action" value="venta_puerta">
          <input type="hidden" name="modo" value="monto">
          <input type="hidden" name="evento_id" value="<?php echo (int)$activeEventId; ?>">

          <label>Nombre / alias</label>
          <input type="text" name="nombre" placeholder="Ej: Venta manual">

          <label>Tipo (texto)</label>
          <input type="text" name="tipo_variable" value="PUERTA_VARIABLE">

          <label>Monto</label>
          <input type="number" min="0" step="1" name="monto_variable" value="0" required>

          <button class="btn" type="submit" style="margin-top:10px;">Registrar variable</button>
        </form>
      </div>
    </div>
  <?php endif; ?>
</div>

<nav class="staff-bottom-nav" aria-label="Navegación staff">
  <a href="panel_usuario.php"><span class="i">🏠</span><span>Inicio</span></a>
  <a href="panel_staff.php<?php echo $activeEventId > 0 ? ('?evento_id=' . (int)$activeEventId) : ''; ?>"><span class="i">📋</span><span>Gestión</span></a>
  <a href="staff_scan_qr.php<?php echo $activeEventId > 0 ? ('?evento_id=' . (int)$activeEventId) : ''; ?>" class="center" aria-label="QR" title="QR"><span class="i">▣</span><span>QR</span></a>
  <a href="panel_staff_venta_puerta.php<?php echo $activeEventId > 0 ? ('?evento_id=' . (int)$activeEventId) : ''; ?>"><span class="i">💸</span><span>Venta</span></a>
  <a href="panel_staff_checkin_log.php<?php echo $activeEventId > 0 ? ('?evento_id=' . (int)$activeEventId) : ''; ?>"><span class="i">🧾</span><span>Log</span></a>
</nav>

<?php include __DIR__ . '/inc/layout_bottom.php'; ?>
