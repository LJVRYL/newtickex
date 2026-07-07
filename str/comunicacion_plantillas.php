<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/communication_templates.php';
require_once __DIR__ . '/inc/communication_template_renderer.php';

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
$flashOk = '';
$flashErr = '';
$csrf = function_exists('tickex_csrf_token') ? (string)tickex_csrf_token() : '';
$organizationId = 1;
$adminId = 0;
if (isset($_SESSION['admin_id'])) $adminId = (int)$_SESSION['admin_id'];
elseif (isset($_SESSION['user_id'])) $adminId = (int)$_SESSION['user_id'];
elseif (isset($_SESSION['usuario_id'])) $adminId = (int)$_SESSION['usuario_id'];

$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$fType = isset($_GET['f_type']) ? trim((string)$_GET['f_type']) : '';
$fStatus = isset($_GET['f_status']) ? trim((string)$_GET['f_status']) : '';
$editId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$previewId = isset($_GET['preview_id']) ? (int)$_GET['preview_id'] : 0;

$form = array(
    'id' => 0,
    'name' => '',
    'slug' => '',
    'template_type' => 'general',
    'status' => 'active',
    'description' => '',
    'subject_template' => 'Hola {{nombre}}',
    'body_html_template' => '<p>Hola {{nombre}}</p><p>Te escribimos por {{evento}}.</p>',
    'body_text_template' => "Hola {{nombre}}\n\nTe escribimos por {{evento}}.",
    'sample_data_json' => json_encode(communication_variables_default_sample(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    'source_type' => 'custom',
    'is_system_locked' => 0,
);

$livePreview = null;

try {
    communication_templates_ensure_schema($pdo);
} catch (Exception $e) {
    $flashErr = 'No se pudo preparar plantillas: ' . $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $flashErr === '') {
    $provided = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
    if (function_exists('tickex_csrf_verify') && !tickex_csrf_verify($provided)) {
        $flashErr = 'CSRF invalido. Recarga la pagina e intenta nuevamente.';
    } else {
        $action = isset($_POST['action']) ? (string)$_POST['action'] : '';

        if ($action === 'save' || $action === 'preview_form') {
            $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            $name = trim((string)(isset($_POST['name']) ? $_POST['name'] : ''));
            $slugInput = trim((string)(isset($_POST['slug']) ? $_POST['slug'] : ''));
            $templateType = communication_templates_normalize_type(isset($_POST['template_type']) ? $_POST['template_type'] : 'general');
            $status = communication_templates_normalize_status(isset($_POST['status']) ? $_POST['status'] : 'active');
            $description = trim((string)(isset($_POST['description']) ? $_POST['description'] : ''));
            $subjectTemplate = trim((string)(isset($_POST['subject_template']) ? $_POST['subject_template'] : ''));
            $bodyHtmlTemplate = (string)(isset($_POST['body_html_template']) ? $_POST['body_html_template'] : '');
            $bodyTextTemplate = (string)(isset($_POST['body_text_template']) ? $_POST['body_text_template'] : '');
            $sampleRaw = (string)(isset($_POST['sample_data_json']) ? $_POST['sample_data_json'] : '');

            $form['id'] = $id;
            $form['name'] = $name;
            $form['slug'] = $slugInput;
            $form['template_type'] = $templateType;
            $form['status'] = $status;
            $form['description'] = $description;
            $form['subject_template'] = $subjectTemplate;
            $form['body_html_template'] = $bodyHtmlTemplate;
            $form['body_text_template'] = $bodyTextTemplate;
            $form['sample_data_json'] = $sampleRaw;

            $sampleJson = communication_templates_parse_sample_json($sampleRaw);
            if ($sampleJson === false) {
                $flashErr = 'sample_data_json debe ser un JSON valido (objeto clave-valor).';
            }

            $allVars = communication_variables_extract_from_template_parts($subjectTemplate, $bodyHtmlTemplate, $bodyTextTemplate);
            $unknownVars = communication_variables_unknown_keys($allVars);
            if (empty($flashErr) && !empty($unknownVars)) {
                $flashErr = 'Variables no registradas: ' . implode(', ', $unknownVars) . '. Revisa el catalogo de variables.';
            }

            $variablesSchemaJson = communication_variables_schema_json_from_keys($allVars);

            $livePreview = communication_template_renderer_preview(
                $subjectTemplate,
                $bodyHtmlTemplate,
                $bodyTextTemplate,
                ($sampleJson !== null && $sampleJson !== false) ? $sampleJson : ''
            );

            if ($action === 'save' && empty($flashErr)) {
                if ($name === '') {
                    $flashErr = 'El nombre es obligatorio.';
                }
                if ($subjectTemplate === '') {
                    $flashErr = 'El asunto es obligatorio.';
                }

                if (empty($flashErr)) {
                    $slugBase = ($slugInput !== '') ? $slugInput : $name;
                    $targetOrg = ($id > 0 && isset($_POST['editing_system']) && (int)$_POST['editing_system'] === 1) ? 0 : $organizationId;
                    $slug = communication_templates_unique_slug($pdo, $targetOrg, $slugBase, $id);

                    try {
                        if ($id > 0) {
                            $scopeSql = communication_templates_scope_sql($isSuper);
                            $scopeParams = communication_templates_scope_params($organizationId, $adminId, $isSuper);
                            $stCheck = $pdo->prepare('SELECT id, source_type, is_system_locked FROM communication_templates WHERE id = :id AND ' . $scopeSql . ' LIMIT 1');
                            $paramsCheck = array(':id' => $id) + $scopeParams;
                            $stCheck->execute($paramsCheck);
                            $current = $stCheck->fetch(PDO::FETCH_ASSOC);

                            if (!$current) {
                                $flashErr = 'No se encontro la plantilla para editar.';
                            } elseif ((int)$current['is_system_locked'] === 1) {
                                $flashErr = 'Las plantillas del sistema estan bloqueadas. Duplica para personalizar.';
                            } else {
                                $st = $pdo->prepare('UPDATE communication_templates SET name = :n, slug = :s, template_type = :tt, status = :st, description = :d, subject_template = :subj, body_html_template = :html, body_text_template = :txt, variables_schema_json = :vs, sample_data_json = :sd, updated_at = datetime(\'now\') WHERE id = :id');
                                $st->execute(array(
                                    ':n' => $name,
                                    ':s' => $slug,
                                    ':tt' => $templateType,
                                    ':st' => $status,
                                    ':d' => $description,
                                    ':subj' => $subjectTemplate,
                                    ':html' => $bodyHtmlTemplate,
                                    ':txt' => $bodyTextTemplate,
                                    ':vs' => $variablesSchemaJson,
                                    ':sd' => ($sampleJson === null ? null : $sampleJson),
                                    ':id' => $id,
                                ));
                                $flashOk = 'Plantilla actualizada.';
                                $editId = $id;
                            }
                        } else {
                            $st = $pdo->prepare('INSERT INTO communication_templates (organization_id, created_by_admin_id, source_type, parent_template_id, is_system_locked, template_type, name, slug, description, subject_template, body_html_template, body_text_template, variables_schema_json, sample_data_json, status, created_at, updated_at) VALUES (:org, :aid, :src, :parent, :locked, :tt, :n, :s, :d, :subj, :html, :txt, :vs, :sd, :st, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)');
                            $st->execute(array(
                                ':org' => $organizationId,
                                ':aid' => $adminId,
                                ':src' => 'custom',
                                ':parent' => null,
                                ':locked' => 0,
                                ':tt' => $templateType,
                                ':n' => $name,
                                ':s' => $slug,
                                ':d' => $description,
                                ':subj' => $subjectTemplate,
                                ':html' => $bodyHtmlTemplate,
                                ':txt' => $bodyTextTemplate,
                                ':vs' => $variablesSchemaJson,
                                ':sd' => ($sampleJson === null ? null : $sampleJson),
                                ':st' => $status,
                            ));
                            $editId = (int)$pdo->lastInsertId();
                            $flashOk = 'Plantilla creada.';
                        }
                    } catch (Exception $e) {
                        $flashErr = 'No se pudo guardar la plantilla: ' . $e->getMessage();
                    }
                }
            }
        }

        if ($action === 'duplicate') {
            $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            if ($id > 0) {
                try {
                    $scopeSql = communication_templates_scope_sql($isSuper);
                    $scopeParams = communication_templates_scope_params($organizationId, $adminId, $isSuper);
                    $stGet = $pdo->prepare('SELECT * FROM communication_templates WHERE id = :id AND ' . $scopeSql . ' LIMIT 1');
                    $paramsGet = array(':id' => $id) + $scopeParams;
                    $stGet->execute($paramsGet);
                    $row = $stGet->fetch(PDO::FETCH_ASSOC);

                    if (!$row) {
                        $flashErr = 'No se encontro la plantilla para duplicar.';
                    } else {
                        $slug = communication_templates_unique_slug($pdo, $organizationId, (string)$row['slug'] . '-copia', 0);
                        $stIns = $pdo->prepare('INSERT INTO communication_templates (organization_id, created_by_admin_id, source_type, parent_template_id, is_system_locked, template_type, name, slug, description, subject_template, body_html_template, body_text_template, variables_schema_json, sample_data_json, status, created_at, updated_at) VALUES (:org, :aid, :src, :parent, :locked, :tt, :n, :s, :d, :subj, :html, :txt, :vs, :sd, :st, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)');
                        $stIns->execute(array(
                            ':org' => $organizationId,
                            ':aid' => $adminId,
                            ':src' => 'custom',
                            ':parent' => (int)$row['id'],
                            ':locked' => 0,
                            ':tt' => isset($row['template_type']) ? $row['template_type'] : 'general',
                            ':n' => (string)$row['name'] . ' (copia)',
                            ':s' => $slug,
                            ':d' => isset($row['description']) ? $row['description'] : '',
                            ':subj' => isset($row['subject_template']) ? $row['subject_template'] : '',
                            ':html' => isset($row['body_html_template']) ? $row['body_html_template'] : '',
                            ':txt' => isset($row['body_text_template']) ? $row['body_text_template'] : '',
                            ':vs' => isset($row['variables_schema_json']) ? $row['variables_schema_json'] : null,
                            ':sd' => isset($row['sample_data_json']) ? $row['sample_data_json'] : null,
                            ':st' => 'draft',
                        ));
                        $flashOk = 'Plantilla duplicada.';
                    }
                } catch (Exception $e) {
                    $flashErr = 'No se pudo duplicar la plantilla: ' . $e->getMessage();
                }
            }
        }

        if ($action === 'archive' || $action === 'activate') {
            $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            if ($id > 0) {
                try {
                    $scopeSql = communication_templates_scope_sql($isSuper);
                    $scopeParams = communication_templates_scope_params($organizationId, $adminId, $isSuper);
                    $stCheck = $pdo->prepare('SELECT id, is_system_locked FROM communication_templates WHERE id = :id AND ' . $scopeSql . ' LIMIT 1');
                    $paramsCheck = array(':id' => $id) + $scopeParams;
                    $stCheck->execute($paramsCheck);
                    $row = $stCheck->fetch(PDO::FETCH_ASSOC);
                    if (!$row) {
                        $flashErr = 'No se encontro la plantilla para actualizar estado.';
                    } elseif ((int)$row['is_system_locked'] === 1) {
                        $flashErr = 'Las plantillas del sistema bloqueadas no se archivan directamente.';
                    } else {
                        $newStatus = ($action === 'activate') ? 'active' : 'archived';
                        $st = $pdo->prepare('UPDATE communication_templates SET status = :st, updated_at = datetime(\'now\') WHERE id = :id');
                        $st->execute(array(':st' => $newStatus, ':id' => $id));
                        $flashOk = ($newStatus === 'active') ? 'Plantilla reactivada.' : 'Plantilla archivada.';
                    }
                } catch (Exception $e) {
                    $flashErr = 'No se pudo actualizar estado de plantilla: ' . $e->getMessage();
                }
            }
        }
    }
}

$scopeSql = communication_templates_scope_sql($isSuper);
$scopeParams = communication_templates_scope_params($organizationId, $adminId, $isSuper);
$sql = 'SELECT * FROM communication_templates WHERE ' . $scopeSql;

if ($q !== '') {
    $sql .= ' AND (name LIKE :q OR slug LIKE :q OR subject_template LIKE :q)';
    $scopeParams[':q'] = '%' . $q . '%';
}

$allowedTypes = communication_templates_allowed_types();
if ($fType !== '' && isset($allowedTypes[$fType])) {
    $sql .= ' AND template_type = :tt';
    $scopeParams[':tt'] = $fType;
}

if ($fStatus !== '') {
    $fStatusN = communication_templates_normalize_status($fStatus);
    $sql .= ' AND status = :fs';
    $scopeParams[':fs'] = $fStatusN;
}

$sql .= ' ORDER BY is_system_locked DESC, updated_at DESC, id DESC';
$stList = $pdo->prepare($sql);
$stList->execute($scopeParams);
$rows = $stList->fetchAll(PDO::FETCH_ASSOC);

$editRow = null;
if ($editId > 0) {
    $scopeSqlE = communication_templates_scope_sql($isSuper);
    $scopeParamsE = communication_templates_scope_params($organizationId, $adminId, $isSuper);
    $stE = $pdo->prepare('SELECT * FROM communication_templates WHERE id = :id AND ' . $scopeSqlE . ' LIMIT 1');
    $paramsE = array(':id' => $editId) + $scopeParamsE;
    $stE->execute($paramsE);
    $editRow = $stE->fetch(PDO::FETCH_ASSOC);
}

if ($editRow && empty($livePreview)) {
    $form['id'] = (int)$editRow['id'];
    $form['name'] = (string)$editRow['name'];
    $form['slug'] = (string)$editRow['slug'];
    $form['template_type'] = (string)$editRow['template_type'];
    $form['status'] = (string)$editRow['status'];
    $form['description'] = (string)$editRow['description'];
    $form['subject_template'] = (string)$editRow['subject_template'];
    $form['body_html_template'] = (string)$editRow['body_html_template'];
    $form['body_text_template'] = (string)$editRow['body_text_template'];
    $form['sample_data_json'] = (string)$editRow['sample_data_json'];
    $form['source_type'] = (string)$editRow['source_type'];
    $form['is_system_locked'] = (int)$editRow['is_system_locked'];
}

$previewRow = null;
if ($previewId > 0) {
    $scopeSqlP = communication_templates_scope_sql($isSuper);
    $scopeParamsP = communication_templates_scope_params($organizationId, $adminId, $isSuper);
    $stP = $pdo->prepare('SELECT * FROM communication_templates WHERE id = :id AND ' . $scopeSqlP . ' LIMIT 1');
    $paramsP = array(':id' => $previewId) + $scopeParamsP;
    $stP->execute($paramsP);
    $previewRow = $stP->fetch(PDO::FETCH_ASSOC);
}

$preview = null;
if ($livePreview) {
    $preview = $livePreview;
} elseif ($previewRow) {
    $preview = communication_template_renderer_preview(
        isset($previewRow['subject_template']) ? $previewRow['subject_template'] : '',
        isset($previewRow['body_html_template']) ? $previewRow['body_html_template'] : '',
        isset($previewRow['body_text_template']) ? $previewRow['body_text_template'] : '',
        isset($previewRow['sample_data_json']) ? $previewRow['sample_data_json'] : ''
    );
}

$title = 'Comunicacion - Plantillas';
include __DIR__ . '/inc/layout_top.php';
?>

<div class="card" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
  <a class="btn secondary" href="panel_admin.php">Volver</a>
  <div>
    <div class="muted" style="font-size:12px;letter-spacing:.08em;text-transform:uppercase;">📣 Comunicacion</div>
    <h2 style="margin:0;">Plantillas</h2>
  </div>
  <span class="muted">Editor reutilizable de plantillas sin envio directo de emails.</span>
</div>

<div class="card" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
  <a class="btn secondary" href="superadmin_emails_db.php">👥 Contactos</a>
  <a class="btn secondary" href="comunicacion_audiencias.php">Audiencias</a>
  <a class="btn" href="comunicacion_plantillas.php">Plantillas</a>
  <span class="btn secondary" style="opacity:.6;cursor:not-allowed;">Campanas · Proximamente</span>
  <span class="btn secondary" style="opacity:.6;cursor:not-allowed;">Historial · Proximamente</span>
</div>

<?php if ($flashOk !== ''): ?>
  <div class="flash ok"><?php echo $flashOk; ?></div>
<?php endif; ?>
<?php if ($flashErr !== ''): ?>
  <div class="flash err"><?php echo e($flashErr); ?></div>
<?php endif; ?>

<div class="card" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
  <form method="get" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
    <input name="q" placeholder="Buscar nombre, slug o asunto" value="<?php echo e($q); ?>" style="min-width:280px;">
    <select name="f_type">
      <option value="">Tipo: Todos</option>
      <?php foreach ($allowedTypes as $k => $label): ?>
        <option value="<?php echo e($k); ?>" <?php echo $fType === $k ? 'selected' : ''; ?>><?php echo e($label); ?></option>
      <?php endforeach; ?>
    </select>
    <select name="f_status">
      <option value="">Estado: Todos</option>
      <option value="draft" <?php echo $fStatus === 'draft' ? 'selected' : ''; ?>>draft</option>
      <option value="active" <?php echo $fStatus === 'active' ? 'selected' : ''; ?>>active</option>
      <option value="archived" <?php echo $fStatus === 'archived' ? 'selected' : ''; ?>>archived</option>
    </select>
    <button class="btn secondary" type="submit">Filtrar</button>
    <?php if ($q !== '' || $fType !== '' || $fStatus !== ''): ?>
      <a class="btn secondary" href="comunicacion_plantillas.php">Limpiar</a>
    <?php endif; ?>
  </form>
</div>

<div class="card" style="overflow:auto;">
  <table class="table" style="width:100%;font-size:14px;">
    <thead>
      <tr>
        <th>Nombre</th>
        <th>Tipo</th>
        <th>Origen</th>
        <th>Estado</th>
        <th>Slug</th>
        <th>Actualizada</th>
        <th>Acciones</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($rows)): ?>
        <tr><td colspan="7" class="muted">No hay plantillas.</td></tr>
      <?php else: ?>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td>
              <strong><?php echo e($r['name']); ?></strong>
              <?php if (!empty($r['description'])): ?>
                <div class="muted" style="font-size:12px;max-width:360px;word-break:break-word;"><?php echo e($r['description']); ?></div>
              <?php endif; ?>
            </td>
            <td><?php echo e(isset($allowedTypes[$r['template_type']]) ? $allowedTypes[$r['template_type']] : $r['template_type']); ?></td>
            <td>
              <?php if ((int)$r['is_system_locked'] === 1): ?>
                <span class="muted">system (bloqueada)</span>
              <?php else: ?>
                <span class="muted">custom</span>
              <?php endif; ?>
            </td>
            <td><?php echo e($r['status']); ?></td>
            <td><?php echo e($r['slug']); ?></td>
            <td><?php echo e($r['updated_at']); ?></td>
            <td>
              <div style="display:flex;gap:6px;flex-wrap:wrap;">
                <a class="btn secondary" href="comunicacion_plantillas.php?id=<?php echo (int)$r['id']; ?>">Editar</a>
                <a class="btn secondary" href="comunicacion_plantillas.php?preview_id=<?php echo (int)$r['id']; ?>">Previsualizar</a>
                <form method="post" action="comunicacion_plantillas.php" style="display:inline;">
                  <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
                  <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                  <button class="btn secondary" type="submit" name="action" value="duplicate">Duplicar</button>
                </form>
                <?php if ((int)$r['is_system_locked'] !== 1): ?>
                  <form method="post" action="comunicacion_plantillas.php" style="display:inline;">
                    <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
                    <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                    <?php if ((string)$r['status'] === 'archived'): ?>
                      <button class="btn secondary" type="submit" name="action" value="activate">Reactivar</button>
                    <?php else: ?>
                      <button class="btn secondary" type="submit" name="action" value="archive">Archivar</button>
                    <?php endif; ?>
                  </form>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<div class="card" style="max-width:1200px;margin:16px auto;">
  <h3 style="margin-top:0;"><?php echo ((int)$form['id'] > 0) ? 'Editar plantilla' : 'Nueva plantilla'; ?></h3>

  <?php if ((int)$form['is_system_locked'] === 1): ?>
    <div class="flash err">Esta plantilla de sistema esta bloqueada. Usa Duplicar para crear una version editable.</div>
  <?php endif; ?>

  <form method="post" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px;">
    <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
    <input type="hidden" name="id" value="<?php echo (int)$form['id']; ?>">
    <input type="hidden" name="editing_system" value="<?php echo ((int)$form['is_system_locked'] === 1) ? 1 : 0; ?>">

    <div>
      <label>Nombre</label>
      <input name="name" value="<?php echo e($form['name']); ?>" required>
    </div>

    <div>
      <label>Slug</label>
      <input name="slug" value="<?php echo e($form['slug']); ?>" placeholder="se genera automaticamente si se deja vacio">
    </div>

    <div>
      <label>Tipo</label>
      <select name="template_type">
        <?php foreach ($allowedTypes as $k => $label): ?>
          <option value="<?php echo e($k); ?>" <?php echo $form['template_type'] === $k ? 'selected' : ''; ?>><?php echo e($label); ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div>
      <label>Estado</label>
      <select name="status">
        <option value="draft" <?php echo $form['status'] === 'draft' ? 'selected' : ''; ?>>draft</option>
        <option value="active" <?php echo $form['status'] === 'active' ? 'selected' : ''; ?>>active</option>
        <option value="archived" <?php echo $form['status'] === 'archived' ? 'selected' : ''; ?>>archived</option>
      </select>
    </div>

    <div style="grid-column:1/-1;">
      <label>Descripcion</label>
      <input name="description" value="<?php echo e($form['description']); ?>" placeholder="Uso interno de la plantilla">
    </div>

    <div style="grid-column:1/-1;">
      <label>Asunto (subject_template)</label>
      <input name="subject_template" value="<?php echo e($form['subject_template']); ?>" required>
    </div>

    <div style="grid-column:1/-1;">
      <label>Cuerpo HTML (body_html_template)</label>
      <textarea name="body_html_template" style="min-height:160px;"><?php echo e($form['body_html_template']); ?></textarea>
    </div>

    <div style="grid-column:1/-1;">
      <label>Cuerpo texto (body_text_template)</label>
      <textarea name="body_text_template" style="min-height:140px;"><?php echo e($form['body_text_template']); ?></textarea>
    </div>

    <div style="grid-column:1/-1;">
      <label>sample_data_json (para preview)</label>
      <textarea name="sample_data_json" style="min-height:120px;"><?php echo e($form['sample_data_json']); ?></textarea>
    </div>

    <div style="grid-column:1/-1;display:flex;gap:8px;flex-wrap:wrap;">
      <button class="btn" type="submit" name="action" value="save" <?php echo ((int)$form['is_system_locked'] === 1) ? 'disabled' : ''; ?>>Guardar plantilla</button>
      <button class="btn secondary" type="submit" name="action" value="preview_form">Previsualizar</button>
      <?php if ((int)$form['id'] > 0): ?>
        <a class="btn secondary" href="comunicacion_plantillas.php">Nueva plantilla</a>
      <?php endif; ?>
    </div>
  </form>
</div>

<?php if ($preview): ?>
  <div class="card" style="max-width:1200px;margin:16px auto;">
    <h3 style="margin-top:0;">Vista previa (email renderizado)</h3>

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

    <div class="card" style="margin-top:12px;">
      <div class="muted" style="font-size:12px;letter-spacing:.08em;text-transform:uppercase;">Body texto renderizado</div>
      <pre style="white-space:pre-wrap;word-break:break-word;"><?php echo e($preview['body_text']); ?></pre>
    </div>
  </div>
<?php endif; ?>

<?php include __DIR__ . '/inc/layout_bottom.php'; ?>
