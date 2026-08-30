<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/communication_ops.php';
require_once __DIR__ . '/inc/communication_delivery_feedback.php';

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

$testRenderResult = null;
$testAudienceResult = null;
$testExecSimResult = null;
$testTransportResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $provided = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
    if (function_exists('tickex_csrf_verify') && !tickex_csrf_verify($provided)) {
        $flashErr = 'CSRF invalido. Recarga la pagina e intenta nuevamente.';
    } else {
        $action = isset($_POST['action']) ? (string)$_POST['action'] : '';

        if ($action === 'run_integrity_checks') {
            $checks = communication_ops_integrity_checks($pdo, $organizationId, $adminId, $isSuper);
            if (!empty($checks['ok'])) {
                $flashOk = 'Integridad verificada: sin inconsistencias detectadas.';
            } else {
                $flashErr = 'Integridad con alertas: revisa los checks debajo.';
            }
        }

        if ($action === 'test_render') {
            $templateId = isset($_POST['template_id']) ? (int)$_POST['template_id'] : 0;
            $sampleJson = isset($_POST['sample_json']) ? (string)$_POST['sample_json'] : '';
            $testRenderResult = communication_ops_test_render_template($pdo, $organizationId, $adminId, $isSuper, $templateId, $sampleJson);
            if (empty($testRenderResult['ok'])) {
                $flashErr = isset($testRenderResult['error']) ? (string)$testRenderResult['error'] : 'Error en test de render.';
            }
        }

        if ($action === 'test_audience') {
            $audienceId = isset($_POST['audience_id']) ? (int)$_POST['audience_id'] : 0;
            $limit = isset($_POST['audience_limit']) ? (int)$_POST['audience_limit'] : 20;
            $testAudienceResult = communication_ops_test_audience_resolution($pdo, $organizationId, $adminId, $isSuper, $audienceId, $limit);
            if (empty($testAudienceResult['ok'])) {
                $flashErr = isset($testAudienceResult['error']) ? (string)$testAudienceResult['error'] : 'Error en test de audiencia.';
            }
        }

        if ($action === 'test_execution_simulation') {
            $campaignId = isset($_POST['campaign_id']) ? (int)$_POST['campaign_id'] : 0;
            $limit = isset($_POST['sim_limit']) ? (int)$_POST['sim_limit'] : 10;
            $testExecSimResult = communication_ops_test_execution_simulation($pdo, $organizationId, $adminId, $isSuper, $campaignId, $limit);
            if (empty($testExecSimResult['ok'])) {
                $flashErr = isset($testExecSimResult['error']) ? (string)$testExecSimResult['error'] : 'Error en simulacion de ejecucion.';
            }
        }

        if ($action === 'test_transport_simulation') {
            $campaignId = isset($_POST['campaign_id']) ? (int)$_POST['campaign_id'] : 0;
            $runId = isset($_POST['run_id']) ? (int)$_POST['run_id'] : 0;
            $toEmail = isset($_POST['to_email']) ? (string)$_POST['to_email'] : '';
            $subject = isset($_POST['subject']) ? (string)$_POST['subject'] : '';
            $testTransportResult = communication_ops_test_transport_simulation($pdo, $organizationId, $campaignId, $runId, $toEmail, $subject);
            if (empty($testTransportResult['ok'])) {
                $flashErr = isset($testTransportResult['error']) ? (string)$testTransportResult['error'] : 'Error en simulacion de transporte.';
            }
        }
    }
}

$health = communication_ops_health_check($pdo, $organizationId, $adminId, $isSuper);
$checks = communication_ops_integrity_checks($pdo, $organizationId, $adminId, $isSuper);
$logs = communication_ops_fetch_latest_logs($pdo, $organizationId, 120);
$templates = communication_campaigns_fetch_templates($pdo, $organizationId, $adminId, $isSuper);
$audiences = communication_campaigns_fetch_audiences($pdo, $organizationId, $adminId, $isSuper);
$campaigns = communication_ops_fetch_campaigns($pdo, $organizationId, $adminId, $isSuper);
$deliveryMetrics = communication_delivery_feedback_metrics($pdo, 30, $adminId, $isSuper);

$title = 'Comunicacion - Health Check';
include __DIR__ . '/inc/layout_top.php';
?>

<div class="card" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
  <a class="btn secondary" href="panel_admin.php">Volver</a>
  <div>
    <div class="muted" style="font-size:12px;letter-spacing:.08em;text-transform:uppercase;">📣 Comunicacion</div>
    <h2 style="margin:0;">Health Check Tecnico</h2>
  </div>
  <span class="muted">Operacion, integridad, logging y pruebas controladas.</span>
