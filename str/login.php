<?php
require_once __DIR__ . '/inc/security.php';
require_once __DIR__ . '/inc/turnstile.php';
require_once __DIR__ . '/inc/routes.php';
tickex_send_security_headers();
tickex_session_start();
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/login_security.php';
require_once __DIR__ . '/inc/google_identity.php';

// Conexión directa a la misma base que usamos en registro_usuario.php
$dbFile = __DIR__ . '/save_the_rave.sqlite';

try {
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  // Mitigar locks transitorios de SQLite
  try {
    $pdo->exec('PRAGMA busy_timeout = 15000');
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA synchronous = NORMAL');
  } catch (Exception $e) {
    // ignore
  }

  try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_blocks (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      email TEXT NOT NULL,
      reason TEXT,
      active INTEGER NOT NULL DEFAULT 1,
      blocked_at TEXT NOT NULL DEFAULT (datetime('now')),
      blocked_by_admin_id INTEGER,
      unblocked_at TEXT,
      unblocked_by_admin_id INTEGER
    )");
  } catch (Exception $e) {
    // ignore
  }
} catch (Exception $e) {
    // Si falla la DB mostramos algo entendible
    header('Content-Type: text/plain; charset=utf-8');
    echo "Error al conectar a la base de datos: " . $e->getMessage();
    exit;
}

$errores = array();
$email   = '';
$pass    = '';
$csrf = tickex_csrf_token();
$googleError = isset($_GET['google_error']) ? trim((string)$_GET['google_error']) : '';
$googleEnabled = tickex_google_is_configured();

// Soporte para volver a una URL específica (ej: checkout) luego del login.
$next = '';
if (isset($_GET['next'])) {
  $next = (string)$_GET['next'];
} elseif (isset($_POST['next'])) {
  $next = (string)$_POST['next'];
}

// Prefill de email si viene por query (ej: desde checkout)
if ($email === '' && isset($_GET['email'])) {
  $email = trim((string)$_GET['email']);
}

function _safe_next_url($next)
{
  $n = trim((string)$next);
  if ($n === '') return '';
  // evitar open redirect
  if (strpos($n, '://') !== false) return '';
  if (stripos($n, 'javascript:') === 0) return '';
  // aceptar rutas relativas del sitio
  if ($n[0] === '/') return $n;
  // aceptar archivos relativos simples
  if (preg_match('/^[a-zA-Z0-9_\-\/\.]+\.php(\?.*)?$/', $n)) return $n;
  return '';
}

function _tickex_login_is_blocked($pdo, $email)
{
  $email = trim((string)$email);
  if ($email === '') return false;
  try {
    $st = $pdo->prepare("SELECT 1 FROM user_blocks WHERE active = 1 AND lower(email) = lower(:e) LIMIT 1");
    $st->execute(array(':e' => $email));
    return (bool)$st->fetchColumn();
  } catch (Exception $e) {
    return false;
  }
}

$nextSafe = _safe_next_url($next);

// ---------------------------------------------------------------------
// Si ya está logueado, mandarlo directo al panel correspondiente
// ---------------------------------------------------------------------
if (!empty($_SESSION['es_admin']) && !empty($_SESSION['admin_id'])) {
    // Usuario admin ya logueado
  header('Location: ' . ($nextSafe !== '' ? $nextSafe : tickex_route('admin_home', array())));
    exit;
}

if (isset($_SESSION['usuario_id']) && $_SESSION['usuario_id'] > 0) {
    // Usuario común ya logueado
  header('Location: ' . ($nextSafe !== '' ? $nextSafe : tickex_route('customer_home', array())));
    exit;
}

