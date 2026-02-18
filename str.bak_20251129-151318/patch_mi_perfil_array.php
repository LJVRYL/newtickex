<?php
$file = __DIR__ . '/mi_perfil.php';
$code = file_get_contents($file);

$search = <<<'SEARCH'
            $stmtUpd->execute([
                ':nombre' => $nombre !==
                ':email'  => $email  !== '' ? $email  : null,
                ':dni'    => $dni    !== '' ? $dni    : null,
                ':cbu'    => $cbu    !== '' ? $cbu    : null,
                ':avatar' => $avatarFilename,
                ':id'     => $user['id'],
            ]);
SEARCH;

$replace = <<<'REPLACE'
            $stmtUpd->execute([
                ':nombre' => $nombre !== '' ? $nombre : null,
                ':email'  => $email  !== '' ? $email  : null,
                ':dni'    => $dni    !== '' ? $dni    : null,
                ':cbu'    => $cbu    !== '' ? $cbu    : null,
                ':avatar' => $avatarFilename,
                ':id'     => $user['id'],
            ]);
REPLACE;

if (strpos($code, $search) === false) {
    fwrite(STDERR, "No se encontró el bloque roto en mi_perfil.php\n");
    exit(1);
}

$code = str_replace($search, $replace, $code);
file_put_contents($file, $code);
echo "Bloque de execute() corregido en mi_perfil.php\n";
