<?php
require_once __DIR__.'/inc/bootstrap.php';

$pdo = db();

echo "<h1>Análisis: Datos en Bridge por evento</h1>";

// Contar cuántas entradas hay por slug en el bridge
$stmt = $pdo->query("SELECT event_slug, COUNT(*) as cnt FROM v_senforms_bridge_status GROUP BY event_slug ORDER BY cnt DESC");
$slugCounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<h2>Eventos en Bridge (con conteo):</h2>";
echo "<table border='1' cellpadding='8'>";
echo "<tr><th>Slug del Bridge</th><th>Total entradas</th><th>Slug en STR?</th></tr>";

foreach ($slugCounts as $row) {
    $bridgeSlug = $row['event_slug'];
    $count = $row['cnt'];
    
    // Ver si existe en STR
    $stmtStr = $pdo->prepare("SELECT id, nombre FROM eventos WHERE slug = ?");
    $stmtStr->execute([$bridgeSlug]);
    $strEvent = $stmtStr->fetch(PDO::FETCH_ASSOC);
    
    $strInfo = $strEvent 
        ? "✓ Evento #{$strEvent['id']} ({$strEvent['nombre']})"
        : "❌ NO en STR";
    
    echo "<tr>";
    echo "<td><code>$bridgeSlug</code></td>";
    echo "<td><strong>$count</strong></td>";
    echo "<td>$strInfo</td>";
    echo "</tr>";
}
echo "</table>";

echo "<h2>¿Qué evento en STR tiene el slug 'savetherave15-03'?</h2>";
$stmt = $pdo->prepare("SELECT * FROM eventos WHERE slug = ?");
$stmt->execute(['savetherave15-03']);
$event = $stmt->fetch(PDO::FETCH_ASSOC);
if ($event) {
    echo "<pre>" . json_encode($event, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "</pre>";
    echo "<p>👉 Este es el evento que debería ver unificado en el panel</p>";
} else {
    echo "<p>No encontrado</p>";
}
?>
