<?php
// Script one-shot para crear la tabla clientes_sites en save_the_rave.sqlite
// Ejecutar con: php crear_tabla_clientes_sites.php

$dbFile = __DIR__ . '/save_the_rave.sqlite';

if (!file_exists($dbFile)) {
    die("ERROR: No se encontró la base de datos en: " . $dbFile . PHP_EOL);
}

$dsn = 'sqlite:' . $dbFile;

try {
    $pdo = new PDO($dsn);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $sql = "
    CREATE TABLE IF NOT EXISTS clientes_sites (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        admin_id INTEGER NOT NULL,
        slug_publico TEXT NOT NULL,
        nombre_publico TEXT NOT NULL,
        texto_hero TEXT,
        texto_intro TEXT,
        visible INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    );

    CREATE UNIQUE INDEX IF NOT EXISTS idx_clientes_sites_admin
        ON clientes_sites (admin_id);

    CREATE UNIQUE INDEX IF NOT EXISTS idx_clientes_sites_slug
        ON clientes_sites (slug_publico);
    ";

    $pdo->exec($sql);

    echo "OK: Tabla clientes_sites creada / verificada.\n";
} catch (Exception $e) {
    echo "ERROR al crear la tabla clientes_sites: " . $e->getMessage() . "\n";
    exit(1);
}
