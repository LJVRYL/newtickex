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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $provided = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
    if (function_exists('tickex_csrf_verify') && !tickex_csrf_verify($provided)) {
        $flashErr = 'CSRF invalido. Recarga la pagina e intenta nuevamente.';
    } else {
        $action = isset($_POST['action']) ? (string)$_POST['action'] : '';

        if ($action === 'run_worker_now') {
            $max = isset($_POST['max_commands']) ? (int)$_POST['max_commands'] : 5;
            if ($max <= 0) $max = 5;
            if ($max > 100) $max = 100;
            $workerId = 'manual-ui-' . $adminId . '-' . gmdate('YmdHis');
            $res = communication_execution_process_queue($pdo, $max, $workerId);
            $flashOk = 'Worker ejecutado: picked=' . (int)$res['picked'] . ', done=' . (int)$res['done'] . ', failed=' . (int)$res['failed'] . ', cancelled=' . (int)$res['cancelled'];
        }

        if ($action === 'requeue_campaign') {
            $campaignId = isset($_POST['campaign_id']) ? (int)$_POST['campaign_id'] : 0;
            $res = communication_ops_action_requeue_campaign($pdo, $organizationId, $adminId, $isSuper, $campaignId, 'estado_motor');
            if (!empty($res['ok'])) {
                $flashOk = 'Campana reencolada. Comando #' . (int)$res['command_id'];
            } else {
                $flashErr = isset($res['error']) ? (string)$res['error'] : 'No se pudo reencolar la campana.';
            }
        }

        if ($action === 'retry_run') {
            $runId = isset($_POST['run_id']) ? (int)$_POST['run_id'] : 0;
            $res = communication_ops_action_retry_run($pdo, $organizationId, $adminId, $isSuper, $runId, 'estado_motor');
            if (!empty($res['ok'])) {
                $flashOk = 'Reintento solicitado. Comando #' . (int)$res['command_id'];
            } else {
                $flashErr = isset($res['error']) ? (string)$res['error'] : 'No se pudo solicitar reintento.';
            }
        }

        if ($action === 'resume_run') {
            $runId = isset($_POST['run_id']) ? (int)$_POST['run_id'] : 0;
            $res = communication_ops_action_resume_run($pdo, $organizationId, $adminId, $isSuper, $runId, 'estado_motor');
            if (!empty($res['ok'])) {
                $flashOk = 'Reanudacion solicitada. Comando #' . (int)$res['command_id'];
            } else {
                $flashErr = isset($res['error']) ? (string)$res['error'] : 'No se pudo reanudar run.';
            }
        }

        if ($action === 'cancel_run') {
            $runId = isset($_POST['run_id']) ? (int)$_POST['run_id'] : 0;
            $res = communication_ops_action_cancel_run($pdo, $organizationId, $adminId, $isSuper, $runId, 'estado_motor');
            if (!empty($res['ok'])) {
                $flashOk = 'Run cancelado correctamente.';
            } else {
                $flashErr = isset($res['error']) ? (string)$res['error'] : 'No se pudo cancelar run.';
            }
        }
    }
}

$state = communication_ops_fetch_engine_state($pdo, $organizationId, $adminId, $isSuper);
$pendingCommands = communication_ops_fetch_pending_commands($pdo, $organizationId, $adminId, $isSuper, 50);
$queuedCampaigns = communication_ops_fetch_campaigns_by_status($pdo, $organizationId, $adminId, $isSuper, array('draft', 'scheduled'), 30);
$runningCampaigns = communication_ops_fetch_campaigns_by_status($pdo, $organizationId, $adminId, $isSuper, array('sending'), 30);
$finishedCampaigns = communication_ops_fetch_campaigns_by_status($pdo, $organizationId, $adminId, $isSuper, array('sent', 'cancelled'), 30);
$failedCampaigns = communication_ops_fetch_campaigns_by_status($pdo, $organizationId, $adminId, $isSuper, array('failed'), 30);

$title = 'Comunicacion - Estado del Motor';
include __DIR__ . '/inc/layout_top.php';
?>

<div class="card" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
  <a class="btn secondary" href="panel_admin.php">Volver</a>
  <div>
    <div class="muted" style="font-size:12px;letter-spacing:.08em;text-transform:uppercase;">📣 Comunicacion</div>
    <h2 style="margin:0;">Estado del Motor</h2>
  </div>
  <span class="muted">Operacion y observabilidad sin acceso directo a BD.</span>
</div>

