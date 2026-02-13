<?php
/**
 * inc/manual_income.php
 * Funciones para gestionar ingresos/egresos manuales por evento
 */

/**
 * Asegura que existe la tabla manual_income
 */
function ensure_manual_income_table($pdo) {
    try {
        // Verificar si existe
        $stmt = $pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='manual_income' LIMIT 1");
        if ($stmt && $stmt->fetch()) {
            // Migraciones ligeras de columnas nuevas
            $cols = $pdo->query("PRAGMA table_info(manual_income)")->fetchAll(PDO::FETCH_ASSOC);
            $colNames = array();
            foreach ($cols as $c) { $colNames[] = $c['name']; }

            if (!in_array('tipo', $colNames, true)) {
                $pdo->exec("ALTER TABLE manual_income ADD COLUMN tipo TEXT DEFAULT 'ingreso'");
            }
            return true;  // tabla ya existe
        }
        
        // Crear tabla
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS manual_income (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                evento_id INTEGER NOT NULL,
                concepto TEXT NOT NULL,
                monto REAL NOT NULL,
                descripcion TEXT,
                tipo TEXT DEFAULT 'ingreso',
                creado_por INTEGER,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
        
        // Crear índice
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_manual_income_evento ON manual_income(evento_id)");
        
        return true;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Registra un nuevo ingreso manual
 */
function add_manual_income($pdo, $evento_id, $concepto, $monto, $descripcion = '', $user_id = null, $tipo = 'ingreso') {
    try {
        ensure_manual_income_table($pdo);

        $tipo = strtolower(trim($tipo));
        if (!in_array($tipo, array('ingreso','egreso'), true)) {
            $tipo = 'ingreso';
        }
        // Guardamos monto con signo según el tipo para mantener el neto coherente
        $montoFirmado = $tipo === 'egreso' ? -abs((float)$monto) : abs((float)$monto);
        
        $stmt = $pdo->prepare("
            INSERT INTO manual_income (evento_id, concepto, monto, descripcion, creado_por, tipo)
            VALUES (:eid, :concepto, :monto, :desc, :user_id, :tipo)
        ");
        
        $stmt->execute(array(
            ':eid'      => (int)$evento_id,
            ':concepto' => trim($concepto),
            ':monto'    => $montoFirmado,
            ':desc'     => trim($descripcion),
            ':user_id'  => $user_id ? (int)$user_id : null,
            ':tipo'     => $tipo,
        ));
        
        return true;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Obtiene lista de ingresos manuales para un evento
 */
function get_manual_incomes($pdo, $evento_id) {
    try {
        ensure_manual_income_table($pdo);
        
        $stmt = $pdo->prepare("
            SELECT * FROM manual_income 
            WHERE evento_id = :eid
            ORDER BY created_at DESC
        ");
        
        $stmt->execute(array(':eid' => (int)$evento_id));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return array();
    }
}

/**
 * Suma total de ingresos manuales para un evento
 */
function get_total_manual_income($pdo, $evento_id) {
    try {
        ensure_manual_income_table($pdo);
        
        $stmt = $pdo->prepare("
            SELECT SUM(monto) as total FROM manual_income 
            WHERE evento_id = :eid
        ");
        
        $stmt->execute(array(':eid' => (int)$evento_id));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row && $row['total'] ? (float)$row['total'] : 0.0;
    } catch (Exception $e) {
        return 0.0;
    }
}

/**
 * Devuelve desglose de ingresos/egresos manuales: totales y cantidad de movimientos
 */
function get_manual_income_breakdown($pdo, $evento_id) {
    $result = array(
        'ingresos' => array('total' => 0.0, 'count' => 0),
        'egresos'  => array('total' => 0.0, 'count' => 0),
        'neto'     => 0.0,
    );

    try {
        ensure_manual_income_table($pdo);
        $stmt = $pdo->prepare("
            SELECT
                SUM(CASE WHEN monto >= 0 THEN monto ELSE 0 END) AS ingresos,
                SUM(CASE WHEN monto < 0 THEN monto ELSE 0 END)  AS egresos,
                SUM(CASE WHEN monto >= 0 THEN 1 ELSE 0 END)     AS cant_ingresos,
                SUM(CASE WHEN monto < 0 THEN 1 ELSE 0 END)      AS cant_egresos
            FROM manual_income
            WHERE evento_id = :eid
        ");
        $stmt->execute(array(':eid' => (int)$evento_id));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $result['ingresos']['total'] = $row['ingresos'] ? (float)$row['ingresos'] : 0.0;
            $result['egresos']['total']  = $row['egresos'] ? (float)$row['egresos'] : 0.0; // ya viene en negativo
            $result['ingresos']['count'] = $row['cant_ingresos'] ? (int)$row['cant_ingresos'] : 0;
            $result['egresos']['count']  = $row['cant_egresos'] ? (int)$row['cant_egresos'] : 0;
            $result['neto'] = $result['ingresos']['total'] + $result['egresos']['total'];
        }
    } catch (Exception $e) {
        // Ignorar
    }

    return $result;
}

/**
 * Elimina un ingreso manual
 */
function delete_manual_income($pdo, $income_id) {
    try {
        $stmt = $pdo->prepare("DELETE FROM manual_income WHERE id = :id");
        $stmt->execute(array(':id' => (int)$income_id));
        return true;
    } catch (Exception $e) {
        return false;
    }
}
?>
