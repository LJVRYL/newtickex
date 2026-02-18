<?php
$file = __DIR__ . '/eventos.php';
$code = file_get_contents($file);

$search = <<<'SEARCH'
              $fd = $ev['fecha_desde'] ?: '';
              $fh = $e
              if ($fd === '' && $fh === '') {
SEARCH;

$replace = <<<'REPLACE'
              $fd = $ev['fecha_desde'] ?: '';
              $fh = $ev['fecha_hasta'] ?: '';
              if ($fd === '' && $fh === '') {
REPLACE;

if (strpos($code, $search) === false) {
    fwrite(STDERR, "No se encontró el bloque a reemplazar en eventos.php\n");
    exit(1);
}

$code = str_replace($search, $replace, $code);
file_put_contents($file, $code);
echo "Bloque de fechas corregido en eventos.php\n";