<div class="card" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
  <a class="btn secondary" href="superadmin_emails_db.php">👥 Contactos</a>
  <a class="btn secondary" href="comunicacion_audiencias.php">Audiencias</a>
  <a class="btn secondary" href="comunicacion_plantillas.php">Plantillas</a>
  <a class="btn secondary" href="comunicacion_campanas.php">Campanas</a>
  <a class="btn" href="comunicacion_estado_motor.php">Estado Motor</a>
  <a class="btn secondary" href="comunicacion_historial.php">Historial</a>
  <a class="btn secondary" href="comunicacion_healthcheck.php">Health Check</a>
</div>

<?php if ($flashOk !== ''): ?>
  <div class="flash ok"><?php echo e($flashOk); ?></div>
<?php endif; ?>
<?php if ($flashErr !== ''): ?>
  <div class="flash err"><?php echo e($flashErr); ?></div>
<?php endif; ?>

<div class="card" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px;">
  <div><div class="muted">Campanas en cola</div><strong style="font-size:24px;"><?php echo (int)$state['counts']['campaigns_queued']; ?></strong></div>
  <div><div class="muted">Campanas ejecutandose</div><strong style="font-size:24px;"><?php echo (int)$state['counts']['campaigns_running']; ?></strong></div>
  <div><div class="muted">Campanas finalizadas</div><strong style="font-size:24px;"><?php echo (int)$state['counts']['campaigns_finished']; ?></strong></div>
  <div><div class="muted">Campanas fallidas</div><strong style="font-size:24px;"><?php echo (int)$state['counts']['campaigns_failed']; ?></strong></div>
  <div><div class="muted">Runs activos</div><strong style="font-size:24px;"><?php echo (int)$state['counts']['active_runs']; ?></strong></div>
  <div><div class="muted">Cola pendiente</div><strong style="font-size:24px;"><?php echo (int)$state['counts']['queue_pending']; ?></strong></div>
</div>

<div class="card">
  <h3 style="margin-top:0;">Herramienta rapida: procesar cola</h3>
  <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end;">
    <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
    <div>
      <label>Max comandos</label>
      <input type="number" name="max_commands" value="5" min="1" max="100" style="width:120px;">
    </div>
    <button class="btn" type="submit" name="action" value="run_worker_now">Ejecutar worker ahora</button>
  </form>
</div>

