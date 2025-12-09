<?php
$file = __DIR__ . '/admin.php';
$code = file_get_contents($file);

$search  = "        \$_SESSION['is_admin'] = true;";
$replace = "        \$_SESSION['is_admin'] = true;\n        \$_SESSION['usuario'] = ADMIN_USER;";

if (strpos($code, $search) === false) {
    fwrite(STDERR, "No se encontró la línea \$_SESSION['is_admin'] = true; en admin.php\n");
    exit(1);
}

$code = str_replace($search, $replace, $code);
file_put_contents($file, $code);
echo "Línea de \$_SESSION['usuario'] agregada en admin.php\n";
