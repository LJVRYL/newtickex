<?php
$f = __DIR__ . '/secundarios.php';
$c = file_get_contents($f);

/*
1) Cambiar SELECT de staff para traer nombre/slug del evento con LEFT JOIN.
2) Cambiar columna en la tabla para mostrarlo.
*/

// --- 1) SELECT staff con JOIN ---
$c = preg_replace(
    "/SELECT id, username, rol_evento, evento_id, activo\s+FROM usuarios_admin\s+WHERE tipo_global='staff_evento'/i",
    "SELECT u.id, u.username, u.rol_evento, u.evento_id, u.activo,\n".
    "       e.nombre AS evento_nombre, e.slug AS evento_slug\n".
    "FROM usuarios_admin u\n".
    "LEFT JOIN eventos e ON e.id = u.evento_id\n".
    "WHERE u.tipo_global='staff_evento'",
    $c,
    1,
    $count1
);

$c = preg_replace(
    "/SELECT id, username, rol_evento, evento_id, activo\s+FROM usuarios_admin\s+WHERE tipo_global='staff_evento' AND creado_por_admin_id = :aid/i",
    "SELECT u.id, u.username, u.rol_evento, u.evento_id, u.activo,\n".
    "       e.nombre AS evento_nombre, e.slug AS evento_slug\n".
    "FROM usuarios_admin u\n".
    "LEFT JOIN eventos e ON e.id = u.evento_id\n".
    "WHERE u.tipo_global='staff_evento' AND u.creado_por_admin_id = :aid",
    $c,
    1,
    $count2
);

if (($count1 + $count2) === 0) {
    fwrite(STDERR, "No encontré los SELECT staff esperados en secundarios.php\n");
    exit(1);
}

// --- 2) Header de tabla ---
$c = str_replace(
    "<th>Evento</th>",
    "<th>Evento</th>",
    $c
);

// --- 3) Celda de evento ---
$c = preg_replace(
    "/<td>\s*<\?php echo \(int\)\$s\['evento_id'\]; \?>\s*<\/td>/i",
    "<td>\n".
    "  <?php\n".
    "    \$en = isset(\$s['evento_nombre']) ? \$s['evento_nombre'] : '';\n".
    "    \$es = isset(\$s['evento_slug']) ? \$s['evento_slug'] : '';\n".
    "    if (\$en !== '' || \$es !== '') {\n".
    "        echo e(\$en) . (\$es !== '' ? ' (' . e(\$es) . ')' : '');\n".
    "    } else {\n".
    "        echo (int)\$s['evento_id'];\n".
    "    }\n".
    "  ?>\n".
    "</td>",
    $c,
    1,
    $count3
);

if ($count3 === 0) {
    fwrite(STDERR, "No encontré la celda evento_id para reemplazar en la tabla.\n");
    exit(1);
}

file_put_contents($f, $c);
echo "secundarios.php ahora muestra nombre/slug del evento en staff\n";
