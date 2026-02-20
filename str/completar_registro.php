<?php
require_once __DIR__.'/inc/bootstrap.php';
require_once __DIR__.'/inc/db.php';

function ensure_registro_pendientes($pdo)
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS registro_pendientes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        email TEXT NOT NULL,
        token TEXT NOT NULL,
        nombre TEXT,
        apellido TEXT,
        apodo TEXT,
        dni TEXT,
        genero TEXT,
        foto_path TEXT,
        next_url TEXT,
        creado_en TEXT,
    completado_en TEXT,
    password_hash TEXT
    )");
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_regpend_token ON registro_pendientes(token)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_regpend_email ON registro_pendientes(email)");

    // Tickex ID (apodo): idealmente único. Best-effort: si hay duplicados, este índice puede fallar.
    try {
      $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_regpend_apodo_unique ON registro_pendientes(apodo)");
    } catch (Exception $e) {
      // ignore (puede fallar si hay datos duplicados existentes)
    }

  // Backfill para instancias existentes sin columna de password
  try {
    $cols = $pdo->query("PRAGMA table_info(registro_pendientes)")->fetchAll(PDO::FETCH_ASSOC);
    $hasPass = false;
    foreach ($cols as $c) {
      if (isset($c['name']) && $c['name'] === 'password_hash') { $hasPass = true; break; }
    }
    if (!$hasPass) {
      $pdo->exec("ALTER TABLE registro_pendientes ADD COLUMN password_hash TEXT");
    }
  } catch (Exception $e) {
    // ignorar
  }
}

  function ensure_registro_pendientes_log($pdo)
  {
    $pdo->exec("CREATE TABLE IF NOT EXISTS registro_pendientes_log (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      reg_id INTEGER,
      email TEXT,
      nombre TEXT,
      apellido TEXT,
      apodo TEXT,
      dni TEXT,
      genero TEXT,
      foto_path TEXT,
      created_at TEXT
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_regpendlog_regid ON registro_pendientes_log(reg_id)");
  }

$title = 'Completar registro';
$errores = array();
$okMsg = '';

$token = isset($_GET['token']) ? trim($_GET['token']) : '';
$sessionId = isset($_SESSION['usuario_id']) ? (int)$_SESSION['usuario_id'] : 0;

$pdo = db();
ensure_registro_pendientes($pdo);
ensure_registro_pendientes_log($pdo);

if ($token === '' && $sessionId > 0) {
  $st = $pdo->prepare('SELECT * FROM registro_pendientes WHERE id = :id LIMIT 1');
  $st->execute(array(':id' => $sessionId));
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) {
    $errores[] = 'No encontramos tu registro. Pedí otro email de registro.';
  }
} elseif ($token !== '') {
  $st = $pdo->prepare('SELECT * FROM registro_pendientes WHERE token = :t LIMIT 1');
  $st->execute(array(':t' => $token));
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) {
    $errores[] = 'No encontramos un registro pendiente con ese enlace. Pedí otro email de registro.';
  }
} else {
  $errores[] = 'Token de registro faltante.';
  $row = null;
}

