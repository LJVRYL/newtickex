<?php
// mi_perfil.php - Perfil del admin (nombre, email, dni, cbu) SIN avatar para evitar errores

require __DIR__ . '/inc/bootstrap.php';
require_login();

$cu = current_user();

$tipoGlobal = isset($_SESSION['tipo_global'])
    ? $_SESSION['tipo_global']
    : (isset($cu['rol']) ? $cu['rol'] : '');

$userId = isset($cu['id'])
    ? (int)$cu['id']
    : (isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0);

if ($userId <= 0) {
    http_response_code(403);
    $title = 'Sesion invalida';
    require __DIR__ . '/inc/layout_top.php';
    echo '<div class="card"><div class="alert alert-danger">Sesion invalida (falta user_id).</div></div>';
    require __DIR__ . '/inc/layout_bottom.php';
    exit;
}

// Conectar DB
try {
    $pdo = db();
} catch (Exception $e) {
    http_response_code(500);
    echo 'Error DB.';
    exit;
}

// Detectar si existe la columna apellido (por compatibilidad de DB)
$hasApellido = false;
try {
  $colsInfo = $pdo->query("PRAGMA table_info(usuarios_admin)")->fetchAll(PDO::FETCH_ASSOC);
  foreach ($colsInfo as $ci) {
    if (isset($ci['name']) && $ci['name'] === 'apellido') { $hasApellido = true; break; }
  }
} catch (Exception $e) {
  // si falla pragma, asumimos que puede no estar
}

// Cargar usuario
$user = null;
try {
  $selectCols = 'id, username, nombre, email, dni, cbu';
  if ($hasApellido) $selectCols .= ', apellido';
  $stmt = $pdo->prepare(
    "SELECT $selectCols FROM usuarios_admin WHERE id = :id LIMIT 1"
  );
  $stmt->execute(array(':id' => $userId));
  $user = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
  http_response_code(500);
  echo 'Error cargando usuario.';
  exit;
}

if (!$user) {
    http_response_code(403);
    $title = 'Usuario no encontrado';
    require __DIR__ . '/inc/layout_top.php';
    echo '<div class="card"><div class="alert alert-danger">Usuario no encontrado.</div></div>';
    require __DIR__ . '/inc/layout_bottom.php';
    exit;
}

$error = '';
$okMsg = '';

$avatarDir = __DIR__ . '/avatars';
if (!is_dir($avatarDir)) {
  @mkdir($avatarDir, 0775, true);
}

$avatarBase = 'admin_' . (int)$user['id'];
$avatarUrl  = '';
foreach (array('jpg', 'jpeg', 'png', 'webp') as $ext) {
  $candidate = $avatarDir . '/' . $avatarBase . '.' . $ext;
  if (file_exists($candidate)) {
    $avatarUrl = 'avatars/' . $avatarBase . '.' . $ext;
    break;
  }
}

