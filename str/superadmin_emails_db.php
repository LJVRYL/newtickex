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
  echo "<div class='card'><h3>Acceso restringido</h3><p>Solo para administradores.</p></div>";
    include __DIR__ . '/inc/layout_bottom.php';
    exit;
}

$pdo = db();
$csrf = function_exists('tickex_csrf_token') ? (string)tickex_csrf_token() : '';
$adminId = 0;
if (isset($_SESSION['admin_id'])) $adminId = (int)$_SESSION['admin_id'];
elseif (isset($_SESSION['user_id'])) $adminId = (int)$_SESSION['user_id'];
elseif (isset($_SESSION['usuario_id'])) $adminId = (int)$_SESSION['usuario_id'];
$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$fRegistered = isset($_GET['f_registered']) ? trim((string)$_GET['f_registered']) : '';
$fBlocked = isset($_GET['f_blocked']) ? trim((string)$_GET['f_blocked']) : '';
$fSource = isset($_GET['f_source']) ? trim((string)$_GET['f_source']) : '';
$fImportBatch = isset($_GET['f_import_batch']) ? trim((string)$_GET['f_import_batch']) : '';
$fImportFile = isset($_GET['f_import_file']) ? trim((string)$_GET['f_import_file']) : '';
$fImportedFrom = isset($_GET['f_imported_from']) ? trim((string)$_GET['f_imported_from']) : '';
$fImportedTo = isset($_GET['f_imported_to']) ? trim((string)$_GET['f_imported_to']) : '';
$view = isset($_GET['view']) ? trim((string)$_GET['view']) : 'all';
if (!in_array($view, array('all', 'base', 'imported'), true)) {
  $view = 'all';
}
$export = isset($_GET['export']) ? trim((string)$_GET['export']) : '';
$flashOk = '';
$flashErr = '';

communication_contacts_imports_ensure_schema($pdo);

$importBatches = array();
$importFiles = array();
try {
  if ($isSuper) {
    $stB = $pdo->query("SELECT COALESCE(import_batch,'') AS batch_label, COALESCE(import_file,'') AS import_file, COUNT(*) AS n, MAX(COALESCE(imported_at,created_at)) AS last_at FROM communication_contacts_imports GROUP BY COALESCE(import_batch,''), COALESCE(import_file,'') ORDER BY last_at DESC LIMIT 200");
  } else {
    $stB = $pdo->prepare("SELECT COALESCE(import_batch,'') AS batch_label, COALESCE(import_file,'') AS import_file, COUNT(*) AS n, MAX(COALESCE(imported_at,created_at)) AS last_at FROM communication_contacts_imports WHERE created_by_admin_id = :aid GROUP BY COALESCE(import_batch,''), COALESCE(import_file,'') ORDER BY last_at DESC LIMIT 200");
    $stB->execute(array(':aid' => $adminId));
  }
  while ($rb = $stB->fetch(PDO::FETCH_ASSOC)) {
    $label = isset($rb['batch_label']) ? trim((string)$rb['batch_label']) : '';
    $file = isset($rb['import_file']) ? trim((string)$rb['import_file']) : '';
    if ($label !== '') {
      $importBatches[] = array(
        'label' => $label,
        'n' => isset($rb['n']) ? (int)$rb['n'] : 0,
        'last_at' => isset($rb['last_at']) ? (string)$rb['last_at'] : '',
      );
    }
    if ($file !== '') {
      $importFiles[$file] = $file;
    }
  }
} catch (Exception $e) {
  // ignore
}

