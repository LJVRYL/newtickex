<?php
$file = __DIR__ . '/crear_evento.php';
$code = file_get_contents($file);

$search1 = <<<'SEARCH'
                $stmtEv = $pdo->prepare("
                    INSERT INTO eventos
                        (nombre, slug, descripcion, flyer_filename, fecha_desde, fecha_hasta,
                         creado_por_admin_id, creado_en)
                    VALUES
                        (:nombre, :slug, :descripcion, :flyer, :fdesde, :fhasta,
                         :creado_por, datetime('now'))
                ");
SEARCH;

$replace1 = <<<'REPLACE'
                $stmtEv = $pdo->prepare("
                    INSERT INTO eventos
                        (nombre, slug, descripcion, flyer_filename, fecha_desde, fecha_hasta, creado_en)
                    VALUES
                        (:nombre, :slug, :descripcion, :flyer, :fdesde, :fhasta, datetime('now'))
                ");
REPLACE;

$search2 = <<<'SEARCH'
                $stmtEv->execute([
                    ':nombre'     => $nombre,
                    ':slug'       => $slug,
                    ':descripcion'=> $descripcion,
                    ':flyer'      => $flyerFilename,
                    ':fdesde'     => $fechaDesde !== '' ? $fechaDesde : null,
                    ':fhasta'     => $fechaHasta !== '' ? $fechaHasta : null,
                    ':creado_por' => (int)$user['id'],
                ]);
SEARCH;

$replace2 = <<<'REPLACE'
                $stmtEv->execute([
                    ':nombre'     => $nombre,
                    ':slug'       => $slug,
                    ':descripcion'=> $descripcion,
                    ':flyer'      => $flyerFilename,
                    ':fdesde'     => $fechaDesde !== '' ? $fechaDesde : null,
                    ':fhasta'     => $fechaHasta !== '' ? $fechaHasta : null,
                ]);
REPLACE;

if (strpos($code, $search1) === false || strpos($code, $search2) === false) {
    fwrite(STDERR, "No se encontraron los bloques esperados en crear_evento.php\n");
    exit(1);
}

$code = str_replace($search1, $replace1, $code);
$code = str_replace($search2, $replace2, $code);

file_put_contents($file, $code);
echo "Patch aplicado a crear_evento.php\n";
