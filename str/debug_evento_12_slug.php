<?php
require_once __DIR__.'/inc/bootstrap.php';

$pdo = db();
$evento_id = 12;

echo "<h1>Info del Evento ID 12</h1>";

$stmt = $pdo->prepare("SELECT * FROM eventos WHERE id = ?");
$stmt->execute([$evento_id]);
$evento = $stmt->fetch(PDO::FETCH_ASSOC);

if ($evento) {
    echo "<h2>Datos STR:</h2>";
    echo "<pre>" . json_encode($evento, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "</pre>";
    
    $slug = $evento['slug'] ?? '';
    echo "<h2>Slug: <code>$slug</code></h2>";
    
    if ($slug) {
        echo "<h2>Buscando en el bridge por slug: $slug</h2>";
        
        // Buscar en la vista
        $stmt = $pdo->prepare("SELECT * FROM v_senforms_bridge_status WHERE event_slug = ? LIMIT 5");
        $stmt->execute([$slug]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if ($rows) {
            echo "<p>Encontradas <strong>" . count($rows) . "</strong> entradas en el bridge con ese slug</p>";
            echo "<pre>" . json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "</pre>";
        } else {
            echo "<p>❌ No hay coincidencias en el bridge con slug: $slug</p>";
            
            // Listar todos los slugs disponibles en el bridge
            echo "<h3>Slugs disponibles en el bridge:</h3>";
            $stmt = $pdo->query("SELECT DISTINCT event_slug FROM v_senforms_bridge_status ORDER BY event_slug");
            $slugs = $stmt->fetchAll(PDO::FETCH_COLUMN);
            echo "<pre>" . json_encode($slugs, JSON_PRETTY_PRINT) . "</pre>";
        }
    }
} else {
    echo "<p>❌ Evento 12 no encontrado en STR</p>";
}
?>
