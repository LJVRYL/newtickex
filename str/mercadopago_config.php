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
$isSuper = in_array($role, array('super_admin', 'superadmin'), true);
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
            tickex_mp_save_event_config($pdo, $eventId, $adminId, $provider, 0);
            $ok = 'Medio de pago actualizado para el evento.';
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_platform'])) {
    if (!$isSuper) {
        $error = 'Solo el superadministrador puede cambiar la politica de cobros.';
    } elseif (!tickex_csrf_verify(isset($_POST['_csrf']) ? $_POST['_csrf'] : '')) {
        $error = 'La sesion vencio. Recarga la pagina.';
    } else {
        try {
            $totalTarget = isset($_POST['total_cost_target_percent']) ? (float)str_replace(',', '.', (string)$_POST['total_cost_target_percent']) : 10;
            $mpEstimate = isset($_POST['mp_cost_estimate_percent']) ? (float)str_replace(',', '.', (string)$_POST['mp_cost_estimate_percent']) : 0;
            tickex_mp_save_platform_settings($pdo, $totalTarget, $mpEstimate, !empty($_POST['enforcement_enabled']), $adminId);
            $ok = 'Politica general de cobros actualizada.';
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_policy'])) {
    if (!$isSuper) {
        $error = 'Solo el superadministrador puede cambiar el tipo de cuenta.';
    } elseif (!tickex_csrf_verify(isset($_POST['_csrf']) ? $_POST['_csrf'] : '')) {
        $error = 'La sesion vencio. Recarga la pagina.';
    } else {
        try {
            $policyAdminId = isset($_POST['policy_admin_id']) ? (int)$_POST['policy_admin_id'] : 0;
            if ($policyAdminId <= 0) throw new RuntimeException('Administrador invalido.');
            tickex_mp_save_admin_policy($pdo, $policyAdminId, isset($_POST['account_type']) ? (string)$_POST['account_type'] : 'client', isset($_POST['fee_override']) ? $_POST['fee_override'] : '', $adminId);
            $ok = 'Politica del administrador actualizada.';
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
        $ok = 'Cuenta desconectada. Las ventas Mercado Pago quedaron pausadas.';
    }
}

$account = tickex_mp_account($pdo, $adminId, false);
$configured = tickex_mp_configured();
$settings = tickex_mp_platform_settings($pdo);
$policy = tickex_mp_admin_policy($pdo, $adminId);
$effectiveFee = tickex_mp_effective_platform_fee_percent($settings, $policy);
$stEvents = $pdo->prepare("SELECT e.id,e.nombre,e.fecha_desde,c.provider,c.marketplace_fee_percent FROM eventos e LEFT JOIN mercadopago_event_configs c ON c.event_id=e.id WHERE e.creado_por_admin_id=:admin AND (e.borrado_en IS NULL) ORDER BY e.id DESC");
$stEvents->execute(array(':admin' => $adminId));
$events = $stEvents->fetchAll(PDO::FETCH_ASSOC);
$adminPolicies = array();
if ($isSuper) {
    try {
        $rows = $pdo->query("SELECT id,nombre,email,tipo_global FROM usuarios_admin WHERE tipo_global IN ('admin_evento','super_admin','superadmin') ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $adminRow) {
            $adminRow['mp_policy'] = tickex_mp_admin_policy($pdo, (int)$adminRow['id']);
            $adminRow['mp_account'] = tickex_mp_account($pdo, (int)$adminRow['id'], false);
            $adminPolicies[] = $adminRow;
        }
    } catch (Exception $_adminListError) {
        $adminPolicies = array();
    }
}
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
  <div class="card" style="margin:12px 0 0;background:var(--panel-2);">
    <strong>Politica de esta cuenta:</strong> <?php echo $policy['account_type'] === 'str_owner' ? 'SAVE THE RAVE / cuenta interna' : 'Organizador cliente'; ?>
    <div class="muted"><?php echo $policy['account_type'] === 'str_owner' ? 'Puede elegir TotalCoin o Mercado Pago por evento.' : 'Los eventos pagos utilizan Mercado Pago obligatoriamente.'; ?></div>
    <?php if ($policy['account_type'] !== 'str_owner'): ?>
      <div class="muted">Costo total objetivo: <?php echo e(number_format((float)$settings['total_cost_target_percent'], 2, ',', '.')); ?>% · comisión Tickex aplicada: <?php echo e(number_format((float)$effectiveFee, 2, ',', '.')); ?>% · costo Mercado Pago estimado: <?php echo e(number_format((float)$settings['mp_cost_estimate_percent'], 2, ',', '.')); ?>%.</div>
    <?php endif; ?>
  </div>
</div>

<?php if ($isSuper): ?>
<div class="card">
  <h3 style="margin-top:0;">Politica general de la plataforma</h3>
  <p class="muted">La comision Tickex se calcula como costo total objetivo menos costo estimado de Mercado Pago. El costo real de Mercado Pago puede variar segun el medio y plazo elegido.</p>
  <form method="post">
    <input type="hidden" name="_csrf" value="<?php echo e($csrf); ?>">
    <div style="display:grid;grid-template-columns:repeat(3,minmax(180px,1fr));gap:10px;align-items:end;">
      <label>Costo total objetivo (%)<input type="number" name="total_cost_target_percent" min="0" max="100" step="0.01" value="<?php echo e($settings['total_cost_target_percent']); ?>"></label>
      <label>Costo estimado Mercado Pago (%)<input type="number" name="mp_cost_estimate_percent" min="0" max="100" step="0.01" value="<?php echo e($settings['mp_cost_estimate_percent']); ?>"></label>
      <div><strong>Fee resultante Tickex:</strong><br><?php echo e(number_format((float)tickex_mp_effective_platform_fee_percent($settings, array('platform_fee_override_percent' => null)), 2, ',', '.')); ?>%</div>
    </div>
    <label style="display:block;margin:12px 0;"><input type="checkbox" name="enforcement_enabled" value="1"<?php echo !empty($settings['enforcement_enabled']) ? ' checked' : ''; ?>> Activar politica: clientes obligados a usar Mercado Pago</label>
    <button class="btn" type="submit" name="save_platform" value="1">Guardar politica general</button>
  </form>
</div>

<div class="card">
  <h3 style="margin-top:0;">Tipo de cuenta por administrador</h3>
  <p class="muted">Marca exclusivamente tu cuenta de SAVE THE RAVE como interna. Todas las demas deben permanecer como clientes.</p>
  <?php foreach ($adminPolicies as $adminRow): ?>
    <?php $adminPolicy = $adminRow['mp_policy']; $adminAccount = $adminRow['mp_account']; ?>
    <form method="post" class="card" style="margin:10px 0;background:var(--panel-2);">
      <input type="hidden" name="_csrf" value="<?php echo e($csrf); ?>">
      <input type="hidden" name="policy_admin_id" value="<?php echo (int)$adminRow['id']; ?>">
      <div style="display:grid;grid-template-columns:minmax(230px,1fr) 210px 190px auto;gap:10px;align-items:end;">
        <div><strong><?php echo e(isset($adminRow['nombre']) ? $adminRow['nombre'] : 'Administrador'); ?></strong><div class="muted">#<?php echo (int)$adminRow['id']; ?> · <?php echo e(isset($adminRow['email']) ? $adminRow['email'] : ''); ?> · MP <?php echo $adminAccount && $adminAccount['status'] === 'connected' ? 'conectado' : 'sin conectar'; ?></div></div>
        <label>Tipo de cuenta<select name="account_type"><option value="client"<?php echo $adminPolicy['account_type'] === 'client' ? ' selected' : ''; ?>>Organizador cliente</option><option value="str_owner"<?php echo $adminPolicy['account_type'] === 'str_owner' ? ' selected' : ''; ?>>SAVE THE RAVE / interna</option></select></label>
        <label>Fee Tickex especial (%)<input type="number" name="fee_override" min="0" max="100" step="0.01" placeholder="Usar general" value="<?php echo $adminPolicy['platform_fee_override_percent'] === null ? '' : e($adminPolicy['platform_fee_override_percent']); ?>"></label>
        <button class="btn" type="submit" name="save_policy" value="1">Guardar</button>
      </div>
    </form>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="card">
  <h3 style="margin-top:0;">Medio de pago por evento</h3>
  <p class="muted"><?php echo $policy['account_type'] === 'str_owner' ? 'SAVE THE RAVE puede elegir el proveedor de cada evento.' : 'Mercado Pago es obligatorio para los eventos pagos de clientes.'; ?></p>
  <?php if (!$events): ?><p class="muted">No hay eventos activos.</p><?php endif; ?>
  <?php foreach ($events as $event): ?>
    <?php $eventConfig = tickex_mp_event_config($pdo, (int)$event['id']); $provider = $eventConfig['provider']; ?>
    <form method="post" class="card" style="margin:10px 0;background:var(--panel-2);">
      <input type="hidden" name="_csrf" value="<?php echo e($csrf); ?>">
      <input type="hidden" name="event_id" value="<?php echo (int)$event['id']; ?>">
      <div style="display:grid;grid-template-columns:minmax(220px,1fr) 220px auto;gap:10px;align-items:end;">
        <div><strong><?php echo e($event['nombre']); ?></strong><div class="muted">#<?php echo (int)$event['id']; ?> · <?php echo e($event['fecha_desde']); ?></div></div>
        <?php if ($policy['account_type'] === 'str_owner'): ?>
          <label>Proveedor<select name="provider"><option value="totalcoin"<?php echo $provider === 'totalcoin' ? ' selected' : ''; ?>>TotalCoin</option><option value="mercadopago"<?php echo $provider === 'mercadopago' ? ' selected' : ''; ?>>Mercado Pago Split</option></select></label>
          <button class="btn" type="submit" name="save_event" value="1">Guardar</button>
        <?php else: ?>
          <input type="hidden" name="provider" value="mercadopago">
          <div><strong>Mercado Pago Split</strong><div class="muted">Fee Tickex automático: <?php echo e(number_format((float)$eventConfig['marketplace_fee_percent'], 2, ',', '.')); ?>%</div></div>
          <span class="muted">Administrado por Tickex</span>
        <?php endif; ?>
      </div>
    </form>
  <?php endforeach; ?>
</div>
<?php include __DIR__ . '/inc/layout_bottom.php'; ?>