// Procesar POST
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {

  $deleteAvatar = isset($_POST['delete_avatar']);

  if ($deleteAvatar) {
    $deleted = false;
    foreach (array('jpg', 'jpeg', 'png', 'webp') as $ext) {
      $cand = $avatarDir . '/' . $avatarBase . '.' . $ext;
      if (file_exists($cand)) {
        @unlink($cand);
        $deleted = true;
      }
    }
    $avatarUrl = '';
    if ($deleted) {
      $okMsg = 'Avatar eliminado.';
    } else {
      $error = 'No se encontró avatar para eliminar.';
    }
  }

    $nombre   = isset($_POST['nombre'])   ? trim($_POST['nombre'])   : '';
    $apellido = isset($_POST['apellido']) ? trim($_POST['apellido']) : '';
    $email    = isset($_POST['email'])    ? trim($_POST['email'])    : '';
    $dni      = isset($_POST['dni'])      ? trim($_POST['dni'])      : '';
    $cbu      = isset($_POST['cbu'])      ? trim($_POST['cbu'])      : '';

    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
      $error = 'El email no tiene un formato valido.';
    }

    if ($error === '' && $dni === '') {
      $error = 'El DNI es obligatorio para generar pagos.';
    }
    if ($error === '' && $hasApellido && $apellido === '') {
      $error = 'El apellido es obligatorio para generar pagos.';
    }

    if ($error === '' && !$deleteAvatar) {
        try {
            if ($hasApellido) {
              $stmtUpd = $pdo->prepare(
                'UPDATE usuarios_admin
                 SET nombre = :nombre,
                   apellido = :apellido,
                   email  = :email,
                   dni    = :dni,
                   cbu    = :cbu
                 WHERE id = :id'
              );
              $stmtUpd->execute(array(
                ':nombre'   => ($nombre   !== '' ? $nombre   : null),
                ':apellido' => ($apellido !== '' ? $apellido : null),
                ':email'    => ($email    !== '' ? $email    : null),
                ':dni'      => ($dni      !== '' ? $dni      : null),
                ':cbu'      => ($cbu      !== '' ? $cbu      : null),
                ':id'       => (int)$user['id'],
              ));
            } else {
              $stmtUpd = $pdo->prepare(
                'UPDATE usuarios_admin
                 SET nombre = :nombre,
                   email  = :email,
                   dni    = :dni,
                   cbu    = :cbu
                 WHERE id = :id'
              );
              $stmtUpd->execute(array(
                ':nombre'   => ($nombre   !== '' ? $nombre   : null),
                ':email'    => ($email    !== '' ? $email    : null),
                ':dni'      => ($dni      !== '' ? $dni      : null),
                ':cbu'      => ($cbu      !== '' ? $cbu      : null),
                ':id'       => (int)$user['id'],
              ));
            }

            $okMsg = 'Perfil actualizado correctamente.';

            // Refrescar datos en memoria
            $user['nombre']   = $nombre;
            if ($hasApellido) $user['apellido'] = $apellido;
            $user['email']    = $email;
            $user['dni']      = $dni;
            $user['cbu']      = $cbu;

            // Refrescar sesión para prefills de checkout
            $_SESSION['dni'] = $dni;
            if ($hasApellido) {
              $_SESSION['last_name'] = $apellido;
              $_SESSION['apellido']  = $apellido;
            }

            // Procesar avatar si se subió
            if (isset($_FILES['avatar']) && isset($_FILES['avatar']['tmp_name']) && is_uploaded_file($_FILES['avatar']['tmp_name'])) {
              if ((int)$_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
                $tmp  = $_FILES['avatar']['tmp_name'];
                $size = isset($_FILES['avatar']['size']) ? (int)$_FILES['avatar']['size'] : 0;

                if ($size > 2 * 1024 * 1024) {
                  $error = 'El avatar no puede superar los 2MB.';
                }

                if ($error === '') {
                  $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : false;
                  $mime  = $finfo ? finfo_file($finfo, $tmp) : '';
                  if ($finfo) {
                    finfo_close($finfo);
                  }

                  $ext = '';
                  if ($mime === 'image/jpeg') {
                    $ext = 'jpg';
                  } elseif ($mime === 'image/png') {
                    $ext = 'png';
                  } elseif ($mime === 'image/webp') {
                    $ext = 'webp';
                  }

                  if ($ext === '') {
                    $error = 'Formato de avatar no permitido. Usa JPG, PNG o WEBP.';
                  }

                  if ($error === '') {
                    $destName = $avatarBase . '.' . $ext;
                    $destPath = $avatarDir . '/' . $destName;

                    if (!move_uploaded_file($tmp, $destPath)) {
                      $error = 'No se pudo guardar el avatar.';
                    } else {
                      $avatarUrl = 'avatars/' . $destName;
                    }
                  }
                }
              } else {
                $error = 'Error al subir el avatar. Intentalo nuevamente.';
              }
            }

        } catch (Exception $e) {
            $error = 'Error al guardar el perfil.';
        }
    }
}

