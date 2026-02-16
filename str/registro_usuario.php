<?php
// registro_usuario.php – Paso 1: pedir solo email y enviar link con token

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

  // Backfill para instancias que ya existan sin la columna de password
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
    // ignorar fallos de alter
  }
}

$title     = 'Registro – Paso 1';
$errores   = array();
$mensajeOk = '';
$linkDirecto = '';
$email     = '';
$nextUrl   = isset($_GET['next']) ? $_GET['next'] : '';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && isset($_GET['email'])) {
  $email = trim($_GET['email']);
}

// ---------- función para enviar el mail con el link ----------
function enviar_mail_confirmacion_step1($email, $token)
{
    $fromEmail = 'no-reply@tickex.com.ar';
    $fromName  = 'Tickex';
    $from      = $fromName . ' <' . $fromEmail . '>';

    $link    = 'https://str.tickex.com.ar/completar_registro.php?token=' . urlencode($token);
    $subject = 'Confirmá tu email en Tickex';

    $body  = "Hola,\n\n";
    $body .= "Para continuar tu registro en Tickex, hacé clic en este enlace:\n";
    $body .= $link . "\n\n";
    $body .= "Si no fuiste vos, podés ignorar este mensaje.\n\n";
    $body .= "Tickex\n";

    $headers  = "From: " . $from . "\r\n";
    $headers .= "Reply-To: " . $fromEmail . "\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";

    // Envelope sender (-f) importante
    $extraParams = '-f ' . $fromEmail;

    $ok = mail($email, $subject, $body, $headers, $extraParams);

    // Log simple
    $logLine = date('c') . " registro_step1 mail to=" . $email . " ok=" . ($ok ? '1' : '0') . "\n";
    @file_put_contents(__DIR__ . '/log_mail_registro.txt', $logLine, FILE_APPEND);

    return $ok;
}

