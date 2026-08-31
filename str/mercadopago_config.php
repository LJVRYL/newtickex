<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/mercadopago_marketplace.php';

require_login();
$title = 'Mercado Pago';
$pdo = db();
$cu = current_user();
$adminId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : (isset($cu['id']) ? (int)$cu['id'] : 0);
$role = isset($cu['tipo_global']) ? (string)$cu['tipo_global'] : '';
if ($adminId <= 0 || !in_array($role, array('admin_evento', 'super_admin', 'superadmin'), true)) abort_404('No tenes permiso.');
tickex_mp_ensure_schema($pdo);

$error = isset($_SESSION['mp_flash_error']) ? (string)$_SESSION['mp_flash_error'] : '';
$ok = isset($_SESSION['mp_flash_ok']) ? (string)$_SESSION['mp_flash_ok'] : '';
unset($_SESSION['mp_flash_error'], $_SESSION['mp_flash_ok']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_event'])) {
    if (!tickex_csrf_verify(isset($_POST['_csrf']) ? $_POST['_csrf'] : '')) {
        $error = 'La sesion vencio. Recarga la pagina.';
    } else {
        try {
            $eventId = isset($_POST['event_id']) ? (int)$_POST['event_id'] : 0;
            $provider = isset($_POST['provider']) ? (string)$_POST['provider'] : 'totalcoin';
            $fee = isset($_POST['fee_percent']) ? (float)str_replace(',', '.', (string)$_POST['fee_percent']) : 0;
            tickex_mp_save_event_config($pdo, $eventId, $adminId, $provider, $fee);
            $ok = 'Medio de pago actualizado para el evento.';
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['disconnect'])) {
    if (!tickex_csrf_verify(isset($_POST['_csrf']) ? $_POST['_csrf'] : '')) {
        $error = 'La sesion vencio. Recarga la pagina.';
    } else {
        $st = $pdo->prepare("UPDATE mercadopago_marketplace_accounts SET status='disconnected', access_token_enc=NULL, refresh_token_enc=NULL, updated_at=CURRENT_TIMESTAMP WHERE admin_id=:admin");
        $st->execute(array(':admin' => $adminId));
        $pdo->prepare("UPDATE mercadopago_event_configs SET provider='totalcoin', updated_at=CURRENT_TIMESTAMP WHERE admin_id=:admin AND provider='mercadopago'")->execute(array(':admin' => $adminId));
        $ok = 'Cuenta desconectada. Los eventos volvieron a TotalCoin.';
    }
}

$account = tickex_mp_account($pdo, $adminId, false);
$configured = tickex_mp_configured();
$stEvents = $pdo->prepare("SELECT e.id,e.nombre,e.fecha_desde,c.provider,c.marketplace_fee_percent FROM eventos e LEFT JOIN mercadopago_event_configs c ON c.event_id=e.id WHERE e.creado_por_admin_id=:admin AND (e.borrado_en IS NULL) ORDER BY e.id DESC");
$stEvents->execute(array(':admin' => $adminId));
$events = $stEvents->fetchAll(PDO::FETCH_ASSOC);
$csrf = tickex_csrf_token();
include __DIR__ . '/inc/layout_top.php';
?>
<div class="card">
  <h2 style="margin-top:0;">Mercado Pago para mis eventos</h2>
  <p class="muted">Cada organizador conecta su propia cuenta. Mercado Pago acredita la venta al organizador y separa automaticamente la comision de Tickex.</p>
  <?php if ($error !== ''): ?><div class="flash err"><?php echo e($error); ?></div><?php endif; ?>
  <?php if ($ok !== ''): ?><div class="flash ok"><?php echo e($ok); ?></div><?php endif; ?>
  <?php if (!$configured): ?>
    <div class="flash err">La aplicacion Marketplace aun no tiene sus credenciales seguras configuradas en el servidor.</div>
  <?php elseif ($account && $account['status'] === 'connected'): ?>
    <div class="flash ok"><strong>Cuenta conectada.</strong> ID de vendedor: <?php echo e($account['mp_user_id']); ?> · vinculacion vigente hasta <?php echo e($account['expires_at']); ?> UTC.</div>
    <form method="post" onsubmit="return confirm('¿Desconectar Mercado Pago y volver los eventos a TotalCoin?');">
      <input type="hidden" name="_csrf" value="<?php echo e($csrf); ?>">
      <button class="btn danger" type="submit" name="disconnect" value="1">Desconectar cuenta</button>
    </form>
  <?php else: ?>
    <form method="post" action="mercadopago_connect.php">
      <input type="hidden" name="_csrf" value="<?php echo e($csrf); ?>">
      <button class="btn" type="submit">Conectar Mercado Pago</button>
    </form>
  <?php endif; ?>
</div>

<div class="card">
  <h3 style="margin-top:0;">Medio de pago por evento</h3>
  <p class="muted">Los eventos existentes siguen usando TotalCoin hasta que cambies uno expresamente.</p>
  <?php if (!$events): ?><p class="muted">No hay eventos activos.</p><?php endif; ?>
  <?php foreach ($events as $event): ?>
    <?php $provider = isset($event['provider']) && $event['provider'] === 'mercadopago' ? 'mercadopago' : 'totalcoin'; ?>
    <form method="post" class="card" style="margin:10px 0;background:var(--panel-2);">
      <input type="hidden" name="_csrf" value="<?php echo e($csrf); ?>">
      <input type="hidden" name="event_id" value="<?php echo (int)$event['id']; ?>">
      <div style="display:grid;grid-template-columns:minmax(220px,1fr) 190px 170px auto;gap:10px;align-items:end;">
        <div><strong><?php echo e($event['nombre']); ?></strong><div class="muted">#<?php echo (int)$event['id']; ?> · <?php echo e($event['fecha_desde']); ?></div></div>
        <label>Proveedor<select name="provider"><option value="totalcoin"<?php echo $provider === 'totalcoin' ? ' selected' : ''; ?>>TotalCoin</option><option value="mercadopago"<?php echo $provider === 'mercadopago' ? ' selected' : ''; ?>>Mercado Pago Split</option></select></label>
        <label>Comision Tickex (%)<input type="number" name="fee_percent" min="0" max="100" step="0.01" value="<?php echo e(isset($event['marketplace_fee_percent']) ? $event['marketplace_fee_percent'] : '0'); ?>"></label>
        <button class="btn" type="submit" name="save_event" value="1">Guardar</button>
      </div>
    </form>
  <?php endforeach; ?>
</div>
<?php include __DIR__ . '/inc/layout_bottom.php'; ?>

