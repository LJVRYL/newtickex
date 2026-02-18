<?php
// inc/mail.php
// Simple mail sender for Tickex (uses PHP mail())

function tickex_send_mail($to, $subject, $body, $from = 'no-reply@tickex.com.ar') {
    $headers = "From: $from\r\n";
    $headers .= "Reply-To: $from\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    return mail($to, $subject, $body, $headers);
}
