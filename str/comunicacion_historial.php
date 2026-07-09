<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/communication_ops.php';

require_login();
$cu = current_user();
$tipoGlobal = isset($cu['tipo_global']) ? (string)$cu['tipo_global'] : (isset($_SESSION['tipo_global']) ? (string)$_SESSION['tipo_global'] : '');
$isSuper = in_array($tipoGlobal, array('super_admin', 'superadmin'), true);
$isAllowed = (is_admin() && ($isSuper || $tipoGlobal === 'admin_evento'));
if (!$isAllowed) {
    http_response_code(403);
    include __DIR__ . '/inc/layout_top.php';
    echo '<div class="card" style="max-width:640px;margin:32px auto;"><h2>Acceso restringido</h2><p>Solo para administradores.</p></div>';
    include __DIR__ . '/inc/layout_bottom.php';
    exit;
}

$pdo = db();
communication_execution_ensure_schema($pdo);
communication_transport_ensure_schema($pdo);
communication_campaigns_ensure_schema($pdo);
communication_ops_ensure_schema($pdo);

$organizationId = 1;
$adminId = 0;
if (isset($_SESSION['admin_id'])) $adminId = (int)$_SESSION['admin_id'];
elseif (isset($_SESSION['user_id'])) $adminId = (int)$_SESSION['user_id'];
elseif (isset($_SESSION['usuario_id'])) $adminId = (int)$_SESSION['usuario_id'];

$csrf = function_exists('tickex_csrf_token') ? (string)tickex_csrf_token() : '';
$flashOk = '';
$flashErr = '';

$campaignId = isset($_GET['campaign_id']) ? (int)$_GET['campaign_id'] : 0;
$runId = isset($_GET['run_id']) ? (int)$_GET['run_id'] : 0;
$page = isset($_GET['p']) ? (int)$_GET['p'] : 1;
if ($page <= 0) $page = 1;
$perPage = 100;
$offset = ($page - 1) * $perPage;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $provided = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
    if (function_exists('tickex_csrf_verify') && !tickex_csrf_verify($provided)) {
        $flashErr = 'CSRF invalido. Recarga la pagina e intenta nuevamente.';
    } else {
        $action = isset($_POST['action']) ? (string)$_POST['action'] : '';

        if ($action === 'retry_run') {
            $runIdPost = isset($_POST['run_id']) ? (int)$_POST['run_id'] : 0;
            $res = communication_ops_action_retry_run($pdo, $organizationId, $adminId, $isSuper, $runIdPost, 'historial');
            if (!empty($res['ok'])) {
                $flashOk = 'Reintento solicitado. Comando #' . (int)$res['command_id'];
                $runId = $runIdPost;
            } else {
                $flashErr = isset($res['error']) ? (string)$res['error'] : 'No se pudo solicitar reintento.';
            }
        }

        if ($action === 'resume_run') {
            $runIdPost = isset($_POST['run_id']) ? (int)$_POST['run_id'] : 0;
            $res = communication_ops_action_resume_run($pdo, $organizationId, $adminId, $isSuper, $runIdPost, 'historial');
            if (!empty($res['ok'])) {
                $flashOk = 'Reanudacion solicitada. Comando #' . (int)$res['command_id'];
                $runId = $runIdPost;
            } else {
                $flashErr = isset($res['error']) ? (string)$res['error'] : 'No se pudo reanudar run.';
            }
        }

        if ($action === 'cancel_run') {
            $runIdPost = isset($_POST['run_id']) ? (int)$_POST['run_id'] : 0;
            $res = communication_ops_action_cancel_run($pdo, $organizationId, $adminId, $isSuper, $runIdPost, 'historial');
            if (!empty($res['ok'])) {
                $flashOk = 'Run cancelado.';
                $runId = $runIdPost;
            } else {
                $flashErr = isset($res['error']) ? (string)$res['error'] : 'No se pudo cancelar run.';
            }
        }

        if ($action === 'requeue_campaign') {
            $campaignIdPost = isset($_POST['campaign_id']) ? (int)$_POST['campaign_id'] : 0;
            $res = communication_ops_action_requeue_campaign($pdo, $organizationId, $adminId, $isSuper, $campaignIdPost, 'historial');
            if (!empty($res['ok'])) {
                $flashOk = 'Campana reencolada. Comando #' . (int)$res['command_id'];
                $campaignId = $campaignIdPost;
            } else {
                $flashErr = isset($res['error']) ? (string)$res['error'] : 'No se pudo reencolar campana.';
            }
        }
    }
}

