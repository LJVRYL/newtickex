<?php
// panel_revendedor.php - Dashboard básico para revendedores (clientes)
require_once __DIR__ . '/inc/security.php';
tickex_send_security_headers();
tickex_session_start();
require_once __DIR__ . '/inc/db.php';

if (!isset($_SESSION['usuario_id']) || (int)$_SESSION['usuario_id'] <= 0) {
  header('Location: login.php');
  exit;
}

$pdo = db();
$clienteId = (int)$_SESSION['usuario_id'];
$flashOk = '';
$flashErr = '';

$csrf = function_exists('tickex_csrf_token') ? tickex_csrf_token() : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $provided = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
  if (function_exists('tickex_csrf_verify') && !tickex_csrf_verify($provided)) {
    http_response_code(403);
    $title = 'CSRF inválido';
    include __DIR__ . '/inc/layout_top.php';
    echo '<div class="card" style="max-width:700px;margin:32px auto;"><h2>CSRF inválido</h2><p>Actualizá la página e intentá de nuevo.</p><a class="btn secondary" href="panel_revendedor.php">⬅ Volver</a></div>';
    include __DIR__ . '/inc/layout_bottom.php';
    exit;
  }
}

// Cargar cliente
$cliente = null;
try {
  $st = $pdo->prepare('SELECT id, email, nombre, apellido, apodo, dni, cbu FROM registro_pendientes WHERE id = :id LIMIT 1');
  $st->execute(array(':id' => $clienteId));
  $cliente = $st->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
  $cliente = null;
}

if (!$cliente) {
  $_SESSION = array();
  session_destroy();
  header('Location: login.php');
  exit;
}

// Cargar revendedor activo
$rev = null;
try {
  $stR = $pdo->prepare('SELECT * FROM revendedores WHERE cliente_id = :cid AND activo = 1 ORDER BY id DESC LIMIT 1');
  $stR->execute(array(':cid' => $clienteId));
  $rev = $stR->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
  $rev = null;
}

if (!$rev) {
  http_response_code(403);
  $title = 'Acceso restringido';
  include __DIR__ . '/inc/layout_top.php';
  echo '<div class="card" style="max-width:700px;margin:32px auto;"><h2>Acceso restringido</h2><p>No tenés una cuenta de revendedor activa.</p><a class="btn secondary" href="panel_usuario.php">⬅ Volver</a></div>';
  include __DIR__ . '/inc/layout_bottom.php';
  exit;
}

$revId = (int)$rev['id'];
$ownerAdminId = isset($rev['owner_admin_id']) ? (int)$rev['owner_admin_id'] : 0;
$comisionPercent = isset($rev['comision_percent']) ? (float)$rev['comision_percent'] : 0.0;

function _tickex_money($n)
{
  return '$' . number_format((float)$n, 2, ',', '.');
}

function _tickex_is_paid_state($st)
{
  $s = strtolower(trim((string)$st));
  return in_array($s, array('approved','aprobado','success','paid','ok','completed'), true);
}

// Guardar CBU
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_cbu') {
  $cbu = isset($_POST['cbu']) ? trim((string)$_POST['cbu']) : '';

  if ($cbu === '') {
    $flashErr = 'El CBU es obligatorio.';
  } elseif (strlen($cbu) > 64) {
    $flashErr = 'El CBU es demasiado largo.';
  } elseif (!preg_match('/^[0-9]+$/', $cbu)) {
    $flashErr = 'El CBU solo puede tener números.';
  } else {
    try {
      $stU = $pdo->prepare('UPDATE registro_pendientes SET cbu = :cbu WHERE id = :id');
      $stU->execute(array(':cbu' => $cbu, ':id' => $clienteId));
      $cliente['cbu'] = $cbu;
      $flashOk = 'CBU actualizado.';
    } catch (Exception $e) {
      $flashErr = 'No se pudo guardar el CBU.';
    }
  }
}

