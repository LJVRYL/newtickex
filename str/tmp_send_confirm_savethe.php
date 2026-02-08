<?php
// Script puntual para reenviar confirmación a savetherave3@gmail.com

$dbFile = __DIR__ . '/save_the_rave.sqlite';
if (!file_exists($dbFile)) {
    echo "No se encuentra la base\n";
    exit(1);
}

$pdo = new PDO('sqlite:' . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$email = 'savetherave3@gmail.com';

$stmt = $pdo->prepare("
    SELECT id, email, token_confirmacion, email_confirmado
    FROM usuarios
    WHERE email = :email
    LIMIT 1
");
$stmt->execute(array(':email' => $email));
$u = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$u) {
    echo "No existe usuario con email {$email}\n";
    exit(1);
}

if ((int)$u['email_confirmado'] === 1) {
    echo "El usuario {$email} ya está confirmado. No se envía nada.\n";
    exit(0);
}

$token = (string)$u['token_confirmacion'];
if ($token === '') {
    echo "El usuario {$email} no tiene token_confirmacion.\n";
    exit(1);
}

$link   = 'https://str.tickex.com.ar/verificar_email.php?token=' . $token;
$from   = 'no-reply@tickex.com.ar';
$asunto = 'Confirmá tu email en Tickex';

$cuerpo = "Hola!\n\n"
    . "Para activar tu cuenta Tickex, hacé clic en el siguiente enlace:\n\n"
    . $link . "\n\n"
    . "Si no te registraste vos, podés ignorar este mensaje.\n\n"
    . "Gracias,\n"
    . "Equipo Tickex\n";

$headers  = "From: Tickex <{$from}>\r\n";
$headers .= "Reply-To: {$from}\r\n";
$headers .= "MIME-Version: 1.0\r\n";
$headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

$ok = mail($email, $asunto, $cuerpo, $headers, "-f {$from}");

echo "Enviando a {$email} con link {$link}\n";
echo "mail() devolvió: " . ($ok ? "true\n" : "false\n");

$logLine = sprintf(
    "%s registro_step1_force mail to=%s ok=%d user_id=%d token=%s\n",
    date('c'),
    $email,
    $ok ? 1 : 0,
    (int)$u['id'],
    substr($token, 0, 10)
);
file_put_contents(__DIR__ . '/log_mail_registro.txt', $logLine, FILE_APPEND);
