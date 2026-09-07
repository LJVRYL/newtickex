<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/staff_roles.php';
require_once __DIR__ . '/inc/staff_operations.php';

$title = 'Roles de Staff';
$cu = current_user();
$tipoGlobal = isset($_SESSION['tipo_global']) ? $_SESSION['tipo_global'] : (isset($cu['rol']) ? $cu['rol'] : '');
$adminContext = isset($_SESSION['auth_context']) && $_SESSION['auth_context'] === 'admin';
if (!$adminContext || !in_array($tipoGlobal, array('admin_evento','super_admin','superadmin'), true)) {
  header('Location: /login_admin.php?next=' . urlencode('/roles_staff.php'));
  exit;
}

$adminId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : (isset($cu['id']) ? (int)$cu['id'] : 0);
if ($adminId <= 0) {
  header('Location: panel_admin.php');
  exit;
}

$pdo = db();
tickex_staff_operations_ensure_schema($pdo);
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
          tickex_staff_audit($pdo,$adminId,$adminId,'role_created',null,null,array('code'=>$code,'permissions'=>$validPerms));
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
        tickex_staff_audit($pdo,$adminId,$adminId,'role_updated',null,null,array('role_id'=>$id,'permissions'=>$validPerms));
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
            tickex_staff_audit($pdo,$adminId,$adminId,'role_deleted',null,null,array('code'=>$code));
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
$systemRoles = array();
$customRoles = array();
foreach ($roles as $roleRow) {
  if (!empty($roleRow['is_system'])) $systemRoles[] = $roleRow;
  else $customRoles[] = $roleRow;
}
$roleAudit = array();
try {
  $stAudit=$pdo->prepare("SELECT action,detail_json,created_at FROM staff_audit_log WHERE owner_admin_id=:owner AND evento_id IS NULL ORDER BY id DESC LIMIT 20");
  $stAudit->execute(array(':owner'=>$adminId));
  $roleAudit=$stAudit->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e) {}

include __DIR__ . '/inc/layout_top.php';
?>

