<?php
date_default_timezone_set('America/Argentina/Buenos_Aires');

$dbFile = __DIR__ . '/save_the_rave.sqlite';

try {
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Último usuario creado
    $st = $pdo->query("SELECT * FROM usuarios ORDER BY id DESC LIMIT 1");
    $u  = $st->fetch(PDO::FETCH_ASSOC);

    if (!$u) {
        echo "No hay usuarios en la tabla.\n";
        exit(1);
    }

    $to     = $u['email'];
    $nombre = trim($u['nombre'] . ' ' . $u['apellido']);
    $token  = $u['token_confirmacion'];

    $fromEmail = 'no-reply@tickex.com.ar';
    $fromName  = 'Tickex';
    $from      = $fromName . ' <' . $fromEmail . '>';

    $link    = 'https://str.tickex.com.ar/verificar_email.php?token=' . urlencode($token);
    $subject = 'Confirmá tu email en Tickex';

    $body  = "Hola " . $nombre . ",\n\n";
    $body .= "Gracias por registrarte en Tickex.\n\n";
    $body .= "Para confirmar tu email y activar tu cuenta, hacé clic en este enlace:\n";
    $body .= $link . "\n\n";
    $body .= "Si no te registraste vos, podés ignorar este mensaje.\n\n";
    $body .= "Tickex\n";

    $headers  = "From: " . $from . "\r\n";
    $headers .= "Reply-To: " . $fromEmail . "\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";

    // Envelope sender (-f) importante
    $extraParams = '-f ' . $fromEmail;

    echo "Reenviando confirmación a: " . $to . "\n";
    echo "Token: " . $token . "\n";
    echo "Link:  " . $link . "\n\n";

    $ok = mail($to, $subject, $body, $headers, $extraParams);

    echo "mail() devolvió: " . var_export($ok, true) . "\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
