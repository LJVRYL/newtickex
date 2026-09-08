<?php
// login_admin.php
// Login exclusivo para administradores / organizadores (tabla usuarios_admin)
// Permite loguear por EMAIL (si el input tiene @) o por username.

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/login_security.php';

$title   = 'Ingresar como administrador - Tickex';
$errors  = array();
$loginId = '';
$csrf = tickex_csrf_token();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $loginId = isset($_POST['login_id']) ? trim($_POST['login_id']) : '';
    $password = isset($_POST['password']) ? (string)$_POST['password'] : '';

    if (!tickex_csrf_verify(isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '')) {
        $errors[] = 'La sesión venció. Actualizá la página e intentá nuevamente.';
    }
    if (tickex_login_throttled('admin', 10, 600)) {
        $errors[] = 'Demasiados intentos. Esperá unos minutos e intentá de nuevo.';
        usleep(350000);
    }
    if ($loginId === '' || $password === '') {
        $errors[] = 'Email/usuario y contraseña son obligatorios.';
    } elseif (empty($errors)) {
        try {
            // Usar la conexión central: configura busy_timeout/WAL y evita que el
            // login compita con otra conexión SQLite durante la inicialización.
            $pdo = db();

            // Si contiene @ asumimos que es un email, si no username
            if (strpos($loginId, '@') !== false) {
                $stmt = $pdo->prepare("
                    SELECT id, username, email, password, rol, tipo_global, activo, evento_id
                    FROM usuarios_admin
                    WHERE email = :v COLLATE NOCASE
                    LIMIT 1
                ");
            } else {
                $stmt = $pdo->prepare("
                    SELECT id, username, email, password, rol, tipo_global, activo, evento_id
                    FROM usuarios_admin
                    WHERE username = :v COLLATE NOCASE
                    LIMIT 1
                ");
            }

            $stmt->execute(array(':v' => $loginId));
            $admin = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$admin) {
                tickex_login_record_failure('admin');
                $errors[] = 'Email/usuario o contraseña incorrectos.';
            } else {
                if ((int)$admin['activo'] !== 1) {
                    tickex_login_record_failure('admin');
                    $errors[] = 'Email/usuario o contraseña incorrectos.';
                } else {
                    $stored = (string)$admin['password'];
                    $passwordCheck = tickex_password_verify_compat($password, $stored);
                    $okPass = !empty($passwordCheck['valid']);

                    if (!$okPass) {
                        tickex_login_record_failure('admin');
                        $errors[] = 'Email/usuario o contraseña incorrectos.';
                    } else {
                        if (!empty($passwordCheck['needs_upgrade'])) {
                            tickex_password_upgrade($pdo, 'usuarios_admin', 'password', (int)$admin['id'], $password);
                        }
                        tickex_login_clear_failures('admin');
                        if (session_status() !== PHP_SESSION_ACTIVE) {
                            session_start();
                        }

                        if (function_exists('session_regenerate_id')) {
                            @session_regenerate_id(true);
                        }
                        tickex_clear_identity_session();

                        $_SESSION['usuario']     = $admin['username'];
                        $_SESSION['username']    = $admin['username'];
                        $_SESSION['usuario_email'] = $admin['email'];
                        $_SESSION['email']       = $admin['email'];
                        $_SESSION['admin_id']    = (int)$admin['id'];
                        $_SESSION['user_id']     = (int)$admin['id'];
                        $_SESSION['es_admin']    = true;
                        $_SESSION['is_admin']    = true;
                        $_SESSION['auth_context'] = 'admin';
                        $_SESSION['rol']         = (string)$admin['rol'];
                        $_SESSION['tipo_global'] = (string)$admin['tipo_global'];

                        // Staff de puerta
                        if ($admin['tipo_global'] === 'staff_evento') {
                            // buscar asignaciones múltiples
                            $pdoMap = $pdo;
                            $stmtEvStaff = $pdoMap->prepare("SELECT evento_id FROM staff_eventos WHERE staff_id = :sid");
                            $stmtEvStaff->execute(array(':sid'=>(int)$admin['id']));
                            $evList = $stmtEvStaff->fetchAll(PDO::FETCH_COLUMN);
                            $evList = $evList ? array_map('intval', $evList) : array();

                            if (count($evList) === 1) {
                                $_SESSION['evento_id'] = $evList[0];
                                header('Location: puerta.php?evento_id=' . $evList[0]);
                                exit;
                            } elseif (count($evList) > 1) {
                                // sin elegir aún, puerta mostrará selector
                                $_SESSION['evento_id'] = 0;
                                header('Location: puerta.php');
                                exit;
                            } elseif (!empty($admin['evento_id'])) {
                                $_SESSION['evento_id'] = (int)$admin['evento_id'];
                                header('Location: puerta.php?evento_id=' . (int)$admin['evento_id']);
                                exit;
                            } else {
                                $_SESSION['evento_id'] = 0;
                                header('Location: puerta.php');
                                exit;
                            }
                        }

                        // Super admin / admin de eventos
                        if ($admin['tipo_global'] === 'super_admin') {
                            header('Location: panel_superadmin.php');
                            exit;
                        } else {
                            header('Location: panel_admin.php');
                            exit;
                        }
                    }
                }
            }
        } catch (Exception $e) {
            error_log('Tickex admin login database error: ' . $e->getMessage());
            $errors[] = 'Error interno al conectar con la base de datos.';
        }
    }
}

