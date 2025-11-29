<?php
// verificar_email.php
// Paso 2 del registro de usuario Tickex:
// - GET ?token=...  → valida token y muestra formulario
// - POST            → guarda datos, marca email_confirmado=1, setea password y loguea al usuario

require_once __DIR__ . '/inc/bootstrap.php';

$title = 'Confirmar tu email - Tickex';

// Asegurarnos de tener una conexión a la base
try {
    $pdo = new PDO('sqlite:' . __DIR__ . '/save_the_rave.sqlite');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    http_response_code(500);
    echo "Error al conectar con la base de datos.";
    exit;
}

// Helper de escape por si no existe
if (!function_exists('e')) {
    function e($s) {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }
}

$errors  = array();
$token   = '';
$usuario = null;

// 1) Determinar token (GET o POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = isset($_POST['token']) ? trim($_POST['token']) : '';
} else {
    $token = isset($_GET['token']) ? trim($_GET['token']) : '';
}

if ($token === '') {
    $errors[] = 'El enlace de verificación es inválido o ha expirado (token vacío).';
} else {
    // Buscar usuario por token
    $stmt = $pdo->prepare("
        SELECT id, email, nombre, apellido, dni, password_hash, email_confirmado, token_confirmacion
        FROM usuarios
        WHERE token_confirmacion = :token
        LIMIT 1
    ");
    $stmt->execute(array(':token' => $token));
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$usuario) {
        $errors[] = 'El enlace de verificación es inválido o ya fue utilizado.';
    } elseif ((int)$usuario['email_confirmado'] === 1) {
        $errors[] = 'Este email ya fue confirmado. Podés ingresar a tu cuenta.';
    }
}

// 2) Si POST y no hay errores de token, procesar el formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($errors) && $usuario) {
    $nombre   = isset($_POST['nombre'])   ? trim($_POST['nombre'])   : '';
    $apellido = isset($_POST['apellido']) ? trim($_POST['apellido']) : '';
    $dni      = isset($_POST['dni'])      ? trim($_POST['dni'])      : '';
    $pass1    = isset($_POST['password']) ? $_POST['password']       : '';
    $pass2    = isset($_POST['password2'])? $_POST['password2']      : '';

    if ($nombre === '') {
        $errors[] = 'El nombre es obligatorio.';
    }
    if ($apellido === '') {
        $errors[] = 'El apellido es obligatorio.';
    }
    if ($dni === '') {
        $errors[] = 'El DNI es obligatorio.';
    }
    if ($pass1 === '' || $pass2 === '') {
        $errors[] = 'La contraseña y su confirmación son obligatorias.';
    } elseif ($pass1 !== $pass2) {
        $errors[] = 'Las contraseñas no coinciden.';
    } elseif (strlen($pass1) < 6) {
        $errors[] = 'La contraseña debe tener al menos 6 caracteres.';
    }

    if (empty($errors)) {
        $hash = password_hash($pass1, PASSWORD_BCRYPT);

        $upd = $pdo->prepare("
            UPDATE usuarios
            SET nombre = :nombre,
                apellido = :apellido,
                dni = :dni,
                password_hash = :pass,
                email_confirmado = 1,
                token_confirmacion = ''
            WHERE id = :id
        ");
        $upd->execute(array(
            ':nombre' => $nombre,
            ':apellido' => $apellido,
            ':dni' => $dni,
            ':pass' => $hash,
            ':id' => $usuario['id'],
        ));

        // Refrescar datos del usuario desde la base (por si hace falta)
        $stmt2 = $pdo->prepare("
            SELECT id, email, nombre, apellido, dni, email_confirmado
            FROM usuarios
            WHERE id = :id
            LIMIT 1
        ");
        $stmt2->execute(array(':id' => $usuario['id']));
        $usuarioActualizado = $stmt2->fetch(PDO::FETCH_ASSOC);

        // Iniciar sesión de usuario final Tickex
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        // Si existe un helper login_usuario_tickex() definido por Cod
        if (function_exists('login_usuario_tickex')) {
            login_usuario_tickex($usuarioActualizado);
        } else {
            // Fallback simple: setear variables de sesión básicas
            $_SESSION['usuario_id']    = $usuarioActualizado['id'];
            $_SESSION['usuario_email'] = $usuarioActualizado['email'];
        }

        // Redirigir al panel de usuario
        header('Location: panel_usuario.php');
        exit;
    }
}

// Para el formulario, si hay usuario, usamos sus datos
$emailUsuario = ($usuario && isset($usuario['email'])) ? $usuario['email'] : '';
$nombreVal    = ($usuario && isset($usuario['nombre']))   ? $usuario['nombre']   : '';
$apellidoVal  = ($usuario && isset($usuario['apellido'])) ? $usuario['apellido'] : '';
$dniVal       = ($usuario && isset($usuario['dni']))      ? $usuario['dni']      : '';

require __DIR__ . '/inc/layout_top.php';
?>
<div class="container py-4">
    <div class="row justify-content-center">
        <div class="col-md-8 col-lg-6">
            <div class="card shadow-sm border-0">
                <div class="card-body">
                    <h1 class="h4 mb-3 text-center">Confirmá tu email</h1>

                    <?php if (!empty($errors)): ?>
                        <div class="alert alert-danger">
                            <ul class="mb-0">
                                <?php foreach ($errors as $e): ?>
                                    <li><?php echo e($e); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <?php if ($usuario && empty($errors) && $_SERVER['REQUEST_METHOD'] === 'GET'): ?>
                        <p class="mb-3">
                            Encontramos tu cuenta Tickex asociada a:
                            <strong><?php echo e($emailUsuario); ?></strong>
                        </p>
                        <p class="mb-3">
                            Completá tus datos y definí una contraseña para activar tu cuenta.
                        </p>
                    <?php endif; ?>

                    <?php if ($usuario && (empty($errors) || $_SERVER['REQUEST_METHOD'] === 'POST')): ?>
                        <form method="post" novalidate>
                            <input type="hidden" name="token" value="<?php echo e($token); ?>">

                            <div class="mb-3">
                                <label class="form-label">Email</label>
                                <input type="email"
                                       class="form-control"
                                       value="<?php echo e($emailUsuario); ?>"
                                       disabled>
                                <div class="form-text">
                                    Este es el email que quedará asociado a tu cuenta Tickex.
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Nombre</label>
                                <input type="text"
                                       name="nombre"
                                       class="form-control"
                                       value="<?php echo e($nombreVal); ?>">
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Apellido</label>
                                <input type="text"
                                       name="apellido"
                                       class="form-control"
                                       value="<?php echo e($apellidoVal); ?>">
                            </div>

                            <div class="mb-3">
                                <label class="form-label">DNI</label>
                                <input type="text"
                                       name="dni"
                                       class="form-control"
                                       value="<?php echo e($dniVal); ?>">
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Contraseña</label>
                                <input type="password"
                                       name="password"
                                       class="form-control">
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Repetir contraseña</label>
                                <input type="password"
                                       name="password2"
                                       class="form-control">
                            </div>

                            <div class="d-grid">
                                <button type="submit" class="btn btn-primary">
                                    Activar mi cuenta Tickex
                                </button>
                            </div>
                        </form>
                    <?php else: ?>
                        <?php if (!empty($errors) && !$usuario): ?>
                            <p class="mt-3 mb-0">
                                Si creés que esto es un error, intentá registrarte de nuevo desde la página principal de Tickex.
                            </p>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<?php
require __DIR__ . '/inc/layout_bottom.php';