$importFiles = array_values($importFiles);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $provided = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
  if (function_exists('tickex_csrf_verify') && !tickex_csrf_verify($provided)) {
    $flashErr = 'CSRF invalido. Recarga la pagina e intenta nuevamente.';
  } else {
    $action = isset($_POST['action']) ? (string)$_POST['action'] : '';
    $emailAction = isset($_POST['email']) ? trim((string)$_POST['email']) : '';
    $adminId = isset($_SESSION['admin_id']) ? (int)$_SESSION['admin_id'] : (isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null);

    if ($action === 'import_csv') {
      $fileOk = isset($_FILES['csv_file']) && is_array($_FILES['csv_file']) && isset($_FILES['csv_file']['error']) && (int)$_FILES['csv_file']['error'] === UPLOAD_ERR_OK;
      if (!$fileOk) {
        $flashErr = 'No se pudo leer el archivo CSV.';
      } else {
        $tmp = isset($_FILES['csv_file']['tmp_name']) ? (string)$_FILES['csv_file']['tmp_name'] : '';
        $origName = isset($_FILES['csv_file']['name']) ? (string)$_FILES['csv_file']['name'] : 'import.csv';
        $batchLabel = isset($_POST['batch_label']) ? trim((string)$_POST['batch_label']) : '';
        if ($batchLabel === '') {
          $base = preg_replace('/\.[a-z0-9]+$/i', '', basename($origName));
          if (!is_string($base) || trim($base) === '') $base = 'import';
          $batchLabel = $base . ' ' . date('Y-m-d H:i');
        }

        $inserted = 0;
        $ignored = 0;
        try {
          $fh = fopen($tmp, 'r');
          if ($fh === false) {
            throw new Exception('No se pudo abrir el CSV temporal.');
          }

          $first = fgetcsv($fh, 0, ',');
          $delimiter = ',';
          if (is_array($first) && count($first) <= 1) {
            rewind($fh);
            $first = fgetcsv($fh, 0, ';');
            $delimiter = ';';
          }

          $idxEmail = 0;
          $idxNombre = 1;
          $idxRol = 2;
          $hasHeader = false;

          if (is_array($first)) {
            $headers = array();
            foreach ($first as $i => $h) {
              $key = strtolower(trim((string)$h));
              $headers[$key] = $i;
            }
            if (isset($headers['email']) || isset($headers['correo']) || isset($headers['mail'])) {
              $hasHeader = true;
              if (isset($headers['email'])) $idxEmail = (int)$headers['email'];
              elseif (isset($headers['correo'])) $idxEmail = (int)$headers['correo'];
              else $idxEmail = (int)$headers['mail'];

              if (isset($headers['nombre'])) $idxNombre = (int)$headers['nombre'];
              elseif (isset($headers['name'])) $idxNombre = (int)$headers['name'];

              if (isset($headers['rol'])) $idxRol = (int)$headers['rol'];
              elseif (isset($headers['role'])) $idxRol = (int)$headers['role'];
            }
          }

          $stIns = $pdo->prepare("INSERT INTO communication_contacts_imports (organization_id, created_by_admin_id, source, import_batch, import_file, imported_at, batch_label, email, nombre, rol, created_at) VALUES (1, :aid, :src, :batch, :file, CURRENT_TIMESTAMP, :batch_legacy, :email, :nombre, :rol, CURRENT_TIMESTAMP)");

          $processRow = function($row) use (&$inserted, &$ignored, $idxEmail, $idxNombre, $idxRol, $stIns, $adminId, $batchLabel, $origName) {
            if (!is_array($row)) return;
            $email = isset($row[$idxEmail]) ? trim((string)$row[$idxEmail]) : '';
            $nombre = isset($row[$idxNombre]) ? trim((string)$row[$idxNombre]) : '';
            $rol = isset($row[$idxRol]) ? trim((string)$row[$idxRol]) : '';

            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
              $ignored++;
              return;
            }

            $stIns->execute(array(
              ':aid' => $adminId,
              ':src' => 'import_csv',
              ':batch' => $batchLabel,
              ':file' => $origName,
              ':batch_legacy' => $batchLabel,
              ':email' => $email,
              ':nombre' => $nombre,
              ':rol' => $rol,
            ));
            $inserted++;
          };

          if (!$hasHeader) {
            $processRow($first);
          }

          while (($row = fgetcsv($fh, 0, $delimiter)) !== false) {
            $processRow($row);
          }
          fclose($fh);

          if ($inserted > 0) {
            $flashOk = 'CSV importado. Contactos agregados: ' . (int)$inserted . '. Ignorados: ' . (int)$ignored . '. Lote: ' . e($batchLabel);
          } else {
            $flashErr = 'No se importaron contactos validos. Revisar formato CSV (email,nombre,rol).';
          }
        } catch (Exception $e) {
          $flashErr = 'No se pudo importar el CSV: ' . $e->getMessage();
        }
      }
    } elseif ($emailAction === '') {
      $flashErr = 'No se encontro el contacto a actualizar.';
    } else {
      try {
        if ($action === 'bloquear') {
          $reason = isset($_POST['reason']) ? trim((string)$_POST['reason']) : '';
          $stOff = $pdo->prepare('UPDATE user_blocks SET active = 0, unblocked_at = datetime(\'now\'), unblocked_by_admin_id = :aid WHERE active = 1 AND lower(email) = lower(:e)');
          $stOff->execute(array(':aid' => $adminId, ':e' => $emailAction));

          $stOn = $pdo->prepare('INSERT INTO user_blocks (email, reason, active, blocked_by_admin_id) VALUES (:e, :r, 1, :aid)');
          $stOn->execute(array(':e' => $emailAction, ':r' => $reason, ':aid' => $adminId));
          $flashOk = 'Contacto bloqueado: ' . e($emailAction);
        } elseif ($action === 'desbloquear') {
          $stOff = $pdo->prepare('UPDATE user_blocks SET active = 0, unblocked_at = datetime(\'now\'), unblocked_by_admin_id = :aid WHERE active = 1 AND lower(email) = lower(:e)');
          $stOff->execute(array(':aid' => $adminId, ':e' => $emailAction));
          $flashOk = 'Contacto desbloqueado: ' . e($emailAction);
        }
      } catch (Exception $e) {
        $flashErr = 'No se pudo actualizar el contacto: ' . $e->getMessage();
      }
    }
  }
}

