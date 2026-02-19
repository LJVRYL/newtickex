<?php
require_once __DIR__ . '/inc/bootstrap.php';

require_login();
$cu = current_user();
$tipoGlobal = isset($cu['tipo_global']) ? $cu['tipo_global'] : (isset($_SESSION['tipo_global']) ? $_SESSION['tipo_global'] : '');
$esAdmin = is_admin();
if (!$esAdmin || !in_array($tipoGlobal, array('super_admin', 'superadmin'), true)) {
  http_response_code(403);
  include __DIR__ . '/inc/layout_top.php';
  echo '<div class="card" style="max-width:640px;margin:32px auto;"><h2>Acceso restringido</h2><p>Solo para superadministradores.</p></div>';
  include __DIR__ . '/inc/layout_bottom.php';
  exit;
}

$pdo = db();
$title = 'Revendedores';
$csrf = function_exists('tickex_csrf_token') ? tickex_csrf_token() : '';

$flashErr = '';
$flashOk = '';

function _rev_codigo_is_valid($codigo) {
  if ($codigo === '') return true;
  if (strlen($codigo) > 64) return false;
  return (bool)preg_match('/^[a-zA-Z0-9_-]+$/', $codigo);
}

// --- POST actions ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $provided = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
  if (function_exists('tickex_csrf_verify') && !tickex_csrf_verify($provided)) {
    http_response_code(403);
    include __DIR__ . '/inc/layout_top.php';
    echo '<div class="card" style="max-width:640px;margin:32px auto;"><h2>CSRF inválido</h2><p>Actualizá la página e intentá de nuevo.</p></div>';
    include __DIR__ . '/inc/layout_bottom.php';
    exit;
  }

  $action = isset($_POST['action']) ? (string)$_POST['action'] : '';

  if ($action === 'save') {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $codigo = isset($_POST['codigo']) ? trim((string)$_POST['codigo']) : '';
    $nombre = isset($_POST['nombre']) ? trim((string)$_POST['nombre']) : '';
    $comision = isset($_POST['comision_percent']) ? (float)$_POST['comision_percent'] : 0.0;
    $activo = isset($_POST['activo']) && (string)$_POST['activo'] === '1' ? 1 : 0;
    $usuarioAdminId = isset($_POST['usuario_admin_id']) ? (int)$_POST['usuario_admin_id'] : 0;

    if ($nombre === '') {
      $flashErr = 'Nombre requerido.';
    } elseif ($comision < 0 || $comision > 100) {
      $flashErr = 'La comisión debe estar entre 0 y 100.';
    } elseif (!_rev_codigo_is_valid($codigo)) {
      $flashErr = 'El código solo puede tener letras/números/_/- (máx 64).';
    } else {
      // validar usuario_admin_id si se setea
      if ($usuarioAdminId <= 0) {
        $usuarioAdminId = null;
      } else {
        try {
          $stU = $pdo->prepare('SELECT 1 FROM usuarios_admin WHERE id = :id LIMIT 1');
          $stU->execute(array(':id' => $usuarioAdminId));
          if (!$stU->fetchColumn()) {
            $usuarioAdminId = null;
          }
        } catch (Exception $e) {
          $usuarioAdminId = null;
        }
      }

      try {
        if ($id > 0) {
          $st = $pdo->prepare('UPDATE revendedores SET usuario_admin_id=:uid, codigo=:c, nombre=:n, comision_percent=:p, activo=:a WHERE id=:id');
          $st->execute(array(
            ':uid' => $usuarioAdminId,
            ':c' => ($codigo !== '' ? $codigo : null),
            ':n' => $nombre,
            ':p' => $comision,
            ':a' => $activo,
            ':id' => $id,
          ));
          $flashOk = 'Revendedor actualizado.';
        } else {
          $st = $pdo->prepare('INSERT INTO revendedores (usuario_admin_id, codigo, nombre, comision_percent, activo) VALUES (:uid,:c,:n,:p,:a)');
          $st->execute(array(
            ':uid' => $usuarioAdminId,
            ':c' => ($codigo !== '' ? $codigo : null),
            ':n' => $nombre,
            ':p' => $comision,
            ':a' => $activo,
          ));
          $flashOk = 'Revendedor creado.';
        }

        header('Location: superadmin_revendedores.php');
        exit;
      } catch (Exception $e) {
        $flashErr = 'No se pudo guardar (¿código duplicado?).';
      }
    }
  }

  if ($action === 'toggle') {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $activo = isset($_POST['activo']) && (string)$_POST['activo'] === '1' ? 1 : 0;
    if ($id > 0) {
      try {
        $st = $pdo->prepare('UPDATE revendedores SET activo = :a WHERE id = :id');
        $st->execute(array(':a' => $activo, ':id' => $id));
        header('Location: superadmin_revendedores.php');
        exit;
      } catch (Exception $e) {
        $flashErr = 'No se pudo actualizar el estado.';
      }
    }
  }
}

// --- GET: edit, search, list ---
$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;

$edit = null;
if ($editId > 0) {
  try {
    $st = $pdo->prepare('SELECT * FROM revendedores WHERE id = :id LIMIT 1');
    $st->execute(array(':id' => $editId));
    $edit = $st->fetch(PDO::FETCH_ASSOC);
  } catch (Exception $e) {
    $edit = null;
  }
}

$rows = array();
try {
  if ($q !== '') {
    $st = $pdo->prepare("SELECT * FROM revendedores WHERE nombre LIKE :q OR codigo LIKE :q OR CAST(id AS TEXT) = :id ORDER BY id DESC LIMIT 500");
    $st->execute(array(':q' => '%' . $q . '%', ':id' => $q));
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
  } else {
    $st = $pdo->query('SELECT * FROM revendedores ORDER BY id DESC LIMIT 500');
    $rows = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : array();
  }
} catch (Exception $e) {
  $rows = array();
}

