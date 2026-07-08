<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/communication_contacts.php';
require_once __DIR__ . '/inc/communication_templates.php';
require_once __DIR__ . '/inc/communication_template_renderer.php';
require_once __DIR__ . '/inc/communication_campaigns.php';
require_once __DIR__ . '/inc/communication_execution_engine.php';
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
$csrf = function_exists('tickex_csrf_token') ? (string)tickex_csrf_token() : '';
$organizationId = 1;
$adminId = 0;
if (isset($_SESSION['admin_id'])) $adminId = (int)$_SESSION['admin_id'];
elseif (isset($_SESSION['user_id'])) $adminId = (int)$_SESSION['user_id'];
elseif (isset($_SESSION['usuario_id'])) $adminId = (int)$_SESSION['usuario_id'];

$contactScope = array(
    'is_super' => $isSuper,
    'admin_id' => $adminId,
);

$flashOk = '';
$flashErr = '';
$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$fStatus = isset($_GET['f_status']) ? trim((string)$_GET['f_status']) : '';
$editId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$previewId = isset($_GET['preview_id']) ? (int)$_GET['preview_id'] : 0;

$form = array(
    'id' => 0,
    'name' => '',
    'slug' => '',
    'description' => '',
    'status' => 'draft',
    'audience_id' => 0,
    'template_id' => 0,
    'subject_override' => '',
    'notes_internal' => '',
);

$estimatedRecipients = null;
$preview = null;
$previewTemplateName = '';

try {
    communication_templates_ensure_schema($pdo);
    communication_campaigns_ensure_schema($pdo);
  communication_execution_ensure_schema($pdo);
  communication_transport_ensure_schema($pdo);
  communication_ops_ensure_schema($pdo);
} catch (Exception $e) {
    if ($flashErr === '') {
        $flashErr = 'No se pudo preparar campanas: ' . $e->getMessage();
    }
}

