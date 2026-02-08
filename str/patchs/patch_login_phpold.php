<?php
$f = __DIR__ . '/login.php';
$c = file_get_contents($f);

$c = str_replace("\$tg = \$_SESSION['tipo_global'] ?? '';", "\$tg = isset(\$_SESSION['tipo_global']) ? \$_SESSION['tipo_global'] : '';", $c);
$c = str_replace("\$re = \$_SESSION['rol_evento'] ?? '';", "\$re = isset(\$_SESSION['rol_evento']) ? \$_SESSION['rol_evento'] : '';", $c);

file_put_contents($f, $c);
echo "Patch PHP viejo aplicado en login.php\n";
