<?php
// mi_perfil.php - Perfil del admin (nombre, email, dni, cbu) SIN avatar para evitar errores

require __DIR__ . '/inc/bootstrap.php';
$cu = current_user();

$tipoGlobal = isset($cu['tipo_global'])
    ? (string)$cu['tipo_global']
    : (isset($cu['rol']) ? (string)$cu['rol'] : '');
$adminContext = isset($_SESSION['auth_context']) && $_SESSION['auth_context'] === 'admin';
$allowedRoles = array('super_admin', 'superadmin', 'admin_evento', 'staff_evento');

if (!$adminContext || !in_array($tipoGlobal, $allowedRoles, true)) {
    $next = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '/mi_perfil.php';
    header('Location: /login_admin.php?next=' . urlencode($next), true, 302);
    exit;
}

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
$hasApodo = false;
try {
  $colsInfo = $pdo->query("PRAGMA table_info(usuarios_admin)")->fetchAll(PDO::FETCH_ASSOC);
  foreach ($colsInfo as $ci) {
    if (isset($ci['name']) && $ci['name'] === 'apellido') { $hasApellido = true; break; }
  }
  foreach ($colsInfo as $ci) {
    if (isset($ci['name']) && $ci['name'] === 'apodo') { $hasApodo = true; break; }
  }
} catch (Exception $e) {
  // si falla pragma, asumimos que puede no estar
}

