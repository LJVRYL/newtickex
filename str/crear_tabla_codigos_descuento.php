<?php
try {
    $pdo = new PDO('sqlite:save_the_rave.sqlite');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Crear tabla si no existe
    $pdo->exec("
CREATE TABLE IF NOT EXISTS codigos_descuento (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  admin_id INTEGER NOT NULL,
  codigo TEXT NOT NULL,
  porcentaje_descuento INTEGER NOT NULL,
  cantidad_maxima INTEGER NOT NULL DEFAULT 0,
  cantidad_usada INTEGER NOT NULL DEFAULT 0,
  activo INTEGER NOT NULL DEFAULT 1,
  creado_en TEXT NOT NULL
);
");

    // Indice para evitar duplicados por admin + codigo
    $pdo->exec("
CREATE UNIQUE INDEX IF NOT EXISTS idx_codigos_descuento_admin_codigo
ON codigos_descuento (admin_id, codigo);
");

    echo "Tabla codigos_descuento OK\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