// Stats de órdenes
$totalOrders = 0;
$totalPaidOrders = 0;
$totalPaidAmount = 0.0;
try {
  $stO = $pdo->prepare('SELECT state, amount FROM tc_orders WHERE revendedor_id = :rid');
  $stO->execute(array(':rid' => $revId));
  while ($o = $stO->fetch(PDO::FETCH_ASSOC)) {
    $totalOrders++;
    if (_tickex_is_paid_state($o['state'] ?? '')) {
      $totalPaidOrders++;
      $totalPaidAmount += (float)($o['amount'] ?? 0);
    }
  }
} catch (Exception $e) {
  // ignore
}

$comisionTotal = ($comisionPercent > 0) ? ($totalPaidAmount * ($comisionPercent / 100.0)) : 0.0;

// Retiros (pagados y pendientes)
$paidWithdraw = 0.0;
$pendingWithdraw = 0.0;
try {
  $stW = $pdo->prepare('SELECT amount, estado FROM revendedor_retiros WHERE revendedor_id = :rid');
  $stW->execute(array(':rid' => $revId));
  while ($w = $stW->fetch(PDO::FETCH_ASSOC)) {
    $amt = (float)($w['amount'] ?? 0);
    $stt = (string)($w['estado'] ?? '');
    if ($stt === 'paid') $paidWithdraw += $amt;
    if ($stt === 'pending') $pendingWithdraw += $amt;
  }
} catch (Exception $e) {
  // ignore
}

$available = $comisionTotal - $paidWithdraw - $pendingWithdraw;
if ($available < 0) $available = 0.0;

// Solicitar retiro
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'withdraw_request') {
  $amount = isset($_POST['amount']) ? (float)str_replace(',', '.', (string)$_POST['amount']) : 0.0;
  $cbuCur = isset($cliente['cbu']) ? trim((string)$cliente['cbu']) : '';

  if ($cbuCur === '') {
    $flashErr = 'Para solicitar un retiro tenés que completar tu CBU.';
  } elseif ($amount <= 0) {
    $flashErr = 'Ingresá un monto válido.';
  } elseif ($amount > $available + 0.00001) {
    $flashErr = 'El monto supera tu disponible.';
  } else {
    try {
      $stI = $pdo->prepare("INSERT INTO revendedor_retiros (revendedor_id, owner_admin_id, cliente_id, amount, cbu, estado) VALUES (:rid, :oid, :cid, :am, :cbu, 'pending')");
      $stI->execute(array(
        ':rid' => $revId,
        ':oid' => ($ownerAdminId > 0 ? $ownerAdminId : null),
        ':cid' => $clienteId,
        ':am'  => $amount,
        ':cbu' => $cbuCur,
      ));
      $flashOk = 'Solicitud de retiro enviada.';

      // refrescar disponibles
      $pendingWithdraw += $amount;
      $available = $comisionTotal - $paidWithdraw - $pendingWithdraw;
      if ($available < 0) $available = 0.0;
    } catch (Exception $e) {
      $flashErr = 'No se pudo registrar la solicitud de retiro.';
    }
  }
}

$title = 'Dashboard revendedor';
include __DIR__ . '/inc/layout_top.php';

$tickexId = ($cliente['apodo'] && $cliente['apodo'] !== '') ? (string)$cliente['apodo'] : ('#' . (int)$cliente['id']);
$nombre = trim((string)($cliente['nombre'] ?? '') . ' ' . (string)($cliente['apellido'] ?? ''));
if ($nombre === '') $nombre = (string)$cliente['email'];
?>

<div class="card" style="max-width:900px;margin:0 auto 16px auto;display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:center;">
  <div>
    <h2 style="margin:0;">Dashboard revendedor</h2>
    <div class="muted" style="margin-top:4px;"><?php echo htmlspecialchars($nombre, ENT_QUOTES, 'UTF-8'); ?> — Tickex ID: <strong><?php echo htmlspecialchars($tickexId, ENT_QUOTES, 'UTF-8'); ?></strong></div>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap;">
    <a class="btn secondary" href="panel_usuario.php">⬅ Volver</a>
  </div>
</div>

