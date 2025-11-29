<?php
$f = __DIR__ . '/panel_evento.php';
$c = file_get_contents($f);

$repls = [
    // $_SESSION['x'] ?? ''  ->  isset($_SESSION['x']) ? $_SESSION['x'] : ''
    "\$_SESSION['tipo_global'] ?? ''" => "(isset(\$_SESSION['tipo_global']) ? \$_SESSION['tipo_global'] : '')",
    "\$_SESSION['user_id'] ?? 0"      => "(isset(\$_SESSION['user_id']) ? \$_SESSION['user_id'] : 0)",
];

foreach ($repls as $a=>$b) {
    $c = str_replace($a, $b, $c);
}

// también por si quedó algún ?? suelto
if (strpos($c, '??') !== false) {
    // reemplazo genérico muy básico para evitar romper: lo dejamos avisado
    fwrite(STDERR, "Ojo: quedaron '??' en el archivo. Revisar.\n");
}

file_put_contents($f, $c);
echo "panel_evento.php compatible con PHP viejo.\n";