<style>
.roles-page{max-width:1120px;margin:0 auto;display:grid;gap:16px}.roles-top{display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap}.roles-actions{display:flex;gap:8px;flex-wrap:wrap}.roles-hero{position:relative;overflow:hidden;padding:30px;background:radial-gradient(circle at 88% 15%,rgba(45,212,191,.15),transparent 28%),linear-gradient(135deg,rgba(44,35,104,.95),rgba(12,19,34,.97))}.roles-kicker{color:#55e4c3;font-size:11px;font-weight:850;letter-spacing:.13em;text-transform:uppercase}.roles-hero h1{font-size:clamp(31px,5vw,48px);margin:7px 0}.roles-hero p{max-width:700px;margin:0;color:var(--muted);line-height:1.55}.roles-summary{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-top:24px}.roles-stat{padding:14px 16px;border:1px solid rgba(255,255,255,.09);border-radius:14px;background:rgba(6,10,22,.35)}.roles-stat span{display:block;color:var(--muted);font-size:11px;font-weight:750;text-transform:uppercase;letter-spacing:.06em}.roles-stat strong{display:block;margin-top:4px;font-size:24px}.roles-how{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}.roles-step{padding:16px;border-left:3px solid #6548ef;background:var(--panel-2);border-radius:12px}.roles-step b{display:block;margin-bottom:4px}.roles-step span{color:var(--muted);font-size:12px;line-height:1.45}.roles-section-head{display:flex;justify-content:space-between;align-items:end;gap:12px;margin-bottom:14px}.roles-section-head h2{margin:0}.roles-section-head p{margin:4px 0 0;color:var(--muted);font-size:13px}.role-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.role-card{border:1px solid var(--line);border-radius:17px;background:linear-gradient(145deg,rgba(18,26,43,.88),rgba(9,14,27,.8));overflow:hidden}.role-overview{padding:18px}.role-card-top{display:flex;justify-content:space-between;align-items:flex-start;gap:10px}.role-name{font-size:20px;font-weight:850}.role-code{margin-top:3px;color:var(--muted);font:11px ui-monospace,SFMono-Regular,Consolas,monospace}.role-type{display:inline-flex;padding:5px 8px;border-radius:999px;background:rgba(84,226,193,.1);color:#54e3c1;font-size:10px;font-weight:850;letter-spacing:.06em}.role-type.custom{background:rgba(111,76,244,.14);color:#b6a7ff}.role-desc{min-height:38px;margin:12px 0;color:var(--muted);font-size:13px;line-height:1.5}.role-capabilities{display:flex;gap:6px;flex-wrap:wrap}.role-capability{padding:5px 8px;border:1px solid rgba(255,255,255,.08);border-radius:999px;background:rgba(255,255,255,.025);font-size:10px;font-weight:750}.role-editor{border-top:1px solid var(--line)}.role-editor>summary,.roles-create>summary,.roles-history>summary{list-style:none;cursor:pointer;padding:13px 18px;font-size:12px;font-weight:800;color:#b7adff}.role-editor>summary::-webkit-details-marker,.roles-create>summary::-webkit-details-marker,.roles-history>summary::-webkit-details-marker{display:none}.role-editor>summary:after,.roles-create>summary:after,.roles-history>summary:after{content:'+';float:right;font-size:18px}.role-editor[open]>summary:after,.roles-create[open]>summary:after,.roles-history[open]>summary:after{content:'−'}.role-form{padding:0 18px 18px;display:grid;gap:12px}.role-fields{display:grid;grid-template-columns:1fr 1fr;gap:9px}.role-permissions{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:7px}.role-permission{display:flex;align-items:flex-start;gap:8px;padding:10px;border:1px solid var(--line);border-radius:10px;background:var(--panel-2);font-size:12px}.role-permission input{margin-top:2px}.role-form-actions{display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap}.roles-create,.roles-history{padding:0}.roles-create>summary,.roles-history>summary{padding:19px 21px;color:var(--ink);font-size:16px}.roles-create-body,.roles-history-body{padding:0 21px 21px;border-top:1px solid var(--line)}.roles-create-form{display:grid;grid-template-columns:1fr 1fr;gap:10px;padding-top:17px}.roles-create-form .wide{grid-column:1/-1}.roles-empty{padding:22px;text-align:center;color:var(--muted);border:1px dashed var(--line);border-radius:14px}.history-row{padding:11px 0;border-bottom:1px solid var(--line)}.history-row:last-child{border:0}.history-row strong{display:block;font-size:12px}.history-row span{color:var(--muted);font-size:11px}@media(max-width:780px){.roles-summary,.roles-how,.role-grid{grid-template-columns:1fr}.role-fields,.role-permissions,.roles-create-form{grid-template-columns:1fr}.roles-create-form .wide{grid-column:auto}.roles-top,.roles-actions,.roles-actions .btn{width:100%}.roles-actions .btn{text-align:center}.role-desc{min-height:0}}
</style>

<main class="roles-page">
  <div class="roles-top"><a class="btn secondary" href="secundarios.php">← Volver a Staff</a><div class="roles-actions"><a class="btn secondary" href="panel_admin.php">Panel</a><a class="btn" href="#crear-rol">Crear rol propio</a></div></div>
  <section class="card roles-hero"><div class="roles-kicker">Accesos del equipo</div><h1>Roles y permisos</h1><p>Definí qué puede hacer cada persona. El rol se asigna por evento: una misma persona puede trabajar en Puerta hoy y en Caja en otro evento.</p><div class="roles-summary"><div class="roles-stat"><span>Roles disponibles</span><strong><?php echo count($roles); ?></strong></div><div class="roles-stat"><span>Predefinidos</span><strong><?php echo count($systemRoles); ?></strong></div><div class="roles-stat"><span>Personalizados</span><strong><?php echo count($customRoles); ?></strong></div></div></section>
  <section class="card"><div class="roles-section-head"><div><h2>Cómo se organiza</h2><p>El acceso se arma en tres pasos simples.</p></div></div><div class="roles-how"><div class="roles-step"><b>1. Elegí un rol</b><span>Usá uno predefinido o creá uno propio.</span></div><div class="roles-step"><b>2. Asignalo al evento</b><span>El rol sólo aplica dentro de ese evento.</span></div><div class="roles-step"><b>3. Tickex limita el acceso</b><span>Panel, entradas, QR, ventas y reportes respetan esos permisos.</span></div></div></section>

  <?php
  function tickex_render_role_card($r,$catalog,$csrf,$adminId,$pdo){
    $rid=(int)$r['id'];$name=(string)$r['name'];$code=(string)$r['code'];$perms=is_array($r['permissions'])?$r['permissions']:array();$system=!empty($r['is_system']);
  ?>
  <article class="role-card"><div class="role-overview"><div class="role-card-top"><div><div class="role-name"><?php echo e($name); ?></div><div class="role-code"><?php echo e($code); ?></div></div><span class="role-type<?php echo $system?'':' custom'; ?>"><?php echo $system?'PREDEFINIDO':'PERSONALIZADO'; ?></span></div><div class="role-desc"><?php echo e(tickex_staff_role_description($code)); ?></div><div class="role-capabilities"><?php foreach($perms as $permission): ?><?php if(isset($catalog[$permission])): ?><span class="role-capability"><?php echo e($catalog[$permission]); ?></span><?php endif; ?><?php endforeach; ?><?php if(empty($perms)): ?><span class="role-capability">Sin accesos</span><?php endif; ?></div></div>
    <details class="role-editor"><summary>Editar nombre y permisos</summary><form method="post" class="role-form"><input type="hidden" name="csrf" value="<?php echo e($csrf); ?>"><input type="hidden" name="action" value="update_role"><input type="hidden" name="id" value="<?php echo $rid; ?>"><div class="role-fields"><label>Nombre<input name="name" value="<?php echo e($name); ?>" required></label><label>Código<input value="<?php echo e($code); ?>" disabled></label></div><div><strong style="font-size:12px;">Permisos operativos</strong><div class="role-permissions" style="margin-top:7px;"><?php foreach($catalog as $key=>$label): ?><label class="role-permission"><input type="checkbox" name="permissions[]" value="<?php echo e($key); ?>"<?php echo in_array($key,$perms,true)?' checked':''; ?>><span><?php echo e($label); ?></span></label><?php endforeach; ?></div></div><div class="role-form-actions"><button class="btn secondary" type="submit">Guardar cambios</button><?php if(!$system): ?><button class="btn danger" name="action" value="delete_role" onclick="return confirm('¿Eliminar rol <?php echo e($name); ?>?');">Eliminar rol</button><?php else: ?><span class="muted" style="font-size:11px;">Los roles predefinidos no se eliminan.</span><?php endif; ?></div></form></details>
  </article><?php }
  ?>

  <section class="card"><div class="roles-section-head"><div><h2>Roles predefinidos</h2><p>La base operativa recomendada para la mayoría de los eventos.</p></div><span class="role-type"><?php echo count($systemRoles); ?> DEL SISTEMA</span></div><div class="role-grid"><?php foreach($systemRoles as $role): ?><?php tickex_render_role_card($role,$catalog,tickex_csrf_token(),$adminId,$pdo); ?><?php endforeach; ?></div></section>
  <section class="card"><div class="roles-section-head"><div><h2>Roles personalizados</h2><p>Combinaciones propias para la manera en que trabaja tu equipo.</p></div><span class="role-type custom"><?php echo count($customRoles); ?> PROPIOS</span></div><?php if(empty($customRoles)): ?><div class="roles-empty">Todavía no creaste roles propios. Los cuatro roles predefinidos ya están listos para usar.</div><?php else: ?><div class="role-grid"><?php foreach($customRoles as $role): ?><?php tickex_render_role_card($role,$catalog,tickex_csrf_token(),$adminId,$pdo); ?><?php endforeach; ?></div><?php endif; ?></section>

  <details class="card roles-create" id="crear-rol"><summary>Crear un rol personalizado</summary><div class="roles-create-body"><p class="muted">Dale un nombre claro y activá solamente las herramientas que necesita.</p><form method="post" class="roles-create-form"><input type="hidden" name="csrf" value="<?php echo e(tickex_csrf_token()); ?>"><input type="hidden" name="action" value="create_role"><label>Nombre del rol<input type="text" name="name" required placeholder="Ej: Producción"></label><label>Código interno opcional<input type="text" name="code" placeholder="Se genera automáticamente"></label><div class="role-permissions wide"><?php foreach($catalog as $key=>$label): ?><label class="role-permission"><input type="checkbox" name="permissions[]" value="<?php echo e($key); ?>"><span><?php echo e($label); ?></span></label><?php endforeach; ?></div><div class="wide"><button class="btn" type="submit">Crear rol</button></div></form></div></details>
  <details class="card roles-history"><summary>Historial de roles y permisos <span class="muted">· <?php echo count($roleAudit); ?> cambios</span></summary><div class="roles-history-body"><?php if(empty($roleAudit)): ?><div class="roles-empty" style="margin-top:16px;">Todavía no hay cambios registrados.</div><?php endif; ?><?php foreach($roleAudit as $change): ?><div class="history-row"><strong><?php echo e((string)$change['action']); ?></strong><span><?php echo e((string)$change['created_at']); ?></span></div><?php endforeach; ?></div></details>
</main>

<?php include __DIR__ . '/inc/layout_bottom.php'; ?>
