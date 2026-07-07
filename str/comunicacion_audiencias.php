<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/communication_contacts.php';

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
$editId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!function_exists('communication_audiences_slugify')) {
    function communication_audiences_slugify($txt)
    {
        $txt = strtolower(trim((string)$txt));
        $txt = preg_replace('/[^a-z0-9]+/', '-', $txt);
        $txt = trim((string)$txt, '-');
        if ($txt === '') $txt = 'audiencia';
        return $txt;
    }
}

if (!function_exists('communication_audiences_unique_slug')) {
    function communication_audiences_unique_slug($pdo, $organizationId, $slugBase, $excludeId)
    {
        $slug = communication_audiences_slugify($slugBase);
        $base = $slug;
        $n = 2;

        while (true) {
            $sql = 'SELECT id FROM communication_audiences WHERE organization_id = :org AND slug = :slug';
            if ((int)$excludeId > 0) {
                $sql .= ' AND id <> :id';
            }
            $sql .= ' LIMIT 1';
            $st = $pdo->prepare($sql);
            $params = array(':org' => (int)$organizationId, ':slug' => $slug);
            if ((int)$excludeId > 0) $params[':id'] = (int)$excludeId;
            $st->execute($params);
            $exists = $st->fetch(PDO::FETCH_ASSOC);
            if (!$exists) return $slug;
            $slug = $base . '-' . $n;
            $n++;
        }
    }
}

if (!function_exists('communication_audiences_scope_sql')) {
    function communication_audiences_scope_sql($isSuper)
    {
        $sql = 'organization_id = :org';
        if (!$isSuper) {
            $sql .= ' AND created_by_admin_id = :aid';
        }
        return $sql;
    }
}

if (!function_exists('communication_audiences_scope_params')) {
    function communication_audiences_scope_params($organizationId, $adminId, $isSuper)
    {
        $params = array(':org' => (int)$organizationId);
        if (!$isSuper) {
            $params[':aid'] = (int)$adminId;
        }
        return $params;
    }
}

if (!function_exists('communication_audiences_form_filters')) {
    function communication_audiences_form_filters()
    {
        return communication_contacts_normalize_filters(array(
            'q' => isset($_POST['filter_q']) ? $_POST['filter_q'] : '',
            'registered' => isset($_POST['filter_registered']) ? $_POST['filter_registered'] : '',
            'blocked' => isset($_POST['filter_blocked']) ? $_POST['filter_blocked'] : '',
            'source' => isset($_POST['filter_source']) ? $_POST['filter_source'] : '',
            'role' => isset($_POST['filter_role']) ? $_POST['filter_role'] : '',
            'buyer' => isset($_POST['filter_buyer']) ? $_POST['filter_buyer'] : '',
            'event_id' => isset($_POST['filter_event_id']) ? $_POST['filter_event_id'] : '',
        ));
    }
}

if (!function_exists('communication_audiences_ensure_schema')) {
  function communication_audiences_ensure_schema($pdo)
  {
    $pdo->exec('CREATE TABLE IF NOT EXISTS communication_audiences (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      organization_id INTEGER NOT NULL DEFAULT 1,
      created_by_admin_id INTEGER,
      name TEXT NOT NULL,
      slug TEXT NOT NULL,
      description TEXT,
      filters_json TEXT,
      status TEXT NOT NULL DEFAULT "active",
      last_used_at TEXT,
      created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )');
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_comm_aud_org_slug ON communication_audiences(organization_id, slug)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_comm_aud_org_status ON communication_audiences(organization_id, status)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_comm_aud_created_by ON communication_audiences(created_by_admin_id)');
  }
}

