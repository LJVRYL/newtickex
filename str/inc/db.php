<?php
// inc/db.php (PHP5-safe)
function db(){
    static $pdo = null;
    if ($pdo) return $pdo;

    $dbFile = __DIR__ . '/../save_the_rave.sqlite';
    if (!file_exists($dbFile)) {
        die("Base no encontrada: ".$dbFile);
    }

    try {
        $pdo = new PDO('sqlite:' . $dbFile);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Ensure latest columns exist (idempotent)
        try {
            $cols = $pdo->query("PRAGMA table_info(usuarios_admin)")->fetchAll(PDO::FETCH_ASSOC);
            $hasApellido = false;
            foreach ($cols as $c) {
                if (isset($c['name']) && $c['name'] === 'apellido') { $hasApellido = true; break; }
            }
            if (!$hasApellido) {
                $pdo->exec("ALTER TABLE usuarios_admin ADD COLUMN apellido TEXT");
            }

            // staff_eventos: asignación múltiple de staff a eventos
            $pdo->exec("CREATE TABLE IF NOT EXISTS staff_eventos (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              staff_id INTEGER NOT NULL,
              evento_id INTEGER NOT NULL,
              UNIQUE(staff_id, evento_id)
            )");

            // tipos_entrada: visibilidad y fecha de corte
            $colsTe = $pdo->query("PRAGMA table_info(tipos_entrada)")->fetchAll(PDO::FETCH_ASSOC);
            $hasVisTe = false; $hasVentaHastaTe = false;
            foreach ($colsTe as $c) {
                if (isset($c['name']) && $c['name'] === 'visible_publico') { $hasVisTe = true; }
                if (isset($c['name']) && $c['name'] === 'venta_hasta') { $hasVentaHastaTe = true; }
            }
            if (!$hasVisTe) {
                $pdo->exec("ALTER TABLE tipos_entrada ADD COLUMN visible_publico INTEGER DEFAULT 1");
            }
            if (!$hasVentaHastaTe) {
                $pdo->exec("ALTER TABLE tipos_entrada ADD COLUMN venta_hasta TEXT");
            }

            // plantillas_entrada: visibilidad y fecha de corte
            $colsPe = $pdo->query("PRAGMA table_info(plantillas_entrada)")->fetchAll(PDO::FETCH_ASSOC);
            $hasVisPe = false; $hasVentaHastaPe = false;
            foreach ($colsPe as $c) {
                if (isset($c['name']) && $c['name'] === 'visible_publico') { $hasVisPe = true; }
                if (isset($c['name']) && $c['name'] === 'venta_hasta') { $hasVentaHastaPe = true; }
            }
            if (!$hasVisPe) {
                $pdo->exec("ALTER TABLE plantillas_entrada ADD COLUMN visible_publico INTEGER DEFAULT 1");
            }
            if (!$hasVentaHastaPe) {
                $pdo->exec("ALTER TABLE plantillas_entrada ADD COLUMN venta_hasta TEXT");
            }
        } catch (Exception $e) {
            // ignore schema checks errors
        }
    } catch (Exception $ex) {
        die("Error DB: " . htmlspecialchars($ex->getMessage(), ENT_QUOTES, 'UTF-8'));
    }

    return $pdo;
}
