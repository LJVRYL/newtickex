<?php
$f = __DIR__ . '/panel_evento.php';
$c = file_get_contents($f);

$lines = explode("\n", $c);
$out = [];
$fixed = false;

foreach ($lines as $i => $line) {
    // detectamos la línea rota del creador
    if (strpos($line, '$creador = (isset(') !== false && !$fixed) {
        $out[] = "    \$creador = isset(\$evento['creado_por_admin_id']) ? (int)\$evento['creado_por_admin_id'] : 0;";
        $fixed = true;
        continue;
    }
    $out[] = $line;
}

if (!$fixed) {
    fwrite(STDERR, "No encontré la línea rota para arreglar.\n");
    exit(1);
}

file_put_contents($f, implode("\n", $out));
echo "Línea de creador arreglada en panel_evento.php\n";
