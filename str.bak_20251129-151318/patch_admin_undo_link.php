<?php
$file = __DIR__ . '/admin.php';
$code = file_get_contents($file);

$search = 'href="admin.php?undo=<?php echo (int)$r[\'id\']; ?>"';
$replace = 'href="undo_checkin.php?id=<?php echo (int)$r[\'id\']; ?>"';

if (strpos($code, $search) === false) {
    fwrite(STDERR, "No se encontró el href de undo esperado en admin.php\n");
    exit(1);
}

$code = str_replace($search, $replace, $code);
file_put_contents($file, $code);
echo "Link de Atrás en admin.php actualizado a undo_checkin.php.\n";
