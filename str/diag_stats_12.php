<?php
require_once __DIR__.'/inc/bootstrap.php';
require_once __DIR__.'/inc/unified_tickets.php';

header('Content-Type: text/plain; charset=utf-8');

$pdo = db();
$evento_id = 12;

echo "=== DIAGNÓSTICO get_unified_stats() ===\n\n";

$stats = get_unified_stats($pdo, $evento_id);

echo "Resultado de get_unified_stats($evento_id):\n";
print_r($stats);

echo "\nValores individuales:\n";
echo "total: " . $stats['total'] . "\n";
echo "paid: " . $stats['paid'] . "\n";
echo "checkins: " . $stats['checkins'] . "\n";
echo "pendiente: " . $stats['pendiente'] . "\n";

echo "\nFIN\n";
?>