</div>

<div class="card">
  <h3 style="margin-top:0;">Entrega real de emails (ultimos 30 dias)</h3>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;">
    <div><div class="muted">Entregados por Exim</div><strong><?php echo (int)$deliveryMetrics['delivered']; ?></strong></div>
    <div><div class="muted">Demoras registradas</div><strong><?php echo (int)$deliveryMetrics['deferred']; ?></strong></div>
    <div><div class="muted">Rebotes</div><strong><?php echo (int)$deliveryMetrics['bounced']; ?></strong></div>
    <div><div class="muted">Casillas inexistentes detectadas</div><strong><?php echo (int)$deliveryMetrics['hard_bounces']; ?></strong></div>
  </div>
  <p class="muted" style="margin-bottom:0;">Los rebotes se muestran para revision. Esta version no bloquea contactos automaticamente.</p>
</div>

<div class="card" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
  <a class="btn secondary" href="superadmin_emails_db.php">👥 Contactos</a>
  <a class="btn secondary" href="comunicacion_audiencias.php">Audiencias</a>
  <a class="btn secondary" href="comunicacion_newsletter.php">Newsletter</a>
  <a class="btn secondary" href="comunicacion_plantillas.php">Plantillas</a>
  <a class="btn secondary" href="comunicacion_campanas.php">Campanas</a>
  <a class="btn secondary" href="comunicacion_estado_motor.php">Estado Motor</a>
  <a class="btn secondary" href="comunicacion_historial.php">Historial</a>
  <a class="btn" href="comunicacion_healthcheck.php">Health Check</a>
</div>

<?php if ($flashOk !== ''): ?>
  <div class="flash ok"><?php echo e($flashOk); ?></div>
<?php endif; ?>
<?php if ($flashErr !== ''): ?>
  <div class="flash err"><?php echo e($flashErr); ?></div>
<?php endif; ?>

<div class="card" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:10px;">
  <div><div class="muted">Estado motor</div><strong><?php echo e($health['engine_status']); ?></strong></div>
  <div><div class="muted">Estado transporte</div><strong><?php echo e($health['transport_status']); ?></strong></div>
  <div><div class="muted">Proveedor configurado</div><strong><?php echo e($health['provider_name']); ?></strong></div>
  <div><div class="muted">Cola pendiente</div><strong><?php echo (int)$health['queue_pending']; ?></strong></div>
  <div><div class="muted">Ultimo procesamiento</div><strong><?php echo e((string)$health['last_processing_at']); ?></strong></div>
  <div><div class="muted">Ultimo error</div><strong><?php echo e((string)$health['last_error']); ?></strong></div>
  <div><div class="muted">Version modulo</div><strong><?php echo e($health['module_version']); ?></strong></div>
</div>

