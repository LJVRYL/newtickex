<?php
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/mail.php';
require_once __DIR__ . '/inc/turnstile.php';
require_once __DIR__ . '/inc/password_reset_security.php';

$title = 'Recuperar contraseña';
$error = '';
$okMsg = '';
$email = '';
$csrf = function_exists('tickex_csrf_token') ? tickex_csrf_token() : '';
$genericMsg = 'Si tu email existe en Tickex, te enviamos un enlace para restablecer la contraseña.';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = isset($_POST['email']) ? tickex_password_reset_normalize_email($_POST['email']) : '';
    $honeypot = isset($_POST['website']) ? trim((string)$_POST['website']) : '';
    $providedCsrf = isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '';

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Ingresá un email válido.';
    } elseif ($honeypot !== '') {
        $okMsg = $genericMsg;
    } elseif (!function_exists('tickex_csrf_verify') || !tickex_csrf_verify($providedCsrf)) {
        $okMsg = $genericMsg;
    } else {
        $turnstileErrors = array();
        if (!tickex_turnstile_verify_post($turnstileErrors)) {
            $error = !empty($turnstileErrors[0]) ? (string)$turnstileErrors[0] : 'Verificá el captcha para continuar.';
        }
    }

    if ($error === '' && $okMsg === '') {
        try {
            $pdo = db();
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            tickex_password_reset_prune($pdo);

            $remoteIp = isset($_SERVER['REMOTE_ADDR']) ? trim((string)$_SERVER['REMOTE_ADDR']) : 'unknown';
            $ipLimited = tickex_password_reset_rate_limited($pdo, 'ip', $remoteIp, 8, 900);
            $exists = !$ipLimited && tickex_password_reset_account_exists($pdo, $email);
            $emailLimited = $exists
                ? tickex_password_reset_rate_limited($pdo, 'email', $email, 3, 3600)
                : true;

            if ($exists && !$emailLimited) {
                $token = tickex_password_reset_create_token($pdo, $email);
                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'str.tickex.com.ar';
                $scriptName = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '/';
                $basePath = rtrim(dirname($scriptName), '/\\');
                if ($basePath === '' || $basePath === '.') { $basePath = ''; }
                $link = $scheme . '://' . $host . $basePath . '/reset_password.php?token=' . urlencode($token);

                $fromEmail = 'servicio@tickex.com.ar';
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

            $okMsg = $genericMsg;
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
    <input type="hidden" name="_csrf" value="<?php echo e($csrf); ?>">
    <div style="position:absolute;left:-10000px;width:1px;height:1px;overflow:hidden;" aria-hidden="true">
      <label for="website">Sitio web</label>
      <input type="text" id="website" name="website" value="" tabindex="-1" autocomplete="off">
    </div>
    <label for="email">Email</label>
    <input type="email" id="email" name="email" required value="<?php echo e($email); ?>">
    <?php tickex_turnstile_widget(array('theme' => 'auto')); ?>
    <button class="btn" type="submit" style="width:100%;margin-top:14px;">Enviar enlace</button>
  </form>
  <div style="margin-top:10px;font-size:14px;">
    <a class="link" href="login.php">Volver al login</a>
  </div>
</div>

<?php require __DIR__ . '/inc/layout_bottom.php'; ?>
