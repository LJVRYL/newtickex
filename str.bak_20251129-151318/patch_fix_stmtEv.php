<?php
$file = __DIR__ . '/crear_evento.php';
$code = file_get_contents($file);

$search = <<<'SEARCH'
                // Insertar evento (SIN creado_por_admin_id)
                $st
                    INSERT INTO eventos
                        (nombre, slug, descripcion, flyer_filename, fecha_desde, fecha_hasta, creado_en)
                    VALUES
                        (:nombre, :slug, :descripcion, :flyer, :fdesde, :fhasta, datetime('now'))
                ");
SEARCH;

$replace = <<<'REPLACE'
                // Insertar evento (SIN creado_por_admin_id)
                $stmtEv = $pdo->prepare("
                    INSERT INTO eventos
                        (nombre, slug, descripcion, flyer_filename, fecha_desde, fecha_hasta, creado_en)
                    VALUES
                        (:nombre, :slug, :descripcion, :flyer, :fdesde, :fhasta, datetime('now'))
                ");
REPLACE;

if (strpos($code, $search) === false) {
    fwrite(STDERR, "No se encontró el bloque roto en crear_evento.php\n");
    exit(1);
}

$code = str_replace($search, $replace, $code);
file_put_contents($file, $code);
echo "Bloque INSERT eventos arreglado en crear_evento.php\n";