<div class="card" style="overflow:auto;">
  <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap;">
    <h3 style="margin:0;">Verificaciones de integridad</h3>
    <form method="post" style="display:inline;">
      <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
      <button class="btn secondary" type="submit" name="action" value="run_integrity_checks">Ejecutar checks</button>
    </form>
  </div>
  <table class="table" style="width:100%;font-size:14px;margin-top:10px;">
    <thead><tr><th>Check</th><th>Resultado</th><th>Cantidad</th></tr></thead>
    <tbody>
      <?php foreach ($checks['checks'] as $ch): ?>
        <tr>
          <td><?php echo e($ch['name']); ?></td>
          <td><?php echo !empty($ch['ok']) ? 'OK' : 'ALERTA'; ?></td>
          <td><?php echo (int)$ch['count']; ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="card" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:14px;">
  <div>
    <h3 style="margin-top:0;">Prueba: Render de plantillas</h3>
    <form method="post">
      <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
      <label>Plantilla</label>
      <select name="template_id" required>
        <option value="">Elegir</option>
        <?php foreach ($templates as $t): ?>
          <option value="<?php echo (int)$t['id']; ?>"><?php echo e($t['name']); ?></option>
        <?php endforeach; ?>
      </select>
      <label>Sample JSON (opcional)</label>
      <textarea name="sample_json" style="min-height:90px;" placeholder='{"nombre":"Prueba","evento":"Demo"}'></textarea>
      <button class="btn secondary" type="submit" name="action" value="test_render">Probar render</button>
    </form>
    <?php if (is_array($testRenderResult) && !empty($testRenderResult['ok'])): ?>
      <div class="card" style="margin:10px 0 0 0;">
        <div><strong>Asunto:</strong> <?php echo e($testRenderResult['preview']['subject']); ?></div>
        <div class="muted" style="margin-top:6px;">Variables usadas: <?php echo e(implode(', ', $testRenderResult['preview']['used_variables'])); ?></div>
      </div>
    <?php endif; ?>
  </div>

  <div>
    <h3 style="margin-top:0;">Prueba: Resolucion de audiencias</h3>
    <form method="post">
      <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
      <label>Audiencia</label>
      <select name="audience_id" required>
        <option value="">Elegir</option>
        <?php foreach ($audiences as $a): ?>
          <option value="<?php echo (int)$a['id']; ?>"><?php echo e($a['name']); ?></option>
        <?php endforeach; ?>
      </select>
      <label>Limite sample</label>
      <input type="number" name="audience_limit" value="20" min="1" max="200">
      <button class="btn secondary" type="submit" name="action" value="test_audience">Probar audiencia</button>
    </form>
    <?php if (is_array($testAudienceResult) && !empty($testAudienceResult['ok'])): ?>
      <div class="card" style="margin:10px 0 0 0;">
        <div><strong>Total:</strong> <?php echo (int)$testAudienceResult['count']; ?></div>
        <div class="muted">Sample cargado: <?php echo (int)count($testAudienceResult['sample']); ?></div>
      </div>
    <?php endif; ?>
  </div>

  <div>
    <h3 style="margin-top:0;">Prueba: Simulacion de ejecucion</h3>
    <form method="post">
      <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
      <label>Campana</label>
      <select name="campaign_id" required>
        <option value="">Elegir</option>
        <?php foreach ($campaigns as $c): ?>
          <option value="<?php echo (int)$c['id']; ?>"><?php echo e($c['name']); ?></option>
        <?php endforeach; ?>
      </select>
      <label>Limite simulacion</label>
      <input type="number" name="sim_limit" value="10" min="1" max="100">
      <button class="btn secondary" type="submit" name="action" value="test_execution_simulation">Simular ejecucion</button>
    </form>
    <?php if (is_array($testExecSimResult) && !empty($testExecSimResult['ok'])): ?>
      <div class="card" style="margin:10px 0 0 0;">
        <div><strong>Filtrados:</strong> <?php echo (int)$testExecSimResult['filtered_recipients']; ?></div>
        <div class="muted">Simulados: <?php echo (int)$testExecSimResult['simulated_recipients']; ?></div>
      </div>
    <?php endif; ?>
  </div>

  <div>
    <h3 style="margin-top:0;">Prueba: Simulacion de transporte</h3>
    <form method="post">
      <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
      <label>Email destino</label>
      <input type="email" name="to_email" required placeholder="test@dominio.com">
      <label>Subject</label>
      <input type="text" name="subject" value="Prueba simulada">
      <label>Campaign ID (opcional)</label>
      <input type="number" name="campaign_id" value="0" min="0">
      <label>Run ID (opcional)</label>
      <input type="number" name="run_id" value="0" min="0">
      <button class="btn secondary" type="submit" name="action" value="test_transport_simulation">Simular transporte</button>
    </form>
    <?php if (is_array($testTransportResult) && !empty($testTransportResult['ok'])): ?>
      <div class="card" style="margin:10px 0 0 0;">
        <div><strong>Estado:</strong> <?php echo e($testTransportResult['result']['status']); ?></div>
        <div class="muted">Codigo: <?php echo e($testTransportResult['result']['response_code']); ?> · Proveedor: <?php echo e($testTransportResult['result']['provider_name']); ?></div>
      </div>
    <?php endif; ?>
  </div>
</div>

<div class="card" style="overflow:auto;">
  <h3 style="margin-top:0;">Logging unificado (motor/transporte/campanas/workers)</h3>
  <table class="table" style="width:100%;font-size:13px;">
    <thead>
      <tr>
        <th>Fecha</th>
        <th>Componente</th>
        <th>Nivel</th>
        <th>Evento</th>
        <th>Mensaje</th>
        <th>Campana</th>
        <th>Run</th>
        <th>Comando</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($logs)): ?>
        <tr><td colspan="8" class="muted">Sin logs todavia.</td></tr>
      <?php else: ?>
        <?php foreach ($logs as $l): ?>
          <tr>
            <td><?php echo e($l['created_at']); ?></td>
            <td><?php echo e($l['component']); ?></td>
            <td><?php echo e($l['level']); ?></td>
            <td><?php echo e($l['event_name']); ?></td>
            <td style="max-width:420px;word-break:break-word;"><?php echo e($l['message']); ?></td>
            <td><?php echo (int)$l['campaign_id']; ?></td>
            <td><?php echo (int)$l['run_id']; ?></td>
            <td><?php echo (int)$l['command_id']; ?></td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<?php include __DIR__ . '/inc/layout_bottom.php'; ?>