$contactScope = array(
  'is_super' => $isSuper,
  'admin_id' => $adminId,
);
$emails = communication_contacts_resolve($pdo, $contactScope);

$registeredCount = 0;
$blockedCount = 0;
foreach ($emails as $rowEmail) {
  if (isset($rowEmail['registrado']) && $rowEmail['registrado'] === 'Si') {
    $registeredCount++;
  }
  if (!empty($rowEmail['bloqueado'])) {
    $blockedCount++;
  }
}

$filters = communication_contacts_normalize_filters(array(
    'q' => $q,
    'f_registered' => $fRegistered,
    'f_blocked' => $fBlocked,
    'f_source' => $fSource,
    'f_import_batch' => $fImportBatch,
  'f_import_file' => $fImportFile,
  'imported_from' => $fImportedFrom,
  'imported_to' => $fImportedTo,
));
$rows = communication_contacts_apply_filters(array_values($emails), $filters);

if ($view === 'base') {
  $rows = array_values(array_filter($rows, function($r) {
    return empty($r['fuentes']) || !isset($r['fuentes']['import_csv']);
  }));
} elseif ($view === 'imported') {
  $rows = array_values(array_filter($rows, function($r) {
    return !empty($r['fuentes']) && isset($r['fuentes']['import_csv']);
  }));
}

if ($export === 'csv') {
  header('Content-Type: text/csv; charset=UTF-8');
  header('Content-Disposition: attachment; filename="contactos_tickex.csv"');
  $out = fopen('php://output', 'w');
  if ($out !== false) {
    fputs($out, "\xEF\xBB\xBF");
    fputcsv($out, array('email', 'nombre', 'rol', 'source', 'import_batch', 'import_file', 'imported_at', 'registrado', 'fuentes', 'lotes_importados', 'ultimo_envio', 'ultima_entrada', 'acceso'));
    foreach ($rows as $r) {
      $src = array_keys($r['fuentes']);
      $batches = isset($r['imported_batches']) && is_array($r['imported_batches']) ? $r['imported_batches'] : array();
      fputcsv($out, array(
        (string)$r['email'],
        (string)($r['nombre'] !== '' ? $r['nombre'] : '-'),
        (string)($r['rol'] !== '' ? $r['rol'] : '-'),
        (string)(isset($r['source']) ? $r['source'] : ''),
        (string)(isset($r['import_batch']) ? $r['import_batch'] : ''),
        (string)(isset($r['import_file']) ? $r['import_file'] : ''),
        (string)(isset($r['imported_at']) ? $r['imported_at'] : ''),
        (string)$r['registrado'],
        implode(', ', $src),
        implode(', ', $batches),
        (string)($r['ultimo_envio'] !== '' ? $r['ultimo_envio'] : ''),
        (string)($r['ultima_entrada'] !== '' ? $r['ultima_entrada'] : ''),
        ((int)$r['bloqueado'] === 1 ? 'PROHIBIDO' : 'ACTIVO'),
      ));
    }
    fclose($out);
  }
  exit;
}

$title = 'Comunicacion - Contactos';
include __DIR__ . '/inc/layout_top.php';
?>

<div class="card" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
  <a class="btn secondary" href="panel_admin.php">Volver</a>
  <div>
    <div class="muted" style="font-size:12px;letter-spacing:.08em;text-transform:uppercase;">📣 Comunicacion</div>
    <h2 style="margin:0;">👥 Contactos</h2>
  </div>
  <span class="muted">
    <?php if ($isSuper): ?>
      Vista global de personas conocidas por Tickex.
    <?php else: ?>
      Vista limitada a contactos que interactuaron con tus eventos y envios.
    <?php endif; ?>
  </span>
</div>

<div class="card" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
  <a class="btn" href="superadmin_emails_db.php">👥 Contactos</a>
  <a class="btn secondary" href="comunicacion_audiencias.php">Audiencias</a>
  <a class="btn secondary" href="comunicacion_plantillas.php">Plantillas</a>
  <a class="btn secondary" href="comunicacion_campanas.php">Campanas</a>
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

