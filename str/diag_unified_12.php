<?php
/**
 * diag_unified_12.php
 * Diagnóstico rápido para evento 12: muestra mapping, slugs del bridge y muestras de filas
 */
require_once __DIR__.'/inc/bootstrap.php';
require_once __DIR__.'/inc/unified_tickets.php';

header('Content-Type: text/plain; charset=utf-8');

$pdo = db();
$evento_id = 12;

echo "DIAGNÓSTICO UNIFICADO - evento_id=12\n";
echo "--------------------------------\n";

// Mapping
$mapped = get_mapped_bridge_slugs($pdo, $evento_id);
echo "Mapped slugs: ";
echo empty($mapped) ? "(none)\n" : implode(', ', $mapped) . "\n";

// STR slug
try {
    $s = $pdo->prepare("SELECT slug FROM eventos WHERE id = :id LIMIT 1");
    $s->execute(array(':id'=>$evento_id));
    $r = $s->fetch(PDO::FETCH_ASSOC);
    echo "STR.slug: ";
    echo ($r && !empty($r['slug'])) ? $r['slug'] . "\n" : "(not found)\n";
} catch (Exception $e) { echo "STR.slug: error\n"; }

// Detect bridge source
$hasView = false; $hasTable = false;
try {
    $st = $pdo->query("SELECT 1 FROM sqlite_master WHERE type='view' AND name='v_senforms_bridge_status' LIMIT 1");
    if ($st && $st->fetch()) $hasView = true;
    $st2 = $pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='senforms_bridge_tickets' LIMIT 1");
    if ($st2 && $st2->fetch()) $hasTable = true;
} catch (Exception $e) {}
echo "Bridge view present: ".($hasView?"YES":"NO")."  table present: ".($hasTable?"YES":"NO")."\n";

$source = $hasView ? 'v_senforms_bridge_status' : ($hasTable ? 'senforms_bridge_tickets' : null);
if (!$source) { echo "No bridge source found.\n"; exit; }
echo "Using source: $source\n";

$bridgeCols = detect_table_columns($pdo, $source);
echo "Bridge columns: ".implode(',', array_keys($bridgeCols))."\n";

// Build query like in get_unified_entries
$bWhere = array(); $bParams = array();
if (isset($bridgeCols['is_paid'])) {
    $bWhere[] = "is_paid = 1";
} elseif (isset($bridgeCols['pago_status'])) {
    $bWhere[] = "pago_status IN ('SUCCESS','APROBADO')";
} elseif (isset($bridgeCols['status'])) {
    $bWhere[] = "status IN ('SUCCESS','APROBADO')";
}

if (!empty($mapped) && isset($bridgeCols['event_slug'])) {
    $place = array();
    foreach ($mapped as $i => $s) {
        $ph = ':slug'.$i;
        $place[] = $ph;
        $bParams[$ph] = $s;
    }
    if (!empty($place)) $bWhere[] = "event_slug IN (".implode(',', $place).")";
} else {
    // try str.slug
    try {
        $sstmt = $pdo->prepare("SELECT slug FROM eventos WHERE id = :eid LIMIT 1");
        $sstmt->execute(array(':eid'=>$evento_id));
        $srow = $sstmt->fetch(PDO::FETCH_ASSOC);
        if ($srow && !empty($srow['slug']) && isset($bridgeCols['event_slug'])) {
            $bWhere[] = "event_slug = :eslug";
            $bParams[':eslug'] = $srow['slug'];
        }
    } catch (Exception $e) {}
}

$bSql = "SELECT * FROM $source";
if (!empty($bWhere)) $bSql .= " WHERE ".implode(' AND ', $bWhere);
$bSql .= " ORDER BY id DESC LIMIT 20";

echo "Bridge SQL: $bSql\n";
echo "Bridge Params: ".json_encode($bParams)."\n";

try {
    $bSt = $pdo->prepare($bSql);
    $bSt->execute($bParams);
    $rows = $bSt->fetchAll(PDO::FETCH_ASSOC);
    echo "Bridge rows found: ".count($rows)."\n";
    $i = 0;
    foreach ($rows as $rw) {
        $i++; if ($i>10) break;
        echo "--- row $i ---\n";
        foreach ($rw as $k=>$v) {
            echo "$k: ".(is_null($v)?'NULL':$v)."\n";
        }
    }
} catch (Exception $e) {
    echo "Bridge query error: " . $e->getMessage() . "\n";
}

// Also show get_unified_entries results
$all = get_unified_entries($pdo, $evento_id);
echo "\nget_unified_entries total: ".count($all)."\n";
foreach ($all as $idx => $a) {
    echo "-- unified [$idx] source={$a['source']} id={$a['ticket_id']} ref={$a['ticket_ref']} name={$a['nombre']} paid=".($a['is_paid']?1:0)." checked=".($a['is_checked_in']?1:0)." created_at={$a['created_at']}\n";
}

echo "\nFIN\n";
