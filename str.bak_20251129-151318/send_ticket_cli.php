<?php
date_default_timezone_set('America/Argentina/Buenos_Aires');

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "Este script debe ejecutarse por CLI.\n");
    exit(1);
}

if ($argc < 2) {
    fwrite(STDERR, "Uso: php send_ticket_cli.php CODIGO\n");
    exit(1);
}

$codigo = trim($argv[1]);

$dbFile = __DIR__ . '/save_the_rave.sqlite';
if (!file_exists($dbFile)) {
    fwrite(STDERR, "Base de datos no encontrada: " . $dbFile . "\n");
    exit(1);
}

try {
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $pdo->prepare("SELECT * FROM entradas WHERE codigo = :codigo LIMIT 1");
    $stmt->execute(array(':codigo' => $codigo));
    $entrada = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$entrada) {
        fwrite(STDERR, "No se encontró entrada para el código: " . $codigo . "\n");
        exit(1);
    }

    $to        = $entrada['email'];
    $nombre    = $entrada['nombre'];
    $id        = (int)$entrada['id'];
    $fechaReg  = $entrada['fecha_registro'];
    $tipo      = isset($entrada['tipo']) && $entrada['tipo'] !== '' ? $entrada['tipo'] : 'FREE';

    $fromEmail = 'no-reply@tickex.com.ar';
    $fromName  = 'Save The Rave';
    $from      = $fromName . ' <' . $fromEmail . '>';

    $ticketUrl = 'https://str.tickex.com.ar/ticket.php?c=' . urlencode($codigo);
    $subject   = 'Tu entrada #' . $id . ' para Save The Rave';

    $body  = "Hola " . $nombre . ",\n\n";
    $body .= "¡Gracias por registrarte en SAVE THE RAVE!\n\n";
    $body .= "Datos de tu entrada:\n";
    $body .= "  - Número de entrada: #" . $id . "\n";
    $body .= "  - Nombre / alias: " . $nombre . "\n";
    $body .= "  - Email registrado: " . $to . "\n";
    $body .= "  - Tipo: " . $tipo . "\n";
    $body .= "  - Fecha de registro: " . $fechaReg . "\n\n";
    $body .= "Para ver tu QR de acceso, abrí este link:\n";
    $body .= $ticketUrl . "\n\n";
    $body .= "En la puerta vamos a escanear ese QR para validar tu entrada.\n";
    $body .= "Guardá este mensaje hasta la fecha del evento.\n\n";
    $body .= "Save The Rave\n";
    $body .= "tickex.com.ar\n";

    $headers  = "From: " . $from . "\r\n";
    $headers .= "Reply-To: " . $fromEmail . "\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";

    $ok = mail($to, $subject, $body, $headers);

    if (!$ok) {
        fwrite(STDERR, "mail() devolvió false\n");
        exit(2);
    }

    echo "OK: mail() enviado a " . $to . " para código " . $codigo . "\n";
    exit(0);

} catch (Exception $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}
