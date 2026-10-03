<?php

function tickex_mobile_inventory_super($admin)
{
    return in_array(isset($admin['tipo_global']) ? $admin['tipo_global'] : '', array('super_admin', 'superadmin'), true);
}

function tickex_mobile_inventory_allowed($admin)
{
    return tickex_mobile_inventory_super($admin) || (isset($admin['tipo_global']) && $admin['tipo_global'] === 'admin_evento');
}

function tickex_mobile_inventory_tables($pdo, $create)
{
    $exists = $pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='inv_items' LIMIT 1")->fetchColumn();
    if ($exists || !$create) return (bool)$exists;
    $pdo->exec("CREATE TABLE IF NOT EXISTS inv_items (id INTEGER PRIMARY KEY AUTOINCREMENT, codigo TEXT NOT NULL UNIQUE, nombre TEXT NOT NULL, descripcion TEXT, dueno TEXT, estado TEXT DEFAULT 'OK', owner_admin_id INTEGER, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS inv_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, item_id INTEGER NOT NULL, nota TEXT, foto_path TEXT, estado TEXT, created_by INTEGER, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_inv_items_owner ON inv_items(owner_admin_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_inv_logs_item ON inv_logs(item_id)');
    return true;
}

function tickex_mobile_inventory_list($pdo, $admin, $query, $status)
{
    if (!tickex_mobile_inventory_allowed($admin)) return array('error' => 'forbidden');
    if (!tickex_mobile_inventory_tables($pdo, false)) return array('items' => array());
    $query = trim((string)$query);
    $status = trim((string)$status);
    if (strlen($query) > 80 || strlen($status) > 40) return array('error' => 'invalid_filter');
    $where = array(); $params = array();
    if (!tickex_mobile_inventory_super($admin)) { $where[] = 'owner_admin_id = :owner'; $params[':owner'] = (int)$admin['id']; }
    if ($query !== '') { $where[] = '(nombre LIKE :q OR descripcion LIKE :q OR dueno LIKE :q OR codigo LIKE :q)'; $params[':q'] = '%' . $query . '%'; }
    if ($status !== '') { $where[] = 'estado = :status'; $params[':status'] = $status; }
    $sql = 'SELECT id, codigo, nombre, descripcion, dueno, estado, owner_admin_id, created_at FROM inv_items' . (count($where) ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY created_at DESC, id DESC LIMIT 200';
    $stmt = $pdo->prepare($sql); $stmt->execute($params);
    return array('items' => $stmt->fetchAll(PDO::FETCH_ASSOC));
}

function tickex_mobile_inventory_detail($pdo, $admin, $itemId)
{
    if (!tickex_mobile_inventory_allowed($admin)) return array('error' => 'forbidden');
    if (!tickex_mobile_inventory_tables($pdo, false)) return array('error' => 'not_found');
    $sql = 'SELECT id, codigo, nombre, descripcion, dueno, estado, owner_admin_id, created_at FROM inv_items WHERE id = :id' . (tickex_mobile_inventory_super($admin) ? '' : ' AND owner_admin_id = :owner') . ' LIMIT 1';
    $stmt = $pdo->prepare($sql); $params = array(':id' => (int)$itemId);
    if (!tickex_mobile_inventory_super($admin)) $params[':owner'] = (int)$admin['id'];
    $stmt->execute($params); $item = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$item) return array('error' => 'not_found');
    $logs = $pdo->prepare('SELECT id, item_id, nota, foto_path, estado, created_by, created_at FROM inv_logs WHERE item_id = :id ORDER BY created_at DESC, id DESC LIMIT 100');
    $logs->execute(array(':id' => (int)$itemId));
    return array('item' => $item, 'logs' => $logs->fetchAll(PDO::FETCH_ASSOC));
}

function tickex_mobile_inventory_create($pdo, $admin, $body)
{
    if (!tickex_mobile_inventory_allowed($admin)) return array('error' => 'forbidden');
    $name = isset($body['name']) ? trim((string)$body['name']) : '';
    $description = isset($body['description']) ? trim((string)$body['description']) : '';
    $owner = isset($body['owner']) ? trim((string)$body['owner']) : '';
    $status = isset($body['status']) ? trim((string)$body['status']) : 'OK';
    if ($name === '' || strlen($name) > 120 || strlen($description) > 1000 || strlen($owner) > 120 || $status === '' || strlen($status) > 40) return array('error' => 'invalid_item');
    tickex_mobile_inventory_tables($pdo, true);
    $bytes = function_exists('random_bytes') ? random_bytes(3) : openssl_random_pseudo_bytes(3);
    $code = 'ITM-' . strtoupper(bin2hex($bytes));
    $stmt = $pdo->prepare('INSERT INTO inv_items (codigo, nombre, descripcion, dueno, estado, owner_admin_id) VALUES (:code, :name, :description, :owner, :status, :admin)');
    $stmt->execute(array(':code' => $code, ':name' => $name, ':description' => $description, ':owner' => $owner, ':status' => $status, ':admin' => (int)$admin['id']));
    return tickex_mobile_inventory_detail($pdo, $admin, $pdo->lastInsertId());
}

function tickex_mobile_inventory_add_log($pdo, $admin, $itemId, $body)
{
    $detail = tickex_mobile_inventory_detail($pdo, $admin, $itemId);
    if (isset($detail['error'])) return $detail;
    $note = isset($body['note']) ? trim((string)$body['note']) : '';
    $status = isset($body['status']) ? trim((string)$body['status']) : '';
    if (($note === '' && $status === '') || strlen($note) > 1000 || strlen($status) > 40) return array('error' => 'invalid_log');
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('INSERT INTO inv_logs (item_id, nota, foto_path, estado, created_by) VALUES (:item, :note, :photo, :status, :admin)');
        $stmt->execute(array(':item' => (int)$itemId, ':note' => $note, ':photo' => '', ':status' => $status, ':admin' => (int)$admin['id']));
        if ($status !== '') {
            $update = $pdo->prepare('UPDATE inv_items SET estado = :status WHERE id = :item');
            $update->execute(array(':status' => $status, ':item' => (int)$itemId));
        }
        $pdo->commit();
    } catch (Exception $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
    return tickex_mobile_inventory_detail($pdo, $admin, $itemId);
}