<div class="card" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;">
  <div>
    <div class="muted" style="font-size:12px;text-transform:uppercase;letter-spacing:.08em;">Contactos</div>
    <div style="font-size:28px;font-weight:800;"><?php echo (int)count($emails); ?></div>
  </div>
  <div>
    <div class="muted" style="font-size:12px;text-transform:uppercase;letter-spacing:.08em;">Registrados</div>
    <div style="font-size:28px;font-weight:800;"><?php echo (int)$registeredCount; ?></div>
  </div>
  <div>
    <div class="muted" style="font-size:12px;text-transform:uppercase;letter-spacing:.08em;">Bloqueados</div>
    <div style="font-size:28px;font-weight:800;"><?php echo (int)$blockedCount; ?></div>
  </div>
  <div>
    <div class="muted" style="font-size:12px;text-transform:uppercase;letter-spacing:.08em;">Fuentes</div>
    <div style="font-size:15px;font-weight:700;">usuarios, registro_pendientes, entradas, email_logs, import_csv, user_blocks</div>
  </div>
</div>

<div class="card" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
  <a class="btn <?php echo $view === 'all' ? '' : 'secondary'; ?>" href="superadmin_emails_db.php?view=all">Todos</a>
  <a class="btn <?php echo $view === 'base' ? '' : 'secondary'; ?>" href="superadmin_emails_db.php?view=base">Base propia</a>
  <a class="btn <?php echo $view === 'imported' ? '' : 'secondary'; ?>" href="superadmin_emails_db.php?view=imported">CSV importados</a>
</div>

<div class="card" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
  <form method="post" enctype="multipart/form-data" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
    <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
    <input type="file" name="csv_file" accept=".csv,text/csv" required>
    <input type="text" name="batch_label" placeholder="Nombre del lote (opcional)">
    <button class="btn" type="submit" name="action" value="import_csv">Importar CSV</button>
  </form>
  <span class="muted">Formato recomendado: email,nombre,rol (con o sin encabezado).</span>
</div>

<div class="card" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
  <form method="get" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
    <input type="hidden" name="view" value="<?php echo e($view); ?>">
    <input name="q" placeholder="Buscar email, nombre o rol" value="<?php echo e($q); ?>" style="min-width:260px;">
    <select name="f_registered">
      <option value="" <?php echo $fRegistered === '' ? 'selected' : ''; ?>>Registrados: Todos</option>
      <option value="si" <?php echo $fRegistered === 'si' ? 'selected' : ''; ?>>Registrados: Si</option>
      <option value="no" <?php echo $fRegistered === 'no' ? 'selected' : ''; ?>>Registrados: No</option>
    </select>
    <select name="f_blocked">
      <option value="" <?php echo $fBlocked === '' ? 'selected' : ''; ?>>Acceso: Todos</option>
      <option value="0" <?php echo $fBlocked === '0' ? 'selected' : ''; ?>>Acceso: Activos</option>
      <option value="1" <?php echo $fBlocked === '1' ? 'selected' : ''; ?>>Acceso: Prohibidos</option>
    </select>
    <select name="f_source">
      <option value="" <?php echo $fSource === '' ? 'selected' : ''; ?>>Fuente: Todas</option>
      <option value="usuarios" <?php echo $fSource === 'usuarios' ? 'selected' : ''; ?>>usuarios</option>
      <option value="registro_pendientes" <?php echo $fSource === 'registro_pendientes' ? 'selected' : ''; ?>>registro_pendientes</option>
      <option value="entradas" <?php echo $fSource === 'entradas' ? 'selected' : ''; ?>>entradas</option>
      <option value="email_logs" <?php echo $fSource === 'email_logs' ? 'selected' : ''; ?>>email_logs</option>
      <option value="import_csv" <?php echo $fSource === 'import_csv' ? 'selected' : ''; ?>>import_csv</option>
    </select>
    <select name="f_import_batch">
      <option value="" <?php echo $fImportBatch === '' ? 'selected' : ''; ?>>Lote CSV: Todos</option>
      <?php foreach ($importBatches as $ib): ?>
        <option value="<?php echo e($ib['label']); ?>" <?php echo $fImportBatch === $ib['label'] ? 'selected' : ''; ?>><?php echo e($ib['label']); ?> (<?php echo (int)$ib['n']; ?>)</option>
      <?php endforeach; ?>
    </select>
    <select name="f_import_file">
      <option value="" <?php echo $fImportFile === '' ? 'selected' : ''; ?>>Archivo CSV: Todos</option>
      <?php foreach ($importFiles as $if): ?>
        <option value="<?php echo e($if); ?>" <?php echo $fImportFile === $if ? 'selected' : ''; ?>><?php echo e($if); ?></option>
      <?php endforeach; ?>
    </select>
    <input type="date" name="f_imported_from" value="<?php echo e($fImportedFrom); ?>" title="Importado desde">
    <input type="date" name="f_imported_to" value="<?php echo e($fImportedTo); ?>" title="Importado hasta">
    <button class="btn secondary" type="submit">Buscar</button>
    <?php if ($q !== '' || $fRegistered !== '' || $fBlocked !== '' || $fSource !== '' || $fImportBatch !== '' || $fImportFile !== '' || $fImportedFrom !== '' || $fImportedTo !== '' || $view !== 'all'): ?>
      <a class="btn secondary" href="superadmin_emails_db.php?view=<?php echo e($view); ?>">Limpiar</a>
    <?php endif; ?>
    <button class="btn secondary" type="submit" name="export" value="csv">Exportar CSV</button>
  </form>
  <span class="muted">Resultado actual: <?php echo (int)count($rows); ?> contactos</span>
