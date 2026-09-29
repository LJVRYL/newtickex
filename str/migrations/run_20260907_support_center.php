<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$dbFile = '';
foreach ($argv as $arg) if (strpos($arg, '--db=') === 0) $dbFile = substr($arg, 5);
if ($dbFile === '' || !is_file($dbFile)) {
    fwrite(STDERR, "Uso: php run_20260907_support_center.php --db=/ruta/copia.sqlite\n");
    exit(1);
}
require_once __DIR__ . '/../inc/support_center.php';
try {
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA busy_timeout=15000');
    tickex_support_ensure_schema($pdo);
    $integrity = $pdo->query('PRAGMA integrity_check')->fetchColumn();
    if ($integrity !== 'ok') throw new RuntimeException('Integridad SQLite: ' . $integrity);
    echo 'Centro de soporte preparado en: ' . $dbFile . PHP_EOL;
} catch (Exception $e) {
    fwrite(STDERR, 'No se pudo aplicar la migración: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