// ---------- POST: procesar email ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $email = isset($_POST['email']) ? trim($_POST['email']) : '';
  $nextUrl = isset($_POST['next']) ? $_POST['next'] : $nextUrl;

  if ($email === '') {
    $errores[] = 'El email es obligatorio.';
  } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errores[] = 'El email no tiene un formato válido.';
  }

  if (empty($errores)) {
    try {
      $pdo = db();
      $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
      ensure_registro_pendientes($pdo);

      // ¿Ya existe un pending para ese email?
      $st = $pdo->prepare("SELECT * FROM registro_pendientes WHERE email = :email ORDER BY id DESC LIMIT 1");
      $st->execute(array(':email' => $email));
      $u = $st->fetch(PDO::FETCH_ASSOC);

      // Generar token
      if (function_exists('random_bytes')) {
        $token = bin2hex(random_bytes(16));
      } else {
        $token = sha1(uniqid(mt_rand(), true));
      }

      $ahora = date('Y-m-d H:i:s');

      if ($u) {
        $stmtUp = $pdo->prepare("
          UPDATE registro_pendientes
          SET token = :token,
            completado_en = NULL,
            next_url = :next_url,
            creado_en = :creado_en
          WHERE id = :id
        ");
        $stmtUp->execute(array(
          ':token'     => $token,
          ':next_url'  => $nextUrl,
          ':creado_en' => $ahora,
          ':id'        => (int)$u['id'],
        ));
      } else {
        $stmtIns = $pdo->prepare("
          INSERT INTO registro_pendientes
            (email, token, next_url, creado_en)
          VALUES
            (:email, :token, :next_url, :creado_en)
        ");
        $stmtIns->execute(array(
          ':email'     => $email,
          ':token'     => $token,
          ':next_url'  => $nextUrl,
          ':creado_en' => $ahora,
        ));
      }

      $mailOk = enviar_mail_confirmacion_step1($email, $token);
      $linkDirecto = 'https://str.tickex.com.ar/completar_registro.php?token=' . urlencode($token);

      $mensajeOk  = 'Te enviamos un mensaje a ';
      $mensajeOk .= htmlspecialchars($email, ENT_QUOTES, 'UTF-8');

      if (!$mailOk) {
        $mensajeOk .= ' (Aviso: mail() devolvió false, revisar configuración de correo.)';
      }

      // Limpiar el campo para que no quede el email en el form
      $email = '';
    } catch (Exception $e) {
      $msg = $e->getMessage();
      // Fallback cuando la instancia tenga 'usuarios' como vista y no permita INSERT/UPDATE
      if (stripos($msg, 'cannot modify usuarios') !== false) {
        try {
          $pdo = db();
          $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
          ensure_registro_pendientes($pdo);

          if (function_exists('random_bytes')) {
            $token = bin2hex(random_bytes(16));
          } else {
            $token = sha1(uniqid(mt_rand(), true));
          }

          $ahora = date('Y-m-d H:i:s');
          $stmtIns = $pdo->prepare("
              INSERT INTO registro_pendientes
                (email, token, next_url, creado_en)
              VALUES
                (:email, :token, :next_url, :creado_en)
          ");
          $stmtIns->execute(array(
              ':email'     => $email,
              ':token'     => $token,
              ':next_url'  => $nextUrl,
              ':creado_en' => $ahora,
          ));

          $mailOk = enviar_mail_confirmacion_step1($email, $token);
          $linkDirecto = 'https://str.tickex.com.ar/completar_registro.php?token=' . urlencode($token);

          $mensajeOk  = 'Te enviamos un mensaje a ';
          $mensajeOk .= htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
          if (!$mailOk) {
            $mensajeOk .= ' (Aviso: mail() devolvió false, revisar configuración de correo.)';
          }
          $email = '';
        } catch (Exception $e2) {
          $errores[] = 'Error al procesar el registro: ' . $msg . ' / Fallback: ' . $e2->getMessage();
        }
      } else {
        $errores[] = 'Error al procesar el registro: ' . $msg;
      }
    }
  }
}

include __DIR__.'/inc/layout_top.php';
?>
<div class="card" style="max-width:480px;margin:0 auto 16px auto;text-align:center;">
  <div style="margin-bottom:16px;">
    <img src="tickex-logo_sobre_oscuro.svg"
         alt="Tickex"
         style="height:230px;display:block;margin:0 auto 8px auto;">
  </div>
  <h2>Registrate en Tickex</h2>
  <p style="color:var(--muted);margin-top:8px;">
    Ingresá tu email para recibir un enlace y completar tu registro.
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

<?php if ($mensajeOk !== ''): ?>
  <div class="card" style="max-width:480px;margin:0 auto 16px auto;">
    <div class="flash ok">
      <strong>Revisá tu email</strong><br><br>
      <?php echo $mensajeOk; ?><br><br>
      Si no ves el mail en unos minutos, revisá la carpeta de spam / correo no deseado.
      <?php if ($linkDirecto !== ''): ?>
        <div style="margin-top:10px;">
          Enlace directo (dev):
          <a class="link" href="<?php echo htmlspecialchars($linkDirecto, ENT_QUOTES, 'UTF-8'); ?>">
            <?php echo htmlspecialchars($linkDirecto, ENT_QUOTES, 'UTF-8'); ?>
          </a>
        </div>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<div class="card" style="max-width:480px;margin:0 auto 32px auto;">
  <form method="post" autocomplete="off">
    <input type="hidden" name="next" value="<?php echo htmlspecialchars($nextUrl, ENT_QUOTES, 'UTF-8'); ?>">
    <label for="email" style="margin-top:8px;display:block;">Email</label>
    <input type="email" id="email" name="email" required
           value="<?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>">

    <button class="btn" type="submit" style="width:100%;margin-top:16px;">
      Enviarme enlace de registro
    </button>
  </form>
</div>

<?php include __DIR__.'/inc/layout_bottom.php'; ?>
