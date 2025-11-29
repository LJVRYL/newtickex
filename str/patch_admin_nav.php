<?php
$file = __DIR__ . '/admin.php';
$code = file_get_contents($file);

$search = '<form method="get" class="filters">';
$replace = <<<'HTML'
<div style="margin:6px 0 10px 0; display:flex; flex-wrap:wrap; gap:8px;">
  <a class="btn-link" href="admin.php">Panel principal</a>
  <a class="btn-link" href="eventos.php" target="_blank">Eventos</a>
  <a class="btn-link" href="usuarios.php" target="_blank">Usuarios</a>
</div>

<form method="get" class="filters">
HTML;

if (strpos($code, $search) === false) {
    fwrite(STDERR, "No se encontró el formulario de filtros esperado en admin.php\n");
    exit(1);
}

$code = str_replace($search, $replace, $code);
file_put_contents($file, $code);
echo "Navegación superior agregada en admin.php.\n";