$campaignOptions = communication_ops_fetch_campaigns($pdo, $organizationId, $adminId, $isSuper);
if ($campaignId <= 0 && !empty($campaignOptions)) {
    $campaignId = (int)$campaignOptions[0]['id'];
}

$history = array('campaign' => null, 'runs' => array());
if ($campaignId > 0) {
    $history = communication_ops_fetch_run_history($pdo, $organizationId, $adminId, $isSuper, $campaignId);
}

if ($runId <= 0 && !empty($history['runs'])) {
    $runId = (int)$history['runs'][0]['id'];
}

$runDetail = array('run' => null, 'recipients' => array(), 'total' => 0);
if ($runId > 0) {
    $runDetail = communication_ops_fetch_run_detail($pdo, $organizationId, $adminId, $isSuper, $runId, $perPage, $offset);
}

$title = 'Comunicacion - Historial';
include __DIR__ . '/inc/layout_top.php';
?>

<div class="card" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
  <a class="btn secondary" href="panel_admin.php">Volver</a>
  <div>
    <div class="muted" style="font-size:12px;letter-spacing:.08em;text-transform:uppercase;">📣 Comunicacion</div>
    <h2 style="margin:0;">Historial de ejecucion</h2>
  </div>
  <span class="muted">Runs por campana y detalle por destinatario.</span>
</div>

<div class="card" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
  <a class="btn secondary" href="superadmin_emails_db.php">👥 Contactos</a>
  <a class="btn secondary" href="comunicacion_audiencias.php">Audiencias</a>
  <a class="btn secondary" href="comunicacion_newsletter.php">Newsletter</a>
  <a class="btn secondary" href="comunicacion_plantillas.php">Plantillas</a>
  <a class="btn secondary" href="comunicacion_campanas.php">Campanas</a>
  <a class="btn secondary" href="comunicacion_estado_motor.php">Estado Motor</a>
  <a class="btn" href="comunicacion_historial.php">Historial</a>
  <a class="btn secondary" href="comunicacion_healthcheck.php">Health Check</a>
</div>

<?php if ($flashOk !== ''): ?>
  <div class="flash ok"><?php echo e($flashOk); ?></div>
<?php endif; ?>
<?php if ($flashErr !== ''): ?>
  <div class="flash err"><?php echo e($flashErr); ?></div>
<?php endif; ?>

<div class="card">
  <form method="get" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end;">
    <div style="min-width:340px;">
      <label>Campana</label>
      <select name="campaign_id" onchange="this.form.submit()">
        <?php foreach ($campaignOptions as $c): ?>
          <option value="<?php echo (int)$c['id']; ?>" <?php echo ((int)$campaignId === (int)$c['id']) ? 'selected' : ''; ?>>
            <?php echo e($c['name']); ?> (<?php echo e($c['status']); ?>)
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <button class="btn secondary" type="submit">Ver historial</button>
    <?php if ($campaignId > 0): ?>
      <a class="btn secondary" href="comunicacion_campanas.php?id=<?php echo (int)$campaignId; ?>">Editar campana</a>
    <?php endif; ?>
  </form>
</div>

