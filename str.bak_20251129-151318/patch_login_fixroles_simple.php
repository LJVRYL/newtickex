<?php
$f = __DIR__ . '/login.php';
$c = file_get_contents($f);

// 1) Insertar normalización justo después del comentario de redirects
$needle = "            // Redirect por template / rol\n";
$insert = "            // Redirect por template / rol\n".
          "            \$tipo = strtolower(trim(\$row['tipo_global']));\n".
          "            \$rol  = strtolower(trim(\$row['rol_evento']));\n";

if (strpos($c, $needle) === false) {
    fwrite(STDERR, "No encontré el punto de inserción de redirects.\n");
    exit(1);
}
if (strpos($c, '$tipo = strtolower(trim($row[\'tipo_global\']))') === false) {
    $c = str_replace($needle, $insert, $c);
}

// 2) Reemplazar comparaciones por $tipo / $rol (normalizados)
$c = str_replace("\$row['tipo_global'] === 'super_admin'", "\$tipo === 'super_admin'", $c);
$c = str_replace("\$row['tipo_global'] === 'admin_evento'", "\$tipo === 'admin_evento'", $c);
$c = str_replace("\$row['tipo_global'] === 'staff_evento'", "\$tipo === 'staff_evento'", $c);
$c = str_replace("\$row['rol_evento'] === 'puerta'", "\$rol === 'puerta'", $c);

file_put_contents($f, $c);
echo "Fix simple aplicado a login.php\n";
