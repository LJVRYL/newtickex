<?php
$file = __DIR__ . '/puerta.php';
$code = file_get_contents($file);

$search = 'href="puerta.php?undo=<?php echo (int)$r[\'id\']; ?>"';
$replace = 'href="admin.php?undo=<?php echo (int)$r[\'id\']; ?>&from=puerta"';

if (strpos($code, $search) === false) {
    fwrite(STDERR, "No se encontró el href de undo esperado en puerta.php\n");
    exit(1);
}

$code = str_replace($search, $replace, $code);
file_put_contents($file, $code);
echo "Link de Atrás en puerta.php actualizado para usar admin.php?undo=...&from=puerta.\n";
