<?php
$f = __DIR__ . '/login.php';
$c = file_get_contents($f);

if (strpos($c, '###DEBUG_SCREEN###') !== false) {
    echo "Debug screen ya estaba puesto.\n";
    exit;
}

$needle = "            // ✅ Login OK\n";
$debug  = "            // ✅ Login OK\n".
"            // ###DEBUG_SCREEN###\n".
"            if (isset(\$_GET['debug']) && \$_GET['debug'] == '1') {\n".
"                echo '<pre style=\"background:#111;color:#0f0;padding:10px\">';\n".
"                echo \"DEBUG LOGIN OK\\n\";\n".
"                echo \"user={$row['username']}\\n\";\n".
"                echo \"tipo_global={$row['tipo_global']}\\n\";\n".
"                echo \"rol_evento={$row['rol_evento']}\\n\";\n".
"                echo \"(si es staff_evento+puerta deberia ir a puerta.php)\\n\";\n".
"                echo '</pre>';\n".
"                exit;\n".
"            }\n";

if (strpos($c, $needle) === false) {
    fwrite(STDERR, "No encontré el punto de inserción en login.php\n");
    exit(1);
}

$c = str_replace($needle, $debug, $c);
file_put_contents($f, $c);
echo "Debug screen agregado en login.php\n";