<?php if ($flashOk !== ''): ?>
  <div class="card" style="max-width:900px;margin:0 auto 12px auto;"><div class="flash ok"><?php echo htmlspecialchars($flashOk, ENT_QUOTES, 'UTF-8'); ?></div></div>
<?php endif; ?>
<?php if ($flashErr !== ''): ?>
  <div class="card" style="max-width:900px;margin:0 auto 12px auto;"><div class="flash err"><?php echo htmlspecialchars($flashErr, ENT_QUOTES, 'UTF-8'); ?></div></div>
<?php endif; ?>

<div style="max-width:900px;margin:0 auto;display:flex;flex-direction:column;gap:16px;">

  <div class="card">
    <h3 style="margin-top:0;">Resumen</h3>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;">
      <div style="border:1px solid var(--line);border-radius:12px;padding:12px;background:var(--panel-2);">
        <div class="muted" style="font-size:12px;">Ventas atribuidas</div>
        <div style="font-size:22px;font-weight:800;"><?php echo (int)$totalPaidOrders; ?></div>
      </div>
      <div style="border:1px solid var(--line);border-radius:12px;padding:12px;background:var(--panel-2);">
        <div class="muted" style="font-size:12px;">Monto vendido (pagado)</div>
        <div style="font-size:22px;font-weight:800;"><?php echo htmlspecialchars(_tickex_money($totalPaidAmount), ENT_QUOTES, 'UTF-8'); ?></div>
      </div>
      <div style="border:1px solid var(--line);border-radius:12px;padding:12px;background:var(--panel-2);">
        <div class="muted" style="font-size:12px;">Comisión</div>
        <div style="font-size:22px;font-weight:800;"><?php echo htmlspecialchars(_tickex_money($comisionTotal), ENT_QUOTES, 'UTF-8'); ?></div>
        <div class="muted" style="font-size:12px;"><?php echo (float)$comisionPercent; ?>%</div>
      </div>
      <div style="border:1px solid var(--line);border-radius:12px;padding:12px;background:var(--panel-2);">
        <div class="muted" style="font-size:12px;">Disponible para retirar</div>
        <div style="font-size:22px;font-weight:800;"><?php echo htmlspecialchars(_tickex_money($available), ENT_QUOTES, 'UTF-8'); ?></div>
        <div class="muted" style="font-size:12px;">Pendiente: <?php echo htmlspecialchars(_tickex_money($pendingWithdraw), ENT_QUOTES, 'UTF-8'); ?></div>
      </div>
    </div>
    <div class="muted" style="margin-top:10px;font-size:12px;">Nota: se cuentan como pagadas las órdenes con estado approved/success/paid.</div>
  </div>

  <div class="card">
    <h3 style="margin-top:0;">Datos para cobro</h3>
    <form method="post" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;align-items:end;">
      <input type="hidden" name="csrf" value="<?php echo htmlspecialchars((string)$csrf, ENT_QUOTES, 'UTF-8'); ?>">
      <input type="hidden" name="action" value="save_cbu">
      <label>
        CBU
        <input name="cbu" value="<?php echo htmlspecialchars((string)($cliente['cbu'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="22 dígitos" required>
      </label>
      <div>
        <button class="btn" type="submit">Guardar CBU</button>
      </div>
    </form>
  </div>

  <div class="card">
    <h3 style="margin-top:0;">Solicitar retiro</h3>
    <form method="post" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;align-items:end;">
      <input type="hidden" name="csrf" value="<?php echo htmlspecialchars((string)$csrf, ENT_QUOTES, 'UTF-8'); ?>">
      <input type="hidden" name="action" value="withdraw_request">
      <label>
        Monto a retirar
        <input name="amount" inputmode="decimal" placeholder="0.00" required>
      </label>
      <div>
        <button class="btn" type="submit" <?php echo ($available <= 0.00001) ? 'disabled' : ''; ?>>Enviar solicitud</button>
      </div>
    </form>
    <div class="muted" style="margin-top:8px;font-size:12px;">El admin del evento recibirá tu solicitud y la podrá marcar como pagada o rechazar.</div>
  </div>

</div>

<?php include __DIR__ . '/inc/layout_bottom.php';
