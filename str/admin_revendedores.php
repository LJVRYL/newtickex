<?php
require_once __DIR__ . '/inc/bootstrap.php';

require_login();
$cu = current_user();
$tipoGlobal = isset($cu['tipo_global']) ? $cu['tipo_global'] : (isset($_SESSION['tipo_global']) ? $_SESSION['tipo_global'] : '');
$esAdmin = is_admin();

if (!$esAdmin || !in_array($tipoGlobal, array('admin_evento', 'super_admin', 'superadmin'), true)) {
  http_response_code(403);
  include __DIR__ . '/inc/layout_top.php';
  echo '<div class="card" style="max-width:640px;margin:32px auto;"><h2>Acceso restringido</h2><p>Solo para administradores de evento.</p></div>';
  include __DIR__ . '/inc/layout_bottom.php';
  exit;
}

$pdo = db();
$title = 'Mis revendedores';
$csrf = function_exists('tickex_csrf_token') ? tickex_csrf_token() : '';

$adminId = 0;
foreach (array('user_id','admin_id') as $k) {
  if (isset($_SESSION[$k]) && (int)$_SESSION[$k] > 0) { $adminId = (int)$_SESSION[$k]; break; }
}
if ($adminId <= 0 && isset($cu['id'])) $adminId = (int)$cu['id'];

if ($tipoGlobal === 'admin_evento' && $adminId <= 0) {
  http_response_code(500);
  include __DIR__ . '/inc/layout_top.php';
  echo '<div class="card" style="max-width:640px;margin:32px auto;"><h2>Error</h2><p>No se pudo determinar tu ID de admin.</p></div>';
  include __DIR__ . '/inc/layout_bottom.php';
  exit;
}

function _rev_codigo_is_valid($codigo) {
  if ($codigo === '') return true;
  if (strlen($codigo) > 64) return false;
  return (bool)preg_match('/^[a-zA-Z0-9_-]+$/', $codigo);
}

