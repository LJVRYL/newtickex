<?php
// Entrada de la propuesta compatible con el DirectoryIndex del sitio.
header('Content-Type: text/html; charset=UTF-8');
header('X-Robots-Tag: noindex, nofollow');
readfile(__DIR__ . '/index.html');
