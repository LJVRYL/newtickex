<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

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

// Si ya está logueado, mandarlo directo al panel
if (isset($_SESSION['usuario_id']) && $_SESSION['usuario_id'] > 0) {
    header('Location: panel_usuario.php');
    exit;
}

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
            $stmt = $pdo->prepare("
                SELECT id, nombre, apellido, email, password_hash, rol, email_confirmado
                FROM usuarios
                WHERE email = :email
                LIMIT 1
            ");
            $stmt->execute(array(':email' => $email));
            $u = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$u) {
                $errores[] = 'Email o contraseña incorrectos.';
            } else {
                $passwordHash = $u['password_hash'];

                // Compatibilidad: password_verify si existe, sino MD5 plano
                $okPass = false;
                if ($passwordHash !== '' && $passwordHash !== null) {
                    if (function_exists('password_verify')) {
                        $okPass = password_verify($pass, $passwordHash);
                    } else {
                        $okPass = (md5($pass) === $passwordHash);
                    }
                }

                if (!$okPass) {
                    $errores[] = 'Email o contraseña incorrectos.';
                } else {
                    // Si querés forzar que tenga email confirmado:
                    // if ((int)$u['email_confirmado'] !== 1) { ... }

                    $_SESSION['usuario_id']    = (int)$u['id'];
                    $_SESSION['usuario_email'] = $u['email'];
                    $_SESSION['usuario_nombre']= trim($u['nombre'] . ' ' . $u['apellido']);
                    $_SESSION['usuario_rol']   = $u['rol'];

                    header('Location: panel_usuario.php');
                    exit;
                }
            }
        } catch (Exception $e) {
            $errores[] = 'Error al verificar el usuario: ' . $e->getMessage();
        }
    }
}

// Vista
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
    <input type="email"
           id="email"
           name="email"
           r
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
    <span style="opacity:0.6;">(más adelante: recuperación por email)</span>
  </div>
</div>

<?php include __DIR__ . '/inc/layout_bottom.php'; ?>