include __DIR__ . '/inc/layout_top.php';
?>
<main class="tx-login-shell tx-admin-login-shell">
  <section class="tx-login-story">
    <div class="tx-login-brand">TICKEX <span>BACKSTAGE</span></div>
    <div class="tx-login-story-copy">
      <div class="tx-kicker"><i></i> Centro de operaciones</div>
      <h1>Tu evento bajo control, desde cualquier lugar.</h1>
      <p>Ventas, puerta, equipo, comunicación y economía organizados en una sola plataforma.</p>
    </div>
    <div class="tx-login-proof">
      <div><strong>Ventas</strong><span>Seguimiento claro</span></div>
      <div><strong>Puerta</strong><span>Accesos en vivo</span></div>
      <div><strong>Equipo</strong><span>Roles separados</span></div>
    </div>
  </section>
  <section class="tx-login-form-column">
  <div class="container tx-admin-login-card">
    <div class="tx-kicker"><i></i> Acceso privado</div>
    <h1 class="mb-4">Ingreso de administradores</h1>
    <p class="tx-login-intro">Ingresá para administrar tus eventos y operaciones.</p>

    <?php if ($errors): ?>
        <div class="alert alert-danger">
            <ul style="margin:0;padding-left:20px;">
                <?php foreach ($errors as $e): ?>
                    <li><?php echo htmlspecialchars($e, ENT_QUOTES, 'UTF-8'); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="post" autocomplete="off">
        <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
        <div class="mb-3">
            <label for="login_id" class="form-label">Email de admin</label>
            <input
                type="email"
                class="form-control"
                id="login_id"
                name="login_id"
                value="<?php echo htmlspecialchars($loginId, ENT_QUOTES, 'UTF-8'); ?>"
                required
            >
        </div>

        <div class="mb-3">
            <label for="password" class="form-label">Contraseña</label>
            <input
                type="password"
                class="form-control"
                id="password"
                name="password"
                required
            >
        </div>

        <button type="submit" class="btn btn-primary w-100">
            Ingresar al panel de administración
        </button>
    </form>

    <hr class="my-4">

    <p class="text-muted" style="font-size:0.9rem;">
        Este acceso es sólo para organizadores, productores y personal de puerta.
        Si sos público general, usá el ingreso desde la página principal de Tickex.
    </p>
  </div>
  </section>
</main>
<?php
include __DIR__ . '/inc/layout_bottom.php';
