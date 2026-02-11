<?php
/**
 * diag_bridge_query_12.php
 * Muestra exactamente qué query se ejecuta para el bridge en evento 12
 */
require_once __DIR__.'/inc/bootstrap.php';
require_once __DIR__.'/inc/unified_tickets.php';

header('Content-Type: text/plain; charset=utf-8');

$pdo = db();
$evento_id = 12;

echo "=== DIAGNÓSTICO QUERY BRIDGE - evento_id=12 ===\n\n";

// Simulación de lo que get_unified_entries() hace
$hasBridgeView = false;
try {
    $stmt = $pdo->query("SELECT 1 FROM sqlite_master WHERE type='view' AND name='v_senforms_bridge_status' LIMIT 1");
    if ($stmt && $stmt->fetch()) {
        $hasBridgeView = true;
    }
} catch (Exception $e) {}

$source = $hasBridgeView ? 'v_senforms_bridge_status' : 'senforms_bridge_tickets';
$bridgeCols = detect_table_columns($pdo, $source);

echo "Source: $source\n";
echo "Has event_slug column: " . (isset($bridgeCols['event_slug']) ? 'YES' : 'NO') . "\n\n";

// Get mapped slugs
$mappedSlugs = get_mapped_bridge_slugs($pdo, $evento_id);
echo "Mapped slugs for evento_id=$evento_id:\n";
print_r($mappedSlugs);

// Build where clause exactly like get_unified_entries
$bWhere = array();
$bParams = array();

// Filter by paid
if (isset($bridgeCols['is_paid'])) {
    $bWhere[] = "is_paid = 1";
}

// Filter by event
if (!empty($mappedSlugs) && isset($bridgeCols['event_slug'])) {
    $placeholders = array();
    foreach ($mappedSlugs as $i => $s) {
        $ph = ':slug' . $i;
        $placeholders[] = $ph;
        $bParams[$ph] = $s;
    }
    if (!empty($placeholders)) {
        $bWhere[] = "event_slug IN (" . implode(',', $placeholders) . ")";
    }
}

// Order by
$orderBy = null;
if (isset($bridgeCols['last_updated_at'])) {
    $orderBy = 'last_updated_at DESC';
}

$bSql = "SELECT * FROM $source";
if (!empty($bWhere)) {
    $bSql .= " WHERE " . implode(" AND ", $bWhere);
}
if ($orderBy) {
    $bSql .= " ORDER BY " . $orderBy;
}

echo "\nFinal SQL:\n$bSql\n";
echo "\nParams:\n";
print_r($bParams);

// Execute
try {
    $bStmt = $pdo->prepare($bSql);
    $bStmt->execute($bParams);
    $rows = $bStmt->fetchAll(PDO::FETCH_ASSOC);
    echo "\nRows returned: " . count($rows) . "\n";
    if (!empty($rows)) {
        echo "\nFirst 3 rows:\n";
        for ($i=0; $i<min(3, count($rows)); $i++) {
            echo "Row $i: event_slug=" . $rows[$i]['event_slug'] . ", legacy_ticket_id=" . $rows[$i]['legacy_ticket_id'] . ", is_paid=" . $rows[$i]['is_paid'] . "\n";
        }
    }
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

echo "\nFIN\n";
?>