</div>

<div class="card" style="overflow:auto;">
  <table class="table" style="width:100%;font-size:14px;">
    <thead>
      <tr>
        <th>Email</th>
        <th>Nombre</th>
        <th>Rol</th>
        <th>Source</th>
        <th>Import batch</th>
        <th>Import file</th>
        <th>Imported at</th>
        <th>Registrado</th>
        <th>Fuentes</th>
        <th>Lotes CSV</th>
        <th>Ultimo envio</th>
        <th>Ultima entrada</th>
        <th>Acceso</th>
        <th>Accion</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?php echo e($r['email']); ?></td>
          <td><?php echo e($r['nombre'] !== '' ? $r['nombre'] : '-'); ?></td>
          <td><?php echo e($r['rol'] !== '' ? $r['rol'] : '-'); ?></td>
          <td><?php echo e(isset($r['source']) && $r['source'] !== '' ? $r['source'] : '-'); ?></td>
          <td><?php echo e(isset($r['import_batch']) && $r['import_batch'] !== '' ? $r['import_batch'] : '-'); ?></td>
          <td><?php echo e(isset($r['import_file']) && $r['import_file'] !== '' ? $r['import_file'] : '-'); ?></td>
          <td><?php echo e(isset($r['imported_at']) && $r['imported_at'] !== '' ? $r['imported_at'] : '-'); ?></td>
          <td><?php echo e($r['registrado']); ?></td>
          <td>
            <?php
              $src = array_keys($r['fuentes']);
              echo e(implode(', ', $src));
            ?>
          </td>
          <td>
            <?php
              $batches = isset($r['imported_batches']) && is_array($r['imported_batches']) ? $r['imported_batches'] : array();
              echo e(!empty($batches) ? implode(', ', $batches) : '-');
            ?>
          </td>
          <td><?php echo e($r['ultimo_envio'] !== '' ? $r['ultimo_envio'] : '-'); ?></td>
          <td><?php echo e($r['ultima_entrada'] !== '' ? $r['ultima_entrada'] : '-'); ?></td>
          <td>
            <?php if ((int)$r['bloqueado'] === 1): ?>
              <span style="display:inline-block;padding:4px 8px;border-radius:999px;background:#5a1a1a;color:#fff;font-weight:700;font-size:12px;">PROHIBIDO</span>
            <?php else: ?>
              <span style="display:inline-block;padding:4px 8px;border-radius:999px;background:#184d2a;color:#fff;font-weight:700;font-size:12px;">ACTIVO</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if ((int)$r['bloqueado'] === 1): ?>
              <form method="post" action="superadmin_emails_db.php" style="display:inline;">
                <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
                <input type="hidden" name="email" value="<?php echo e($r['email']); ?>">
                <button type="submit" name="action" value="desbloquear" style="font-size:12px;padding:4px 8px;">Quitar bloqueo</button>
              </form>
            <?php else: ?>
              <form method="post" action="superadmin_emails_db.php" style="display:flex;gap:4px;align-items:center;">
                <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
                <input type="hidden" name="email" value="<?php echo e($r['email']); ?>">
                <input type="text" name="reason" placeholder="Motivo (opcional)" style="font-size:12px;padding:4px 6px;max-width:170px;">
                <button type="submit" name="action" value="bloquear" style="font-size:12px;padding:4px 8px;background:#8e2b2b;color:#fff;border:1px solid #8e2b2b;border-radius:6px;">PROHIBIDO</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php include __DIR__ . '/inc/layout_bottom.php'; ?>
