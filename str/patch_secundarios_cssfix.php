<?php
$f = __DIR__ . '/secundarios.php';
$c = file_get_contents($f);

// Reemplazamos la regla body rota por una completa
$start = "body{margin:0;font-family:system-ui,-apple-system,\"Segoe UI\",sans-serif;background:#05050";
$pos = strpos($c, $start);

if ($pos === false) {
    fwrite(STDERR, "No encontré el body roto a reemplazar en secundarios.php\n");
    exit(1);
}

// Cortamos desde 'body{' hasta el primer salto de línea después de eso
$endPos = strpos($c, "\n", $pos);
$brokenLine = substr($c, $pos, $endPos - $pos);

$fixedBody = "body{margin:0;font-family:system-ui,-apple-system,\"Segoe UI\",sans-serif;background:#050505;color:#f5f5f5;padding:16px;}\n";

$c = str_replace($brokenLine, $fixedBody, $c);

file_put_contents($f, $c);
echo "CSS body corregido en secundarios.php\n";
