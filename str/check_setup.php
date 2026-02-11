<?php
/**
 * check_setup.php
 * Verifica que todo esté configurado correctamente para el refactor
 */
require_once __DIR__.'/inc/bootstrap.php';
require_once __DIR__.'/inc/unified_tickets.php';
require_once __DIR__.'/inc/manual_income.php';

try {
    $pdo = db();
    echo "<h2>✅ Verificación de Setup</h2>";
    
    echo "<h3>Base de Datos</h3>";
    echo "<p>✓ BD abierta correctamente</p>";
    
    // Verificar tabla entradas
    echo "<h3>Tabla: entradas</h3>";
    $cols = detect_table_columns($pdo, 'entradas');
    if (empty($cols)) {
        echo "<p>✗ Tabla no existe o está vacía</p>";
    } else {
        echo "<p>✓ Tabla existe con " . count($cols) . " columnas</p>";
        echo "<ul>";
        foreach (array_keys($cols) as $col) {
            echo "<li>$col</li>";
        }
        echo "</ul>";
    }
    
    // Verificar columna check-in
    echo "<h3>Detección de Check-in</h3>";
    $colCheck = get_checkin_column($pdo);
    echo "<p>✓ Columna detectada: <code>$colCheck</code></p>";
    
    // Verificar bridge
    echo "<h3>Bridge Tickex/SenForms</h3>";
    try {
        $stmt = $pdo->query("SELECT 1 FROM v_senforms_bridge_status LIMIT 1");
        echo "<p>✓ Vista <code>v_senforms_bridge_status</code> existe</p>";
    } catch (Exception $e) {
        try {
            $stmt = $pdo->query("SELECT 1 FROM senforms_bridge_tickets LIMIT 1");
            echo "<p>✓ Tabla <code>senforms_bridge_tickets</code> existe</p>";
        } catch (Exception $e2) {
            echo "<p>⚠ Ni vista ni tabla de bridge encontrada (normal si no hay Tickex)</p>";
        }
    }
    
    // Verificar/crear tabla manual_income
    echo "<h3>Ingresos Manuales</h3>";
    if (ensure_manual_income_table($pdo)) {
        $cols = detect_table_columns($pdo, 'manual_income');
        if ($cols) {
            echo "<p>✓ Tabla <code>manual_income</code> creada/existe con " . count($cols) . " columnas</p>";
        }
    } else {
        echo "<p>⚠ No se pudo crear tabla manual_income (check permisos BD)</p>";
    }
    
    // Verificar eventos
    echo "<h3>Eventos disponibles</h3>";
    $stmtEv = $pdo->query("SELECT COUNT(*) as cnt FROM eventos");
    $row = $stmtEv->fetch(PDO::FETCH_ASSOC);
    $cnt = $row ? (int)$row['cnt'] : 0;
    echo "<p>✓ Total de eventos: <strong>$cnt</strong></p>";
    
    if ($cnt > 0) {
        echo "<p>Prueba con un evento:</p>";
        $stmtFirst = $pdo->query("SELECT id, nombre FROM eventos LIMIT 1");
        $first = $stmtFirst->fetch(PDO::FETCH_ASSOC);
        if ($first) {
            $id = (int)$first['id'];
            $name = htmlspecialchars($first['nombre']);
            echo "<p><a href=\"panel_evento.php?evento_id=$id\">panel_evento.php?evento_id=$id</a> - $name</p>";
        }
    }
    
    echo "<hr>";
    echo "<p style='color:green;font-weight:700;'>✅ Setup verificado correctamente!</p>";
    
} catch (Exception $e) {
    echo "<h2>✗ Error de Setup</h2>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
}
?>
