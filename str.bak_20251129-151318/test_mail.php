<?php
$to      = 'ljvryl@gmail.com';
$subject = 'Prueba STR desde servidor';
$body    = "Hola,\n\nEsto es una prueba de mail() desde el servidor STR.\n\nFecha: " . date('c') . "\n";
$headers  = "From: Save The Rave <no-reply@str.tickex.com.ar>\r\n";
$headers .= "Reply-To: no-reply@str.tickex.com.ar\r\n";
$headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";

var_dump(mail($to, $subject, $body, $headers));
?>
