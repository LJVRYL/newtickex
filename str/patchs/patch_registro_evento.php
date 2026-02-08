<?php
$file = __DIR__ . '/registro.php';
$code = file_get_contents($file);

//
// 1) Patch al CREATE TABLE entradas (agregar evento_id)
//
$searchCreate = <<<'SEARCH'
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS entradas (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nombre TEXT NOT NULL,
            email TEXT NOT NULL,
            fecha_registro TEXT NOT NULL,
            codigo TEXT NOT NULL UNIQUE,
            checked_in INTEGER NOT NULL DEFAULT 0,
            checked_in_at TEXT,
            tipo TEXT NOT NULL DEFAULT 'FREE',
            monto_pagado INTEGER NOT NULL DEFAULT 0
        )
    ");
SEARCH;

$replaceCreate = <<<'REPLACE'
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS entradas (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nombre TEXT NOT NULL,
            email TEXT NOT NULL,
            fecha_registro TEXT NOT NULL,
            codigo TEXT NOT NULL UNIQUE,
            checked_in INTEGER NOT NULL DEFAULT 0,
            checked_in_at TEXT,
            tipo TEXT NOT NULL DEFAULT 'FREE',
            monto_pagado INTEGER NOT NULL DEFAULT 0,
            evento_id INTEGER NOT NULL DEFAULT 1
        )
    ");
REPLACE;

//
// 2) Patch al INSERT INTO entradas (agregar evento_id = 1)
//
$searchInsert = <<<'SEARCH'
    // Insertar entrada, tipo hardcodeado a 'FREE'
    $stmt = $pdo->prepare("
        INSERT INTO entradas (nombre, email, fecha_registro, codigo, checked_in, tipo)
        VALUES (:nombre, :email, :fecha_registro, :codigo, 0, 'FREE')
    ");
SEARCH;

$replaceInsert = <<<'REPLACE'
    // Insertar entrada, tipo hardcodeado a 'FREE' y evento_id = 1 (STR)
    $stmt = $pdo->prepare("
        INSERT INTO entradas (nombre, email, fecha_registro, codigo, checked_in, tipo, evento_id)
        VALUES (:nombre, :email, :fecha_registro, :codigo, 0, 'FREE', 1)
    ");
REPLACE;

if (strpos($code, $searchCreate) === false) {
    fwrite(STDERR, "No se encontró el bloque CREATE TABLE esperado en registro.php\n");
} else {
    $code = str_replace($searchCreate, $replaceCreate, $code);
}

if (strpos($code, $searchInsert) === false) {
    fwrite(STDERR, "No se encontró el bloque INSERT esperado en registro.php\n");
} else {
    $code = str_replace($searchInsert, $replaceInsert, $code);
}

file_put_contents($file, $code);
echo "Patch aplicado a registro.php\n";