// Layout
$title = 'Mi perfil';
require __DIR__ . '/inc/layout_top.php';
?>
<div class="card" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
  <a class="btn secondary" href="panel_admin.php">⬅ Volver al panel</a>
</div>

<div class="card">
  <h2>Mi perfil</h2>
  <div style="color:var(--muted);font-size:14px;">
    Usuario: <strong><?php echo e($user['username']); ?></strong>
    (<?php echo e($tipoGlobal); ?>)
  </div>
</div>

<?php if ($okMsg !== ''): ?>
  <div class="card">
    <div class="alert alert-success">
      <?php echo e($okMsg); ?>
    </div>
  </div>
<?php endif; ?>

<?php if ($error !== ''): ?>
  <div class="card">
    <div class="alert alert-danger">
      <?php echo e($error); ?>
    </div>
  </div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data">
  <div class="card">
    <h3>Datos del perfil</h3>

    <div style="margin-bottom:10px;">
      <label for="nombre">Nombre</label>
      <input type="text" id="nombre" name="nombre"
             value="<?php echo e($user['nombre']); ?>">
    </div>

    <div style="margin-bottom:10px;">
      <label for="apellido">Apellido (requerido para pagos)</label>
      <input type="text" id="apellido" name="apellido" required
             value="<?php echo e(isset($user['apellido']) ? $user['apellido'] : ''); ?>" placeholder="Ej: Pérez">
    </div>

    <div style="margin-bottom:10px;">
      <label for="email">Email</label>
      <input type="email" id="email" name="email"
             value="<?php echo e($user['email']); ?>">
    </div>

    <div style="margin-bottom:10px;">
      <label for="dni">DNI / Documento (requerido para pagos)</label>
      <input type="text" id="dni" name="dni" required
             value="<?php echo e($user['dni']); ?>" placeholder="Ej: 12345678">
    </div>

    <div style="margin-bottom:10px;">
      <label for="cbu">CBU / Cuenta para pagos</label>
      <input type="text" id="cbu" name="cbu"
             value="<?php echo e($user['cbu']); ?>">
    </div>

    <div style="margin-bottom:14px;display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
      <?php if ($avatarUrl !== ''): ?>
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
          <img src="<?php echo e($avatarUrl); ?>" alt="Avatar" style="width:72px;height:72px;border-radius:50%;object-fit:cover;border:1px solid var(--border-subtle);">
          <form method="post" onsubmit="return confirm('¿Eliminar avatar?');" style="margin:0;">
            <input type="hidden" name="delete_avatar" value="1">
            <button type="submit" class="btn danger" style="padding:6px 10px;font-size:14px;">
              🗑️ Eliminar avatar
            </button>
          </form>
        </div>
      <?php else: ?>
        <div style="width:72px;height:72px;border-radius:50%;background:var(--bg-elevated);border:1px dashed var(--border-subtle);display:flex;align-items:center;justify-content:center;color:var(--fg-muted);font-size:12px;">
          Sin avatar
        </div>
      <?php endif; ?>

      <div style="flex:1;min-width:220px;">
        <label for="avatar">Avatar (JPG/PNG/WEBP, máx 2MB)</label>
        <input type="file" id="avatar" name="avatar" accept="image/jpeg,image/png,image/webp">
      </div>
    </div>

    <button type="submit" class="btn" style="margin-top:12px;">
      Guardar cambios
    </button>
  </div>
</form>

<div class="card">
  <h3>Seguridad</h3>
  <p style="margin-bottom:10px;font-size:14px;">
    Desde aca vas a poder gestionar tu contraseña.
  </p>
  <a href="cambiar_password.php" class="btn secondary">
    Cambiar contraseña
  </a>
  <p style="margin-top:8px;font-size:12px;color:var(--muted);">
    La opcion de recuperar contraseña se hace desde la pantalla de login (cuando no estas logueado).
  </p>
</div>

<?php
require __DIR__ . '/inc/layout_bottom.php';
