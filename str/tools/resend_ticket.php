<?php

// Herramienta operativa: solo puede ejecutarse desde consola.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/mail.php';

if ($argc !== 2 || !ctype_digit((string)$argv[1]) || (int)$argv[1] <= 0) {
    fwrite(STDERR, "Uso: php resend_ticket.php <entrada_id>\n");
    exit(2);
}

$pdo = db();
$id = (int)$argv[1];

$st = $pdo->prepare("SELECT en.id, en.nombre, en.email, en.codigo, en.tipo, en.fecha_registro,
                            COALESCE(NULLIF(ev.nombre, ''), 'Tickex') AS evento_nombre
                     FROM entradas en
                     LEFT JOIN eventos ev ON ev.id = en.evento_id
                     WHERE en.id = :id
                     LIMIT 1");
$st->execute(array(':id' => $id));
$entrada = $st->fetch(PDO::FETCH_ASSOC);

if (!$entrada) {
    fwrite(STDERR, "Entrada no encontrada\n");
    exit(3);
}

$ticketUrl = 'https://str.tickex.com.ar/ticket.php?c=' . urlencode($entrada['codigo']);
$subject = 'Tu entrada #' . $entrada['id'] . ' para ' . $entrada['evento_nombre'];

$body = "Hola " . $entrada['nombre'] . ",\n\n";
$body .= "Te reenviamos tu entrada para " . $entrada['evento_nombre'] . ".\n\n";
$body .= "Podés verla aquí:\n" . $ticketUrl . "\n\n";
$body .= "Código: " . $entrada['codigo'] . "\n";
$body .= "Tipo: " . $entrada['tipo'] . "\n\n";
$body .= "Tickex\n";

$ok = tickex_send_mail_template(
    $entrada['email'],
    'entrada_registro',
    array(
        'id' => $entrada['id'],
        'nombre' => $entrada['nombre'],
        'email' => $entrada['email'],
        'tipo' => $entrada['tipo'],
        'fecha_registro' => $entrada['fecha_registro'],
        'ticket_url' => $ticketUrl,
        'codigo' => $entrada['codigo'],
    ),
    array(
        'context' => 'entrada_registro',
        'related_table' => 'entradas',
        'related_id' => $entrada['id'],
    ),
    array(
        'subject' => $subject,
        'body' => $body,
        'from_email' => 'no-reply@tickex.com.ar',
        'from_name' => 'Tickex',
        'reply_to' => 'no-reply@tickex.com.ar',
        'extra_params' => '-f no-reply@tickex.com.ar',
        'is_html' => 0,
    )
);

if (!$ok) {
    fwrite(STDERR, "ERROR: no se pudo enviar el email\n");
    exit(1);
}

echo "MAIL ENVIADO\n";

