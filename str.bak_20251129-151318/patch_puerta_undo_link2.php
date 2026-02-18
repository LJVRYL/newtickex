<?php
$file = __DIR__ . '/puerta.php';
$code = file_get_contents($file);

$search = 'href="admin.php?undo=<?php echo (int)$r[\'id\']; ?>&from=puerta"';
$replace = 'href="undo_checkin.php?id=<?php echo (int)$r[\'id\']; ?>"';

if (strpos($code, $search) === false) {
    // Si no encuentra esa versión, probamos la versión anterior por si quedó
    $search2 = 'href="puerta.php?undo=<?php echo (int)$r[\'id\']; ?>"';
    if (strpos($code, $search2) === false) {
        fwrite(STDERR, "No se encontró el href de undo esperado en puerta.php\n");
        exit(1);
    }
    $code = str_replace($search2, $replace, $code);
} else {
    $code = str_replace($search, $replace, $code);
}

file_put_contents($file, $code);
echo "Link de Atrás en puerta.php actualizado a undo_checkin.php.\n";