<div class="card" style="overflow:auto;">
  <h3 style="margin-top:0;">Runs de la campana</h3>
  <table class="table" style="width:100%;font-size:14px;">
    <thead>
      <tr>
        <th>Run</th>
        <th>Inicio</th>
        <th>Fin</th>
        <th>Duracion</th>
        <th>Estado</th>
        <th>Destinatarios</th>
        <th>Enviados</th>
        <th>Fallidos</th>
        <th>Pendientes</th>
        <th>Acciones</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($history['runs'])): ?>
        <tr><td colspan="10" class="muted">No hay runs para esta campana.</td></tr>
      <?php else: ?>
        <?php foreach ($history['runs'] as $r): ?>
          <tr>
            <td>
              <a class="btn secondary" href="comunicacion_historial.php?campaign_id=<?php echo (int)$campaignId; ?>&run_id=<?php echo (int)$r['id']; ?>">#<?php echo (int)$r['id']; ?></a>
            </td>
            <td><?php echo e($r['started_at']); ?></td>
            <td><?php echo e($r['finished_at']); ?></td>
            <td>
              <?php if ($r['duration_seconds'] === null): ?>
                <span class="muted">-</span>
              <?php else: ?>
                <?php echo (int)$r['duration_seconds']; ?>s
              <?php endif; ?>
            </td>
            <td><?php echo e($r['status']); ?></td>
            <td><?php echo (int)$r['resolved_recipients']; ?></td>
            <td><?php echo (int)$r['sent_count']; ?></td>
            <td><?php echo (int)$r['failed_count']; ?></td>
            <td><?php echo (int)$r['pending_count']; ?></td>
            <td>
              <div style="display:flex;gap:6px;flex-wrap:wrap;">
                <form method="post" style="display:inline;">
                  <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
                  <input type="hidden" name="run_id" value="<?php echo (int)$r['id']; ?>">
                  <button class="btn secondary" type="submit" name="action" value="retry_run">Reintentar</button>
                </form>
                <form method="post" style="display:inline;">
                  <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
                  <input type="hidden" name="run_id" value="<?php echo (int)$r['id']; ?>">
                  <button class="btn secondary" type="submit" name="action" value="resume_run">Reanudar</button>
                </form>
                <form method="post" style="display:inline;">
                  <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
                  <input type="hidden" name="run_id" value="<?php echo (int)$r['id']; ?>">
                  <button class="btn secondary" type="submit" name="action" value="cancel_run">Cancelar</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>

  <?php if ($campaignId > 0): ?>
    <div style="margin-top:10px;">
      <form method="post" style="display:inline;">
        <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
        <input type="hidden" name="campaign_id" value="<?php echo (int)$campaignId; ?>">
        <button class="btn" type="submit" name="action" value="requeue_campaign">Volver a encolar campana</button>
      </form>
    </div>
  <?php endif; ?>
</div>

<div class="card" style="overflow:auto;">
  <h3 style="margin-top:0;">Detalle del run <?php echo ($runId > 0 ? '#' . (int)$runId : ''); ?></h3>
  <table class="table" style="width:100%;font-size:14px;">
    <thead>
      <tr>
        <th>Email</th>
        <th>Nombre</th>
        <th>Estado</th>
        <th>Proveedor</th>
        <th>Codigo</th>
        <th>Mensaje</th>
        <th>Intentos</th>
        <th>Ultimo intento</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($runDetail['recipients'])): ?>
        <tr><td colspan="8" class="muted">No hay destinatarios para mostrar.</td></tr>
      <?php else: ?>
        <?php foreach ($runDetail['recipients'] as $rr): ?>
          <tr>
            <td><?php echo e($rr['recipient_email']); ?></td>
            <td><?php echo e($rr['recipient_name']); ?></td>
            <td><?php echo e($rr['status']); ?></td>
            <td><?php echo e($rr['provider_name']); ?></td>
            <td><?php echo e($rr['last_response_code']); ?></td>
            <td style="max-width:420px;word-break:break-word;"><?php echo e($rr['last_response_message']); ?></td>
            <td><?php echo (int)$rr['attempt_count']; ?></td>
            <td><?php echo e($rr['last_attempt_at']); ?></td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>

  <?php
  $total = isset($runDetail['total']) ? (int)$runDetail['total'] : 0;
  $hasPrev = ($page > 1);
  $hasNext = (($offset + $perPage) < $total);
  ?>
  <?php if ($total > $perPage): ?>
    <div style="display:flex;gap:8px;align-items:center;justify-content:flex-end;">
      <?php if ($hasPrev): ?>
        <a class="btn secondary" href="comunicacion_historial.php?campaign_id=<?php echo (int)$campaignId; ?>&run_id=<?php echo (int)$runId; ?>&p=<?php echo (int)($page - 1); ?>">Anterior</a>
      <?php endif; ?>
      <span class="muted">Pagina <?php echo (int)$page; ?> · total <?php echo (int)$total; ?></span>
      <?php if ($hasNext): ?>
        <a class="btn secondary" href="comunicacion_historial.php?campaign_id=<?php echo (int)$campaignId; ?>&run_id=<?php echo (int)$runId; ?>&p=<?php echo (int)($page + 1); ?>">Siguiente</a>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/inc/layout_bottom.php'; ?>