$nombre   = $row['nombre'] ?? '';
$apellido = $row['apellido'] ?? '';
$apodo    = $row['apodo'] ?? '';
$dni      = $row['dni'] ?? '';
$genero   = $row['genero'] ?? '';
$email    = $row['email'] ?? '';
$nextUrl  = $row['next_url'] ?? '';
$fotoPath = $row['foto_path'] ?? '';
$passHash = $row['password_hash'] ?? '';
$passNueva = '';
$passRepite = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $row) {
    $nombre   = isset($_POST['nombre']) ? trim($_POST['nombre']) : '';
    $apellido = isset($_POST['apellido']) ? trim($_POST['apellido']) : '';
    $apodo    = isset($_POST['apodo']) ? trim($_POST['apodo']) : '';
    $dni      = isset($_POST['dni']) ? trim($_POST['dni']) : '';
    $genero   = isset($_POST['genero']) ? trim($_POST['genero']) : '';
  $passNueva  = isset($_POST['pass_nueva']) ? trim($_POST['pass_nueva']) : '';
  $passRepite = isset($_POST['pass_repite']) ? trim($_POST['pass_repite']) : '';

    if ($nombre === '' || $apellido === '') {
        $errores[] = 'Nombre y apellido son obligatorios.';
    }
    if ($dni === '') {
        $errores[] = 'El DNI es obligatorio.';
    }

    // Tickex ID (apodo): único + formato simple
    if ($apodo !== '') {
      if (strlen($apodo) > 64) {
        $errores[] = 'El Tickex ID es demasiado largo (máx 64).';
      } elseif (!preg_match('/^[a-zA-Z0-9_-]+$/', $apodo)) {
        $errores[] = 'El Tickex ID solo puede tener letras, números, _ y - (sin espacios).';
      } else {
        try {
          $stU = $pdo->prepare('SELECT id FROM registro_pendientes WHERE lower(apodo) = lower(:ap) AND id <> :id LIMIT 1');
          $stU->execute(array(':ap' => $apodo, ':id' => (int)$row['id']));
          if ($stU->fetch(PDO::FETCH_ASSOC)) {
            $errores[] = 'Ese Tickex ID ya está en uso. Elegí otro.';
          }
        } catch (Exception $e) {
          // si falla el check, no bloquear el registro
        }
      }
    }
    $generoOpts = array('M','F','X','');
    if (!in_array($genero, $generoOpts, true)) {
        $errores[] = 'Género inválido.';
    }

  // Validar contraseña obligatoria en este paso
  if ($passNueva === '' || $passRepite === '') {
    $errores[] = 'Debés definir una contraseña para tu cuenta.';
  } elseif (strlen($passNueva) < 6) {
    $errores[] = 'La contraseña debe tener al menos 6 caracteres.';
  } elseif ($passNueva !== $passRepite) {
    $errores[] = 'Las contraseñas no coinciden.';
  }

    // Manejar foto de perfil (opcional)
    $subida = $_FILES['foto'] ?? null;
    if ($subida && isset($subida['tmp_name']) && is_uploaded_file($subida['tmp_name'])) {
        $ext = strtolower(pathinfo($subida['name'], PATHINFO_EXTENSION));
        $permitidos = array('jpg','jpeg','png');
        if (!in_array($ext, $permitidos, true)) {
            $errores[] = 'Formato de foto no permitido (solo JPG o PNG).';
        } elseif ($subida['size'] > 2 * 1024 * 1024) {
            $errores[] = 'La foto es muy pesada (máx 2 MB).';
        } else {
            $destDir = __DIR__ . '/uploads/perfiles';
            if (!is_dir($destDir)) {
                @mkdir($destDir, 0777, true);
            }
            $filename = 'perfil_' . (int)$row['id'] . '_' . time() . '.' . $ext;
            $destPath = $destDir . '/' . $filename;
            if (!move_uploaded_file($subida['tmp_name'], $destPath)) {
                $errores[] = 'No se pudo guardar la foto.';
            } else {
                $fotoPath = 'uploads/perfiles/' . $filename;
            }
        }
    }

    if (empty($errores)) {
      // log de versión anterior
      $pdo->prepare('INSERT INTO registro_pendientes_log (reg_id,email,nombre,apellido,apodo,dni,genero,foto_path,created_at) VALUES (:id,:em,:n,:a,:ap,:dni,:g,:fp,:c)')
        ->execute(array(
          ':id' => (int)$row['id'],
          ':em' => $email,
          ':n'  => $row['nombre'] ?? '',
          ':a'  => $row['apellido'] ?? '',
          ':ap' => $row['apodo'] ?? '',
          ':dni'=> $row['dni'] ?? '',
          ':g'  => $row['genero'] ?? '',
          ':fp' => $row['foto_path'] ?? '',
          ':c'  => date('Y-m-d H:i:s'),
        ));

        $newHash = function_exists('password_hash') ? password_hash($passNueva, PASSWORD_DEFAULT) : md5($passNueva);

        $stmtUp = $pdo->prepare('UPDATE registro_pendientes SET nombre=:n, apellido=:a, apodo=:ap, dni=:dni, genero=:g, foto_path=:fp, completado_en=:c, password_hash=:ph WHERE id=:id');
        $stmtUp->execute(array(
          ':n'  => $nombre,
          ':a'  => $apellido,
          ':ap' => $apodo,
          ':dni'=> $dni,
          ':g'  => $genero,
          ':fp' => $fotoPath,
          ':c'  => date('Y-m-d H:i:s'),
          ':ph' => $newHash,
          ':id' => (int)$row['id'],
        ));

        $_SESSION['email']      = $email;
        $_SESSION['first_name'] = $nombre;
        $_SESSION['last_name']  = $apellido;
        $_SESSION['nombre']     = $nombre;
        $_SESSION['apellido']   = $apellido;
        $_SESSION['dni']        = $dni;
        $_SESSION['usuario']    = $email;
        $_SESSION['usuario_id'] = (int)$row['id'];
        $_SESSION['rol']        = 'cliente';

        $okMsg = 'Registro completado. Ya podés continuar con tu compra.';

        $dest = $nextUrl !== '' ? $nextUrl : 'panel_usuario.php';
        header('Location: ' . $dest);
        exit;
    }
}

