<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Asegurar path de sesiones escribible (WSL suele bloquear /var/lib/php/sessions)
$sp = ini_get('session.save_path');
if (!$sp || !is_writable($sp)) {
  $tmp = sys_get_temp_dir();
  if (is_dir($tmp) && is_writable($tmp)) {
    session_save_path($tmp);
  }
}

session_start();

// Conexión directa a la misma base que usamos en registro_usuario.php
$dbFile = __DIR__ . '/save_the_rave.sqlite';

try {
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    // Si falla la DB mostramos algo entendible
    header('Content-Type: text/plain; charset=utf-8');
    echo "Error al conectar a la base de datos: " . $e->getMessage();
    exit;
}

$errores = array();
$email   = '';
$pass    = '';

// ---------------------------------------------------------------------
// Si ya está logueado, mandarlo directo al panel correspondiente
// ---------------------------------------------------------------------
if (!empty($_SESSION['es_admin']) && !empty($_SESSION['admin_id'])) {
    // Usuario admin ya logueado
    header('Location: panel_admin.php');
    exit;
}

if (isset($_SESSION['usuario_id']) && $_SESSION['usuario_id'] > 0) {
    // Usuario común ya logueado
    header('Location: panel_usuario.php');
    exit;
}

// ---------------------------------------------------------------------
// Manejo del POST (login)
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    $pass  = isset($_POST['password']) ? $_POST['password'] : '';

    if ($email === '' || $pass === '') {
        $errores[] = 'Tenés que completar email y contraseña.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errores[] = 'El formato de email no es válido.';
    }

    if (empty($errores)) {
        try {
            // Detectar columnas disponibles en la vista/tabla usuarios
            $hasPwdHash = false;
            $hasLegacy  = false;
            try {
              $cols = $pdo->query("PRAGMA table_info(usuarios)")->fetchAll(PDO::FETCH_ASSOC);
              foreach ($cols as $c) {
                if (isset($c['name']) && $c['name'] === 'password_hash') { $hasPwdHash = true; }
                if (isset($c['name']) && $c['name'] === 'password') { $hasLegacy = true; }
              }
            } catch (Exception $e) {
              // si falla pragma, asumimos que no hay columnas de password
            }

            $selectPwdHash = $hasPwdHash ? 'password_hash' : "'' AS password_hash";
            $selectLegacy  = $hasLegacy  ? 'password AS legacy_password' : "'' AS legacy_password";

            $stmt = $pdo->prepare("SELECT id, nombre, apellido, email, $selectPwdHash, $selectLegacy, rol, email_confirmado, tipo_global FROM usuarios WHERE email = :email LIMIT 1");
            $stmt->execute(array(':email' => $email));
            $u = $stmt->fetch(PDO::FETCH_ASSOC);

            $okPass = false;
            $hasUserPassword = false;

            if ($u) {
                $passwordHash = $u['password_hash'];
                $legacyPass   = isset($u['legacy_password']) ? $u['legacy_password'] : '';
                $hasUserPassword = ($passwordHash !== '' && $passwordHash !== null) || ($legacyPass !== '' && $legacyPass !== null);

                // Compatibilidad: prueba bcrypt/verify, luego md5, luego texto plano del campo legacy
                if ($passwordHash !== '' && $passwordHash !== null) {
                  if (function_exists('password_verify') && strpos($passwordHash, '$2y$') === 0) {
                    $okPass = password_verify($pass, $passwordHash);
                  }
                  // Fallback para hashes legacy en MD5 (32 caracteres hex)
                  if (!$okPass && strlen($passwordHash) === 32 && ctype_xdigit($passwordHash)) {
                    $okPass = (md5($pass) === strtolower($passwordHash));
                  }
                }

                // Si aún no validó, intentamos con el campo legacy_password (de usuarios_admin)
                if (!$okPass && $legacyPass !== '') {
                  if (strlen($legacyPass) === 32 && ctype_xdigit($legacyPass)) {
                    $okPass = (md5($pass) === strtolower($legacyPass));
                  } else {
                    $okPass = ($pass === $legacyPass);
                  }
                }

                if ($okPass) {
                    // Datos base del usuario (vista usuarios)
                    $_SESSION['usuario_id']     = (int)$u['id'];
                    $_SESSION['usuario_email']  = $u['email'];
                    $_SESSION['usuario_nombre'] = trim($u['nombre'] . ' ' . $u['apellido']);
                    $_SESSION['usuario_rol']    = $u['rol'];
                    $_SESSION['tipo_global']    = isset($u['tipo_global']) ? $u['tipo_global'] : '';
                    // Compatibilidad con scripts legacy
                    $_SESSION['nombre']         = $_SESSION['usuario_nombre'];
                    $_SESSION['usuario']        = $u['email'];

                    // -----------------------------------------------------
                    // NUEVO: si también existe en usuarios_admin → admin
                    // -----------------------------------------------------
                    try {
                        $stmtAdmin = $pdo->prepare("
                            SELECT
                                id,
                                username,
                                email,
                                rol,
                                tipo_global,
                                rol_evento
                            FROM usuarios_admin
                            WHERE email = :email
                              AND activo = 1
                            LIMIT 1
                        ");
                        $stmtAdmin->execute(array(':email' => $u['email']));
                        $adminRow = $stmtAdmin->fetch(PDO::FETCH_ASSOC);

                        if ($adminRow) {
                          // Es un admin del sistema → setear sesión como admin
                          $_SESSION['es_admin']    = true;
                          $_SESSION['admin_id']    = (int)$adminRow['id'];
                          $_SESSION['user_id']     = (int)$adminRow['id']; // muchos scripts usan user_id
                          $_SESSION['usuario']     = $adminRow['username'];
                          $_SESSION['rol']         = $adminRow['rol'];
                          $_SESSION['tipo_global'] = $adminRow['tipo_global'];
                          $_SESSION['rol_evento']  = isset($adminRow['rol_evento']) ? $adminRow['rol_evento'] : '';

                          // Si no había nombre/email en la vista usuarios, usar los de admin
                          if (empty($_SESSION['usuario_nombre']) && isset($adminRow['nombre'])) {
                            $_SESSION['usuario_nombre'] = $adminRow['nombre'];
                            $_SESSION['nombre'] = $adminRow['nombre'];
                          }
                          if (empty($_SESSION['usuario_email']) && isset($adminRow['email'])) {
                            $_SESSION['usuario_email'] = $adminRow['email'];
                            $_SESSION['email'] = $adminRow['email'];
                          }

                          // Redirigimos al panel de admins
                          header('Location: panel_admin.php');
                          exit;
                        }
                    } catch (Exception $e) {
                        // Si falla la consulta de admins, no rompemos el login de usuario común
                        // error_log('Error consultando usuarios_admin: '.$e->getMessage());
                    }

                    // Si NO es admin, seguimos con el flujo normal de usuario común:
                    header('Location: panel_usuario.php');
                    exit;
                }
            }

            // Si el usuario existe y tenía contraseña pero no validó, no seguimos a clientes
            if ($u && $hasUserPassword && !$okPass) {
              $errores[] = 'Email o contraseña incorrectos.';
            }

            // Fallback: autenticar como cliente (registro_pendientes) si no hay usuario en vista o no tenía pass
            $shouldTryCliente = (!$u) || !$hasUserPassword;

            if ($shouldTryCliente && empty($errores)) {
              // Detectar/crear columna password_hash en registro_pendientes
              $cliHasPwd = false;
              try {
                $colsCli = $pdo->query("PRAGMA table_info(registro_pendientes)")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($colsCli as $c) {
                  if (isset($c['name']) && $c['name'] === 'password_hash') { $cliHasPwd = true; break; }
                }
                if (!$cliHasPwd) {
                  $pdo->exec("ALTER TABLE registro_pendientes ADD COLUMN password_hash TEXT");
                  $cliHasPwd = true;
                }
              } catch (Exception $e) {
                // ignoramos; si falla alter, usamos select placeholder
              }

              $selectCliPwd = $cliHasPwd ? 'password_hash' : "'' AS password_hash";

              $stmtCli = $pdo->prepare("
                SELECT id, email, nombre, apellido, apodo, dni, genero, $selectCliPwd, completado_en
                FROM registro_pendientes
                WHERE email = :email
                LIMIT 1
              ");
                $stmtCli->execute(array(':email' => $email));
                $cli = $stmtCli->fetch(PDO::FETCH_ASSOC);

                $okCli = false;
              $firstSet = false;
                if ($cli && !empty($cli['password_hash'])) {
                    $hashCli = (string)$cli['password_hash'];
                    if (function_exists('password_verify') && strpos($hashCli, '$2') === 0) {
                        $okCli = password_verify($pass, $hashCli);
                    }
                    if (!$okCli && strlen($hashCli) === 32 && ctype_xdigit($hashCli)) {
                        $okCli = (md5($pass) === strtolower($hashCli));
                    }
                    if (!$okCli && $hashCli !== '') {
                        $okCli = ($pass === $hashCli);
                    }
              } elseif ($cli && (string)$cli['password_hash'] === '') {
                // Primer seteo de contraseña: si no tenía, tomamos la enviada y la guardamos (mín 6 chars)
                if (strlen($pass) >= 6) {
                  $firstSet = true;
                  $newHash = function_exists('password_hash') ? password_hash($pass, PASSWORD_DEFAULT) : md5($pass);
                  $upd = $pdo->prepare("UPDATE registro_pendientes SET password_hash = :h WHERE id = :id");
                  $upd->execute(array(':h' => $newHash, ':id' => (int)$cli['id']));
                  $cli['password_hash'] = $newHash;
                  $okCli = true;
                }
              }

                if ($cli && $okCli) {
                    $_SESSION['usuario_id']     = (int)$cli['id'];
                    $_SESSION['usuario_email']  = $cli['email'];
                    $_SESSION['usuario_nombre'] = trim(($cli['nombre'] ?? '') . ' ' . ($cli['apellido'] ?? ''));
                    $_SESSION['usuario_rol']    = 'cliente';
                    $_SESSION['rol']            = 'cliente';
                    $_SESSION['usuario']        = $cli['email'];
                    $_SESSION['nombre']         = $_SESSION['usuario_nombre'];
                    $_SESSION['email']          = $cli['email'];
                    $_SESSION['tipo_global']    = '';

                    header('Location: panel_usuario.php');
                    exit;
                }

                $errores[] = 'Email o contraseña incorrectos.';
            }
        } catch (Exception $e) {
            $errores[] = 'Error al verificar el usuario: ' . $e->getMessage();
        }
    }
}

// ---------------------------------------------------------------------
// Vista
// ---------------------------------------------------------------------
include __DIR__ . '/inc/layout_top.php';
?>
<div class="card" style="max-width:480px;margin:0 auto 16px auto;text-align:center;">
  <div style="margin-bottom:16px;">
    <img src="tickex-logo_sobre_oscuro.svg"
         alt="Tickex"
         style="height:230px;display:block;margin:0 auto 8px auto;">
  </div>
  <h2>Iniciar sesión</h2>
  <p style="color:var(--muted);margin-top:8px;">
    Ingresá con tu cuenta de Tickex para ver tus Tickex, historial de compras y facturas.
  </p>
</div>

<?php if (!empty($errores)): ?>
  <div class="card" style="max-width:480px;margin:0 auto 16px auto;">
    <div class="flash err">
      <ul style="margin:0 0 0 18px;padding:0;">
        <?php foreach ($errores as $e): ?>
          <li><?php echo htmlspecialchars($e, ENT_QUOTES, 'UTF-8'); ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
<?php endif; ?>

<div class="card" style="max-width:480px;margin:0 auto 16px auto;">
  <form method="post">
    <label for="email">Email</label>
    <input type="text"
           id="email"
           name="email"
           value="<?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>">

    <label for="password">Contraseña</label>
    <input type="password"
           id="password"
           name="password"
           required>

    <button class="btn" type="submit" style="width:100%;margin-top:16px;">
      Iniciar sesión
    </button>
  </form>

  <div style="margin-top:16px;font-size:14px;color:var(--muted);">
    ¿No tenés cuenta?
    <a href="registro_usuario.php">Registrate acá</a>.
  </div>

  <div style="margin-top:8px;font-size:14px;color:var(--muted);">
    ¿Te olvidaste la contraseña?
    <a href="forgot_password.php">Recuperala acá</a>.
  </div>
</div>

<?php include __DIR__ . '/inc/layout_bottom.php'; ?>

