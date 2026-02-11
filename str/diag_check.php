<?php
/**
 * Script de diagnóstico simple de estructura de BD
 */
$dbFile = __DIR__ . '/save_the_rave.sqlite';
if (!file_exists($dbFile)) {
    die("DB no encontrada");
}

try {
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "=== TABLAS Y VISTAS ===\n";
    $stmt = $pdo->query("SELECT type, name FROM sqlite_master WHERE type IN ('table','view') ORDER BY type, name");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $obj) {
        echo $obj['type'] . ': ' . $obj['name'] . "\n";
    }
    
    echo "\n=== COLUMNAS ENTRADAS ===\n";
    $cols = $pdo->query("PRAGMA table_info(entradas)")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $col) {
        echo '  ' . $col['name'] . ' (' . $col['type'] . ')' . "\n";
    }
    
    echo "\n=== PRUEBA v_senforms_bridge_status ===\n";
    try {
        $test = $pdo->query("SELECT * FROM v_senforms_bridge_status LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if ($test) {
            echo 'Columnas encontradas: ' . implode(', ', array_keys($test)) . "\n";
            echo "Sample: " . json_encode($test) . "\n";
        }
    } catch (Exception $e) {
        echo 'Vista no existe: ' . $e->getMessage() . "\n";
    }
    
    echo "\n=== PRUEBA senforms_bridge_tickets ===\n";
    try {
        $test = $pdo->query("SELECT * FROM senforms_bridge_tickets LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if ($test) {
            echo 'Columnas encontradas: ' . implode(', ', array_keys($test)) . "\n";
            echo "Sample: " . json_encode($test, JSON_UNESCAPED_UNICODE) . "\n";
        } else {
            echo 'Tabla vacía o no existe' . "\n";
        }
    } catch (Exception $e) {
        echo 'Tabla no existe: ' . $e->getMessage() . "\n";
    }
    
} catch (Exception $ex) {
    echo 'Error DB: ' . $ex->getMessage();
}
?>
