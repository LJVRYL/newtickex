<?php
/**
 * Helpers de producción / artística
 */

function ensure_produccion_table($pdo) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS produccion_artistas (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nombre TEXT NOT NULL,
            tipo TEXT,
            categoria TEXT,
            precio REAL DEFAULT 0,
            origen TEXT,
            pide_viaticos INTEGER DEFAULT 0,
            notas TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        $cols = array();
        $st = $pdo->query('PRAGMA table_info(produccion_artistas)');
        if ($st) {
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $ci) { $cols[$ci['name']] = true; }
        }
        if (!isset($cols['origen'])) {
            $pdo->exec("ALTER TABLE produccion_artistas ADD COLUMN origen TEXT");
        }
        if (!isset($cols['pide_viaticos'])) {
            $pdo->exec("ALTER TABLE produccion_artistas ADD COLUMN pide_viaticos INTEGER DEFAULT 0");
        }
        if (!isset($cols['precio'])) {
            $pdo->exec("ALTER TABLE produccion_artistas ADD COLUMN precio REAL DEFAULT 0");
        }
    } catch (Exception $e) {
        // ignorar
    }
}

function ensure_produccion_assignment_table($pdo) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS produccion_evento (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            evento_id INTEGER NOT NULL,
            artista_id INTEGER NOT NULL,
            precio REAL DEFAULT 0,
            notas TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_prodev_evento ON produccion_evento(evento_id)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_prodev_artista ON produccion_evento(artista_id)");
    } catch (Exception $e) {
        // ignorar
    }
}

function get_produccion_artistas($pdo) {
    ensure_produccion_table($pdo);
    try {
        $st = $pdo->query("SELECT * FROM produccion_artistas ORDER BY nombre ASC");
        return $st ? $st->fetchAll(PDO::FETCH_ASSOC) : array();
    } catch (Exception $e) {
        return array();
    }
}

function add_produccion_assignment($pdo, $eventoId, $artistaId, $precio = null, $notas = '') {
    ensure_produccion_assignment_table($pdo);
    ensure_produccion_table($pdo);
    if ($eventoId <= 0 || $artistaId <= 0) return false;
    try {
        if ($precio === null) {
            $ps = $pdo->prepare("SELECT precio FROM produccion_artistas WHERE id = :id");
            $ps->execute(array(':id'=>$artistaId));
            $precio = (float)$ps->fetchColumn();
        }
        $stmt = $pdo->prepare("INSERT INTO produccion_evento (evento_id, artista_id, precio, notas) VALUES (:e,:a,:p,:n)");
        $stmt->execute(array(
            ':e' => $eventoId,
            ':a' => $artistaId,
            ':p' => $precio,
            ':n' => $notas,
        ));
        return true;
    } catch (Exception $e) {
        return false;
    }
}

function delete_produccion_assignment($pdo, $assignmentId, $eventoId) {
    ensure_produccion_assignment_table($pdo);
    if ($assignmentId <= 0 || $eventoId <= 0) return false;
    try {
        $stmt = $pdo->prepare("DELETE FROM produccion_evento WHERE id = :id AND evento_id = :e");
        $stmt->execute(array(':id'=>$assignmentId, ':e'=>$eventoId));
        return true;
    } catch (Exception $e) {
        return false;
    }
}

function get_produccion_assignments($pdo, $eventoId) {
    ensure_produccion_assignment_table($pdo);
    ensure_produccion_table($pdo);
    if ($eventoId <= 0) return array();
    try {
        $stmt = $pdo->prepare("SELECT pe.*, pa.nombre, pa.tipo, pa.categoria FROM produccion_evento pe LEFT JOIN produccion_artistas pa ON pa.id = pe.artista_id WHERE pe.evento_id = :e ORDER BY pe.id DESC");
        $stmt->execute(array(':e'=>$eventoId));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return array();
    }
}

function get_artist_cost_by_event($pdo, $eventoId) {
    ensure_produccion_assignment_table($pdo);
    if ($eventoId <= 0) return 0;
    try {
        $stmt = $pdo->prepare("SELECT SUM(COALESCE(precio,0)) FROM produccion_evento WHERE evento_id = :e");
        $stmt->execute(array(':e'=>$eventoId));
        $val = $stmt->fetchColumn();
        return $val ? (float)$val : 0;
    } catch (Exception $e) {
        return 0;
    }
}

function get_artist_cost_total($pdo) {
    ensure_produccion_assignment_table($pdo);
    try {
        $stmt = $pdo->query("SELECT SUM(COALESCE(precio,0)) FROM produccion_evento");
        $val = $stmt ? $stmt->fetchColumn() : 0;
        return $val ? (float)$val : 0;
    } catch (Exception $e) {
        return 0;
    }
}
