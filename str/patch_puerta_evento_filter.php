<?php
$f = __DIR__ . '/puerta.php';
$c = file_get_contents($f);

// insertar lectura de evento_id después del auth ok
$needle = "// ========================\n//  DB\n// ========================\n";
$insert = "// ========================\n//  EVENTO STAFF\n// ========================\n".
          "\$eventoId = 0;\n".
          "if (isset(\$_GET['evento_id'])) {\n".
          "    \$eventoId = (int)\$_GET['evento_id'];\n".
          "} elseif (isset(\$_SESSION['evento_id'])) {\n".
          "    \$eventoId = (int)\$_SESSION['evento_id'];\n".
          "}\n\n".
          "// ========================\n//  DB\n// ========================\n";

if (strpos($c, $needle) !== false && strpos($c, '$eventoId') === false) {
    $c = str_replace($needle, $insert, $c);
}

// ahora metemos el filtro base dentro de where/params
$searchWhereInit = "\$where = array();\n\$params = array();\n";
$replaceWhereInit = "\$where = array();\n\$params = array();\n\n".
                    "// Filtro base por evento del staff\n".
                    "if (\$eventoId > 0) {\n".
                    "    \$where[] = 'evento_id = :evento_id';\n".
                    "    \$params[':evento_id'] = \$eventoId;\n".
                    "}\n";

if (strpos($c, $searchWhereInit) === false) {
    fwrite(STDERR, "No encontré where/params en puerta.php\n");
    exit(1);
}

$c = str_replace($searchWhereInit, $replaceWhereInit, $c);

file_put_contents($f, $c);
echo "puerta.php filtrando por evento_id\n";
