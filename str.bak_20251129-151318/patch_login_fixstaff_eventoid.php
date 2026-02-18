<?php
$f = __DIR__ . '/login.php';
$c = file_get_contents($f);

/**
 * 1) Asegurar que evento_id se setee desde DB inmediatamente al loguear
 *    Buscamos el bloque donde se setea rol_evento y agregamos/reescribimos evento_id.
 */
$needleSess = "            \$_SESSION['rol_evento']  = \$row['rol_evento'];\n";
if (strpos($c, $needleSess) === false) {
    fwrite(STDERR, "No encontré el punto de sesión en login.php\n");
    exit(1);
}

$insertSess =
"            \$_SESSION['rol_evento']  = \$row['rol_evento'];\n".
"            \$_SESSION['evento_id']  = isset(\$row['evento_id']) ? (int)\$row['evento_id'] : 0;\n";

$c = str_replace($needleSess, $insertSess, $c);

/**
 * 2) Reemplazar TODO el router entre:
 *    // Redirect por template / rol
 *    y
 *    // fallback
 */
$start = "            // Redirect por template / rol\n";
$end   = "            // fallback\n";

$posStart = strpos($c, $start);
$posEnd   = strpos($c, $end);

if ($posStart === false || $posEnd === false || $posEnd <= $posStart) {
    fwrite(STDERR, "No encontré el bloque de redirects para reemplazar.\n");
    exit(1);
}

$before = substr($c, 0, $posStart);
$after  = substr($c, $posEnd);

$newRouter = $start .
"            \$tipo = strtolower(trim(\$row['tipo_global']));\n".
"            \$rol  = strtolower(trim(\$row['rol_evento']));\n".
"            \$eid  = isset(\$row['evento_id']) ? (int)\$row['evento_id'] : 0;\n".
"            if (\$eid <= 0) { \$eid = 1; }\n\n".
"            if (\$tipo === 'super_admin') {\n".
"                header('Location: superadmin.php'); exit;\n".
"            }\n".
"            if (\$tipo === 'admin_evento') {\n".
"                header('Location: panel_admin.php'); exit;\n".
"            }\n".
"            if (\$tipo === 'staff_evento') {\n".
"                if (\$rol === 'puerta') {\n".
"                    \$_SESSION['evento_id'] = \$eid;\n".
"                    header('Location: puerta.php?evento_id='.\$eid); exit;\n".
"                }\n".
"                header('Location: panel_admin.php'); exit;\n".
"            }\n\n";

$c = $before . $newRouter . $after;

/**
 * 3) Arreglar el auto-redirect cuando ya está logueado:
 *    si es staff puerta y evento_id vacío, usar 1.
 */
$c = preg_replace(
    "/if\s*\\(\\$tg\s*===\s*'staff_evento'.*?\\)\\s*\\{\\s*header\\(\"Location: puerta.php\\?evento_id=\"\\.\\(int\\)\\$_SESSION\\[\"evento_id\"\\]\\);\\s*exit;\\s*\\}/s",
    "if (\$tg === 'staff_evento' && \$re === 'puerta') {\n".
    "        \$eid = isset(\$_SESSION['evento_id']) ? (int)\$_SESSION['evento_id'] : 0;\n".
    "        if (\$eid <= 0) { \$eid = 1; }\n".
    "        header(\"Location: puerta.php?evento_id=\".\$eid);\n".
    "        exit;\n".
    "    }",
    $c
);

file_put_contents($f, $c);
echo "login.php router staff_evento + evento_id corregido\n";
