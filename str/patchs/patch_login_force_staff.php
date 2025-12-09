<?php
$f = __DIR__ . '/login.php';
$c = file_get_contents($f);

// Asegurar que exista normalización $tipo/$rol
$needle = "            // Redirect por template / rol\n";
if (strpos($c, $needle) !== false && strpos($c, '$tipo = strtolower') === false) {
    $insert = $needle .
              "            \$tipo = strtolower(trim(\$row['tipo_global']));\n" .
              "            \$rol  = strtolower(trim(\$row['rol_evento']));\n";
    $c = str_replace($needle, $insert, $c);
}

// Reemplazar el bloque staff_evento entero por uno correcto (con evento_id)
$c = preg_replace(
    "/if\s*\\(\\s*\\$tipo\\s*===\\s*'staff_evento'\\s*\\)\\s*\\{.*?\\}\\s*/s",
    "if (\$tipo === 'staff_evento') {\n" .
    "    \$eid = isset(\$row['evento_id']) ? (int)\$row['evento_id'] : 0;\n" .
    "    if (\$rol === 'puerta') {\n" .
    "        \$_SESSION['evento_id'] = \$eid;\n" .
    "        header('Location: puerta.php?evento_id='.\$eid);\n" .
    "        exit;\n" .
    "    }\n" .
    "    header('Location: panel_admin.php');\n" .
    "    exit;\n" .
    "}\n\n",
    $c,
    1,
    $count
);

// Si no encontró bloque staff, lo insertamos antes del fallback final
if ($count === 0) {
    $fallback = "            // fallback\n";
    if (strpos($c, $fallback) !== false) {
        $staffBlock =
            "if (\$tipo === 'staff_evento') {\n" .
            "    \$eid = isset(\$row['evento_id']) ? (int)\$row['evento_id'] : 0;\n" .
            "    if (\$rol === 'puerta') {\n" .
            "        \$_SESSION['evento_id'] = \$eid;\n" .
            "        header('Location: puerta.php?evento_id='.\$eid);\n" .
            "        exit;\n" .
            "    }\n" .
            "    header('Location: panel_admin.php');\n" .
            "    exit;\n" .
            "}\n\n";
        $c = str_replace($fallback, $staffBlock . $fallback, $c);
    }
}

file_put_contents($f, $c);
echo "login.php parchado: staff_evento fuerza puerta.php?evento_id\n";