<div class="card" style="overflow:auto;">
  <h3 style="margin-top:0;">Progreso por ejecucion</h3>
  <table class="table" style="width:100%;font-size:14px;">
    <thead>
      <tr>
        <th>Run</th>
        <th>Campana</th>
        <th>Estado</th>
        <th>Progreso</th>
        <th>Destinatarios</th>
        <th>Enviados</th>
        <th>Fallidos</th>
        <th>Pendientes</th>
        <th>Acciones</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($state['run_progress'])): ?>
        <tr><td colspan="9" class="muted">Sin ejecuciones registradas.</td></tr>
      <?php else: ?>
        <?php foreach ($state['run_progress'] as $r): ?>
          <tr>
            <td>#<?php echo (int)$r['run_id']; ?></td>
            <td>
              <a class="btn secondary" href="comunicacion_historial.php?campaign_id=<?php echo (int)$r['campaign_id']; ?>"><?php echo e($r['campaign_name']); ?></a>
            </td>
            <td><?php echo e($r['status']); ?></td>
            <td><?php echo (int)$r['progress_pct']; ?>%</td>
            <td><?php echo (int)$r['resolved_recipients']; ?></td>
            <td><?php echo (int)$r['accepted_count']; ?></td>
            <td><?php echo (int)$r['failed_count']; ?></td>
            <td><?php echo (int)$r['pending_count']; ?></td>
            <td>
              <div style="display:flex;gap:6px;flex-wrap:wrap;">
                <a class="btn secondary" href="comunicacion_historial.php?run_id=<?php echo (int)$r['run_id']; ?>">Ver detalle</a>
                <form method="post" style="display:inline;">
                  <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
                  <input type="hidden" name="run_id" value="<?php echo (int)$r['run_id']; ?>">
                  <button class="btn secondary" type="submit" name="action" value="retry_run">Reintentar</button>
                </form>
                <form method="post" style="display:inline;">
                  <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
                  <input type="hidden" name="run_id" value="<?php echo (int)$r['run_id']; ?>">
                  <button class="btn secondary" type="submit" name="action" value="resume_run">Reanudar</button>
                </form>
                <form method="post" style="display:inline;">
                  <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
                  <input type="hidden" name="run_id" value="<?php echo (int)$r['run_id']; ?>">
                  <button class="btn secondary" type="submit" name="action" value="cancel_run">Cancelar</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<div class="card" style="overflow:auto;">
  <h3 style="margin-top:0;">Destinatarios por estado (global)</h3>
  <table class="table" style="width:100%;font-size:14px;">
    <thead><tr><th>Estado</th><th>Cantidad</th></tr></thead>
    <tbody>
      <?php if (empty($state['recipient_by_status'])): ?>
        <tr><td colspan="2" class="muted">Sin destinatarios procesados todavia.</td></tr>
      <?php else: ?>
        <?php foreach ($state['recipient_by_status'] as $row): ?>
          <tr>
            <td><?php echo e($row['status']); ?></td>
            <td><?php echo (int)$row['count']; ?></td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<div class="card" style="overflow:auto;">
  <h3 style="margin-top:0;">Comandos pendientes/recientes del motor</h3>
  <table class="table" style="width:100%;font-size:14px;">
    <thead>
      <tr><th>Comando</th><th>Campana</th><th>Estado cmd</th><th>Estado campana</th><th>Creado</th><th>Actualizado</th><th>Error</th></tr>
    </thead>
    <tbody>
      <?php if (empty($pendingCommands)): ?>
        <tr><td colspan="7" class="muted">No hay comandos relevantes.</td></tr>
      <?php else: ?>
        <?php foreach ($pendingCommands as $cmd): ?>
          <tr>
            <td>#<?php echo (int)$cmd['id']; ?></td>
            <td>
              <?php echo e($cmd['campaign_name']); ?>
              <div style="margin-top:4px;">
                <form method="post" style="display:inline;">
                  <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
                  <input type="hidden" name="campaign_id" value="<?php echo (int)$cmd['campaign_id']; ?>">
                  <button class="btn secondary" type="submit" name="action" value="requeue_campaign">Reencolar campana</button>
                </form>
              </div>
            </td>
            <td><?php echo e($cmd['status']); ?></td>
            <td><?php echo e($cmd['campaign_status']); ?></td>
            <td><?php echo e($cmd['created_at']); ?></td>
            <td><?php echo e($cmd['updated_at']); ?></td>
            <td><?php echo e((string)$cmd['error_text']); ?></td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<div class="card" style="overflow:auto;">
  <h3 style="margin-top:0;">Campanas por estado</h3>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px;">
    <div>
      <div class="muted" style="font-weight:700;">En cola (draft/scheduled)</div>
      <?php if (empty($queuedCampaigns)): ?>
        <div class="muted">Sin campanas en cola.</div>
      <?php else: ?>
        <?php foreach ($queuedCampaigns as $c): ?>
          <div style="padding:8px 0;border-bottom:1px solid var(--line);">
            <a href="comunicacion_historial.php?campaign_id=<?php echo (int)$c['id']; ?>"><?php echo e($c['name']); ?></a>
            <div class="muted" style="font-size:12px;"><?php echo e($c['status']); ?> · <?php echo e($c['updated_at']); ?></div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <div>
      <div class="muted" style="font-weight:700;">Ejecutandose</div>
      <?php if (empty($runningCampaigns)): ?>
        <div class="muted">Sin campanas en ejecucion.</div>
      <?php else: ?>
        <?php foreach ($runningCampaigns as $c): ?>
          <div style="padding:8px 0;border-bottom:1px solid var(--line);">
            <a href="comunicacion_historial.php?campaign_id=<?php echo (int)$c['id']; ?>"><?php echo e($c['name']); ?></a>
            <div class="muted" style="font-size:12px;"><?php echo e($c['status']); ?> · <?php echo e($c['updated_at']); ?></div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <div>
      <div class="muted" style="font-weight:700;">Finalizadas</div>
      <?php if (empty($finishedCampaigns)): ?>
        <div class="muted">Sin campanas finalizadas.</div>
      <?php else: ?>
        <?php foreach ($finishedCampaigns as $c): ?>
          <div style="padding:8px 0;border-bottom:1px solid var(--line);">
            <a href="comunicacion_historial.php?campaign_id=<?php echo (int)$c['id']; ?>"><?php echo e($c['name']); ?></a>
            <div class="muted" style="font-size:12px;"><?php echo e($c['status']); ?> · <?php echo e($c['updated_at']); ?></div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <div>
      <div class="muted" style="font-weight:700;">Fallidas</div>
      <?php if (empty($failedCampaigns)): ?>
        <div class="muted">Sin campanas fallidas.</div>
      <?php else: ?>
        <?php foreach ($failedCampaigns as $c): ?>
          <div style="padding:8px 0;border-bottom:1px solid var(--line);">
            <a href="comunicacion_historial.php?campaign_id=<?php echo (int)$c['id']; ?>"><?php echo e($c['name']); ?></a>
            <div class="muted" style="font-size:12px;"><?php echo e($c['status']); ?> · <?php echo e($c['updated_at']); ?></div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php include __DIR__ . '/inc/layout_bottom.php'; ?>
