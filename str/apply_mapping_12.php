<?php
// Script temporal para aplicar mapping evento 12 -> savetherave7-3
require_once __DIR__.'/inc/bootstrap.php';
require_once __DIR__.'/inc/unified_tickets.php';

$pdo = db();
$evento_id = 12;
$slug = 'savetherave7-3';

if (set_bridge_mapping($pdo, $evento_id, $slug)) {
    echo "OK: mapping aplicado $evento_id -> $slug\n";
    exit(0);
} else {
    echo "ERROR: no se pudo aplicar mapping\n";
    exit(2);
}
