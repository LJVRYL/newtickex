<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/staff_roles.php';

$title = 'Roles de Staff';
require_login();

$cu = current_user();
$tipoGlobal = isset($_SESSION['tipo_global']) ? $_SESSION['tipo_global'] : (isset($cu['rol']) ? $cu['rol'] : '');
if (!in_array($tipoGlobal, array('admin_evento','super_admin','superadmin'), true)) {
  header('Location: login.php');
  exit;
}

$adminId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : (isset($cu['id']) ? (int)$cu['id'] : 0);
if ($adminId <= 0) {
  header('Location: panel_admin.php');
  exit;
}

$pdo = db();
tickex_staff_roles_ensure_table($pdo);
tickex_staff_roles_seed_defaults($pdo, $adminId);
$catalog = tickex_staff_roles_permissions_catalog();

function _tickex_role_slug($s)
{
  $s = trim((string)$s);
  if ($s === '') return '';
  $s = strtolower($s);
  $s = preg_replace('/[^a-z0-9_\-]+/', '_', $s);
  $s = preg_replace('/_+/', '_', $s);
  $s = trim($s, '_-');
  return $s;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $provided = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
  if (function_exists('tickex_csrf_verify') && !tickex_csrf_verify($provided)) {
    flash('err', 'CSRF inválido.');
    header('Location: roles_staff.php');
    exit;
  }

  $action = isset($_POST['action']) ? (string)$_POST['action'] : '';

  if ($action === 'create_role') {
    $name = trim((string)(isset($_POST['name']) ? $_POST['name'] : ''));
    $code = _tickex_role_slug(isset($_POST['code']) ? $_POST['code'] : '');
    if ($code === '' && $name !== '') $code = _tickex_role_slug($name);
    $perms = isset($_POST['permissions']) && is_array($_POST['permissions']) ? $_POST['permissions'] : array();

    $validPerms = array();
    foreach ($perms as $p) {
      $k = (string)$p;
      if (isset($catalog[$k])) $validPerms[] = $k;
    }

    if ($name === '') {
      flash('warn', 'El nombre del rol es obligatorio.');
    } elseif ($code === '' || strlen($code) < 2) {
      flash('warn', 'El código del rol es inválido.');
    } else {
      try {
        $st = $pdo->prepare('SELECT id FROM staff_roles WHERE owner_admin_id = :oid AND code = :c LIMIT 1');
        $st->execute(array(':oid' => $adminId, ':c' => $code));
        if ($st->fetchColumn()) {
          flash('warn', 'Ya existe un rol con ese código.');
        } else {
          $ins = $pdo->prepare('INSERT INTO staff_roles (owner_admin_id, code, name, permissions_json, is_system, activo, created_at) VALUES (:oid,:c,:n,:p,0,1,datetime(\'now\'))');
          $ins->execute(array(
            ':oid' => $adminId,
            ':c' => $code,
            ':n' => $name,
            ':p' => json_encode(array_values($validPerms)),
          ));
          flash('ok', 'Rol creado.');
        }
      } catch (Exception $e) {
        flash('err', 'No se pudo crear el rol.');
      }
    }
  }

  if ($action === 'update_role') {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $name = trim((string)(isset($_POST['name']) ? $_POST['name'] : ''));
    $perms = isset($_POST['permissions']) && is_array($_POST['permissions']) ? $_POST['permissions'] : array();

    $validPerms = array();
    foreach ($perms as $p) {
      $k = (string)$p;
      if (isset($catalog[$k])) $validPerms[] = $k;
    }

    if ($id <= 0 || $name === '') {
      flash('warn', 'Datos inválidos para actualizar rol.');
    } else {
      try {
        $up = $pdo->prepare('UPDATE staff_roles SET name = :n, permissions_json = :p, updated_at = datetime(\'now\') WHERE id = :id AND owner_admin_id = :oid');
        $up->execute(array(
          ':n' => $name,
          ':p' => json_encode(array_values($validPerms)),
          ':id' => $id,
          ':oid' => $adminId,
        ));
        flash('ok', 'Rol actualizado.');
      } catch (Exception $e) {
        flash('err', 'No se pudo actualizar el rol.');
      }
    }
  }

  if ($action === 'delete_role') {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    if ($id <= 0) {
      flash('warn', 'Rol inválido.');
    } else {
      try {
        $st = $pdo->prepare('SELECT code, is_system FROM staff_roles WHERE id = :id AND owner_admin_id = :oid LIMIT 1');
        $st->execute(array(':id' => $id, ':oid' => $adminId));
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) {
          flash('warn', 'Rol inexistente.');
        } elseif ((int)$r['is_system'] === 1) {
          flash('warn', 'No podés eliminar roles del sistema.');
        } else {
          $code = (string)$r['code'];
          $stU = $pdo->prepare('SELECT 1 FROM staff_admins WHERE owner_admin_id = :oid AND activo = 1 AND COALESCE(rol_staff,\'\') = :r LIMIT 1');
          $stU->execute(array(':oid' => $adminId, ':r' => $code));
          if ($stU->fetchColumn()) {
            flash('warn', 'No podés eliminarlo porque está en uso por miembros del staff.');
          } else {
            $del = $pdo->prepare('DELETE FROM staff_roles WHERE id = :id AND owner_admin_id = :oid');
            $del->execute(array(':id' => $id, ':oid' => $adminId));
            flash('ok', 'Rol eliminado.');
          }
        }
      } catch (Exception $e) {
        flash('err', 'No se pudo eliminar el rol.');
      }
    }
  }

  header('Location: roles_staff.php');
  exit;
}