include __DIR__ . '/inc/layout_top.php';
?>

<div class="card" style="max-width:1100px;margin:16px auto;">
  <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:center;">
    <div>
      <h1 style="margin:0;">Revendedores</h1>
      <div class="muted" style="margin-top:4px;">Tracking: usá <strong>?aff=ID</strong> (cookie 30 días, last-click).</div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
      <a class="btn secondary" href="panel_admin.php">⬅ Volver</a>
    </div>
  </div>

  <?php if ($flashErr !== ''): ?>
    <div class="flash err" style="margin-top:12px;"><?php echo e($flashErr); ?></div>
  <?php endif; ?>
  <?php if ($flashOk !== ''): ?>
    <div class="flash ok" style="margin-top:12px;"><?php echo e($flashOk); ?></div>
  <?php endif; ?>
</div>

<div class="card" style="max-width:1100px;margin:16px auto;">
  <h2 style="margin-top:0;"><?php echo $edit ? 'Editar revendedor' : 'Nuevo revendedor'; ?></h2>
  <form method="post" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;align-items:end;">
    <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?php echo $edit ? (int)$edit['id'] : 0; ?>">

    <label>
      Nombre
      <input type="text" name="nombre" value="<?php echo $edit ? e($edit['nombre']) : ''; ?>" required>
    </label>

    <label>
      Código (opcional)
      <input type="text" name="codigo" value="<?php echo $edit ? e((string)($edit['codigo'] ?? '')) : ''; ?>" placeholder="ej: juan_afiliado">
    </label>

    <label>
      Comisión (%)
      <input type="number" step="0.01" min="0" max="100" name="comision_percent" value="<?php echo $edit ? e((string)($edit['comision_percent'] ?? '0')) : '0'; ?>">
    </label>

    <label>
      Usuario admin ID (opcional)
      <input type="number" min="0" name="usuario_admin_id" value="<?php echo $edit && !empty($edit['usuario_admin_id']) ? (int)$edit['usuario_admin_id'] : 0; ?>" placeholder="ID de usuarios_admin">
    </label>

    <label>
      Activo
      <select name="activo">
        <option value="1" <?php echo (!$edit || (int)$edit['activo'] === 1) ? 'selected' : ''; ?>>Sí</option>
        <option value="0" <?php echo ($edit && (int)$edit['activo'] === 0) ? 'selected' : ''; ?>>No</option>
      </select>
    </label>

    <div style="display:flex;gap:8px;align-items:center;">
      <button class="btn" type="submit"><?php echo $edit ? 'Guardar cambios' : 'Crear'; ?></button>
      <?php if ($edit): ?>
        <a class="btn secondary" href="superadmin_revendedores.php">Cancelar</a>
      <?php endif; ?>
    </div>
  </form>
</div>

<div class="card" style="max-width:1100px;margin:16px auto;">
  <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:center;">
    <h2 style="margin:0;">Listado</h2>
    <form method="get" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
      <input type="text" name="q" value="<?php echo e($q); ?>" placeholder="Buscar por nombre/código/ID" style="min-width:240px;">
      <button class="btn secondary" type="submit">Buscar</button>
      <?php if ($q !== ''): ?>
        <a class="btn secondary" href="superadmin_revendedores.php">Limpiar</a>
      <?php endif; ?>
    </form>
  </div>

  <div style="overflow:auto;margin-top:12px;">
    <table class="table" style="width:100%;min-width:860px;">
      <thead>
        <tr>
          <th style="width:60px;">ID</th>
          <th>Nombre</th>
          <th style="width:180px;">Código</th>
          <th style="width:110px;">Comisión</th>
          <th style="width:120px;">Usuario admin</th>
          <th style="width:90px;">Activo</th>
          <th style="width:260px;">Acciones</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($rows)): ?>
          <tr><td colspan="7" class="muted">No hay revendedores.</td></tr>
        <?php else: ?>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><?php echo (int)$r['id']; ?></td>
              <td>
                <div style="font-weight:700;"><?php echo e((string)$r['nombre']); ?></div>
                <div class="muted" style="font-size:12px;">Param: <strong>?aff=<?php echo (int)$r['id']; ?></strong></div>
              </td>
              <td><?php echo !empty($r['codigo']) ? e((string)$r['codigo']) : '<span class="muted">—</span>'; ?></td>
              <td><?php echo e((string)$r['comision_percent']); ?>%</td>
              <td><?php echo !empty($r['usuario_admin_id']) ? (int)$r['usuario_admin_id'] : '<span class="muted">—</span>'; ?></td>
              <td><?php echo ((int)$r['activo'] === 1) ? 'Sí' : 'No'; ?></td>
              <td>
                <a class="btn secondary" href="superadmin_revendedores.php?edit=<?php echo (int)$r['id']; ?>">Editar</a>
                <form method="post" style="display:inline;">
                  <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
                  <input type="hidden" name="action" value="toggle">
                  <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                  <input type="hidden" name="activo" value="<?php echo ((int)$r['activo'] === 1) ? '0' : '1'; ?>">
                  <button class="btn <?php echo ((int)$r['activo'] === 1) ? 'danger' : 'secondary'; ?>" type="submit">
                    <?php echo ((int)$r['activo'] === 1) ? 'Desactivar' : 'Activar'; ?>
                  </button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/inc/layout_bottom.php'; ?>
