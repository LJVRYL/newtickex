<?php
// reenviar_confirmacion_pendientes_cli.php
// Reenvía mails de confirmación a usuarios Tickex con email no confirmado.
//
// Uso:
//   php reenviar_confirmacion_pendientes_cli.php
//   php reenviar_confirmacion_pendientes_cli.php --email=alguien@example.com

date_default_timezone_set('America/Argentina/Buenos_Aires');

$baseDir = __DIR__;
$dbFile  = $baseDir . '/save_the_rave.sqlite';

if (!file_exists($dbFile)) {
    fwrite(STDERR, "ERROR: No se encontró la base de datos en {$dbFile}\n");
    exit(1);
}

try {
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    fwrite(STDERR, "ERROR: No se pudo abrir la base SQLite: " . $e->getMessage() . "\n");
    exit(1);
}

// Parsear parámetro opcional --email=
$emailFiltro = null;
foreach ($argv as $arg) {
    if (strpos($arg, '--email=') === 0) {
        $emailFiltro = trim(substr($arg, strlen('--email=')));
    }
}

// Armamos la query de usuarios pendientes
$sql = "SELECT id, email, token_confirmacion, email_confirmado, creado_en
        FROM usuarios
        WHERE email_confirmado = 0
          AND token_confirmacion IS NOT NULL
          AND token_confirmacion <> ''";

$params = array();

if ($emailFiltro) {
    $sql .= " AND email = :email";
    $params[':email'] = $emailFiltro;
}

$sql .= " ORDER BY datetime(creado_en) ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$usuarios) {
    if ($emailFiltro) {
        echo "No hay usuarios pendientes para el email {$emailFiltro}\n";
    } else {
        echo "No hay usuarios pendientes de confirmación.\n";
    }
    exit(0);
}

$logFile = $baseDir . '/log_mail_registro.txt';

echo "Encontrados " . count($usuarios) . " usuarios pendientes.\n\n";

foreach ($usuarios as $u) {
    $id     = $u['id'];
    $email  = $u['email'];
    $token  = $u['token_confirmacion'];
    $creado = $u['creado_en'];

    if (!$email || !$token) {
        echo "[SKIP] Usuario #{$id}: email o token vacío.\n";
        continue;
    }

    $link = "https://str.tickex.com.ar/verificar_email.php?token=" . urlencode($token);

    $subject = "Confirmá tu cuenta Tickex";
    $bodyHtml = '<html><body style="font-family: Arial, sans-serif; font-size: 14px; color: #111827;">'
        . '<p>Hola,</p>'
        . '<p>Recibimos un pedido para crear una cuenta en <strong>Tickex</strong> con este email.</p>'
        . '<p>Para confirmar tu cuenta, hacé clic en el siguiente enlace:</p>'
        . '<p><a href="' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '">'
        . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '</a></p>'
        . '<p>Si no fuiste vos, podés ignorar este mensaje.</p>'
        . '<p style="margin-top:20px;">Gracias,<br>Equipo Tickex</p>'
        . '</body></html>';

    $headers = array();
    $headers[] = 'From: Tickex <no-reply@tickex.com.ar>';
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: text/html; charset=UTF-8';

    $headersStr = implode("\r\n", $headers);

    // -f para definir envelope sender (como hicimos en test_mail_tickex)
    $ok = mail($email, $subject, $bodyHtml, $headersStr, '-f no-reply@tickex.com.ar');

    $dtNow = date('c'); // ISO 8601

    $logLine = $dtNow . " registro_step1_retry mail to=" . $email . " ok=" . ($ok ? "1" : "0") . " user_id=" . $id . "\n";
    file_put_contents($logFile, $logLine, FILE_APPEND);

    if ($ok) {
        echo "[OK]  Enviado mail de confirmación a {$email} (usuario #{$id}, creado_en={$creado})\n";
    } else {
        echo "[ERR] Falló el mail a {$email} (usuario #{$id})\n";
    }
}

echo "\nListo.\n";
