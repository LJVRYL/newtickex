<?php
$f = __DIR__ . '/login.php';
$c = file_get_contents($f);

if (strpos($c, "\$_SESSION['evento_id']") !== false) {
    echo "login.php ya tenía evento_id en sesión.\n";
    exit;
}

$search = "            \$_SESSION['rol_evento']  = \$row['rol_evento'];\n";
$replace = "            \$_SESSION['rol_evento']  = \$row['rol_evento'];\n".
           "            \$_SESSION['evento_id']  = isset(\$row['evento_id']) ? (int)\$row['evento_id'] : 0;\n";

if (strpos($c, $search) === false) {
    fwrite(STDERR, "No encontré dónde setear sesión en login.php\n");
    exit(1);
}

$c = str_replace($search, $replace, $c);

// cambiar redirect puerta.php para llevar evento_id
$c = str_replace('header("Location: puerta.php");',
                 'header("Location: puerta.php?evento_id=".(int)$_SESSION["evento_id"]);',
                 $c);

file_put_contents($f, $c);
echo "login.php parchado (staff manda evento_id)\n";
