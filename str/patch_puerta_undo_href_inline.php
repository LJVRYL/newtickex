<?php
$file = __DIR__ . '/puerta.php';
$code = file_get_contents($file);

$search = 'href="undo_checkin.php?id=<?php echo (int)$r[\'id\']; ?>"';
$replace = 'href="puerta.php?undo=<?php echo (int)$r[\'id\']; ?>"';

if (strpos($code, $search) === false) {
    fwrite(STDERR, "No se encontró el href de undo_checkin en puerta.php para reemplazar.\n");
    exit(1);
}

$code = str_replace($search, $replace, $code);
file_put_contents($file, $code);
echo "Link de Atrás en puerta.php apuntando a puerta.php?undo=ID.\n";