try {
  communication_audiences_ensure_schema($pdo);
} catch (Exception $e) {
  if ($flashErr === '') {
    $flashErr = 'No se pudo preparar audiencias: ' . $e->getMessage();
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $provided = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
    if (function_exists('tickex_csrf_verify') && !tickex_csrf_verify($provided)) {
        $flashErr = 'CSRF invalido. Recarga la pagina e intenta nuevamente.';
    } else {
        $action = isset($_POST['action']) ? (string)$_POST['action'] : '';

        if ($action === 'estimate_form') {
            $filters = communication_audiences_form_filters();
          $count = communication_contacts_count($pdo, $filters, $contactScope);
            $flashOk = 'Destinatarios estimados: ' . (int)$count;
        }

        if ($action === 'save') {
            $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            $name = trim((string)(isset($_POST['name']) ? $_POST['name'] : ''));
            $description = trim((string)(isset($_POST['description']) ? $_POST['description'] : ''));
            $status = trim((string)(isset($_POST['status']) ? $_POST['status'] : 'active'));
            if (!in_array($status, array('active', 'archived'), true)) $status = 'active';

            if ($name === '') {
                $flashErr = 'El nombre es obligatorio.';
            } else {
                $rawSlug = trim((string)(isset($_POST['slug']) ? $_POST['slug'] : ''));
                $slugBase = ($rawSlug !== '') ? $rawSlug : $name;
                $slug = communication_audiences_unique_slug($pdo, $organizationId, $slugBase, $id);

                $filters = communication_audiences_form_filters();
                $filtersJson = communication_contacts_filters_to_json($filters);

                try {
                    if ($id > 0) {
                        $scopeSql = communication_audiences_scope_sql($isSuper);
                        $scopeParams = communication_audiences_scope_params($organizationId, $adminId, $isSuper);
                        $stCheck = $pdo->prepare('SELECT id FROM communication_audiences WHERE id = :id AND ' . $scopeSql . ' LIMIT 1');
                        $paramsCheck = array(':id' => $id) + $scopeParams;
                        $stCheck->execute($paramsCheck);
                        if (!$stCheck->fetch(PDO::FETCH_ASSOC)) {
                            $flashErr = 'No se encontro la audiencia para editar.';
                        } else {
                            $st = $pdo->prepare('UPDATE communication_audiences SET name = :n, slug = :s, description = :d, filters_json = :f, status = :st, updated_at = datetime(\'now\') WHERE id = :id');
                            $st->execute(array(
                                ':n' => $name,
                                ':s' => $slug,
                                ':d' => $description,
                                ':f' => $filtersJson,
                                ':st' => $status,
                                ':id' => $id,
                            ));
                            $flashOk = 'Audiencia actualizada.';
                            $editId = $id;
                        }
                    } else {
                        $st = $pdo->prepare('INSERT INTO communication_audiences (organization_id, created_by_admin_id, name, slug, description, filters_json, status, created_at, updated_at) VALUES (:org, :aid, :n, :s, :d, :f, :st, datetime(\'now\'), datetime(\'now\'))');
                        $st->execute(array(
                            ':org' => $organizationId,
                            ':aid' => $adminId,
                            ':n' => $name,
                            ':s' => $slug,
                            ':d' => $description,
                            ':f' => $filtersJson,
                            ':st' => $status,
                        ));
                        $newId = (int)$pdo->lastInsertId();
                        $flashOk = 'Audiencia creada.';
                        $editId = $newId;
                    }
                } catch (Exception $e) {
                    $flashErr = 'No se pudo guardar la audiencia: ' . $e->getMessage();
                }
            }
        }

        if ($action === 'duplicate') {
            $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            if ($id > 0) {
                try {
                    $scopeSql = communication_audiences_scope_sql($isSuper);
                    $scopeParams = communication_audiences_scope_params($organizationId, $adminId, $isSuper);
                    $stGet = $pdo->prepare('SELECT * FROM communication_audiences WHERE id = :id AND ' . $scopeSql . ' LIMIT 1');
                    $paramsGet = array(':id' => $id) + $scopeParams;
                    $stGet->execute($paramsGet);
                    $row = $stGet->fetch(PDO::FETCH_ASSOC);

                    if ($row) {
                        $name = (string)$row['name'] . ' (copia)';
                        $slug = communication_audiences_unique_slug($pdo, $organizationId, (string)$row['slug'] . '-copia', 0);
                        $stIns = $pdo->prepare('INSERT INTO communication_audiences (organization_id, created_by_admin_id, name, slug, description, filters_json, status, created_at, updated_at) VALUES (:org, :aid, :n, :s, :d, :f, :st, datetime(\'now\'), datetime(\'now\'))');
                        $stIns->execute(array(
                            ':org' => $organizationId,
                            ':aid' => $adminId,
                            ':n' => $name,
                            ':s' => $slug,
                            ':d' => isset($row['description']) ? $row['description'] : '',
                            ':f' => isset($row['filters_json']) ? $row['filters_json'] : null,
                            ':st' => 'active',
                        ));
                        $flashOk = 'Audiencia duplicada.';
                    } else {
                        $flashErr = 'No se encontro la audiencia para duplicar.';
                    }
                } catch (Exception $e) {
                    $flashErr = 'No se pudo duplicar la audiencia: ' . $e->getMessage();
                }
            }
        }

        if ($action === 'archive' || $action === 'activate') {
            $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            if ($id > 0) {
                $newStatus = ($action === 'activate') ? 'active' : 'archived';
                try {
                    $scopeSql = communication_audiences_scope_sql($isSuper);
                    $scopeParams = communication_audiences_scope_params($organizationId, $adminId, $isSuper);
                    $st = $pdo->prepare('UPDATE communication_audiences SET status = :st, updated_at = datetime(\'now\') WHERE id = :id AND ' . $scopeSql);
                    $params = array(':st' => $newStatus, ':id' => $id) + $scopeParams;
                    $st->execute($params);
                    if ($st->rowCount() > 0) {
                        $flashOk = ($newStatus === 'active') ? 'Audiencia reactivada.' : 'Audiencia archivada.';
                    } else {
                        $flashErr = 'No se encontro la audiencia para actualizar estado.';
                    }
                } catch (Exception $e) {
                    $flashErr = 'No se pudo actualizar la audiencia: ' . $e->getMessage();
                }
            }
        }

        if ($action === 'estimate_saved') {
            $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            if ($id > 0) {
                try {
                    $scopeSql = communication_audiences_scope_sql($isSuper);
                    $scopeParams = communication_audiences_scope_params($organizationId, $adminId, $isSuper);
                    $st = $pdo->prepare('SELECT id, name, filters_json FROM communication_audiences WHERE id = :id AND ' . $scopeSql . ' LIMIT 1');
                    $params = array(':id' => $id) + $scopeParams;
                    $st->execute($params);
                    $row = $st->fetch(PDO::FETCH_ASSOC);
                    if ($row) {
                        $filters = communication_contacts_filters_from_json(isset($row['filters_json']) ? $row['filters_json'] : '');
                      $count = communication_contacts_count($pdo, $filters, $contactScope);
                        $flashOk = 'Destinatarios estimados para "' . e($row['name']) . '": ' . (int)$count;
                    } else {
                        $flashErr = 'No se encontro la audiencia para estimar.';
                    }
                } catch (Exception $e) {
                    $flashErr = 'No se pudo estimar la audiencia: ' . $e->getMessage();
                }
            }
        }
    }
}

$scopeSql = communication_audiences_scope_sql($isSuper);
$scopeParams = communication_audiences_scope_params($organizationId, $adminId, $isSuper);
$listSql = 'SELECT * FROM communication_audiences WHERE ' . $scopeSql;
if ($q !== '') {
    $listSql .= ' AND (name LIKE :q OR slug LIKE :q OR description LIKE :q)';
    $scopeParams[':q'] = '%' . $q . '%';
}
$listSql .= ' ORDER BY updated_at DESC, id DESC';
$rows = array();
try {
  $stList = $pdo->prepare($listSql);
  $stList->execute($scopeParams);
  $rows = $stList->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
  // Evita HTTP 500 si la migración aun no corrió en este entorno
  try {
    communication_audiences_ensure_schema($pdo);
    $stList = $pdo->prepare($listSql);
    $stList->execute($scopeParams);
    $rows = $stList->fetchAll(PDO::FETCH_ASSOC);
  } catch (Exception $e2) {
    $rows = array();
    if ($flashErr === '') {
      $flashErr = 'No se pudo cargar audiencias: ' . $e2->getMessage();
    }
  }
}

$editRow = null;
if ($editId > 0) {
    $scopeSqlE = communication_audiences_scope_sql($isSuper);
    $scopeParamsE = communication_audiences_scope_params($organizationId, $adminId, $isSuper);
  try {
    $stEdit = $pdo->prepare('SELECT * FROM communication_audiences WHERE id = :id AND ' . $scopeSqlE . ' LIMIT 1');
    $paramsEdit = array(':id' => $editId) + $scopeParamsE;
    $stEdit->execute($paramsEdit);
    $editRow = $stEdit->fetch(PDO::FETCH_ASSOC);
  } catch (Exception $e) {
    if ($flashErr === '') {
      $flashErr = 'No se pudo cargar la audiencia seleccionada.';
    }
  }
}

$formName = $editRow ? (string)$editRow['name'] : '';
$formSlug = $editRow ? (string)$editRow['slug'] : '';
$formDescription = $editRow ? (string)$editRow['description'] : '';
$formStatus = $editRow ? (string)$editRow['status'] : 'active';
$formFilters = $editRow ? communication_contacts_filters_from_json((string)$editRow['filters_json']) : array();

$title = 'Comunicacion - Audiencias';
include __DIR__ . '/inc/layout_top.php';
?>

<div class="card" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
  <a class="btn secondary" href="panel_admin.php">Volver</a>
  <div>
    <div class="muted" style="font-size:12px;letter-spacing:.08em;text-transform:uppercase;">📣 Comunicacion</div>
    <h2 style="margin:0;">Audiencias</h2>
  </div>
  <span class="muted">Definiciones reutilizables de filtros. No almacenan personas.</span>
</div>

<div class="card" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
  <a class="btn secondary" href="superadmin_emails_db.php">👥 Contactos</a>
  <a class="btn" href="comunicacion_audiencias.php">Audiencias</a>
  <span class="btn secondary" style="opacity:.6;cursor:not-allowed;">Plantillas · Proximamente</span>
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
    <input name="q" placeholder="Buscar audiencia (nombre, slug, descripcion)" value="<?php echo e($q); ?>" style="min-width:300px;">
    <button class="btn secondary" type="submit">Buscar</button>
    <?php if ($q !== ''): ?>
      <a class="btn secondary" href="comunicacion_audiencias.php">Limpiar</a>
    <?php endif; ?>
  </form>
</div>

<div class="card" style="overflow:auto;">
  <table class="table" style="width:100%;font-size:14px;">
    <thead>
      <tr>
        <th>Nombre</th>
        <th>Slug</th>
        <th>Estado</th>
        <th>Ultimo uso</th>
        <th>Actualizada</th>
        <th>Acciones</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($rows)): ?>
        <tr><td colspan="6" class="muted">No hay audiencias creadas.</td></tr>
      <?php else: ?>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td>
              <strong><?php echo e($r['name']); ?></strong>
              <?php if (!empty($r['description'])): ?>
                <div class="muted" style="font-size:12px;max-width:340px;word-break:break-word;"><?php echo e($r['description']); ?></div>
              <?php endif; ?>
            </td>
            <td><?php echo e($r['slug']); ?></td>
            <td><?php echo e($r['status']); ?></td>
            <td><?php echo e(!empty($r['last_used_at']) ? $r['last_used_at'] : '-'); ?></td>
            <td><?php echo e($r['updated_at']); ?></td>
            <td>
              <div style="display:flex;gap:6px;flex-wrap:wrap;">
                <a class="btn secondary" href="comunicacion_audiencias.php?id=<?php echo (int)$r['id']; ?>">Editar</a>
                <form method="post" action="comunicacion_audiencias.php" style="display:inline;">
                  <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
                  <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                  <button class="btn secondary" type="submit" name="action" value="estimate_saved">Estimar</button>
                </form>
                <form method="post" action="comunicacion_audiencias.php" style="display:inline;">
                  <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
                  <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                  <button class="btn secondary" type="submit" name="action" value="duplicate">Duplicar</button>
                </form>
                <form method="post" action="comunicacion_audiencias.php" style="display:inline;">
                  <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
                  <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                  <?php if ((string)$r['status'] === 'archived'): ?>
                    <button class="btn secondary" type="submit" name="action" value="activate">Reactivar</button>
                  <?php else: ?>
                    <button class="btn secondary" type="submit" name="action" value="archive">Archivar</button>
                  <?php endif; ?>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<div class="card" style="max-width:1100px;margin:16px auto;">
  <h3 style="margin-top:0;"><?php echo $editRow ? 'Editar audiencia' : 'Nueva audiencia'; ?></h3>

  <form method="post" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px;">
    <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?php echo $editRow ? (int)$editRow['id'] : 0; ?>">

    <div>
      <label>Nombre</label>
      <input name="name" value="<?php echo e($formName); ?>" required>
    </div>

    <div>
      <label>Slug estable</label>
      <input name="slug" value="<?php echo e($formSlug); ?>" placeholder="se genera automaticamente si se deja vacio">
    </div>

    <div>
      <label>Estado</label>
      <select name="status">
        <option value="active" <?php echo $formStatus === 'active' ? 'selected' : ''; ?>>active</option>
        <option value="archived" <?php echo $formStatus === 'archived' ? 'selected' : ''; ?>>archived</option>
      </select>
    </div>

    <div style="grid-column:1/-1;">
      <label>Descripcion</label>
      <input name="description" value="<?php echo e($formDescription); ?>" placeholder="Uso interno de la audiencia">
    </div>

    <div style="grid-column:1/-1;"><strong>Filtros (definicion JSON normalizada)</strong></div>

    <div>
      <label>Busqueda</label>
      <input name="filter_q" value="<?php echo e(isset($formFilters['q']) ? $formFilters['q'] : ''); ?>" placeholder="email, nombre o rol">
    </div>

    <div>
      <label>Registrado</label>
      <select name="filter_registered">
        <option value="" <?php echo !isset($formFilters['registered']) ? 'selected' : ''; ?>>Todos</option>
        <option value="yes" <?php echo (isset($formFilters['registered']) && $formFilters['registered'] === 'yes') ? 'selected' : ''; ?>>Si</option>
        <option value="no" <?php echo (isset($formFilters['registered']) && $formFilters['registered'] === 'no') ? 'selected' : ''; ?>>No</option>
      </select>
    </div>

    <div>
      <label>Bloqueado</label>
      <select name="filter_blocked">
        <option value="" <?php echo !isset($formFilters['blocked']) ? 'selected' : ''; ?>>Todos</option>
        <option value="yes" <?php echo (isset($formFilters['blocked']) && $formFilters['blocked'] === 'yes') ? 'selected' : ''; ?>>Si</option>
        <option value="no" <?php echo (isset($formFilters['blocked']) && $formFilters['blocked'] === 'no') ? 'selected' : ''; ?>>No</option>
      </select>
    </div>

    <div>
      <label>Fuente</label>
      <select name="filter_source">
        <option value="" <?php echo !isset($formFilters['source']) ? 'selected' : ''; ?>>Todas</option>
        <option value="usuarios" <?php echo (isset($formFilters['source']) && $formFilters['source'] === 'usuarios') ? 'selected' : ''; ?>>usuarios</option>
        <option value="registro_pendientes" <?php echo (isset($formFilters['source']) && $formFilters['source'] === 'registro_pendientes') ? 'selected' : ''; ?>>registro_pendientes</option>
        <option value="entradas" <?php echo (isset($formFilters['source']) && $formFilters['source'] === 'entradas') ? 'selected' : ''; ?>>entradas</option>
        <option value="email_logs" <?php echo (isset($formFilters['source']) && $formFilters['source'] === 'email_logs') ? 'selected' : ''; ?>>email_logs</option>
      </select>
    </div>

    <div>
      <label>Rol exacto</label>
      <input name="filter_role" value="<?php echo e(isset($formFilters['role']) ? $formFilters['role'] : ''); ?>" placeholder="admin, cliente, etc.">
    </div>

    <div>
      <label>Comprador</label>
      <select name="filter_buyer">
        <option value="" <?php echo !isset($formFilters['buyer']) ? 'selected' : ''; ?>>Todos</option>
        <option value="yes" <?php echo (isset($formFilters['buyer']) && $formFilters['buyer'] === 'yes') ? 'selected' : ''; ?>>Si</option>
        <option value="no" <?php echo (isset($formFilters['buyer']) && $formFilters['buyer'] === 'no') ? 'selected' : ''; ?>>No</option>
      </select>
    </div>

    <div>
      <label>Evento (ID)</label>
      <input type="number" min="1" name="filter_event_id" value="<?php echo e(isset($formFilters['event_id']) ? (string)$formFilters['event_id'] : ''); ?>" placeholder="Opcional">
    </div>

    <div style="grid-column:1/-1;display:flex;gap:8px;flex-wrap:wrap;">
      <button class="btn" type="submit">Guardar audiencia</button>
      <button class="btn secondary" type="submit" name="action" value="estimate_form">Estimar destinatarios</button>
      <?php if ($editRow): ?>
        <a class="btn secondary" href="comunicacion_audiencias.php">Nueva audiencia</a>
      <?php endif; ?>
    </div>
  </form>
</div>

<?php include __DIR__ . '/inc/layout_bottom.php'; ?>
