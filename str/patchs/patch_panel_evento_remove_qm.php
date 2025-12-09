<?php
$f = __DIR__ . '/panel_evento.php';
$c = file_get_contents($f);

/*
 Reemplaza expresiones tipo:
   $a ?? 'x'
   $_GET['id'] ?? 0
   $row['campo'] ?? ''
 por:
   (isset($a) ? $a : 'x')
*/
$pattern = '/([$\w\[\]\'"->]+)\s*\?\?\s*([^;\)\n\r,]+)/';

$replaced = 0;
$c2 = preg_replace_callback($pattern, function($m) use (&$replaced) {
    $left = trim($m[1]);
    $right= trim($m[2]);
    $replaced++;
    return '(isset(' . $left . ') ? ' . $left . ' : ' . $right . ')';
}, $c);

file_put_contents($f, $c2);

echo "Reemplazos ?? hechos: $replaced\n";
