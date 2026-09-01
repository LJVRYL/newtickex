<?php
require_once __DIR__ . '/inc/bootstrap.php';

require_login();
$cu = current_user();
$tipoGlobal = isset($cu['tipo_global']) ? (string)$cu['tipo_global'] : (isset($_SESSION['tipo_global']) ? (string)$_SESSION['tipo_global'] : '');
if (!is_admin() || !in_array($tipoGlobal, array('super_admin', 'superadmin'), true)) {
    http_response_code(403);
    include __DIR__ . '/inc/layout_top.php';
    echo '<div class="card" style="max-width:640px;margin:32px auto;"><h2>Acceso restringido</h2><p>Solo para superadministradores.</p></div>';
    include __DIR__ . '/inc/layout_bottom.php';
    exit;
}

$pdo = db();
$csrf = function_exists('tickex_csrf_token') ? (string)tickex_csrf_token() : '';
$flashOk = '';
$flashErr = '';

function tickex_superadmin_registered_candidates($pdo) {
    $rows = array();
    foreach (array(
        "SELECT email,COALESCE(nombre,'') nombre,COALESCE(apellido,'') apellido FROM usuarios WHERE email IS NOT NULL",
        "SELECT email,COALESCE(nombre,'') nombre,COALESCE(apellido,'') apellido FROM registro_pendientes WHERE email IS NOT NULL"
    ) as $sql) {
        try {
            foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $email = strtolower(trim((string)$row['email']));
                if ($email !== '') $rows[$email] = $row;
            }
        } catch (Exception $e) {}
    }
    ksort($rows);
    return $rows;
}

