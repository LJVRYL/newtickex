<?php
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/mail.php';

$title = 'Recuperar contraseña';
$error = '';
$okMsg = '';
$email = '';

function ensure_password_reset_tokens($pdo)
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS password_reset_tokens (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      email TEXT NOT NULL,
      token TEXT NOT NULL,
      creado_en TEXT,
      consumido_en TEXT
    )");
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_prt_token ON password_reset_tokens(token)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_prt_email ON password_reset_tokens(email)");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Ingresá un email válido.';
    }

    if ($error === '') {
        try {
            $pdo = db();
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            ensure_password_reset_tokens($pdo);

            // ¿Existe el email en alguna tabla conocida?
            $exists = false;
            try {
                $st = $pdo->prepare('SELECT 1 FROM usuarios WHERE email = :e LIMIT 1');
                $st->execute(array(':e' => $email));
                $exists = (bool)$st->fetchColumn();
            } catch (Exception $e) {
                // la vista/tabla puede no existir o no permitir SELECT; ignoramos
            }
            if (!$exists) {
                try {
                    $st2 = $pdo->prepare('SELECT 1 FROM registro_pendientes WHERE email = :e LIMIT 1');
                    $st2->execute(array(':e' => $email));
                    $exists = (bool)$st2->fetchColumn();
                } catch (Exception $e) {
                    // ignore
                }
            }

            // Generar token siempre para no revelar existencia, pero solo intentamos mail si podemos insertar
            $token = function_exists('random_bytes') ? bin2hex(random_bytes(16)) : sha1(uniqid(mt_rand(), true));
            $inserted = false;
            try {
                $stmtIns = $pdo->prepare('INSERT INTO password_reset_tokens (email, token, creado_en) VALUES (:e,:t, datetime("now"))');
                $stmtIns->execute(array(':e' => $email, ':t' => $token));
                $inserted = true;
            } catch (Exception $e) {
                // si falla el insert, igual devolvemos mensaje genérico
            }

            // Armar link
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'str.tickex.com.ar';
            $basePath = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\');
            if ($basePath === '' || $basePath === '.') { $basePath = ''; }
            $link = $scheme . '://' . $host . $basePath . '/reset_password.php?token=' . urlencode($token);

            if ($inserted) {
                $fromEmail = 'no-reply@tickex.com.ar';
                $fromName  = 'Tickex';
                $from      = $fromName . ' <' . $fromEmail . '>';
                $subject   = 'Recuperá tu contraseña de Tickex';

                $body  = "Hola,\n\n";
                $body .= "Recibimos tu solicitud para restablecer la contraseña.\n";
                $body .= "Hacé clic en el siguiente enlace para crear una nueva contraseña:\n";
                $body .= $link . "\n\n";
                $body .= "Si no solicitaste este cambio, ignorá este mensaje.\n\n";
                $body .= "Tickex\n";

                $headers  = 'From: ' . $from . "\r\n";
                $headers .= 'Reply-To: ' . $fromEmail . "\r\n";
                $headers .= 'X-Mailer: PHP/' . phpversion();
                $extraParams = '-f ' . $fromEmail;

                // Usa plantilla si existe (superadmin la puede editar). Fallback al texto hardcodeado.
                tickex_send_mail_template($email, 'password_reset', array(
                  'link' => $link,
                ), array(
                  'headers_extra' => "",
                  'context'       => 'password_reset',
                ), array(
                  'subject'      => $subject,
                  'body'         => $body,
                  'from_email'   => $fromEmail,
                  'from_name'    => $fromName,
                  'reply_to'     => $fromEmail,
                  'extra_params' => $extraParams,
                  'is_html'      => 0,
                ));
            }

            $okMsg = 'Si tu email existe en Tickex, te enviamos un enlace para restablecer la contraseña.';
            $email = '';
        } catch (Exception $e) {
            $error = 'No pudimos procesar tu solicitud. Intentalo de nuevo en unos minutos.';
        }
    }
}

require __DIR__ . '/inc/layout_top.php';
?>
<div class="card" style="max-width:520px;margin:0 auto 16px auto;text-align:center;">
  <div style="margin-bottom:12px;">
    <img src="tickex-logo_sobre_oscuro.svg" alt="Tickex" style="height:140px;display:block;margin:0 auto 6px auto;">
  </div>
  <h2>Recuperar contraseña</h2>
  <p style="color:var(--muted);margin-top:6px;font-size:14px;">Ingresá tu email y te enviaremos un enlace para crear una nueva contraseña.</p>
</div>

<?php if ($okMsg !== ''): ?>
  <div class="card" style="max-width:520px;margin:0 auto 12px auto;">
    <div class="flash ok"><?php echo e($okMsg); ?></div>
  </div>
<?php endif; ?>

<?php if ($error !== ''): ?>
  <div class="card" style="max-width:520px;margin:0 auto 12px auto;">
    <div class="flash err"><?php echo e($error); ?></div>
  </div>
<?php endif; ?>

<div class="card" style="max-width:520px;margin:0 auto 20px auto;">
  <form method="post">
    <label for="email">Email</label>
    <input type="email" id="email" name="email" required value="<?php echo e($email); ?>">
    <button class="btn" type="submit" style="width:100%;margin-top:14px;">Enviar enlace</button>
  </form>
  <div style="margin-top:10px;font-size:14px;">
    <a class="link" href="login.php">Volver al login</a>
  </div>
</div>

<?php require __DIR__ . '/inc/layout_bottom.php'; ?>
