<?php
// login_admin.php
// Login exclusivo para administradores / organizadores (tabla usuarios_admin)
// Permite loguear por EMAIL (si el input tiene @) o por username.

require_once __DIR__ . '/inc/bootstrap.php';

$title   = 'Ingresar como administrador - Tickex';
$errors  = array();
$loginId = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $loginId = isset($_POST['login_id']) ? trim($_POST['login_id']) : '';
    $password = isset($_POST['password']) ? (string)$_POST['password'] : '';

    if ($loginId === '' || $password === '') {
        $errors[] = 'Email/usuario y contraseña son obligatorios.';
    } else {
        try {
            $pdo = new PDO('sqlite:' . __DIR__ . '/save_the_rave.sqlite');
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Si contiene @ asumimos que es un email, si no username
            if (strpos($loginId, '@') !== false) {
                $stmt = $pdo->prepare("
                    SELECT id, username, email, password, rol, tipo_global, activo, evento_id
                    FROM usuarios_admin
                    WHERE email = :v
                    LIMIT 1
                ");
            } else {
                $stmt = $pdo->prepare("
                    SELECT id, username, email, password, rol, tipo_global, activo, evento_id
                    FROM usuarios_admin
                    WHERE username = :v
                    LIMIT 1
                ");
            }

            $stmt->execute(array(':v' => $loginId));
            $admin = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$admin) {
                $errors[] = 'Email/usuario o contraseña incorrectos.';
            } else {
                if ((int)$admin['activo'] !== 1) {
                    $errors[] = 'Este usuario está desactivado.';
                } else {
                    $stored = (string)$admin['password'];
                    $okPass = false;

                    // Compatibilidad con sistema viejo: password plano o md5
                    if ($stored === $password) {
                        $okPass = true;
                    } elseif ($stored === md5($password)) {
                        $okPass = true;
                    }

                    if (!$okPass) {
                        $errors[] = 'Email/usuario o contraseña incorrectos.';
                    } else {
                        if (session_status() !== PHP_SESSION_ACTIVE) {
                            session_start();
                        }

                        $_SESSION['usuario']     = $admin['username'];
                        $_SESSION['user_id']     = (int)$admin['id'];
                        $_SESSION['tipo_global'] = (string)$admin['tipo_global'];

                        // Staff de puerta
                        if ($admin['tipo_global'] === 'staff_evento') {
                            if (!empty($admin['evento_id'])) {
                                $_SESSION['evento_id'] = (int)$admin['evento_id'];
                                header('Location: puerta.php?evento_id=' . (int)$admin['evento_id']);
                                exit;
                            } else {
                                header('Location: puerta.php');
                                exit;
                            }
                        }

                        // Super admin / admin de eventos
                        header('Location: panel_admin.php');
                        exit;
                    }
                }
            }
        } catch (Exception $e) {
            $errors[] = 'Error interno al conectar con la base de datos.';
        }
    }
}

include __DIR__ . '/inc/layout_top.php';
?>
<div class="container" style="max-width:480px;margin:40px auto;">
    <h1 class="mb-4">Ingreso de administradores</h1>

    <?php if ($errors): ?>
        <div class="alert alert-danger">
            <ul style="margin:0;padding-left:20px;">
                <?php foreach ($errors as $e): ?>
                    
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="post" autocomplete="off">
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
<?php
include __DIR__ . '/inc/layout_bottom.php';