function tickex_superadmin_promote_registered($pdo, $email) {
    $email = strtolower(trim((string)$email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Email invalido.');
    $stSource = $pdo->prepare("SELECT password_hash FROM registro_pendientes WHERE lower(email)=lower(:email) AND password_hash IS NOT NULL AND password_hash<>'' ORDER BY id DESC LIMIT 1");
    $stSource->execute(array(':email'=>$email));
    $secureHash = (string)$stSource->fetchColumn();
    if ($secureHash === '' || strpos($secureHash, '$2') !== 0) throw new RuntimeException('El usuario debe completar o restablecer su contrasena antes de ser administrador.');

    $stExisting = $pdo->prepare('SELECT id FROM usuarios_admin WHERE lower(email)=lower(:email) OR lower(username)=lower(:email) LIMIT 1');
    $stExisting->execute(array(':email'=>$email));
    $existingId = (int)$stExisting->fetchColumn();
    if ($existingId > 0) {
        $st = $pdo->prepare("UPDATE usuarios_admin SET username=:email,email=:email,password=:password,rol='admin',tipo_global='admin_evento',rol_evento=NULL,evento_id=NULL,creado_por_admin_id=NULL,activo=1 WHERE id=:id");
        $st->execute(array(':email'=>$email, ':password'=>$secureHash, ':id'=>$existingId));
        return $existingId;
    }
    $st = $pdo->prepare("INSERT INTO usuarios_admin (username,email,password,rol,tipo_global,rol_evento,evento_id,creado_por_admin_id,activo) VALUES (:email,:email,:password,'admin','admin_evento',NULL,NULL,NULL,1)");
    $st->execute(array(':email'=>$email, ':password'=>$secureHash));
    return (int)$pdo->lastInsertId();
}

$registeredCandidates = tickex_superadmin_registered_candidates($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $provided = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
    if (function_exists('tickex_csrf_verify') && !tickex_csrf_verify($provided)) {
        $flashErr = 'CSRF invalido. Recarga la pagina e intenta nuevamente.';
    } else {
        $usuarioId = isset($_POST['usuario_id']) ? (int)$_POST['usuario_id'] : 0;
        $accion = isset($_POST['accion']) ? (string)$_POST['accion'] : '';

        try {
            if ($accion === 'promover_admin') {
                $adminEmail = isset($_POST['admin_email']) ? (string)$_POST['admin_email'] : '';
                $newAdminId = tickex_superadmin_promote_registered($pdo, $adminEmail);
                $flashOk = 'Administrador independiente creado/actualizado. ID: ' . $newAdminId;
            } elseif ($accion === 'cambiar_rol' && $usuarioId > 0) {
                $nuevoRol = (isset($_POST['nuevo_rol']) && $_POST['nuevo_rol'] === 'admin') ? 'admin' : 'cliente';
                $stmt = $pdo->prepare('UPDATE usuarios_admin SET rol = :rol WHERE id = :id');
                $stmt->execute(array(':rol' => $nuevoRol, ':id' => $usuarioId));
                $flashOk = 'Rol actualizado correctamente.';
            } elseif ($accion === 'cambiar_validado' && $usuarioId > 0) {
                $nuevoVal = (isset($_POST['nuevo_validado']) && (string)$_POST['nuevo_validado'] === '1') ? 1 : 0;
                $stmt = $pdo->prepare('UPDATE usuarios_admin SET email_confirmado = :val WHERE id = :id');
                $stmt->execute(array(':val' => $nuevoVal, ':id' => $usuarioId));
                $flashOk = 'Estado de validacion actualizado.';
            } elseif ($accion === 'bloquear' && $usuarioId > 0) {
                $stU = $pdo->prepare('SELECT email FROM usuarios WHERE id = :id LIMIT 1');
                $stU->execute(array(':id' => $usuarioId));
                $email = (string)$stU->fetchColumn();
                if ($email !== '') {
                    $reason = isset($_POST['reason']) ? trim((string)$_POST['reason']) : '';
                    $adminId = isset($_SESSION['admin_id']) ? (int)$_SESSION['admin_id'] : (isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null);

                    $stOff = $pdo->prepare('UPDATE user_blocks SET active = 0, unblocked_at = datetime(\'now\'), unblocked_by_admin_id = :aid WHERE active = 1 AND lower(email) = lower(:e)');
                    $stOff->execute(array(':aid' => $adminId, ':e' => $email));

                    $stOn = $pdo->prepare('INSERT INTO user_blocks (email, reason, active, blocked_by_admin_id) VALUES (:e, :r, 1, :aid)');
                    $stOn->execute(array(':e' => $email, ':r' => $reason, ':aid' => $adminId));
                    $flashOk = 'Usuario bloqueado: ' . e($email);
                } else {
                    $flashErr = 'No se encontro el usuario para bloquear.';
                }
            } elseif ($accion === 'desbloquear' && $usuarioId > 0) {
                $stU = $pdo->prepare('SELECT email FROM usuarios WHERE id = :id LIMIT 1');
                $stU->execute(array(':id' => $usuarioId));
                $email = (string)$stU->fetchColumn();
                if ($email !== '') {
                    $adminId = isset($_SESSION['admin_id']) ? (int)$_SESSION['admin_id'] : (isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null);
                    $stOff = $pdo->prepare('UPDATE user_blocks SET active = 0, unblocked_at = datetime(\'now\'), unblocked_by_admin_id = :aid WHERE active = 1 AND lower(email) = lower(:e)');
                    $stOff->execute(array(':aid' => $adminId, ':e' => $email));
                    $flashOk = 'Usuario desbloqueado: ' . e($email);
                } else {
                    $flashErr = 'No se encontro el usuario para desbloquear.';
                }
            }
        } catch (Exception $e) {
            $flashErr = 'No se pudo aplicar el cambio: ' . $e->getMessage();
        }
    }
}

$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$sql = "SELECT u.id, u.email, COALESCE(u.nombre, '') AS nombre, COALESCE(u.apellido, '') AS apellido, COALESCE(u.rol, 'cliente') AS rol, COALESCE(u.email_confirmado, 0) AS email_confirmado, COALESCE(u.tipo_global, '') AS tipo_global, COALESCE(u.creado_en, '') AS creado_en, b.reason AS block_reason, b.blocked_at FROM usuarios u LEFT JOIN user_blocks b ON b.active = 1 AND lower(b.email) = lower(u.email)";
$params = array();
if ($q !== '') {
    $sql .= ' WHERE (u.email LIKE :q OR u.nombre LIKE :q OR u.apellido LIKE :q)';
    $params[':q'] = '%' . $q . '%';
}
$sql .= ' ORDER BY u.id DESC LIMIT 500';
$st = $pdo->prepare($sql);
$st->execute($params);
$usuarios = $st->fetchAll(PDO::FETCH_ASSOC);

$title = 'Superadmin Usuarios';
include __DIR__ . '/inc/layout_top.php';
?>

<div class="card" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
  <a class="btn secondary" href="panel_admin.php">Volver</a>
  <h2 style="margin:0;">Usuarios</h2>
  <span class="muted">Gestion de roles, validacion y bloqueo de acceso.</span>
</div>

<div class="card" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
  <a class="btn" href="superadmin_usuarios.php">Usuarios</a>
  <a class="btn secondary" href="superadmin_emails_db.php">👥 Contactos</a>
  <a class="btn secondary" href="superadmin_emails.php">Logs de Emails</a>
</div>

<?php if ($flashOk !== ''): ?>
  <div class="flash ok"><?php echo $flashOk; ?></div>
<?php endif; ?>
<?php if ($flashErr !== ''): ?>
  <div class="flash err"><?php echo e($flashErr); ?></div>
<?php endif; ?>

<div class="card" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
  <form method="get" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
    <input name="q" placeholder="Buscar por email o nombre" value="<?php echo e($q); ?>" style="min-width:260px;">
    <button class="btn secondary" type="submit">Buscar</button>
    <?php if ($q !== ''): ?>
      <a class="btn secondary" href="superadmin_usuarios.php">Limpiar</a>
    <?php endif; ?>
  </form>
</div>

<div class="card">
  <h3 style="margin-top:0;">Crear administrador independiente</h3>
  <p class="muted">La lista combina todos los usuarios registrados y registros completados. El nuevo administrador tendra sus propios eventos, clientes e inventario.</p>
  <form method="post" action="superadmin_usuarios.php" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
    <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
    <select name="admin_email" required style="min-width:320px;">
      <option value="">Seleccionar usuario registrado</option>
      <?php foreach ($registeredCandidates as $email=>$candidate): ?>
        <option value="<?php echo e($email); ?>"><?php echo e(trim($candidate['nombre'].' '.$candidate['apellido']).' — '.$email); ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn" type="submit" name="accion" value="promover_admin">Convertir en administrador cliente</button>
  </form>
</div>

<div class="card" style="overflow:auto;">
  <table class="table" style="width:100%;font-size:14px;">
    <thead>
      <tr>
        <th>ID</th>
        <th>Email</th>
        <th>Nombre</th>
        <th>Rol</th>
        <th>Validado</th>
        <th>Estado acceso</th>
        <th>Accion</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($usuarios as $u): ?>
        <?php
          $uid = (int)$u['id'];
          $isBlocked = !empty($u['blocked_at']);
          $nombreCompleto = trim((string)$u['nombre'] . ' ' . (string)$u['apellido']);
          if ($nombreCompleto === '') $nombreCompleto = '-';
        ?>
        <tr>
          <td><?php echo $uid; ?></td>
          <td><?php echo e($u['email']); ?></td>
          <td><?php echo e($nombreCompleto); ?></td>
          <td>
            <form method="post" action="superadmin_usuarios.php" style="display:flex;align-items:center;gap:4px;">
              <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
              <input type="hidden" name="usuario_id" value="<?php echo $uid; ?>">
              <select name="nuevo_rol" style="font-size:13px;padding:2px 6px;min-width:90px;">
                <option value="admin" <?php if($u['rol']==='admin')echo 'selected';?>>Administrador</option>
                <option value="cliente" <?php if($u['rol']!=='admin')echo 'selected';?>>Cliente</option>
              </select>
              <button type="submit" name="accion" value="cambiar_rol" style="font-size:12px;padding:2px 8px;">Guardar</button>
            </form>
          </td>
          <td>
            <form method="post" action="superadmin_usuarios.php" style="display:flex;align-items:center;gap:4px;">
              <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
              <input type="hidden" name="usuario_id" value="<?php echo $uid; ?>">
              <select name="nuevo_validado" style="font-size:13px;padding:2px 6px;min-width:50px;">
                <option value="1" <?php if((int)$u['email_confirmado']===1)echo 'selected';?>>Si</option>
                <option value="0" <?php if((int)$u['email_confirmado']!==1)echo 'selected';?>>No</option>
              </select>
              <button type="submit" name="accion" value="cambiar_validado" style="font-size:12px;padding:2px 8px;">Guardar</button>
            </form>
          </td>
          <td>
            <?php if ($isBlocked): ?>
              <span style="display:inline-block;padding:4px 8px;border-radius:999px;background:#5a1a1a;color:#fff;font-weight:700;font-size:12px;">PROHIBIDO</span>
              <?php if (!empty($u['block_reason'])): ?>
                <div class="muted" style="font-size:11px;margin-top:4px;max-width:220px;word-break:break-word;">Motivo: <?php echo e($u['block_reason']); ?></div>
              <?php endif; ?>
            <?php else: ?>
              <span style="display:inline-block;padding:4px 8px;border-radius:999px;background:#184d2a;color:#fff;font-weight:700;font-size:12px;">ACTIVO</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($isBlocked): ?>
              <form method="post" action="superadmin_usuarios.php" style="display:inline;">
                <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
                <input type="hidden" name="usuario_id" value="<?php echo $uid; ?>">
                <button type="submit" name="accion" value="desbloquear" style="font-size:12px;padding:4px 8px;">Quitar bloqueo</button>
              </form>
            <?php else: ?>
              <form method="post" action="superadmin_usuarios.php" style="display:flex;gap:4px;align-items:center;">
                <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
                <input type="hidden" name="usuario_id" value="<?php echo $uid; ?>">
                <input type="text" name="reason" placeholder="Motivo (opcional)" style="font-size:12px;padding:4px 6px;max-width:170px;">
                <button type="submit" name="accion" value="bloquear" style="font-size:12px;padding:4px 8px;background:#8e2b2b;color:#fff;border:1px solid #8e2b2b;border-radius:6px;">PROHIBIDO</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php include __DIR__ . '/inc/layout_bottom.php'; ?>
