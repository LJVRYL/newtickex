<?php
$f = __DIR__ . '/panel_admin.php';
$c = file_get_contents($f);

$c2 = preg_replace(
    '/<title>[^<]*$/m',
    '<title>Panel general – Admin</title>',
    $c,
    1,
    $count
);

if ($count < 1) {
    fwrite(STDERR, "No encontré un <title> roto para reemplazar.\n");
    exit(1);
}

file_put_contents($f, $c2);
echo "Title arreglado en panel_admin.php\n";
