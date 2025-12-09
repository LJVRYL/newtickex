<?php
$f = __DIR__ . '/login.php';
$c = file_get_contents($f);

if (strpos($c, 'login_debug.log') !== false) {
    echo "Debug ya estaba puesto.\n";
    exit;
}

$insertAfter = "            // ✅ Login OK\n";
$debugBlock = "            // ✅ Login OK\n".
"            @file_put_contents('/tmp/login_debug.log', date('c').\" LOGIN OK user={$row['username']} tipo={$row['tipo_global']} rol={$row['rol_evento']}\\n\", FILE_APPEND);\n";

if (strpos($c, $insertAfter) === false) {
    fwrite(STDERR, "No encontré el punto de inserción en login.php\n");
    exit(1);
}

$c = str_replace($insertAfter, $debugBlock, $c);

// además logueamos el target justo antes de cada redirect
$c = str_replace('header("Location: superadmin.php");',
    "@file_put_contents('/tmp/login_debug.log', date('c').\" REDIRECT superadmin.php\\n\", FILE_APPEND);\n                header(\"Location: superadmin.php\");", $c);

$c = str_replace('header("Location: panel_admin.php");',
    "@file_put_contents('/tmp/login_debug.log', date('c').\" REDIRECT panel_admin.php\\n\", FILE_APPEND);\n                header(\"Location: panel_admin.php\");", $c);

$c = str_replace('header("Location: puerta.php");',
    "@file_put_contents('/tmp/login_debug.log', date('c').\" REDIRECT puerta.php\\n\", FILE_APPEND);\n                    header(\"Location: puerta.php\");", $c);

file_put_contents($f, $c);
echo "Debug agregado en login.php\n";
