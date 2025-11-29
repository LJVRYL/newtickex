<?php
$f = __DIR__ . '/secundarios.php';
$c = file_get_contents($f);

/*
1) Asegurar JOIN en SELECT staff (si ya está, no lo toca).
2) Reemplazar cualquier <td> que imprima evento_id por nombre/slug.
*/

// --- 1) JOIN staff (solo si NO está ya) ---
if (strpos($c, "LEFT JOIN eventos") === false) {

    $c = preg_replace(
        "/SELECT\s+id,\s*username,\s*rol_evento,\s*evento_id,\s*activo\s+FROM\s+usuarios_admin\s+WHERE\s+tipo_global='staff_evento'/i",
        "SELECT u.id, u.username, u.rol_evento, u.evento_id, u.activo,\n".
        "       e.nombre AS evento_nombre, e.slug AS evento_slug\n".
        "FROM usuarios_admin u\n".
        "LEFT JOIN eventos e ON e.id = u.evento_id\n".
        "WHERE u.tipo_global='staff_evento'",
        $c,
        1
    );

    $c = preg_replace(
        "/SELECT\s+id,\s*username,\s*rol_evento,\s*evento_id,\s*activo\s+FROM\s+usuarios_admin\s+WHERE\s+tipo_global='staff_evento'\s+AND\s+creado_por_admin_id\s*=\s*:aid/i",
        "SELECT u.id, u.username, u.rol_evento, u.evento_id, u.activo,\n".
        "       e.nombre AS evento_nombre, e.slug AS evento_slug\n".
        "FROM usuarios_admin u\n".
        "LEFT JOIN eventos e ON e.id = u.evento_id\n".
        "WHERE u.tipo_global='staff_evento' AND u.creado_por_admin_id = :aid",
        $c,
        1
    );
}

// --- 2) Reemplazo flexible de la celda ---
// Buscamos cualquier <td> ... $s['evento_id'] ... </td>
$regexTdEventoId = "/<td>\s*<\?php[^<]*\\\$s\\[['\\\"]evento_id['\\\"]\\][^<]*\?>\s*<\/td>/is";

$replacementTd =
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
"</td>";

$new = preg_replace($regexTdEventoId, $replacementTd, $c, 1, $countTd);

if ($countTd === 0) {
    // fallback extra: si está todo en una línea rara, reemplazamos SOLO el echo dentro del td
    $new = preg_replace(
        "/(<td>\\s*<\\?php\\s*echo\\s*)([^;]*\\\$s\\[['\\\"]evento_id['\\\"]\\][^;]*)(;\\s*\\?>\\s*<\\/td>)/is",
        $replacementTd,
        $c,
        1,
        $countTd2
    );
    if ($countTd2 === 0) {
        fwrite(STDERR, "No pude localizar ninguna celda con evento_id en la tabla.\n");
        exit(1);
    }
}

file_put_contents($f, $new);
echo "secundarios.php ahora muestra nombre/slug del evento en staff (patch flexible)\n";
