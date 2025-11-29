<?php
$f = __DIR__ . '/login.php';
$c = file_get_contents($f);

/*
Buscamos el SELECT típico sin evento_id y le agregamos la columna.
Si ya está, no hace nada.
*/

$patterns = [
    "SELECT id, username, password, rol, tipo_global, rol_evento, activo FROM usuarios_admin",
    "SELECT id, username, password, rol, tipo_global, rol_evento, activo\n        FROM usuarios_admin",
];

$replaced = false;
foreach ($patterns as $p) {
    if (strpos($c, $p) !== false) {
        $c = str_replace(
            $p,
            str_replace(" activo", " activo, evento_id", $p),
            $c
        );
        $replaced = true;
        break;
    }
}

if (!$replaced) {
    // fallback: si el SELECT está en una sola línea diferente
    $c = preg_replace(
        "/SELECT\s+id,\s*username,\s*password,\s*rol,\s*tipo_global,\s*rol_evento,\s*activo\s+FROM\s+usuarios_admin/i",
        "SELECT id, username, password, rol, tipo_global, rol_evento, activo, evento_id FROM usuarios_admin",
        $c,
        1,
        $count
    );
    $replaced = ($count > 0);
}

if (!$replaced) {
    fwrite(STDERR, "No encontré el SELECT esperado en login.php\n");
    exit(1);
}

file_put_contents($f, $c);
echo "SELECT actualizado para incluir evento_id en login.php\n";