// ---------------------------------------------------------------------
// Manejo del POST (login)
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!tickex_csrf_verify(isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '')) {
      $errores[] = 'La sesión venció. Actualizá la página e intentá nuevamente.';
    }
    // Rate limit básico por sesión: evita brute force casual
    $now = time();
    $windowSec = 10 * 60;
    $maxAttempts = 10;
    if (!isset($_SESSION['_login_fail_count'])) {
      $_SESSION['_login_fail_count'] = 0;
      $_SESSION['_login_fail_ts'] = $now;
    }
    $firstTs = isset($_SESSION['_login_fail_ts']) ? (int)$_SESSION['_login_fail_ts'] : $now;
    if (($now - $firstTs) > $windowSec) {
      $_SESSION['_login_fail_count'] = 0;
      $_SESSION['_login_fail_ts'] = $now;
      $firstTs = $now;
    }
    if ((int)$_SESSION['_login_fail_count'] >= $maxAttempts) {
      $errores[] = 'Demasiados intentos. Esperá unos minutos e intentá de nuevo.';
      // Pequeño delay para frenar automatización
      usleep(350000);
    }

    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    $pass  = isset($_POST['password']) ? $_POST['password'] : '';

    if ($email === '' || $pass === '') {
        $errores[] = 'Tenés que completar email y contraseña.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errores[] = 'El formato de email no es válido.';
    }

    // Captcha anti-bot (opcional, solo si hay keys configuradas)
    if (empty($errores)) {
      tickex_turnstile_verify_post($errores);
    }

    if (empty($errores)) {
        if (_tickex_login_is_blocked($pdo, $email)) {
          $errores[] = 'Tu acceso esta suspendido. Contacta a soporte.';
        }
    }

    if (empty($errores)) {
        try {
            // Un único acceso: primero validamos si el email corresponde a una
            // cuenta administrativa activa. La contraseña debe ser la propia
            // de esa cuenta; una contraseña de comprador nunca eleva permisos.
            $adminStmt = $pdo->prepare("SELECT id,username,email,password,rol,tipo_global,rol_evento,activo,evento_id FROM usuarios_admin WHERE email=:email COLLATE NOCASE LIMIT 1");
            $adminStmt->execute(array(':email'=>$email));
            $adminAccount = $adminStmt->fetch(PDO::FETCH_ASSOC);
            if ($adminAccount && (int)$adminAccount['activo'] === 1) {
              $adminPassword = tickex_password_verify_compat($pass, (string)$adminAccount['password']);
              if (!empty($adminPassword['valid'])) {
                if (!empty($adminPassword['needs_upgrade'])) {
                  tickex_password_upgrade($pdo, 'usuarios_admin', 'password', (int)$adminAccount['id'], $pass);
                }
                $_SESSION['_login_fail_count'] = 0;
                $_SESSION['_login_fail_ts'] = time();
                $destination = tickex_google_establish_session($pdo, 'admin', $adminAccount);
                header('Location: ' . ($nextSafe !== '' ? $nextSafe : $destination));
                exit;
              }
            }

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

            // SQLite puede quedar lockeado por escrituras concurrentes (email_logs, tokens, etc).
            // Reintentamos unos ms antes de fallar.
            $u = false;
            $tries = 0;
            while (true) {
              try {
                $stmt->execute(array(':email' => $email));
                $u = $stmt->fetch(PDO::FETCH_ASSOC);
                break;
              } catch (Exception $e) {
                $msg = $e->getMessage();
                if (stripos($msg, 'database is locked') !== false && $tries < 3) {
                  $tries++;
                  usleep(250000); // 250ms
                  continue;
                }
                throw $e;
              }
            }

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
                    // Anti session fixation
                    if (function_exists('session_regenerate_id')) {
                      @session_regenerate_id(true);
                    }

					tickex_clear_identity_session();

                    // Reset rate limit
                    $_SESSION['_login_fail_count'] = 0;
                    $_SESSION['_login_fail_ts'] = time();

                    // Datos base del usuario (vista usuarios)
                    $_SESSION['usuario_id']     = (int)$u['id'];
                    $_SESSION['auth_context']   = 'user';
                    $_SESSION['usuario_email']  = $u['email'];
                    $_SESSION['usuario_nombre'] = trim($u['nombre'] . ' ' . $u['apellido']);
                  $_SESSION['first_name']      = isset($u['nombre']) ? (string)$u['nombre'] : '';
                  $_SESSION['last_name']       = isset($u['apellido']) ? (string)$u['apellido'] : '';
                    $_SESSION['usuario_rol']    = $u['rol'];
                    $_SESSION['tipo_global']    = isset($u['tipo_global']) ? $u['tipo_global'] : '';
                    // Compatibilidad con scripts legacy
                    $_SESSION['nombre']         = $_SESSION['usuario_nombre'];
                    $_SESSION['usuario']        = $u['email'];

                    // Si NO es admin, seguimos con el flujo normal de usuario común:
                    header('Location: ' . ($nextSafe !== '' ? $nextSafe : tickex_route('customer_home', array())));
                    exit;
                }
            }

            // Si el usuario existe y tenía contraseña pero no validó, no seguimos a clientes
            if ($u && $hasUserPassword && !$okPass) {
              $_SESSION['_login_fail_count'] = (int)$_SESSION['_login_fail_count'] + 1;
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

              $cli = false;
              $triesCli = 0;
              while (true) {
                try {
                  $stmtCli->execute(array(':email' => $email));
                  $cli = $stmtCli->fetch(PDO::FETCH_ASSOC);
                  break;
                } catch (Exception $e) {
                  $msg = $e->getMessage();
                  if (stripos($msg, 'database is locked') !== false && $triesCli < 3) {
                    $triesCli++;
                    usleep(250000);
                    continue;
                  }
                  throw $e;
                }
              }

                $okCli = false;
                if ($cli && !empty($cli['password_hash'])) {
                    $hashCli = (string)$cli['password_hash'];
                    $cliPasswordCheck = tickex_password_verify_compat($pass, $hashCli);
                    $okCli = !empty($cliPasswordCheck['valid']);
                    if ($okCli && !empty($cliPasswordCheck['needs_upgrade'])) {
                        tickex_password_upgrade($pdo, 'registro_pendientes', 'password_hash', (int)$cli['id'], $pass);
                    }
              }

                if ($cli && $okCli) {
					tickex_clear_identity_session();
					if (function_exists('session_regenerate_id')) {
					  @session_regenerate_id(true);
					}
                    $_SESSION['usuario_id']     = (int)$cli['id'];
					$_SESSION['auth_context']   = 'user';
                    $_SESSION['usuario_email']  = $cli['email'];
                    $_SESSION['usuario_nombre'] = trim((isset($cli['nombre']) ? (string)$cli['nombre'] : '') . ' ' . (isset($cli['apellido']) ? (string)$cli['apellido'] : ''));
                  $_SESSION['first_name']      = isset($cli['nombre']) ? (string)$cli['nombre'] : '';
                  $_SESSION['last_name']       = isset($cli['apellido']) ? (string)$cli['apellido'] : '';
                  $_SESSION['dni']             = isset($cli['dni']) ? (string)$cli['dni'] : '';
                    $_SESSION['usuario_rol']    = 'cliente';
                    $_SESSION['rol']            = 'cliente';
                    $_SESSION['usuario']        = $cli['email'];
                    $_SESSION['nombre']         = $_SESSION['usuario_nombre'];
                    $_SESSION['email']          = $cli['email'];
                    $_SESSION['tipo_global']    = '';

                  header('Location: ' . ($nextSafe !== '' ? $nextSafe : tickex_route('customer_home', array())));
                    exit;
                }

                // sumar intento fallido (credenciales incorrectas)
                $_SESSION['_login_fail_count'] = (int)$_SESSION['_login_fail_count'] + 1;
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
<main class="tx-login-shell">
<section class="tx-login-story">
  <div class="tx-login-brand">TICKEX <span>EVENT PLATFORM</span></div>
  <div class="tx-login-story-copy">
    <div class="tx-kicker"><i></i> Todo tu evento, en un mismo lugar</div>
    <h1>Entradas, accesos y resultados en tiempo real.</h1>
    <p>Una plataforma simple para comprar entradas, administrar eventos y llegar más rápido a lo que importa.</p>
  </div>
  <div class="tx-login-proof">
    <div><strong>Seguro</strong><span>Acceso protegido</span></div>
    <div><strong>Ágil</strong><span>Todo desde el celular</span></div>
    <div><strong>Claro</strong><span>Información en vivo</span></div>
  </div>
</section>

<section class="tx-login-form-column">
<div class="card tx-login-heading">
  <div class="tx-login-logo-wrap">
    <img src="tickex-logo_sobre_oscuro.svg"
         alt="Tickex"
         class="tx-login-logo">
  </div>
  <h2>Iniciar sesión</h2>
  <p>
    Ingresá con tu cuenta de Tickex para ver tus Tickex, historial de compras y facturas.
  </p>
</div>

<?php if (!empty($errores)): ?>
  <div class="card tx-login-errors">
    <div class="flash err">
      <ul style="margin:0 0 0 18px;padding:0;">
        <?php foreach ($errores as $e): ?>
          <li><?php echo htmlspecialchars($e, ENT_QUOTES, 'UTF-8'); ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
<?php endif; ?>

<div class="card tx-login-card">
  <?php if ($googleError !== ''): ?>
    <div class="flash err" style="margin-bottom:16px;"><?php echo htmlspecialchars($googleError, ENT_QUOTES, 'UTF-8'); ?></div>
  <?php endif; ?>
  <?php if ($googleEnabled): ?>
    <a class="tx-google-login" href="google_login.php?context=unified<?php echo $nextSafe !== '' ? '&amp;next=' . rawurlencode($nextSafe) : ''; ?>">
      <span class="tx-google-mark" aria-hidden="true">G</span>
      Continuar con Google
    </a>
    <div class="tx-login-divider"><span>o ingresá con tu contraseña</span></div>
  <?php endif; ?>
  <form method="post">
    <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
    <?php if ($nextSafe !== ''): ?>
      <input type="hidden" name="next" value="<?php echo htmlspecialchars($nextSafe, ENT_QUOTES, 'UTF-8'); ?>">
    <?php endif; ?>
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

    <?php tickex_turnstile_widget(array('theme' => 'auto')); ?>

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
</section>
</main>

<?php include __DIR__ . '/inc/layout_bottom.php'; ?>