include __DIR__.'/inc/layout_top.php';
?>
<div class="card" style="max-width:640px;margin:0 auto 16px auto;">
  <h2 style="margin:0 0 8px;">Completar registro</h2>
  <p style="color:var(--muted);margin:0 0 10px;">
    Ingresá tus datos para finalizar el alta. Usaremos esta información para validar tus compras y el ingreso al evento.
  </p>
</div>

<?php if (!empty($errores)): ?>
  <div class="card" style="max-width:640px;margin:0 auto 16px auto;">
    <div class="flash err">
      <ul style="margin:0 0 0 16px;">
        <?php foreach ($errores as $e): ?>
          <li><?php echo htmlspecialchars($e, ENT_QUOTES, 'UTF-8'); ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
<?php endif; ?>

<?php if ($okMsg !== ''): ?>
  <div class="card" style="max-width:640px;margin:0 auto 16px auto;">
    <div class="flash ok">
      <?php echo htmlspecialchars($okMsg, ENT_QUOTES, 'UTF-8'); ?>
    </div>
  </div>
<?php endif; ?>

<?php if ($row): ?>
<div class="card" style="max-width:640px;margin:0 auto 24px auto;">
  <form method="post" enctype="multipart/form-data" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px;">
    <div style="grid-column:1 / -1;">
      <label>Email</label>
      <input type="email" value="<?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>" disabled>
      <div style="font-size:12px;color:var(--muted);">Usaremos este correo para tus compras y avisos.</div>
    </div>
    <div>
      <label>Nombre</label>
      <input name="nombre" required value="<?php echo htmlspecialchars($nombre, ENT_QUOTES, 'UTF-8'); ?>">
    </div>
    <div>
      <label>Apellido</label>
      <input name="apellido" required value="<?php echo htmlspecialchars($apellido, ENT_QUOTES, 'UTF-8'); ?>">
    </div>
    <div>
      <label>Tickex ID (opcional)</label>
      <input name="apodo" value="<?php echo htmlspecialchars($apodo, ENT_QUOTES, 'UTF-8'); ?>" placeholder="ej: leo123 (si lo dejás vacío, usaremos #ID)">
    </div>
    <div>
      <label>DNI</label>
      <input name="dni" required value="<?php echo htmlspecialchars($dni, ENT_QUOTES, 'UTF-8'); ?>">
    </div>
    <div>
      <label>Género</label>
      <select name="genero">
        <option value="" <?php echo $genero===''?'selected':''; ?>>Prefiero no decir</option>
        <option value="M" <?php echo $genero==='M'?'selected':''; ?>>M</option>
        <option value="F" <?php echo $genero==='F'?'selected':''; ?>>F</option>
        <option value="X" <?php echo $genero==='X'?'selected':''; ?>>X</option>
      </select>
    </div>
    <div>
      <label>Contraseña (mín 6)</label>
      <input type="password" name="pass_nueva" required autocomplete="new-password" value="<?php echo htmlspecialchars($passNueva, ENT_QUOTES, 'UTF-8'); ?>">
    </div>
    <div>
      <label>Repetir contraseña</label>
      <input type="password" name="pass_repite" required autocomplete="new-password" value="<?php echo htmlspecialchars($passRepite, ENT_QUOTES, 'UTF-8'); ?>">
    </div>
    <div>
      <label>Foto de perfil (JPG/PNG, máx 2 MB)</label>
      <input type="file" name="foto" accept="image/jpeg,image/png">
      <?php if ($fotoPath): ?>
        <div style="margin-top:6px;font-size:12px;display:flex;align-items:center;gap:8px;">
          <span class="muted">Actual:</span>
          <img src="<?php echo htmlspecialchars($fotoPath, ENT_QUOTES, 'UTF-8'); ?>" alt="Foto" style="height:50px;border-radius:6px;border:1px solid var(--line);">
        </div>
      <?php endif; ?>
    </div>
    <div style="grid-column:1 / -1;display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
      <button class="btn" type="submit">Guardar y continuar</button>
      <?php if ($nextUrl): ?>
        <a class="btn secondary" href="<?php echo htmlspecialchars($nextUrl, ENT_QUOTES, 'UTF-8'); ?>">Volver al checkout</a>
      <?php endif; ?>
    </div>
  </form>
</div>
<?php endif; ?>

<?php include __DIR__.'/inc/layout_bottom.php'; ?>
