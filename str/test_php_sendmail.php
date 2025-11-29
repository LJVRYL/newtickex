<?php
header('Content-Type: text/plain');

// Info de configuración
echo "sendmail_path = " . ini_get('sendmail_path') . "\n";
echo "php.ini = " . php_ini_loaded_file() . "\n";
echo "SAPI = " . php_sapi_name() . "\n\n";

// Envío de mail de prueba
$to   = 'ljvryl@gmail.com';
$subj = '[TEST PHP WEB] Save The Rave';
$body = "Hola,\n\nEsto es una prueba ENVIADA DESDE PHP WEB (test_php_sendmail.php).\n\nFecha del servidor: " . date('Y-m-d\\TH:i:sP') . "\n";

$headers  = "From: \"Save The Rave\" <no-reply@tickex.com.ar>\r\n";
$headers .= "Reply-To: no-reply@tickex.com.ar\r\n";

$ok = mail($to, $subj, $body, $headers);
echo "mail() = " . var_export($ok, true) . "\n";
