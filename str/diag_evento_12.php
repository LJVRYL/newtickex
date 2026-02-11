<?php
/**
 * diag_evento_12.php
 * Diagnóstico específico para evento_id=12
 * Revisa qué datos hay en STR y en el bridge
 */
require_once __DIR__.'/inc/bootstrap.php';
require_once __DIR__.'/inc/unified_tickets.php';

$pdo = db();
$evento_id = 12;

echo "<h1>Diagnóstico Evento ID: 12</h1>";

// ===== ENTRADAS STR =====
echo "<h2>ENTRADAS STR (tabla entradas)</h2>";
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM entradas WHERE evento_id = ?");
    $stmt->execute([$evento_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "<p>Total en STR: <strong>" . $row['cnt'] . "</strong></p>";
    
    if ($row['cnt'] > 0) {
        echo "<h3>Primeras 3 entradas STR:</h3>";
        $stmt = $pdo->prepare("SELECT * FROM entradas WHERE evento_id = ? LIMIT 3");
        $stmt->execute([$evento_id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "<pre>" . json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "</pre>";
    }
} catch (Exception $e) {
    echo "<p>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
}

// ===== BRIDGE - v_senforms_bridge_status =====
echo "<h2>BRIDGE - Vista v_senforms_bridge_status</h2>";
try {
    $stmt = $pdo->query("SELECT 1 FROM sqlite_master WHERE type='view' AND name='v_senforms_bridge_status' LIMIT 1");
    if ($stmt && $stmt->fetch()) {
        echo "<p>✓ Vista EXISTE</p>";
        
        // Ver estructura
        echo "<h3>Columnas:</h3>";
        $cols = $pdo->query("PRAGMA table_info(v_senforms_bridge_status)")->fetchAll(PDO::FETCH_ASSOC);
        echo "<pre>";
        foreach ($cols as $col) {
            echo $col['name'] . " (" . $col['type'] . ")\n";
        }
        echo "</pre>";
        
        // Intentar contar por evento_id
        echo "<h3>Intentando filtrar por evento_id:</h3>";
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM v_senforms_bridge_status WHERE evento_id = ?");
            $stmt->execute([$evento_id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            echo "<p>Total con evento_id=12: <strong>" . $row['cnt'] . "</strong></p>";
        } catch (Exception $e) {
            echo "<p>No se puede filtrar por evento_id: " . htmlspecialchars($e->getMessage()) . "</p>";
        }
        
        // Ver primeros registros
        echo "<h3>Primeras 3 entradas en view:</h3>";
        $stmt = $pdo->query("SELECT * FROM v_senforms_bridge_status LIMIT 3");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "<pre>" . json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "</pre>";
        
    } else {
        echo "<p>✗ Vista NO existe</p>";
    }
} catch (Exception $e) {
    echo "<p>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
}

// ===== BRIDGE - tabla senforms_bridge_tickets =====
echo "<h2>BRIDGE - Tabla senforms_bridge_tickets</h2>";
try {
    $stmt = $pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='senforms_bridge_tickets' LIMIT 1");
    if ($stmt && $stmt->fetch()) {
        echo "<p>✓ Tabla EXISTE</p>";
        
        // Ver estructura
        echo "<h3>Columnas:</h3>";
        $cols = $pdo->query("PRAGMA table_info(senforms_bridge_tickets)")->fetchAll(PDO::FETCH_ASSOC);
        echo "<pre>";
        foreach ($cols as $col) {
            echo $col['name'] . " (" . $col['type'] . ")\n";
        }
        echo "</pre>";
        
        // Contar totales
        echo "<h3>Estadísticas:</h3>";
        $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM senforms_bridge_tickets");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        echo "<p>Total registros: <strong>" . $row['cnt'] . "</strong></p>";
        
        // Intentar filtrar por evento_id
        echo "<h3>Intentando filtrar por evento_id=12:</h3>";
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM senforms_bridge_tickets WHERE evento_id = ?");
            $stmt->execute([$evento_id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            echo "<p>Total con evento_id=12: <strong>" . $row['cnt'] . "</strong></p>";
        } catch (Exception $e) {
            echo "<p>No se puede filtrar por evento_id: " . htmlspecialchars($e->getMessage()) . "</p>";
        }
        
        // Ver primeros registros
        echo "<h3>Primeras 3 entradas en tabla:</h3>";
        $stmt = $pdo->query("SELECT * FROM senforms_bridge_tickets LIMIT 3");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "<pre>" . json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "</pre>";
        
    } else {
        echo "<p>✗ Tabla NO existe</p>";
    }
} catch (Exception $e) {
    echo "<p>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
}

// ===== PRUEBA get_unified_entries =====
echo "<h2>RESULTADO de get_unified_entries()</h2>";
$filters = array('q' => '', 'tipo' => '', 'estado' => '');
$entries = get_unified_entries($pdo, $evento_id, $filters);
echo "<p>Total entradas unificadas: <strong>" . count($entries) . "</strong></p>";
if (!empty($entries)) {
    echo "<h3>Breakdown por source:</h3>";
    $str_count = 0;
    $tickex_count = 0;
    foreach ($entries as $e) {
        if ($e['source'] === 'STR') $str_count++;
        else $tickex_count++;
    }
    echo "<p>STR: $str_count | TICKEX: $tickex_count</p>";
    
    echo "<h3>Primeras 5:</h3>";
    echo "<pre>" . json_encode(array_slice($entries, 0, 5), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "</pre>";
}
?>
