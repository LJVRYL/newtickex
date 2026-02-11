<?php
require_once __DIR__ . '/inc/bootstrap.php';
$pdo = db();

$check = array('v_senforms_bridge_status','senforms_bridge_tickets');
foreach ($check as $t) {
    echo "<h3>Objeto: $t</h3>\n";
    try {
        $st = $pdo->query("SELECT 1 FROM sqlite_master WHERE (type='table' OR type='view') AND name='$t' LIMIT 1");
        if ($st && $st->fetch()) {
            echo "<pre>\n";
            $cols = $pdo->query("PRAGMA table_info($t)")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($cols as $c) {
                echo "- " . $c['name'] . " (" . $c['type'] . ")\n";
            }
            echo "</pre>\n";
            // show sample rows
            echo "<h4>Ejemplos</h4><pre>\n";
            $rs = $pdo->query("SELECT * FROM $t LIMIT 5");
            if ($rs) {
                while($r = $rs->fetch(PDO::FETCH_ASSOC)) {
                    echo json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n---\n";
                }
            }
            echo "</pre>\n";
        } else {
            echo "(no existe)\n";
        }
    } catch (Exception $e) {
        echo "Error: " . htmlspecialchars($e->getMessage(), ENT_QUOTES) . "\n";
    }
}
?>