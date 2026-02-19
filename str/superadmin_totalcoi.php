
<?php
// Página exclusiva para superadmin: conectar cuentas TotalCoi
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
$usuarioSel = null;
$q = isset($_GET['q']) ? trim($_GET['q']) : '';
$usuarioId = isset($_GET['usuario_id']) ? (int)$_GET['usuario_id'] : 0;
$usuarios = array();
if ($q !== '') {
    $stmt = $pdo->prepare("SELECT * FROM usuarios_admin WHERE email LIKE :q OR id = :id LIMIT 20");
    $stmt->execute([':q' => "%$q%", ':id' => (int)$q]);
    $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
} elseif ($usuarioId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM usuarios_admin WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $usuarioId]);
    $usuarioSel = $stmt->fetch(PDO::FETCH_ASSOC);
}
include __DIR__ . '/inc/layout_top.php';
?>
<div class="card" style="max-width:900px;margin:32px auto;">
  <h2>Conectar usuario a TotalCoi</h2>
  <form method="get" action="superadmin_totalcoi.php" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
    <input type="text" name="q" value="<?php echo e($q); ?>" placeholder="Buscar por email o ID" style="min-width:220px;">
    <button class="btn" type="submit">Buscar</button>
    <?php if ($q !== ''): ?><a href="superadmin_totalcoi.php" class="btn secondary">Limpiar</a><?php endif; ?>
  </form>
  <?php if ($q !== '' && $usuarios): ?>
    <div style="margin-top:16px;">
      <strong>Resultados:</strong>
      <ul style="margin:8px 0 0 16px;">
        <?php foreach ($usuarios as $u): ?>
          <li>
            <a href="superadmin_totalcoi.php?usuario_id=<?php echo (int)$u['id']; ?>">
              [ID <?php echo (int)$u['id']; ?>] <?php echo e($u['nombre']); ?> <?php echo e($u['apellido']); ?> - <?php echo e($u['email']); ?>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <?php if ($usuarioSel): ?>
    <div style="margin-top:24px;">
      <h3>Datos del usuario seleccionado</h3>
      <table class="table" style="width:auto;min-width:320px;">
        <tr><th>ID</th><td><?php echo (int)$usuarioSel['id']; ?></td></tr>
        <tr><th>Nombre</th><td><?php echo e($usuarioSel['nombre']); ?></td></tr>
        <tr><th>Apellido</th><td><?php echo e($usuarioSel['apellido']); ?></td></tr>
        <tr><th>Email</th><td><?php echo e($usuarioSel['email']); ?></td></tr>
        <tr><th>DNI</th><td><?php echo e($usuarioSel['dni']); ?></td></tr>
        <tr><th>Género</th><td><?php echo e(isset($usuarioSel['genero']) ? $usuarioSel['genero'] : ''); ?></td></tr>
        <tr><th>Tipo</th><td><?php echo e($usuarioSel['rol']); ?></td></tr>
      </table>
      <form method="post" action="superadmin_totalcoi.php" style="margin-top:16px;display:flex;flex-direction:column;gap:12px;max-width:400px;">
        <input type="hidden" name="usuario_id" value="<?php echo (int)$usuarioSel['id']; ?>">
        <label>ID TotalCoi:<br><input type="text" name="totalcoi_id" required></label>
        <label>Email TotalCoi:<br><input type="email" name="totalcoi_email" required></label>
        <label>Clave API TotalCoi:<br><input type="text" name="totalcoi_apikey" required></label>
        <button class="btn" type="submit">Conectar cuenta</button>
      </form>
    </div>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/inc/layout_bottom.php'; ?>
