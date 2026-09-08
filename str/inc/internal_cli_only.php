<?php

// Historical diagnostics and maintenance utilities are intentionally kept for
// operators, but they must never be reachable through the public web server.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo 'No encontrado.';
    exit;
}