// Cargar usuario
$user = null;
try {
  $selectCols = 'id, username, nombre, email, dni, cbu';
  if ($hasApellido) $selectCols .= ', apellido';
  if ($hasApodo) $selectCols .= ', apodo';
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

$csrf = function_exists('tickex_csrf_token') ? tickex_csrf_token() : '';

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

  $provided = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
  if (function_exists('tickex_csrf_verify') && !tickex_csrf_verify($provided)) {
    $error = 'CSRF inválido. Actualizá la página e intentá de nuevo.';
  }

  $deleteAvatar = isset($_POST['delete_avatar']);

  if ($deleteAvatar && $error === '') {
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

  if (!$deleteAvatar) {
    $nombre   = isset($_POST['nombre'])   ? trim($_POST['nombre'])   : '';
    $apellido = isset($_POST['apellido']) ? trim($_POST['apellido']) : '';
    $email    = isset($_POST['email'])    ? trim($_POST['email'])    : '';
    $dni      = isset($_POST['dni'])      ? trim($_POST['dni'])      : '';
    $cbu      = isset($_POST['cbu'])      ? trim($_POST['cbu'])      : '';
    $apodo    = isset($_POST['apodo'])    ? trim($_POST['apodo'])    : '';

    if ($error === '' && $email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
      $error = 'El email no tiene un formato valido.';
    }

    if ($error === '' && $dni === '') {
      $error = 'El DNI es obligatorio para generar pagos.';
    }
    if ($error === '' && $hasApellido && $apellido === '') {
      $error = 'El apellido es obligatorio para generar pagos.';
    }

    // Tickex ID (apodo) para admins
    if ($error === '' && $apodo !== '') {
      if (strlen($apodo) > 64) {
        $error = 'El Tickex ID es demasiado largo (máx 64).';
      } elseif (!preg_match('/^[a-zA-Z0-9_-]+$/', $apodo)) {
        $error = 'El Tickex ID solo puede tener letras, números, _ y - (sin espacios).';
      } else {
        try {
          // Unicidad contra otros admins
          $stA = $pdo->prepare('SELECT 1 FROM usuarios_admin WHERE lower(apodo) = lower(:ap) AND id <> :id LIMIT 1');
          $stA->execute(array(':ap' => $apodo, ':id' => (int)$user['id']));
          if ($stA->fetchColumn()) {
            $error = 'Ese Tickex ID ya lo usa otro admin.';
          }
        } catch (Exception $e) {
          // ignore
        }

        if ($error === '') {
          try {
            // Unicidad contra clientes
            $stC = $pdo->prepare('SELECT 1 FROM registro_pendientes WHERE lower(apodo) = lower(:ap) LIMIT 1');
            $stC->execute(array(':ap' => $apodo));
            if ($stC->fetchColumn()) {
              $error = 'Ese Tickex ID ya lo usa un cliente.';
            }
          } catch (Exception $e) {
            // ignore
          }
        }
      }
    }

    if ($error === '' && !$deleteAvatar) {
        try {
            $setParts = array(
              'nombre = :nombre',
              'email = :email',
              'dni = :dni',
              'cbu = :cbu'
            );
            $updateParams = array(
              ':nombre' => ($nombre !== '' ? $nombre : null),
              ':email' => ($email !== '' ? $email : null),
              ':dni' => ($dni !== '' ? $dni : null),
              ':cbu' => ($cbu !== '' ? $cbu : null),
              ':id' => (int)$user['id']
            );
            if ($hasApellido) {
              $setParts[] = 'apellido = :apellido';
              $updateParams[':apellido'] = ($apellido !== '' ? $apellido : null);
            }
            if ($hasApodo) {
              $setParts[] = 'apodo = :apodo';
              $updateParams[':apodo'] = ($apodo !== '' ? $apodo : null);
            }

            $stmtUpd = $pdo->prepare('UPDATE usuarios_admin SET ' . implode(', ', $setParts) . ' WHERE id = :id');
            $stmtUpd->execute($updateParams);

            $okMsg = 'Perfil actualizado correctamente.';

            // Refrescar datos en memoria
            $user['nombre']   = $nombre;
            if ($hasApellido) $user['apellido'] = $apellido;
            $user['email']    = $email;
            $user['dni']      = $dni;
            $user['cbu']      = $cbu;
            if ($hasApodo) $user['apodo'] = $apodo;

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
}

// Resumen visual del perfil
$displayName = trim((string)$user['nombre'] . ' ' . ($hasApellido && isset($user['apellido']) ? (string)$user['apellido'] : ''));
if ($displayName === '') $displayName = (string)$user['username'];
$requiredProfileFields = array((string)$user['nombre'], (string)$user['email'], (string)$user['dni']);
if ($hasApellido) $requiredProfileFields[] = isset($user['apellido']) ? (string)$user['apellido'] : '';
$completedProfileFields = 0;
foreach ($requiredProfileFields as $profileField) {
  if (trim($profileField) !== '') $completedProfileFields++;
}
$profileComplete = $completedProfileFields === count($requiredProfileFields);
$paymentsReady = trim((string)$user['dni']) !== '' && (!$hasApellido || trim((string)$user['apellido']) !== '');
$tickexId = $hasApodo && !empty($user['apodo']) ? (string)$user['apodo'] : 'Sin definir';
$roleLabels = array(
  'super_admin' => 'Superadministrador',
  'superadmin' => 'Superadministrador',
  'admin_evento' => 'Organizador',
  'staff_evento' => 'Staff'
);
$roleLabel = isset($roleLabels[$tipoGlobal]) ? $roleLabels[$tipoGlobal] : $tipoGlobal;
$avatarInitial = function_exists('mb_substr') ? mb_substr($displayName, 0, 1, 'UTF-8') : substr($displayName, 0, 1);

$title = 'Mi perfil';
require __DIR__ . '/inc/layout_top.php';
?>
<style>
  .profile-hero{position:relative;overflow:hidden;padding:28px;background:linear-gradient(135deg,rgba(91,55,190,.34),rgba(18,31,55,.94))}
  .profile-hero:after{content:"";position:absolute;width:290px;height:290px;right:-105px;top:-165px;border:1px solid rgba(147,119,255,.25);border-radius:50%}
  .profile-eyebrow{color:#aa94ff;font-size:11px;font-weight:800;letter-spacing:.13em;text-transform:uppercase}
  .profile-hero h1{margin:7px 0 6px;font-size:clamp(28px,4vw,44px)}
  .profile-hero p{margin:0;color:var(--muted);max-width:650px}
  .profile-toolbar{position:relative;z-index:1;display:flex;justify-content:space-between;align-items:flex-end;gap:18px;flex-wrap:wrap}
  .profile-actions{display:flex;gap:8px;flex-wrap:wrap}
  .profile-stats{position:relative;z-index:1;display:grid;grid-template-columns:repeat(4,minmax(120px,1fr));gap:10px;margin-top:24px}
  .profile-stat{padding:13px 15px;border:1px solid rgba(255,255,255,.08);border-radius:14px;background:rgba(6,10,22,.38);min-width:0}
  .profile-stat span{display:block;color:var(--muted);font-size:11px;font-weight:750;text-transform:uppercase;letter-spacing:.06em}
  .profile-stat strong{display:block;margin-top:3px;font-size:19px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .profile-form{padding:0;overflow:hidden}
  .profile-form-head{display:flex;justify-content:space-between;align-items:flex-end;gap:18px;padding:20px 22px;border-bottom:1px solid var(--line);flex-wrap:wrap}
  .profile-form-head h2{margin:0}.profile-form-head p{margin:4px 0 0;color:var(--muted);font-size:13px}
  .profile-form-body{padding:22px}
  .profile-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}
  .profile-group{padding:18px;border:1px solid var(--line);border-radius:16px;background:rgba(8,14,28,.38)}
  .profile-group.full{grid-column:1/-1}
  .profile-group-title{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:16px}
  .profile-group-title h3{margin:0;font-size:17px}.profile-group-title p{margin:4px 0 0;color:var(--muted);font-size:12px}
  .profile-fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:13px}
  .profile-field.full{grid-column:1/-1}.profile-field label{display:block;margin-bottom:6px;font-size:12px;font-weight:750}
  .profile-field input{width:100%}.profile-hint{margin-top:5px;color:var(--muted);font-size:11px;line-height:1.45}
  .profile-avatar-row{display:flex;align-items:center;gap:18px;flex-wrap:wrap}
  .profile-avatar{width:88px;height:88px;flex:0 0 auto;border-radius:24px;overflow:hidden;display:grid;place-items:center;border:1px solid rgba(148,122,255,.35);background:linear-gradient(135deg,rgba(116,80,255,.4),rgba(13,22,40,.9));font-size:30px;font-weight:850;color:#fff}
  .profile-avatar img{width:100%;height:100%;object-fit:cover}
  .profile-avatar-controls{flex:1;min-width:220px}
  .profile-footer{display:flex;justify-content:flex-end;gap:8px;flex-wrap:wrap;margin-top:18px;padding-top:18px;border-top:1px solid var(--line)}
  .profile-security{display:flex;justify-content:space-between;align-items:center;gap:18px;flex-wrap:wrap}
  .profile-security h2{margin:0 0 5px}.profile-security p{margin:0;color:var(--muted);font-size:13px}
  .profile-status{display:inline-flex;align-items:center;gap:6px;padding:5px 9px;border:1px solid var(--line);border-radius:999px;background:var(--panel-2);font-size:11px;font-weight:800;white-space:nowrap}
  .profile-status:before{content:"";width:6px;height:6px;border-radius:50%;background:currentColor}.profile-status.ok{color:var(--ok)}.profile-status.pending{color:var(--warn)}
  @media(max-width:760px){.profile-hero{padding:22px}.profile-stats,.profile-grid,.profile-fields{grid-template-columns:repeat(2,minmax(0,1fr))}.profile-grid{grid-template-columns:1fr}.profile-actions,.profile-actions .btn,.profile-footer .btn{width:100%}.profile-actions .btn,.profile-footer .btn{text-align:center}}
  @media(max-width:480px){.profile-stats,.profile-fields{grid-template-columns:1fr}.profile-group{padding:15px}.profile-form-body{padding:15px}.profile-security .btn{width:100%;text-align:center}}
</style>

<div class="card profile-hero">
  <div class="profile-toolbar">
    <div>
      <div class="profile-eyebrow">Cuenta y preferencias</div>
      <h1>Mi perfil</h1>
      <p>Administrá tu identidad, los datos necesarios para operar y la seguridad de tu cuenta.</p>
    </div>
    <div class="profile-actions">
      <a class="btn secondary" href="panel_admin.php">Volver al panel</a>
      <a class="btn" href="#editar-perfil">Editar perfil</a>
    </div>
  </div>
  <div class="profile-stats">
    <div class="profile-stat"><span>Cuenta</span><strong><?php echo e($displayName); ?></strong></div>
    <div class="profile-stat"><span>Rol</span><strong><?php echo e($roleLabel); ?></strong></div>
    <div class="profile-stat"><span>Datos de pago</span><strong><?php echo $paymentsReady ? 'Completos' : 'Pendientes'; ?></strong></div>
    <div class="profile-stat"><span>Tickex ID</span><strong><?php echo e($tickexId); ?></strong></div>
  </div>
</div>

<?php if ($okMsg !== ''): ?>
  <div class="card"><div class="alert alert-success"><?php echo e($okMsg); ?></div></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
  <div class="card"><div class="alert alert-danger"><?php echo e($error); ?></div></div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" class="card profile-form" id="editar-perfil">
  <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
  <div class="profile-form-head">
    <div>
      <h2>Información de la cuenta</h2>
      <p>Los datos obligatorios se usan para pagos, comprobantes y comunicaciones.</p>
    </div>
    <span class="profile-status <?php echo $profileComplete ? 'ok' : 'pending'; ?>"><?php echo $profileComplete ? 'Perfil completo' : 'Faltan datos'; ?></span>
  </div>

  <div class="profile-form-body">
    <div class="profile-grid">
      <section class="profile-group">
        <div class="profile-group-title">
          <div><h3>Identidad</h3><p>Información visible y datos de contacto.</p></div>
        </div>
        <div class="profile-fields">
          <div class="profile-field">
            <label for="nombre">Nombre</label>
            <input type="text" id="nombre" name="nombre" value="<?php echo e($user['nombre']); ?>" autocomplete="given-name">
          </div>
          <?php if ($hasApellido): ?>
            <div class="profile-field">
              <label for="apellido">Apellido</label>
              <input type="text" id="apellido" name="apellido" required value="<?php echo e(isset($user['apellido']) ? $user['apellido'] : ''); ?>" placeholder="Ej: Pérez" autocomplete="family-name">
            </div>
          <?php endif; ?>
          <div class="profile-field full">
            <label for="email">Email</label>
            <input type="email" id="email" name="email" value="<?php echo e($user['email']); ?>" autocomplete="email">
          </div>
          <?php if ($hasApodo): ?>
            <div class="profile-field full">
              <label for="apodo">Tickex ID</label>
              <input type="text" id="apodo" name="apodo" value="<?php echo e(isset($user['apodo']) ? (string)$user['apodo'] : ''); ?>" placeholder="Ej: STR" maxlength="64">
              <div class="profile-hint">Identificador único para asignaciones y revendedores. Solo letras, números, guion y guion bajo.</div>
            </div>
          <?php endif; ?>
        </div>
      </section>

      <section class="profile-group">
        <div class="profile-group-title">
          <div><h3>Datos para pagos</h3><p>Información operativa y de acreditación.</p></div>
          <span class="profile-status <?php echo $paymentsReady ? 'ok' : 'pending'; ?>"><?php echo $paymentsReady ? 'Listos' : 'Incompletos'; ?></span>
        </div>
        <div class="profile-fields">
          <div class="profile-field full">
            <label for="dni">DNI / Documento</label>
            <input type="text" id="dni" name="dni" required value="<?php echo e($user['dni']); ?>" placeholder="Ej: 12345678" inputmode="numeric">
            <div class="profile-hint">Obligatorio para generar operaciones de pago.</div>
          </div>
          <div class="profile-field full">
            <label for="cbu">CBU / Cuenta para pagos</label>
            <input type="text" id="cbu" name="cbu" value="<?php echo e($user['cbu']); ?>" inputmode="numeric" autocomplete="off">
            <div class="profile-hint">Completalo cuando necesites registrar una cuenta bancaria.</div>
          </div>
        </div>
      </section>

      <section class="profile-group full">
        <div class="profile-group-title">
          <div><h3>Imagen de perfil</h3><p>Personalizá cómo se identifica tu cuenta dentro del panel.</p></div>
        </div>
        <div class="profile-avatar-row">
          <div class="profile-avatar">
            <?php if ($avatarUrl !== ''): ?>
              <img src="<?php echo e($avatarUrl); ?>" alt="Avatar de <?php echo e($displayName); ?>">
            <?php else: ?>
              <?php echo e(strtoupper($avatarInitial)); ?>
            <?php endif; ?>
          </div>
          <div class="profile-avatar-controls">
            <label for="avatar">Nueva imagen</label>
            <input type="file" id="avatar" name="avatar" accept="image/jpeg,image/png,image/webp">
            <div class="profile-hint">JPG, PNG o WEBP. Tamaño máximo: 2 MB.</div>
          </div>
        </div>
      </section>
    </div>

    <div class="profile-footer">
      <button type="submit" class="btn">Guardar cambios</button>
    </div>
  </div>
</form>

<?php if ($avatarUrl !== ''): ?>
  <form method="post" onsubmit="return confirm('¿Eliminar avatar?');" style="margin-top:-12px;margin-bottom:16px;text-align:right;">
    <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
    <button type="submit" name="delete_avatar" value="1" class="btn danger">Eliminar avatar</button>
  </form>
<?php endif; ?>

<div class="card profile-security" id="seguridad-perfil">
  <div>
    <div class="profile-eyebrow">Seguridad</div>
    <h2>Contraseña y recuperación</h2>
    <p>Cambiá tu contraseña desde una pantalla dedicada. La recuperación se inicia desde el acceso de administradores.</p>
  </div>
  <a href="cambiar_password.php" class="btn secondary">Cambiar contraseña</a>
</div>

<?php require __DIR__ . '/inc/layout_bottom.php';
