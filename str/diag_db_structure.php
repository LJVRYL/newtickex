<?php
/**
 * Diagnóstico de estructura de BD para refactor panel_evento.php
 * Ejecutar: php -S 127.0.0.1:8080 -t str
 * Luego: http://127.0.0.1:8080/diag_db_structure.php
 */
$dbFile = __DIR__ . '/save_the_rave.sqlite';
if (!file_exists($dbFile)) {
    die("DB no encontrada: $dbFile");
}

try {
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $ex) {
    die("Error: " . $ex->getMessage());
}

echo "<h1>Diagnóstico de BD SQLite</h1>";
echo "<p><strong>Archivo:</strong> $dbFile</p>";

// Listar todas las tablas y vistas
echo "<h2>Tablas y Vistas</h2>";
$stmt = $pdo->query("SELECT type, name FROM sqlite_master WHERE type IN ('table','view') ORDER BY type, name");
$objects = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<table border='1' cellpadding='8'>";
echo "<tr><th>Tipo</th><th>Nombre</th></tr>";
foreach ($objects as $obj) {
    echo "<tr><td>{$obj['type']}</td><td>{$obj['name']}</td></tr>";
}
echo "</table>";

// Inspeccionar tabla "entradas"
echo "<h2>Tabla: entradas</h2>";
if ($pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='entradas' LIMIT 1")->fetch()) {
    $cols = $pdo->query("PRAGMA table_info(entradas)")->fetchAll(PDO::FETCH_ASSOC);
    echo "<table border='1' cellpadding='8'>";
    echo "<tr><th>Columna</th><th>Tipo</th></tr>";
    foreach ($cols as $col) {
        echo "<tr><td>{$col['name']}</td><td>{$col['type']}</td></tr>";
    }
    echo "</table>";
} else {
    echo "<p>Tabla no existe</p>";
}

// Inspeccionar vista v_senforms_bridge_status (si existe)
echo "<h2>Vista: v_senforms_bridge_status</h2>";
if ($pdo->query("SELECT 1 FROM sqlite_master WHERE type='view' AND name='v_senforms_bridge_status' LIMIT 1")->fetch()) {
    echo "<p>Vista existe. Intentando listar columnas...</p>";
    try {
        $cols = $pdo->query("PRAGMA table_info(v_senforms_bridge_status)")->fetchAll(PDO::FETCH_ASSOC);
        if ($cols) {
            echo "<table border='1' cellpadding='8'>";
            echo "<tr><th>Columna</th><th>Tipo</th></tr>";
            foreach ($cols as $col) {
                echo "<tr><td>{$col['name']}</td><td>{$col['type']}</td></tr>";
            }
            echo "</table>";
        } else {
            echo "<p>No se pudieron obtener columnas con PRAGMA (probando SELECT...)</p>";
            $test = $pdo->query("SELECT * FROM v_senforms_bridge_status LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            if ($test) {
                echo "<table border='1' cellpadding='8'>";
                echo "<tr>";
                foreach (array_keys($test) as $k) {
                    echo "<th>$k</th>";
                }
                echo "</tr>";
                echo "<tr>";
                foreach ($test as $v) {
                    echo "<td>" . htmlspecialchars(var_export($v, true)) . "</td>";
                }
                echo "</tr>";
                echo "</table>";
            }
        }
    } catch (Exception $e) {
        echo "<p>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
} else {
    echo "<p>Vista no existe.</p>";
}

// Inspeccionar tabla senforms_bridge_tickets (si existe)
echo "<h2>Tabla: senforms_bridge_tickets</h2>";
if ($pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='senforms_bridge_tickets' LIMIT 1")->fetch()) {
    $cols = $pdo->query("PRAGMA table_info(senforms_bridge_tickets)")->fetchAll(PDO::FETCH_ASSOC);
    echo "<table border='1' cellpadding='8'>";
    echo "<tr><th>Columna</th><th>Tipo</th></tr>";
    foreach ($cols as $col) {
        echo "<tr><td>{$col['name']}</td><td>{$col['type']}</td></tr>";
    }
    echo "</table>";
} else {
    echo "<p>Tabla no existe</p>";
}

// Sample data
echo "<h2>Sample: entradas (primeras 2 filas)</h2>";
try {
    $sample = $pdo->query("SELECT * FROM entradas LIMIT 2")->fetchAll(PDO::FETCH_ASSOC);
    if ($sample) {
        echo "<pre>" . json_encode($sample, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "</pre>";
    }
} catch (Exception $e) {
    echo "<p>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<h2>Sample: v_senforms_bridge_status (primeras 2 filas)</h2>";
try {
    $sample = $pdo->query("SELECT * FROM v_senforms_bridge_status LIMIT 2")->fetchAll(PDO::FETCH_ASSOC);
    if ($sample) {
        echo "<pre>" . json_encode($sample, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "</pre>";
    }
} catch (Exception $e) {
    echo "<p>Tabla/vista no existe o error: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<h2>Sample: senforms_bridge_tickets (primeras 2 filas)</h2>";
try {
    $sample = $pdo->query("SELECT * FROM senforms_bridge_tickets LIMIT 2")->fetchAll(PDO::FETCH_ASSOC);
    if ($sample) {
        echo "<pre>" . json_encode($sample, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "</pre>";
    }
} catch (Exception $e) {
    echo "<p>Tabla/vista no existe o error: " . htmlspecialchars($e->getMessage()) . "</p>";
}
?>
