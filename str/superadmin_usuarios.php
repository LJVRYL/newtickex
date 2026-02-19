<?php
require_once __DIR__ . '/inc/bootstrap.php';
$pdo = db();
// Procesar cambios de rol o validación (antes de cualquier salida)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['usuario_id'], $_POST['accion'])) {
  $usuarioId = (int)$_POST['usuario_id'];
  if ($_POST['accion'] === 'cambiar_rol' && isset($_POST['nuevo_rol'])) {
    $nuevoRol = $_POST['nuevo_rol'] === 'admin' ? 'admin' : 'cliente';
    $stmt = $pdo->prepare("UPDATE usuarios_admin SET rol = :rol WHERE id = :id");
    $stmt->execute([':rol' => $nuevoRol, ':id' => $usuarioId]);
    header('Location: superadmin_usuarios.php');
    exit;
  }
  if ($_POST['accion'] === 'cambiar_validado' && isset($_POST['nuevo_validado'])) {
    $nuevoVal = ($_POST['nuevo_validado'] == '1') ? 1 : 0;
    $stmt = $pdo->prepare("UPDATE usuarios_admin SET email_confirmado = :val WHERE id = :id");
    $stmt->execute([':val' => $nuevoVal, ':id' => $usuarioId]);
    header('Location: superadmin_usuarios.php');
    exit;
  }
}
// Panel de usuarios para superadmin: logs, validación y cambio de rol
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
$usuarios = $pdo->query("SELECT id, email, nombre, apellido, rol, tipo_global, creado_en, email_confirmado FROM usuarios ORDER BY creado_en DESC")->fetchAll(PDO::FETCH_ASSOC);
include __DIR__ . '/inc/layout_top.php';
?>
<div class="card" style="max-width:900px;margin:32px auto;">
  <h2>Usuarios</h2>
  <table class="table" style="width:100%;font-size:14px;">
    <thead>
      <tr>
        <th style="width:40px;">ID</th>
        <th style="width:180px;">Email</th>
        <th style="width:100px;">Nombre</th>
        <th style="width:60px;">Rol</th>
        <th style="width:60px;">Validado</th>
        <th style="width:60px;">Acción</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($usuarios as $u): ?>
        <tr>
          <td><?php echo (int)$u['id']; ?></td>
          <td><?php echo e($u['email']); ?></td>
          <td><?php echo e($u['nombre']); ?></td>
          <td>
            <form method="post" action="superadmin_usuarios.php" style="display:flex;align-items:center;gap:4px;">
              <input type="hidden" name="usuario_id" value="<?php echo (int)$u['id']; ?>">
              <select name="nuevo_rol" style="font-size:13px;padding:2px 6px;min-width:90px;">
                <option value="admin" <?php if($u['rol']==='admin')echo 'selected';?>>Administrador</option>
                <option value="cliente" <?php if($u['rol']==='cliente')echo 'selected';?>>Cliente</option>
              </select>
              <button type="submit" name="accion" value="cambiar_rol" style="font-size:12px;padding:2px 8px;">Guardar</button>
            </form>
          </td>
          <td>
            <form method="post" action="superadmin_usuarios.php" style="display:flex;align-items:center;gap:4px;">
              <input type="hidden" name="usuario_id" value="<?php echo (int)$u['id']; ?>">
              <select name="nuevo_validado" style="font-size:13px;padding:2px 6px;min-width:50px;">
                <option value="1" <?php if($u['email_confirmado'])echo 'selected';?>>Sí</option>
                <option value="0" <?php if(!$u['email_confirmado'])echo 'selected';?>>No</option>
              </select>
              <button type="submit" name="accion" value="cambiar_validado" style="font-size:12px;padding:2px 8px;">Guardar</button>
            </form>
          </td>
          <td>
            <form method="get" action="editar_usuario.php" style="display:inline;">
              <input type="hidden" name="usuario_id" value="<?php echo (int)$u['id']; ?>">
              <button type="submit" style="font-size:12px;padding:2px 8px;">Editar</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php include __DIR__ . '/inc/layout_bottom.php'; ?>
