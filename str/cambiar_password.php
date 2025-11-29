<?php
// cambiar_password.php - Pantalla de cambio de contraseña (solo UI por ahora)

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

$error = '';
$okMsg = '';

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $passActual = isset($_POST['pass_actual']) ? trim($_POST['pass_actual']) : '';
    $passNueva  = isset($_POST['pass_nueva'])  ? trim($_POST['pass_nueva'])  : '';
    $passRepite = isset($_POST['pass_repite']) ? trim($_POST['pass_repite']) : '';

    if ($passActual === '' || $passNueva === '' || $passRepite === '') {
        $error = 'Todos los campos son obligatorios.';
    } elseif (strlen($passNueva) < 6) {
        $error = 'La nueva contraseña debe tener al menos 6 caracteres.';
    } elseif ($passNueva !== $passRepite) {
        $error = 'La nueva contraseña y su repeticion no coinciden.';
    } else {
        // Aca iria la logica real de cambio de contraseña:
        // - Verificar passActual vs DB
        // - Actualizar hash en usuarios_admin
        //
        // Todavia no lo implementamos para no romper el login,
        // asi que dejamos un mensaje informativo.
        $okMsg = 'Validacion basica OK. En esta version, el cambio real de contraseña lo realiza soporte tecnico.';
    }
}

// Layout
$title = 'Cambiar contraseña';
require __DIR__ . '/inc/layout_top.php';
?>
<div class="card" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
  <a class="btn secondary" href="mi_perfil.php">⬅ Volver a Mi perfil</a>
</div>

<div class="card">
  <h2>Cambiar contraseña</h2>
  <p style="color:var(--muted);font-size:14px;margin-bottom:8px;">
    A futuro, desde aca vas a poder cambiar tu contraseña de acceso a TICKEX STR.
  </p>
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

<form method="post">
  <div class="card">
    <h3>Datos para cambiar la contraseña</h3>

    <div style="margin-bottom:10px;">
      <label for="pass_actual">Contraseña actual</label>
      <input type="password" id="pass_actual" name="pass_actual">
    </div>

    <div style="margin-bottom:10px;">
      <label for="pass_nueva">Nueva contraseña</label>
      <input type="password" id="pass_nueva" name="pass_nueva">
    </div>

    <div style="margin-bottom:10px;">
      <label for="pass_repite">Repetir nueva contraseña</label>
      <input type="password" id="pass_repite" name="pass_repite">
    </div>

    <button type="submit" class="btn" style="margin-top:12px;">
      Guardar nueva contraseña
    </button>

    <p style="margin-top:12px;font-size:12px;color:var(--muted);">
      Nota: En esta version, el cambio real de contraseña todavia no esta conectado a la base de datos.
      Si necesitas cambiarla ahora, contacta a soporte tecnico.
    </p>
  </div>
</form>

<?php
require __DIR__ . '/inc/layout_bottom.php';