$roles = tickex_staff_roles_get_all($pdo, $adminId);

include __DIR__ . '/inc/layout_top.php';
?>

<style>
  .roles-wrap { max-width: 1040px; margin: 0 auto; display: grid; gap: 14px; }
  .roles-toolbar { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
  .roles-title { margin:0; }
  .roles-sub { margin-top:6px; color:var(--muted); font-size:14px; }
  .roles-form-grid { display:grid; grid-template-columns:1fr 1fr; gap:10px; align-items:end; }
  .roles-perm-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:8px; }
  .roles-perm-item { display:flex; gap:8px; align-items:center; border:1px solid var(--line); padding:9px 10px; border-radius:10px; background:var(--panel-2); }
  .roles-list { display:flex; flex-direction:column; gap:10px; }
  .roles-item { border:1px solid var(--line); border-radius:12px; background:var(--panel-2); padding:12px; }
  .roles-item-top { display:grid; grid-template-columns:1.2fr 1fr auto auto; gap:8px; align-items:end; }
  .roles-chip { display:inline-block; padding:3px 8px; border-radius:999px; border:1px solid var(--line); font-size:12px; color:var(--muted); }
  @media (max-width: 860px) {
    .roles-form-grid, .roles-item-top { grid-template-columns:1fr; }
  }
</style>

<div class="roles-wrap">
<div class="card roles-toolbar">
  <a class="btn secondary" href="secundarios.php">⬅ Volver a Staff</a>
  <a class="btn secondary" href="panel_admin.php">Panel</a>
</div>

<div class="card">
  <h2 class="roles-title">Roles de Staff</h2>
  <div class="roles-sub">Creá, editá y eliminá roles. Luego asigná estos roles al staff.</div>
</div>

<div class="card">
  <h3>Crear rol</h3>
  <form method="post" class="roles-form-grid">
    <input type="hidden" name="csrf" value="<?php echo e(tickex_csrf_token()); ?>">
    <input type="hidden" name="action" value="create_role">

    <label>Nombre del rol
      <input type="text" name="name" required placeholder="Ej: Backoffice">
    </label>

    <label>Código (opcional)
      <input type="text" name="code" placeholder="Ej: backoffice">
    </label>

    <div style="grid-column:1 / -1;" class="roles-perm-grid">
      <?php foreach ($catalog as $pk => $pl): ?>
        <label class="roles-perm-item">
          <input type="checkbox" name="permissions[]" value="<?php echo e($pk); ?>">
          <span><?php echo e($pl); ?></span>
        </label>
      <?php endforeach; ?>
    </div>

    <div style="grid-column:1 / -1;">
      <button class="btn" type="submit">Crear rol</button>
    </div>
  </form>
</div>

<div class="card">
  <h3>Roles existentes</h3>
  <?php if (empty($roles)): ?>
    <div class="muted">No hay roles cargados.</div>
  <?php else: ?>
    <div class="roles-list">
      <?php foreach ($roles as $r): ?>
        <?php
          $rid = (int)$r['id'];
          $rname = (string)$r['name'];
          $rcode = (string)$r['code'];
          $rperms = isset($r['permissions']) && is_array($r['permissions']) ? $r['permissions'] : array();
          $isSystem = isset($r['is_system']) && (int)$r['is_system'] === 1;
        ?>
        <div class="roles-item">
          <form method="post" class="roles-item-top">
            <input type="hidden" name="csrf" value="<?php echo e(tickex_csrf_token()); ?>">
            <input type="hidden" name="action" value="update_role">
            <input type="hidden" name="id" value="<?php echo $rid; ?>">

            <label>Nombre
              <input type="text" name="name" value="<?php echo e($rname); ?>" required>
            </label>

            <label>Código
              <input type="text" value="<?php echo e($rcode); ?>" disabled>
            </label>

            <div>
              <button class="btn secondary" type="submit">Guardar</button>
            </div>

            <div>
              <?php if (!$isSystem): ?>
                <button class="btn danger" name="action" value="delete_role" onclick="return confirm('¿Eliminar rol <?php echo e($rname); ?>?');">Eliminar</button>
              <?php else: ?>
                <span class="roles-chip">Sistema</span>
              <?php endif; ?>
            </div>

            <div style="grid-column:1 / -1;" class="roles-perm-grid">
              <?php foreach ($catalog as $pk => $pl): ?>
                <?php $checked = in_array($pk, $rperms, true); ?>
                <label class="roles-perm-item">
                  <input type="checkbox" name="permissions[]" value="<?php echo e($pk); ?>" <?php echo $checked ? 'checked' : ''; ?>>
                  <span><?php echo e($pl); ?></span>
                </label>
              <?php endforeach; ?>
            </div>
          </form>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
</div>

<?php include __DIR__ . '/inc/layout_bottom.php'; ?>