$flashErr = '';
$flashOk = '';

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
    $clienteId = isset($_POST['cliente_id']) ? (int)$_POST['cliente_id'] : 0;

    if ($nombre === '') {
      $flashErr = 'Nombre requerido.';
    } elseif ($comision < 0 || $comision > 100) {
      $flashErr = 'La comisión debe estar entre 0 y 100.';
    } elseif (!_rev_codigo_is_valid($codigo)) {
      $flashErr = 'El código solo puede tener letras/números/_/- (máx 64).';
    } else {
      if ($clienteId <= 0) {
        $clienteId = null;
      } else {
        try {
          $stC = $pdo->prepare('SELECT 1 FROM registro_pendientes WHERE id = :id LIMIT 1');
          $stC->execute(array(':id' => $clienteId));
          if (!$stC->fetchColumn()) $clienteId = null;
        } catch (Exception $e) {
          $clienteId = null;
        }
      }

      try {
        if ($id > 0) {
          // seguridad: un admin_evento solo edita sus revendedores
          $where = 'id = :id';
          $params = array(
            ':id' => $id,
            ':c' => ($codigo !== '' ? $codigo : null),
            ':n' => $nombre,
            ':p' => $comision,
            ':a' => $activo,
            ':cid' => $clienteId,
          );
          if ($tipoGlobal === 'admin_evento') {
            $where .= ' AND owner_admin_id = :oid';
            $params[':oid'] = $adminId;
          }
          $st = $pdo->prepare("UPDATE revendedores SET codigo=:c, nombre=:n, comision_percent=:p, activo=:a, cliente_id=:cid WHERE $where");
          $st->execute($params);
          $flashOk = ($st->rowCount() > 0) ? 'Revendedor actualizado.' : 'No autorizado o revendedor inexistente.';
        } else {
          if ($tipoGlobal === 'admin_evento') {
            $ownerId = $adminId;
          } else {
            // superadmin puede forzar owner_admin_id (opcional)
            $ownerId = isset($_POST['owner_admin_id']) ? (int)$_POST['owner_admin_id'] : 0;
            if ($ownerId <= 0) $ownerId = null;
          }

          $st = $pdo->prepare('INSERT INTO revendedores (owner_admin_id, cliente_id, codigo, nombre, comision_percent, activo) VALUES (:oid,:cid,:c,:n,:p,:a)');
          $st->execute(array(
            ':oid' => $ownerId,
            ':cid' => $clienteId,
            ':c' => ($codigo !== '' ? $codigo : null),
            ':n' => $nombre,
            ':p' => $comision,
            ':a' => $activo,
          ));
          $flashOk = 'Revendedor creado.';
        }

        header('Location: admin_revendedores.php');
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
        $where = 'id = :id';
        $params = array(':a' => $activo, ':id' => $id);
        if ($tipoGlobal === 'admin_evento') {
          $where .= ' AND owner_admin_id = :oid';
          $params[':oid'] = $adminId;
        }
        $st = $pdo->prepare("UPDATE revendedores SET activo = :a WHERE $where");
        $st->execute($params);
        header('Location: admin_revendedores.php');
        exit;
      } catch (Exception $e) {
        $flashErr = 'No se pudo actualizar el estado.';
      }
    }
  }

  if ($action === 'req_decision') {
    $reqId = isset($_POST['req_id']) ? (int)$_POST['req_id'] : 0;
    $decision = isset($_POST['decision']) ? (string)$_POST['decision'] : '';
    if ($reqId > 0 && ($decision === 'approve' || $decision === 'reject')) {
      try {
        $stR = $pdo->prepare("SELECT * FROM revendedor_solicitudes WHERE id = :id AND estado = 'pending' LIMIT 1");
        $stR->execute(array(':id' => $reqId));
        $req = $stR->fetch(PDO::FETCH_ASSOC);

        if (!$req) {
          $flashErr = 'Solicitud inexistente.';
        } elseif ($tipoGlobal === 'admin_evento' && isset($req['owner_admin_id']) && (int)$req['owner_admin_id'] !== $adminId) {
          $flashErr = 'No autorizado.';
        } else {
          if ($decision === 'reject') {
            $stU = $pdo->prepare("UPDATE revendedor_solicitudes SET estado='rejected', updated_at=datetime('now') WHERE id=:id");
            $stU->execute(array(':id' => $reqId));
            header('Location: admin_revendedores.php');
            exit;
          }

          // Approve: crear revendedor y linkear
          $clienteId = isset($req['cliente_id']) ? (int)$req['cliente_id'] : 0;
          $nombre = '';
          try {
            $stC = $pdo->prepare('SELECT nombre, apellido, apodo, email FROM registro_pendientes WHERE id = :id LIMIT 1');
            $stC->execute(array(':id' => $clienteId));
            $c = $stC->fetch(PDO::FETCH_ASSOC);
            if ($c) {
              $nombre = trim((string)($c['apodo'] ?? ''));
              if ($nombre === '') {
                $nombre = trim((string)($c['nombre'] ?? '') . ' ' . (string)($c['apellido'] ?? ''));
              }
              if ($nombre === '') $nombre = (string)($c['email'] ?? 'Revendedor');
            }
          } catch (Exception $e) {}
          if ($nombre === '') $nombre = 'Revendedor';

          $stIns = $pdo->prepare('INSERT INTO revendedores (owner_admin_id, cliente_id, nombre, comision_percent, activo) VALUES (:oid,:cid,:n,0,1)');
          $stIns->execute(array(
            ':oid' => (isset($req['owner_admin_id']) && (int)$req['owner_admin_id'] > 0) ? (int)$req['owner_admin_id'] : ($tipoGlobal === 'admin_evento' ? $adminId : null),
            ':cid' => ($clienteId > 0 ? $clienteId : null),
            ':n' => $nombre,
          ));
          $newRid = (int)$pdo->lastInsertId();

          $stUp = $pdo->prepare("UPDATE revendedor_solicitudes SET estado='approved', revendedor_id=:rid, updated_at=datetime('now') WHERE id=:id");
          $stUp->execute(array(':rid' => $newRid, ':id' => $reqId));

          header('Location: admin_revendedores.php');
          exit;
        }
      } catch (Exception $e) {
        $flashErr = 'No se pudo procesar la solicitud.';
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
    $where = 'id = :id';
    $params = array(':id' => $editId);
    if ($tipoGlobal === 'admin_evento') {
      $where .= ' AND owner_admin_id = :oid';
      $params[':oid'] = $adminId;
    }
    $st = $pdo->prepare("SELECT * FROM revendedores WHERE $where LIMIT 1");
    $st->execute($params);
    $edit = $st->fetch(PDO::FETCH_ASSOC);
  } catch (Exception $e) {
    $edit = null;
  }
}

$rows = array();
try {
  $where = array();
  $params = array();
  if ($tipoGlobal === 'admin_evento') {
    $where[] = 'owner_admin_id = :oid';
    $params[':oid'] = $adminId;
  }
  if ($q !== '') {
    $where[] = '(nombre LIKE :q OR codigo LIKE :q OR CAST(id AS TEXT) = :qid)';
    $params[':q'] = '%' . $q . '%';
    $params[':qid'] = $q;
  }
  $sql = 'SELECT * FROM revendedores';
  if (!empty($where)) $sql .= ' WHERE ' . implode(' AND ', $where);
  $sql .= ' ORDER BY id DESC LIMIT 500';
  $st = $pdo->prepare($sql);
  $st->execute($params);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
  $rows = array();
}

$solicitudes = array();
try {
  $w = array("estado = 'pending'");
  $p = array();
  if ($tipoGlobal === 'admin_evento') {
    $w[] = 'owner_admin_id = :oid';
    $p[':oid'] = $adminId;
  }
  $sql = 'SELECT * FROM revendedor_solicitudes';
  if (!empty($w)) $sql .= ' WHERE ' . implode(' AND ', $w);
  $sql .= ' ORDER BY id DESC LIMIT 200';
  $st = $pdo->prepare($sql);
  $st->execute($p);
  $solicitudes = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
  $solicitudes = array();
}

include __DIR__ . '/inc/layout_top.php';
?>

<div class="card" style="max-width:1100px;margin:16px auto;">
  <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:center;">
    <div>
      <h1 style="margin:0;">Revendedores</h1>
      <div class="muted" style="margin-top:4px;">Compartí links con <strong>?aff=ID</strong> para atribuir ventas por cookie.</div>
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
  <h2 style="margin-top:0;">Solicitudes pendientes</h2>
  <?php if (empty($solicitudes)): ?>
    <div class="muted">No hay solicitudes pendientes.</div>
  <?php else: ?>
    <div style="overflow:auto;">
      <table class="table" style="width:100%;min-width:860px;">
        <thead>
          <tr>
            <th style="width:60px;">ID</th>
            <th style="width:220px;">Cliente</th>
            <th>Mensaje</th>
            <th style="width:120px;">Evento</th>
            <th style="width:200px;">Acciones</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($solicitudes as $s): ?>
            <tr>
              <td><?php echo (int)$s['id']; ?></td>
              <td>
                <div style="font-weight:700;">#<?php echo (int)$s['cliente_id']; ?></div>
                <div class="muted" style="font-size:12px;"><?php echo e((string)($s['cliente_email'] ?? '')); ?></div>
              </td>
              <td><?php echo e((string)($s['mensaje'] ?? '')); ?></td>
              <td><?php echo !empty($s['evento_id']) ? (int)$s['evento_id'] : '<span class="muted">—</span>'; ?></td>
              <td>
                <form method="post" style="display:inline;">
                  <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
                  <input type="hidden" name="action" value="req_decision">
                  <input type="hidden" name="req_id" value="<?php echo (int)$s['id']; ?>">
                  <input type="hidden" name="decision" value="approve">
                  <button class="btn" type="submit">Aprobar</button>
                </form>
                <form method="post" style="display:inline;">
                  <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
                  <input type="hidden" name="action" value="req_decision">
                  <input type="hidden" name="req_id" value="<?php echo (int)$s['id']; ?>">
                  <input type="hidden" name="decision" value="reject">
                  <button class="btn danger" type="submit">Rechazar</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
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
      <input type="text" name="nombre" value="<?php echo $edit ? e((string)$edit['nombre']) : ''; ?>" required>
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
      Cliente ID (opcional)
      <input type="number" min="0" name="cliente_id" value="<?php echo $edit && !empty($edit['cliente_id']) ? (int)$edit['cliente_id'] : 0; ?>" placeholder="ID en registro_pendientes">
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
        <a class="btn secondary" href="admin_revendedores.php">Cancelar</a>
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
        <a class="btn secondary" href="admin_revendedores.php">Limpiar</a>
      <?php endif; ?>
    </form>
  </div>

  <div style="overflow:auto;margin-top:12px;">
    <table class="table" style="width:100%;min-width:900px;">
      <thead>
        <tr>
          <th style="width:60px;">ID</th>
          <th>Nombre</th>
          <th style="width:180px;">Código</th>
          <th style="width:110px;">Comisión</th>
          <th style="width:110px;">Cliente</th>
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
                <div class="muted" style="font-size:12px;">Link: <strong>?aff=<?php echo (int)$r['id']; ?></strong></div>
              </td>
              <td><?php echo !empty($r['codigo']) ? e((string)$r['codigo']) : '<span class="muted">—</span>'; ?></td>
              <td><?php echo e((string)$r['comision_percent']); ?>%</td>
              <td><?php echo !empty($r['cliente_id']) ? (int)$r['cliente_id'] : '<span class="muted">—</span>'; ?></td>
              <td><?php echo ((int)$r['activo'] === 1) ? 'Sí' : 'No'; ?></td>
              <td>
                <a class="btn secondary" href="admin_revendedores.php?edit=<?php echo (int)$r['id']; ?>">Editar</a>
                <form method="post" style="display:inline;">
                  <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
                  <input type="hidden" name="action" value="toggle">
                  <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                  <input type="hidden" name="activo" value="<?php echo ((int)$r['activo'] === 1) ? '0' : '1'; ?>">
                  <button class="btn <?php echo ((int)$r['activo'] === 1) ? 'danger' : 'secondary'; ?>" type="submit"><?php echo ((int)$r['activo'] === 1) ? 'Desactivar' : 'Activar'; ?></button>
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
