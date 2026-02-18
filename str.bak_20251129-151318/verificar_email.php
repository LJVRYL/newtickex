<?php
// verificar_email.php
// Paso 2 del registro: a partir del token, pedir datos y confirmar el email

header('Content-Type: text/html; charset=utf-8');
date_default_timezone_set('America/Argentina/Buenos_Aires');

$dbFile = __DIR__ . '/save_the_rave.sqlite';

try {
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    http_response_code(500);
    echo "<!DOCTYPE html><html><head><meta charset=\"utf-8\"><title>Error</title></head><body>";
    echo "<h1>Error de conexión</h1>";
    echo "<p>No se pudo conectar a la base de datos.</p>";
    echo "</body></html>";
    exit;
}

// Token puede venir por GET (del link) o por POST (del formulario)
$token = isset($_REQUEST['token']) ? trim($_REQUEST['token']) : '';

$errores   = array();
$mensajeOk = '';
$usuario   = null;

if ($token === '') {
    $errores[] = 'Token faltante o inválido.';
} else {
    $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE token_confirmacion = :t LIMIT 1");
    $stmt->execute(array(':t' => $token));
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$usuario || (int)$usuario['email_confirmado'] === 1) {
        $errores[] = 'Token inválido o ya utilizado.';
        $usuario   = null;
    }
}

// Si el token es válido y se envió el formulario, procesamos los datos
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $usuario) {
    $nombre    = isset($_POST['nombre']) ? trim($_POST['nombre']) : '';
    $apellido  = isset($_POST['apellido']) ? trim($_POST['apellido']) : '';
    $dni       = isset($_POST['dni']) ? trim($_POST['dni']) : '';
    $password  = isset($_POST['password']) ? $_POST['password'] : '';
    $password2 = isset($_POST['password2']) ? $_POST['password2'] : '';

    if ($nombre === '') {
        $errores[] = 'El nombre es obligatorio.';
    }
    if ($apellido === '') {
        $errores[] = 'El apellido es obligatorio.';
    }
    if ($dni === '') {
        $errores[] = 'El DNI es obligatorio.';
    }
    if ($password === '') {
        $errores[] = 'La contraseña es obligatoria.';
    } elseif (strlen($password) < 6) {
        $errores[] = 'La contraseña debe tener al menos 6 caracteres.';
    }
    if ($password2 === '' || $password2 !== $password) {
        $errores[] = 'Las contraseñas no coinciden.';
    }

    if (empty($errores)) {
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        $stmtUp = $pdo->prepare("
            UPDATE usuarios
            SET nombre            = :nombre,
                apellido          = :apellido,
                dni               = :dni,
                password_hash     = :pass,
                email_confirmado  = 1,
                token_confirmacion = NULL
            WHERE id = :id
        ");
        $stmtUp->execute(array(
            ':nombre' => $nombre,
            ':apellido' => $apellido,
            ':dni' => $dni,
            ':pass' => $passwordHash,
            ':id' => (int)$usuario['id'],
        ));

        $mensajeOk = 'Tu email fue confirmado y tu cuenta quedó creada. Ya podés recibir entradas y gestionar tus compras en Tickex.';
        $usuario   = null; // para no mostrar más el formulario
    }
}

// HTML con el layout del sistema
include __DIR__.'/inc/layout_top.php';
?>
<div class="card" style="max-width:520px;margin:0 auto 16px auto;text-align:center;">
  <div style="margin-bottom:16px;">
    <img src="tickex-logo_sobre_oscuro.svg"
         alt="Tickex"
         style="height:230px;display:block;margin:0 auto 8px auto;">
  </div>
  <h2>Verificación de email</h2>
</div>

<?php if (!empty($mensajeOk)): ?>
  <div class="card" style="max-width:520px;margin:0 auto 16px auto;">
    <p><?php echo htmlspecialchars($mensajeOk, ENT_QUOTES, 'UTF-8'); ?></p>
  </div>
<?php elseif (!empty($errores) && !$usuario): ?>
  <!-- Caso: token inválido o ya usado, sin formulario -->
  <div class="card" style="max-width:520px;margin:0 auto 16px auto;">
    <div class="flash err">
      <ul style="margin:
        <?php foreach ($errores as $e): ?>
          <li><?php echo htmlspecialchars($e, ENT_QUOTES, 'UTF-8'); ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
<?php else: ?>
  <?php if (!empty($errores)): ?>
    <!-- Errores de validación pero seguimos mostrando el form -->
    <div class="card" style="max-width:520px;margin:0 auto 16px auto;">
      <div class="flash err">
        <ul style="margin:0 0 0 18px;padding:0;">
          <?php foreach ($errores as $e): ?>
            <li><?php echo htmlspecialchars($e, ENT_QUOTES, 'UTF-8'); ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
  <?php endif; ?>

  <div class="card" style="max-width:520px;margin:0 auto 16px auto;">
    <p style="color:var(--muted);margin-top:0;">
      Completá tus datos para terminar el registro.
    </p>

    <form method="post">
      <input type="hidden" name="token" value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">

      <label for="nombre">Nombre</label>
      <input type="text" id="nombre" name="nombre" required value="">

      <label for="apellido">Apellido</label>
      <input type="text" id="apellido" name="apellido" required value="">

      <label for="dni">DNI</label>
      <input type="text" id="dni" name="dni" required value="">

      <label for="password">Contraseña</label>
      <input type="password" id="password" name="password" required>

      <label for="password2">Repetir contraseña</label>
      <input type="password" id="password2" name="password2" required>

      <button class="btn" type="submit" style="width:100%;margin-top:16px;">
        Completar registro
      </button>
    </form>
  </div>
<?php endif; ?>

<?php include __DIR__.'/inc/layout_bottom.php'; ?>