$audienceOptions = array();
$templateOptions = array();
if ($flashErr === '') {
    try {
        $audienceOptions = communication_campaigns_fetch_audiences($pdo, $organizationId, $adminId, $isSuper);
        $templateOptions = communication_campaigns_fetch_templates($pdo, $organizationId, $adminId, $isSuper);
    } catch (Exception $e) {
        if ($flashErr === '') {
            $flashErr = 'No se pudieron cargar audiencias/plantillas: ' . $e->getMessage();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $flashErr === '') {
    $provided = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
    if (function_exists('tickex_csrf_verify') && !tickex_csrf_verify($provided)) {
        $flashErr = 'CSRF invalido. Recarga la pagina e intenta nuevamente.';
    } else {
        $action = isset($_POST['action']) ? (string)$_POST['action'] : '';

        if ($action === 'execute_now') {
          $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
          if ($id > 0) {
            $enqueue = communication_execution_enqueue_campaign($pdo, $organizationId, $id, $adminId, $isSuper, array());
            if (!empty($enqueue['ok'])) {
              $flashOk = 'Campana encolada para ejecucion. Comando #' . (int)$enqueue['command_id'];
              communication_ops_log($pdo, $organizationId, 'campaigns', 'campaign.execute_requested', 'info', 'Solicitud de ejecucion desde UI de campanas.', array(
                'campaign_id' => $id,
                'command_id' => (int)$enqueue['command_id'],
              ), 'campaign.execute_requested|' . (int)$id . '|' . (int)$enqueue['command_id']);
            } else {
              $flashErr = isset($enqueue['error']) ? (string)$enqueue['error'] : 'No se pudo encolar la campana.';
            }
          }
        }

        if ($action === 'cancel_run') {
          $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
          if ($id > 0) {
            try {
              $scopeSql = communication_campaigns_scope_sql($isSuper);
              $scopeParams = communication_campaigns_scope_params($organizationId, $adminId, $isSuper);
              $stCancel = $pdo->prepare('UPDATE communication_campaigns SET status = :st, cancelled_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND ' . $scopeSql . ' AND status = :sending');
              $stCancel->execute(array(':st' => 'cancelled', ':id' => $id, ':sending' => 'sending') + $scopeParams);
              if ($stCancel->rowCount() > 0) {
                $flashOk = 'Campana marcada como cancelada.';
                communication_ops_log($pdo, $organizationId, 'campaigns', 'campaign.cancel_requested', 'warning', 'Campana cancelada desde UI de campanas.', array(
                  'campaign_id' => $id,
                  'requested_by_admin_id' => $adminId,
                ), 'campaign.cancel_requested|' . (int)$id . '|' . gmdate('YmdHis'));
              } else {
                $flashErr = 'No se pudo cancelar (verifica que este en sending).';
              }
            } catch (Exception $e) {
              $flashErr = 'No se pudo cancelar la campana: ' . $e->getMessage();
            }
          }
        }

        if ($action === 'save' || $action === 'estimate_form' || $action === 'preview_form') {
            $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            $name = trim((string)(isset($_POST['name']) ? $_POST['name'] : ''));
            $slugInput = trim((string)(isset($_POST['slug']) ? $_POST['slug'] : ''));
            $description = trim((string)(isset($_POST['description']) ? $_POST['description'] : ''));
            $status = communication_campaigns_normalize_status(isset($_POST['status']) ? $_POST['status'] : 'draft');
            $audienceId = isset($_POST['audience_id']) ? (int)$_POST['audience_id'] : 0;
            $templateId = isset($_POST['template_id']) ? (int)$_POST['template_id'] : 0;
            $subjectOverride = trim((string)(isset($_POST['subject_override']) ? $_POST['subject_override'] : ''));
            $notesInternal = trim((string)(isset($_POST['notes_internal']) ? $_POST['notes_internal'] : ''));

            // En este commit solo operamos draft/archived
            if (!in_array($status, communication_campaigns_editable_statuses(), true)) {
                $status = 'draft';
            }

            $form['id'] = $id;
            $form['name'] = $name;
            $form['slug'] = $slugInput;
            $form['description'] = $description;
            $form['status'] = $status;
            $form['audience_id'] = $audienceId;
            $form['template_id'] = $templateId;
            $form['subject_override'] = $subjectOverride;
            $form['notes_internal'] = $notesInternal;

            $audienceRow = communication_campaigns_find_audience($pdo, $organizationId, $adminId, $isSuper, $audienceId);
            $templateRow = communication_campaigns_find_template($pdo, $organizationId, $adminId, $isSuper, $templateId);

            if (!$audienceRow) {
                $flashErr = 'Selecciona una audiencia valida.';
            } elseif (!$templateRow) {
                $flashErr = 'Selecciona una plantilla valida.';
            }

            if (empty($flashErr) && ($action === 'estimate_form' || $action === 'preview_form' || $action === 'save')) {
                $filters = communication_contacts_filters_from_json(isset($audienceRow['filters_json']) ? $audienceRow['filters_json'] : '');
                $estimatedRecipients = communication_contacts_count($pdo, $filters, $contactScope);
            }

            if (empty($flashErr) && ($action === 'preview_form' || $action === 'save')) {
                $subjectBase = ($subjectOverride !== '') ? $subjectOverride : (string)$templateRow['subject_template'];
                $preview = communication_template_renderer_preview(
                    $subjectBase,
                    isset($templateRow['body_html_template']) ? $templateRow['body_html_template'] : '',
                    isset($templateRow['body_text_template']) ? $templateRow['body_text_template'] : '',
                    isset($templateRow['sample_data_json']) ? $templateRow['sample_data_json'] : ''
                );
                $previewTemplateName = isset($templateRow['name']) ? (string)$templateRow['name'] : '';
            }

            if ($action === 'save' && empty($flashErr)) {
                if ($name === '') {
                    $flashErr = 'El nombre interno es obligatorio.';
                }

                if (empty($flashErr)) {
                    $slugBase = ($slugInput !== '') ? $slugInput : $name;
                    $slug = communication_campaigns_unique_slug($pdo, $organizationId, $slugBase, $id);

                    try {
                        if ($id > 0) {
                            $scopeSql = communication_campaigns_scope_sql($isSuper);
                            $scopeParams = communication_campaigns_scope_params($organizationId, $adminId, $isSuper);
                            $stCheck = $pdo->prepare('SELECT id FROM communication_campaigns WHERE id = :id AND ' . $scopeSql . ' LIMIT 1');
                            $stCheck->execute(array(':id' => $id) + $scopeParams);
                            if (!$stCheck->fetch(PDO::FETCH_ASSOC)) {
                                $flashErr = 'No se encontro la campana para editar.';
                            } else {
                                $st = $pdo->prepare('UPDATE communication_campaigns SET name = :n, slug = :s, description = :d, status = :st, audience_id = :aud, template_id = :tpl, subject_override = :subj, notes_internal = :notes, updated_at = datetime(\'now\') WHERE id = :id');
                                $st->execute(array(
                                    ':n' => $name,
                                    ':s' => $slug,
                                    ':d' => $description,
                                    ':st' => $status,
                                    ':aud' => $audienceId,
                                    ':tpl' => $templateId,
                                    ':subj' => ($subjectOverride !== '' ? $subjectOverride : null),
                                    ':notes' => ($notesInternal !== '' ? $notesInternal : null),
                                    ':id' => $id,
                                ));
                                $flashOk = 'Campana actualizada.';
                                $editId = $id;
                            }
                        } else {
                            $st = $pdo->prepare('INSERT INTO communication_campaigns (organization_id, created_by_admin_id, name, slug, description, status, audience_id, template_id, subject_override, notes_internal, created_at, updated_at) VALUES (:org, :aid, :n, :s, :d, :st, :aud, :tpl, :subj, :notes, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)');
                            $st->execute(array(
                                ':org' => $organizationId,
                                ':aid' => $adminId,
                                ':n' => $name,
                                ':s' => $slug,
                                ':d' => ($description !== '' ? $description : null),
                                ':st' => $status,
                                ':aud' => $audienceId,
                                ':tpl' => $templateId,
                                ':subj' => ($subjectOverride !== '' ? $subjectOverride : null),
                                ':notes' => ($notesInternal !== '' ? $notesInternal : null),
                            ));
                            $editId = (int)$pdo->lastInsertId();
                            $flashOk = 'Campana creada.';
                        }
                    } catch (Exception $e) {
                        $flashErr = 'No se pudo guardar la campana: ' . $e->getMessage();
                    }
                }
            }
        }

        if ($action === 'duplicate') {
            $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            if ($id > 0) {
                try {
                    $scopeSql = communication_campaigns_scope_sql($isSuper);
                    $scopeParams = communication_campaigns_scope_params($organizationId, $adminId, $isSuper);
                    $stGet = $pdo->prepare('SELECT * FROM communication_campaigns WHERE id = :id AND ' . $scopeSql . ' LIMIT 1');
                    $stGet->execute(array(':id' => $id) + $scopeParams);
                    $row = $stGet->fetch(PDO::FETCH_ASSOC);

                    if (!$row) {
                        $flashErr = 'No se encontro la campana para duplicar.';
                    } else {
                        $slug = communication_campaigns_unique_slug($pdo, $organizationId, (string)$row['slug'] . '-copia', 0);
                        $stIns = $pdo->prepare('INSERT INTO communication_campaigns (organization_id, created_by_admin_id, name, slug, description, status, audience_id, template_id, subject_override, notes_internal, created_at, updated_at) VALUES (:org, :aid, :n, :s, :d, :st, :aud, :tpl, :subj, :notes, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)');
                        $stIns->execute(array(
                            ':org' => $organizationId,
                            ':aid' => $adminId,
                            ':n' => (string)$row['name'] . ' (copia)',
                            ':s' => $slug,
                            ':d' => isset($row['description']) ? $row['description'] : null,
                            ':st' => 'draft',
                            ':aud' => isset($row['audience_id']) ? (int)$row['audience_id'] : null,
                            ':tpl' => isset($row['template_id']) ? (int)$row['template_id'] : null,
                            ':subj' => isset($row['subject_override']) ? $row['subject_override'] : null,
                            ':notes' => isset($row['notes_internal']) ? $row['notes_internal'] : null,
                        ));
                        $flashOk = 'Campana duplicada.';
                    }
                } catch (Exception $e) {
                    $flashErr = 'No se pudo duplicar la campana: ' . $e->getMessage();
                }
            }
        }

        if ($action === 'archive' || $action === 'activate') {
            $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            if ($id > 0) {
                try {
                    $scopeSql = communication_campaigns_scope_sql($isSuper);
                    $scopeParams = communication_campaigns_scope_params($organizationId, $adminId, $isSuper);
                    $newStatus = ($action === 'activate') ? 'draft' : 'archived';
                    $st = $pdo->prepare('UPDATE communication_campaigns SET status = :st, updated_at = datetime(\'now\') WHERE id = :id AND ' . $scopeSql);
                    $st->execute(array(':st' => $newStatus, ':id' => $id) + $scopeParams);
                    if ($st->rowCount() > 0) {
                        $flashOk = ($newStatus === 'draft') ? 'Campana reactivada (draft).' : 'Campana archivada.';
                    } else {
                        $flashErr = 'No se encontro la campana para actualizar estado.';
                    }
                } catch (Exception $e) {
                    $flashErr = 'No se pudo actualizar estado de campana: ' . $e->getMessage();
                }
            }
        }

        if ($action === 'estimate_saved' || $action === 'preview_saved') {
            $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            if ($id > 0) {
                try {
                    $scopeSql = communication_campaigns_scope_sql($isSuper);
                    $scopeParams = communication_campaigns_scope_params($organizationId, $adminId, $isSuper);
                    $st = $pdo->prepare('SELECT * FROM communication_campaigns WHERE id = :id AND ' . $scopeSql . ' LIMIT 1');
                    $st->execute(array(':id' => $id) + $scopeParams);
                    $campaign = $st->fetch(PDO::FETCH_ASSOC);
                    if (!$campaign) {
                        $flashErr = 'No se encontro la campana.';
                    } else {
                        $audienceRow = communication_campaigns_find_audience($pdo, $organizationId, $adminId, $isSuper, (int)$campaign['audience_id']);
                        $templateRow = communication_campaigns_find_template($pdo, $organizationId, $adminId, $isSuper, (int)$campaign['template_id']);
                        if (!$audienceRow || !$templateRow) {
                            $flashErr = 'La campana referencia audiencia o plantilla no accesible.';
                        } else {
                            $filters = communication_contacts_filters_from_json(isset($audienceRow['filters_json']) ? $audienceRow['filters_json'] : '');
                            $estimatedRecipients = communication_contacts_count($pdo, $filters, $contactScope);
                            $flashOk = 'Destinatarios estimados para "' . e($campaign['name']) . '": ' . (int)$estimatedRecipients;

                            if ($action === 'preview_saved') {
                                $subjectBase = !empty($campaign['subject_override']) ? (string)$campaign['subject_override'] : (string)$templateRow['subject_template'];
                                $preview = communication_template_renderer_preview(
                                    $subjectBase,
                                    isset($templateRow['body_html_template']) ? $templateRow['body_html_template'] : '',
                                    isset($templateRow['body_text_template']) ? $templateRow['body_text_template'] : '',
                                    isset($templateRow['sample_data_json']) ? $templateRow['sample_data_json'] : ''
                                );
                                $previewTemplateName = isset($templateRow['name']) ? (string)$templateRow['name'] : '';
                            }
                        }
                    }
                } catch (Exception $e) {
                    $flashErr = 'No se pudo procesar la campana: ' . $e->getMessage();
                }
            }
        }
    }
}

$scopeSql = communication_campaigns_scope_sql($isSuper);
$scopeParams = communication_campaigns_scope_params($organizationId, $adminId, $isSuper);
$listSql = 'SELECT c.*, a.name AS audience_name, t.name AS template_name, cr.id AS active_run_id, cr.status AS active_run_status, cr.processed_count AS active_processed_count, cr.resolved_recipients AS active_resolved_recipients FROM communication_campaigns c LEFT JOIN communication_audiences a ON a.id = c.audience_id LEFT JOIN communication_templates t ON t.id = c.template_id LEFT JOIN communication_campaign_runs cr ON cr.id = (SELECT r2.id FROM communication_campaign_runs r2 WHERE r2.campaign_id = c.id ORDER BY r2.id DESC LIMIT 1) WHERE ' . str_replace('organization_id', 'c.organization_id', $scopeSql);
if ($q !== '') {
    $listSql .= ' AND (c.name LIKE :q OR c.slug LIKE :q OR c.description LIKE :q OR c.notes_internal LIKE :q)';
    $scopeParams[':q'] = '%' . $q . '%';
}
if ($fStatus !== '') {
    $fStatusN = communication_campaigns_normalize_status($fStatus);
    $listSql .= ' AND c.status = :fs';
    $scopeParams[':fs'] = $fStatusN;
}
$listSql .= ' ORDER BY c.updated_at DESC, c.id DESC';
$stList = $pdo->prepare($listSql);
$stList->execute($scopeParams);
$rows = $stList->fetchAll(PDO::FETCH_ASSOC);

$editRow = null;
if ($editId > 0) {
    $scopeSqlE = communication_campaigns_scope_sql($isSuper);
    $scopeParamsE = communication_campaigns_scope_params($organizationId, $adminId, $isSuper);
    $stE = $pdo->prepare('SELECT * FROM communication_campaigns WHERE id = :id AND ' . $scopeSqlE . ' LIMIT 1');
    $stE->execute(array(':id' => $editId) + $scopeParamsE);
    $editRow = $stE->fetch(PDO::FETCH_ASSOC);
}

if ($editRow && $form['id'] === 0) {
    $form['id'] = (int)$editRow['id'];
    $form['name'] = (string)$editRow['name'];
    $form['slug'] = (string)$editRow['slug'];
    $form['description'] = (string)$editRow['description'];
    $form['status'] = (string)$editRow['status'];
    $form['audience_id'] = (int)$editRow['audience_id'];
    $form['template_id'] = (int)$editRow['template_id'];
    $form['subject_override'] = (string)$editRow['subject_override'];
    $form['notes_internal'] = (string)$editRow['notes_internal'];
}

$campaignStatuses = communication_campaigns_allowed_statuses();
$editableStatuses = communication_campaigns_editable_statuses();

$title = 'Comunicacion - Campanas';
include __DIR__ . '/inc/layout_top.php';
?>

<div class="card" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
  <a class="btn secondary" href="panel_admin.php">Volver</a>
  <div>
    <div class="muted" style="font-size:12px;letter-spacing:.08em;text-transform:uppercase;">📣 Comunicacion</div>
    <h2 style="margin:0;">Campanas</h2>
  </div>
  <span class="muted">Objeto de negocio independiente del envio.</span>
</div>

<div class="card" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
  <a class="btn secondary" href="superadmin_emails_db.php">👥 Contactos</a>
  <a class="btn secondary" href="comunicacion_audiencias.php">Audiencias</a>
  <a class="btn secondary" href="comunicacion_plantillas.php">Plantillas</a>
  <a class="btn" href="comunicacion_campanas.php">Campanas</a>
  <a class="btn secondary" href="comunicacion_estado_motor.php">Estado Motor</a>
  <a class="btn secondary" href="comunicacion_historial.php">Historial</a>
  <a class="btn secondary" href="comunicacion_healthcheck.php">Health Check</a>
</div>

<?php if ($flashOk !== ''): ?>
  <div class="flash ok"><?php echo $flashOk; ?></div>
<?php endif; ?>
<?php if ($flashErr !== ''): ?>
  <div class="flash err"><?php echo e($flashErr); ?></div>
<?php endif; ?>

<div class="card" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
  <form method="get" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
    <input name="q" placeholder="Buscar campana (nombre, slug, descripcion, notas)" value="<?php echo e($q); ?>" style="min-width:300px;">
    <select name="f_status">
      <option value="">Estado: Todos</option>
      <?php foreach ($campaignStatuses as $st): ?>
        <option value="<?php echo e($st); ?>" <?php echo $fStatus === $st ? 'selected' : ''; ?>><?php echo e($st); ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn secondary" type="submit">Filtrar</button>
    <?php if ($q !== '' || $fStatus !== ''): ?>
      <a class="btn secondary" href="comunicacion_campanas.php">Limpiar</a>
    <?php endif; ?>
  </form>
</div>

<div class="card" style="overflow:auto;">
  <table class="table" style="width:100%;font-size:14px;">
    <thead>
      <tr>
        <th>Nombre</th>
        <th>Estado</th>
        <th>Audiencia</th>
        <th>Plantilla</th>
        <th>Slug</th>
        <th>Actualizada</th>
        <th>Acciones</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($rows)): ?>
        <tr><td colspan="7" class="muted">No hay campanas.</td></tr>
      <?php else: ?>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td>
              <strong><?php echo e($r['name']); ?></strong>
              <?php if (!empty($r['description'])): ?>
                <div class="muted" style="font-size:12px;max-width:340px;word-break:break-word;"><?php echo e($r['description']); ?></div>
              <?php endif; ?>
            </td>
            <td><?php echo e($r['status']); ?></td>
            <td><?php echo e(!empty($r['audience_name']) ? $r['audience_name'] : '-'); ?></td>
            <td><?php echo e(!empty($r['template_name']) ? $r['template_name'] : '-'); ?></td>
            <td><?php echo e($r['slug']); ?></td>
            <td><?php echo e($r['updated_at']); ?></td>
            <td>
              <div style="display:flex;gap:6px;flex-wrap:wrap;">
                <a class="btn secondary" href="comunicacion_campanas.php?id=<?php echo (int)$r['id']; ?>">Editar</a>
                <a class="btn secondary" href="comunicacion_historial.php?campaign_id=<?php echo (int)$r['id']; ?>">Historial</a>
                <?php if (in_array((string)$r['status'], array('draft', 'failed', 'sent'), true)): ?>
                <form method="post" action="comunicacion_campanas.php" style="display:inline;">
                  <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
                  <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                  <button class="btn secondary" type="submit" name="action" value="execute_now">Ejecutar</button>
                </form>
                <?php endif; ?>
                <?php if ((string)$r['status'] === 'sending'): ?>
                <form method="post" action="comunicacion_campanas.php" style="display:inline;">
                  <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
                  <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                  <button class="btn secondary" type="submit" name="action" value="cancel_run">Cancelar</button>
                </form>
                <?php endif; ?>
                <form method="post" action="comunicacion_campanas.php" style="display:inline;">
                  <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
                  <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                  <button class="btn secondary" type="submit" name="action" value="estimate_saved">Estimar</button>
                </form>
                <form method="post" action="comunicacion_campanas.php" style="display:inline;">
                  <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
                  <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                  <button class="btn secondary" type="submit" name="action" value="preview_saved">Previsualizar</button>
                </form>
                <form method="post" action="comunicacion_campanas.php" style="display:inline;">
                  <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
                  <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                  <button class="btn secondary" type="submit" name="action" value="duplicate">Duplicar</button>
                </form>
                <form method="post" action="comunicacion_campanas.php" style="display:inline;">
                  <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
                  <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                  <?php if ((string)$r['status'] === 'archived'): ?>
                    <button class="btn secondary" type="submit" name="action" value="activate">Reactivar</button>
                  <?php else: ?>
                    <button class="btn secondary" type="submit" name="action" value="archive">Archivar</button>
                  <?php endif; ?>
                </form>
              </div>
              <?php if (!empty($r['active_run_id'])): ?>
                <div class="muted" style="font-size:12px;margin-top:4px;">
                  Run #<?php echo (int)$r['active_run_id']; ?> · <?php echo e($r['active_run_status']); ?> · <?php echo (int)$r['active_processed_count']; ?>/<?php echo (int)$r['active_resolved_recipients']; ?>
                </div>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<div class="card" style="max-width:1200px;margin:16px auto;">
  <h3 style="margin-top:0;"><?php echo ((int)$form['id'] > 0) ? 'Editar campana' : 'Nueva campana'; ?></h3>

  <form method="post" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px;">
    <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
    <input type="hidden" name="id" value="<?php echo (int)$form['id']; ?>">

    <div>
      <label>Nombre interno</label>
      <input name="name" value="<?php echo e($form['name']); ?>" required>
    </div>

    <div>
      <label>Slug</label>
      <input name="slug" value="<?php echo e($form['slug']); ?>" placeholder="se genera automaticamente si se deja vacio">
    </div>

    <div>
      <label>Estado</label>
      <select name="status">
        <?php foreach ($editableStatuses as $st): ?>
          <option value="<?php echo e($st); ?>" <?php echo $form['status'] === $st ? 'selected' : ''; ?>><?php echo e($st); ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div style="grid-column:1/-1;">
      <label>Descripcion (objetivo interno)</label>
      <input name="description" value="<?php echo e($form['description']); ?>" placeholder="Para que existe esta campana y que busca lograr">
    </div>

    <div>
      <label>Audiencia</label>
      <select name="audience_id" required>
        <option value="0">Elegir audiencia</option>
        <?php foreach ($audienceOptions as $a): ?>
          <option value="<?php echo (int)$a['id']; ?>" <?php echo ((int)$form['audience_id'] === (int)$a['id']) ? 'selected' : ''; ?>><?php echo e($a['name']); ?> (<?php echo e($a['status']); ?>)</option>
        <?php endforeach; ?>
      </select>
    </div>

    <div>
      <label>Plantilla</label>
      <select name="template_id" required>
        <option value="0">Elegir plantilla</option>
        <?php foreach ($templateOptions as $t): ?>
          <option value="<?php echo (int)$t['id']; ?>" <?php echo ((int)$form['template_id'] === (int)$t['id']) ? 'selected' : ''; ?>><?php echo e($t['name']); ?> (<?php echo e($t['template_type']); ?>)</option>
        <?php endforeach; ?>
      </select>
    </div>

    <div style="grid-column:1/-1;">
      <label>Asunto override (opcional)</label>
      <input name="subject_override" value="<?php echo e($form['subject_override']); ?>" placeholder="Si se completa, reemplaza el asunto de la plantilla para esta campana">
    </div>

    <div style="grid-column:1/-1;">
      <label>Notas internas (opcional)</label>
      <textarea name="notes_internal" style="min-height:120px;"><?php echo e($form['notes_internal']); ?></textarea>
    </div>

    <div style="grid-column:1/-1;display:flex;gap:8px;flex-wrap:wrap;">
      <button class="btn" type="submit" name="action" value="save">Guardar campana</button>
      <button class="btn secondary" type="submit" name="action" value="estimate_form">Estimar destinatarios</button>
      <button class="btn secondary" type="submit" name="action" value="preview_form">Previsualizar</button>
      <?php if ((int)$form['id'] > 0): ?>
        <button class="btn secondary" type="submit" name="action" value="execute_now">Encolar ejecucion</button>
      <?php endif; ?>
      <?php if ((int)$form['id'] > 0): ?>
        <a class="btn secondary" href="comunicacion_campanas.php">Nueva campana</a>
      <?php endif; ?>
    </div>
  </form>

  <?php if ($estimatedRecipients !== null): ?>
    <div class="card" style="margin-top:12px;">
      <strong>Destinatarios estimados:</strong> <?php echo (int)$estimatedRecipients; ?>
    </div>
  <?php endif; ?>
</div>

<?php if ($preview): ?>
  <div class="card" style="max-width:1200px;margin:16px auto;">
    <h3 style="margin-top:0;">Previsualizacion de campana</h3>
    <?php if ($previewTemplateName !== ''): ?>
      <div class="muted" style="margin-bottom:8px;">Plantilla base: <?php echo e($previewTemplateName); ?></div>
    <?php endif; ?>

    <div style="border:1px solid var(--line);border-radius:12px;overflow:hidden;">
      <div style="padding:10px 12px;background:var(--panel-2);border-bottom:1px solid var(--line);display:flex;gap:8px;align-items:center;">
        <strong>Asunto:</strong>
        <span><?php echo e($preview['subject']); ?></span>
      </div>
      <div style="padding:16px;background:#ffffff;color:#111;min-height:120px;">
        <?php if (trim((string)$preview['body_html']) !== ''): ?>
          <?php echo $preview['body_html']; ?>
        <?php else: ?>
          <pre style="white-space:pre-wrap;word-break:break-word;margin:0;"><?php echo e($preview['body_text']); ?></pre>
        <?php endif; ?>
      </div>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:12px;margin-top:12px;">
      <div class="card" style="margin:0;">
        <div class="muted" style="font-size:12px;letter-spacing:.08em;text-transform:uppercase;">Variables usadas</div>
        <div><?php echo e(!empty($preview['used_variables']) ? implode(', ', $preview['used_variables']) : '-'); ?></div>
      </div>
      <div class="card" style="margin:0;">
        <div class="muted" style="font-size:12px;letter-spacing:.08em;text-transform:uppercase;">Variables faltantes en sample</div>
        <div><?php echo e(!empty($preview['missing_variables']) ? implode(', ', $preview['missing_variables']) : '-'); ?></div>
      </div>
      <div class="card" style="margin:0;">
        <div class="muted" style="font-size:12px;letter-spacing:.08em;text-transform:uppercase;">Variables desconocidas</div>
        <div><?php echo e(!empty($preview['unknown_variables']) ? implode(', ', $preview['unknown_variables']) : '-'); ?></div>
      </div>
    </div>
  </div>
<?php endif; ?>

<?php include __DIR__ . '/inc/layout_bottom.php'; ?>
