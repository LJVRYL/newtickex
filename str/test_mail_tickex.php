<?php
$to      = 'ljvryl@gmail.com';  // podés cambiarlo si querés probar con otro correo
$subject = 'Prueba desde tickex.com.ar';
$body    = "Hola,\n\nEsto es una prueba de mail() usando el remitente no-reply@tickex.com.ar.\n\nFecha: " . date('c') . "\n";

// Cabeceras visibles
$headers  = "From: Tickex <no-reply@tickex.com.ar>\r\n";
$headers .= "Reply-To: no-reply@tickex.com.ar\r\n";
$headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";

// Muy importante: forzar el envelope sender (-f) para que el MTA vea tickex.com.ar
$extraParams = "-f no-reply@tickex.com.ar";

var_dump([
    'to'      => $to,
    'subject' => $subject,
]);

var_dump(mail($to, $subject, $body, $headers, $extraParams));
?>
