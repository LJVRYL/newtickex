<?php
$f = __DIR__ . '/login.php';
$c = file_get_contents($f);

/**
 * 1) Eliminar bloque debug roto si existe (entre marcador y el cierre)
 */
if (strpos($c, '###DEBUG_SCREEN###') !== false) {
    // removemos desde el comentario marcador hasta el end del if debug
    $c = preg_replace(
        '/\s*\/\/\s*###DEBUG_SCREEN###.*?exit;\s*\}\s*/s',
        "\n",
        $c
    );
}

/**
 * 2) Normalizar tipo_global y rol_evento antes de comparar
 *    Buscamos el inicio del router y le metemos variables $tipo y $rol.
 */
$needle = "            // Redirect por template / rol\n";
if (strpos($c, $needle) === false) {
    fwrite(STDERR, "No encontré el bloque de redirect en login.php\n");
    exit(1);
}

$insert = "            // Redirect por template / rol\n".
          "            \$tipo = strtolower(trim(\$row['tipo_global']));\n".
          "            \$rol  = strtolower(trim(\$row['rol_evento']));\n";

$c = str_replace($needle, $insert, $c);

/**
 * 3) Reemplazar comparaciones por $tipo/$rol normalizados
 */
$c = str_replace("\$row['tipo_global'] === 'super_admin'", "\$tipo === 'super_admin'", $c);
$c = str_replace("\$row['tipo_global'] === 'admin_evento'", "\$tipo === 'admin_evento'", $c);
$c = str_replace("\$row['tipo_global'] === 'staff_evento'", "\$tipo === 'staff_evento'", $c);
$c = str_replace("\$row['rol_evento'] === 'puerta'", "\$rol === 'puerta'", $c);

file_put_contents($f, $c);
echo "login.php parchado (debug removido + roles normalizados)\n";
