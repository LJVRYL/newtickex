<?php
require_once __DIR__ . '/inc/bootstrap.php';

$pdo = db();

// Ver columnas de tipos_entrada
echo "=== COLUMNAS DE tipos_entrada ===\n";
$cols = $pdo->query("PRAGMA table_info(tipos_entrada)")->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $c) {
    echo "- " . $c['name'] . " (" . $c['type'] . ")\n";
}

// Ver datos de tipos_entrada para evento 12
echo "\n=== DATOS DE tipos_entrada PARA EVENTO 12 ===\n";
$rows = $pdo->prepare("SELECT * FROM tipos_entrada WHERE evento_id = ?");
$rows->execute([12]);
while ($r = $rows->fetch(PDO::FETCH_ASSOC)) {
    echo json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
}

echo "\n=== ENTRADAS CON MONTO EN EVENTO 12 ===\n";
$ents = $pdo->prepare("SELECT nombre, email, tipo, monto_pagado FROM entradas WHERE evento_id = ? AND monto_pagado > 0");
$ents->execute([12]);
while ($r = $ents->fetch(PDO::FETCH_ASSOC)) {
    echo json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
}
?>
