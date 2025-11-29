<?php
date_default_timezone_set('America/Argentina/Buenos_Aires');

$to      = 'ljvryl@gmail.com';
$fromEmail = 'no-reply@tickex.com.ar';
$fromName  = 'Save The Rave';

$subject = 'Prueba WEB mail() STR';
$body    = "Hola,\n\nEsta es una prueba de mail() ejecutada desde la WEB (no desde CLI).\n"
         . "Fecha: " . date('c') . "\n\n"
         . "From: {$fromEmail}\n";

$headers  = "From: {$fromName} <{$fromEmail}>\r\n";
$headers .= "Reply-To: {$fromEmail}\r\n";
$headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";

$result = mail($to, $subject, $body, $headers);

header('Content-Type: text/plain; charset=utf-8');
var_dump(array(
    'mail_result' => $result,
    'to'          => $to,
    'from'        => $fromEmail,
    'ini_file'    => php_ini_loaded_file(),
    'sendmail'    => ini_get('sendmail_path'),
));
