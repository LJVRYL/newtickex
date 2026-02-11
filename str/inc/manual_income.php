<?php
/**
 * inc/manual_income.php
 * Funciones para gestionar ingresos manuales por evento
 */

/**
 * Asegura que existe la tabla manual_income
 */
function ensure_manual_income_table($pdo) {
    try {
        // Verificar si existe
        $stmt = $pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='manual_income' LIMIT 1");
        if ($stmt && $stmt->fetch()) {
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
function add_manual_income($pdo, $evento_id, $concepto, $monto, $descripcion = '', $user_id = null) {
    try {
        ensure_manual_income_table($pdo);
        
        $stmt = $pdo->prepare("
            INSERT INTO manual_income (evento_id, concepto, monto, descripcion, creado_por)
            VALUES (:eid, :concepto, :monto, :desc, :user_id)
        ");
        
        $stmt->execute(array(
            ':eid'      => (int)$evento_id,
            ':concepto' => trim($concepto),
            ':monto'    => (float)$monto,
            ':desc'     => trim($descripcion),
            ':user_id'  => $user_id ? (int)$user_id : null,
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
