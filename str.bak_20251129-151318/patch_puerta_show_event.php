<?php
$f = __DIR__ . '/puerta.php';
$c = file_get_contents($f);

// 1) Insertar bloque que carga datos del evento luego de conectar PDO
$needlePdo = "\$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);\n";
if (strpos($c, $needlePdo) === false) {
    fwrite(STDERR, "No encontré el setAttribute de PDO en puerta.php\n");
    exit(1);
}

$eventBlock = $needlePdo . "\n" .
"// ===== Datos del evento actual (para mostrar en pantalla) =====\n" .
"\$eventoNombre = '';\n" .
"\$eventoSlug   = '';\n" .
"if (isset(\$eventoId) && (int)\$eventoId > 0) {\n" .
"    \$stmtEvInfo = \$pdo->prepare(\"SELECT nombre, slug FROM eventos WHERE id = :id LIMIT 1\");\n" .
"    \$stmtEvInfo->execute(array(':id' => (int)\$eventoId));\n" .
"    \$evInfo = \$stmtEvInfo->fetch(PDO::FETCH_ASSOC);\n" .
"    if (\$evInfo) {\n" .
"        \$eventoNombre = \$evInfo['nombre'];\n" .
"        \$eventoSlug   = \$evInfo['slug'];\n" .
"    }\n" .
"}\n";

if (strpos($c, "Datos del evento actual") === false) {
    $c = str_replace($needlePdo, $eventBlock, $c);
}

// 2) Mostrarlo en el header
$needleH1 = "<h1>Check-in – Modo Puerta</h1>";
if (strpos($c, $needleH1) === false) {
    fwrite(STDERR, "No encontré el H1 esperado en puerta.php\n");
    exit(1);
}

$replaceH1 =
"<h1>Check-in – Modo Puerta</h1>\n" .
"<?php if (!empty(\$eventoNombre) || !empty(\$eventoSlug)): ?>\n" .
"  <div class=\"small\" style=\"margin-top:4px;\">\n" .
"    Evento: <strong><?php echo htmlspecialchars(\$eventoNombre); ?></strong>\n" .
"    <?php if (!empty(\$eventoSlug)): ?>\n" .
"      <span style=\"color:#9ca3af;\">(<?php echo htmlspecialchars(\$eventoSlug); ?>)</span>\n" .
"    <?php endif; ?>\n" .
"  </div>\n" .
"<?php endif; ?>";

$c = str_replace($needleH1, $replaceH1, $c);

file_put_contents($f, $c);
echo "puerta.php ahora muestra nombre/slug del evento\n";
