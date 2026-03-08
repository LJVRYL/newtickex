<?php
/**
 * Helpers de venues / lugares
 */

function ensure_venues_table($pdo) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS venues (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nombre TEXT NOT NULL,
            direccion TEXT,
            costo_base REAL DEFAULT 0,
            detalles TEXT,
            created_by_admin_id INTEGER,
            activo INTEGER NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        $cols = array();
        $st = $pdo->query('PRAGMA table_info(venues)');
        if ($st) {
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $ci) {
                if (isset($ci['name'])) $cols[$ci['name']] = true;
            }
        }

        if (!isset($cols['direccion'])) $pdo->exec("ALTER TABLE venues ADD COLUMN direccion TEXT");
        if (!isset($cols['costo_base'])) $pdo->exec("ALTER TABLE venues ADD COLUMN costo_base REAL DEFAULT 0");
        if (!isset($cols['detalles'])) $pdo->exec("ALTER TABLE venues ADD COLUMN detalles TEXT");
        if (!isset($cols['created_by_admin_id'])) $pdo->exec("ALTER TABLE venues ADD COLUMN created_by_admin_id INTEGER");
        if (!isset($cols['activo'])) $pdo->exec("ALTER TABLE venues ADD COLUMN activo INTEGER NOT NULL DEFAULT 1");
        if (!isset($cols['created_at'])) $pdo->exec("ALTER TABLE venues ADD COLUMN created_at DATETIME DEFAULT CURRENT_TIMESTAMP");
        if (!isset($cols['updated_at'])) $pdo->exec("ALTER TABLE venues ADD COLUMN updated_at DATETIME DEFAULT CURRENT_TIMESTAMP");

        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_venues_admin ON venues(created_by_admin_id)");
    } catch (Exception $e) {
        // ignore
    }
}

function ensure_evento_venue_table($pdo) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS evento_venue (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            evento_id INTEGER NOT NULL,
            venue_id INTEGER NOT NULL,
            costo_venue REAL DEFAULT 0,
            comentarios TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(evento_id)
        )");

        $cols = array();
        $st = $pdo->query('PRAGMA table_info(evento_venue)');
        if ($st) {
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $ci) {
                if (isset($ci['name'])) $cols[$ci['name']] = true;
            }
        }

        if (!isset($cols['costo_venue'])) $pdo->exec("ALTER TABLE evento_venue ADD COLUMN costo_venue REAL DEFAULT 0");
        if (!isset($cols['comentarios'])) $pdo->exec("ALTER TABLE evento_venue ADD COLUMN comentarios TEXT");
        if (!isset($cols['updated_at'])) $pdo->exec("ALTER TABLE evento_venue ADD COLUMN updated_at DATETIME DEFAULT CURRENT_TIMESTAMP");

        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_evento_venue_evento ON evento_venue(evento_id)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_evento_venue_venue ON evento_venue(venue_id)");
    } catch (Exception $e) {
        // ignore
    }
}

function get_venues($pdo, $adminId = 0, $isSuper = false) {
    ensure_venues_table($pdo);
    try {
        if ($isSuper) {
            $st = $pdo->query("SELECT * FROM venues WHERE COALESCE(activo,1)=1 ORDER BY nombre ASC, id DESC");
            return $st ? $st->fetchAll(PDO::FETCH_ASSOC) : array();
        }
        $st = $pdo->prepare("SELECT * FROM venues WHERE COALESCE(activo,1)=1 AND COALESCE(created_by_admin_id,0) = :aid ORDER BY nombre ASC, id DESC");
        $st->execute(array(':aid' => (int)$adminId));
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return array();
    }
}

function get_venue_assignment($pdo, $eventoId) {
    ensure_venues_table($pdo);
    ensure_evento_venue_table($pdo);
    if ((int)$eventoId <= 0) return null;
    try {
        $st = $pdo->prepare("SELECT ev.*, v.nombre AS venue_nombre, v.direccion AS venue_direccion, v.detalles AS venue_detalles
            FROM evento_venue ev
            LEFT JOIN venues v ON v.id = ev.venue_id
            WHERE ev.evento_id = :eid
            LIMIT 1");
        $st->execute(array(':eid' => (int)$eventoId));
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ? $row : null;
    } catch (Exception $e) {
        return null;
    }
}

function assign_venue_to_event($pdo, $eventoId, $venueId, $costoVenue, $comentarios) {
    ensure_evento_venue_table($pdo);
    if ((int)$eventoId <= 0 || (int)$venueId <= 0) return false;
    try {
        $st = $pdo->prepare("INSERT OR REPLACE INTO evento_venue (evento_id, venue_id, costo_venue, comentarios, updated_at)
            VALUES (:eid, :vid, :c, :m, datetime('now'))");
        $st->execute(array(
            ':eid' => (int)$eventoId,
            ':vid' => (int)$venueId,
            ':c' => (float)$costoVenue,
            ':m' => (string)$comentarios,
        ));
        return true;
    } catch (Exception $e) {
        return false;
    }
}

function get_venue_cost_by_event($pdo, $eventoId) {
    ensure_evento_venue_table($pdo);
    if ((int)$eventoId <= 0) return 0;
    try {
        $st = $pdo->prepare("SELECT COALESCE(costo_venue,0) FROM evento_venue WHERE evento_id = :eid LIMIT 1");
        $st->execute(array(':eid' => (int)$eventoId));
        $v = $st->fetchColumn();
        return $v ? (float)$v : 0;
    } catch (Exception $e) {
        return 0;
    }
}
