<?php
require_once __DIR__ . '/staff_roles.php';
require_once __DIR__ . '/staff_operations.php';
// Producer mobile credentials are verified against the web admin account, never a consumer email alone.
require_once __DIR__ . '/event_sales_control.php';
function tickex_mobile_admin_login($pdo, $email, $password, $remoteAddress)
{
    $email = strtolower(trim((string)$email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254 || !is_string($password) || $password === '') return null;
    $fingerprint = hash('sha256', 'admin|' . $email . '|' . (string)$remoteAddress);
    $cutoff = date('Y-m-d H:i:s', time() - 900);
    $rate = $pdo->prepare('SELECT COUNT(*) FROM mobile_login_attempts WHERE fingerprint = :fingerprint AND attempted_at >= :cutoff');
    $rate->execute(array(':fingerprint' => $fingerprint, ':cutoff' => $cutoff));
    if ((int)$rate->fetchColumn() >= 5) return null;
    $attempt = $pdo->prepare('INSERT INTO mobile_login_attempts (fingerprint, attempted_at) VALUES (:fingerprint, :now)');
    $attempt->execute(array(':fingerprint' => $fingerprint, ':now' => date('Y-m-d H:i:s')));
    $blocked = $pdo->prepare('SELECT 1 FROM user_blocks WHERE active = 1 AND lower(email) = :email LIMIT 1');
    $blocked->execute(array(':email' => $email));
    if ($blocked->fetchColumn()) return null;
    $statement = $pdo->prepare("SELECT a.id, a.email, a.nombre, a.apellido, a.tipo_global, a.password AS password_hash FROM usuarios_admin a WHERE lower(a.email) = :email AND a.activo = 1 AND a.tipo_global IN ('admin_evento', 'super_admin') LIMIT 1");
    $statement->execute(array(':email' => $email));
    $admin = $statement->fetch(PDO::FETCH_ASSOC);
    if (!$admin || strpos((string)$admin['password_hash'], '$2') !== 0 || !password_verify($password, $admin['password_hash'])) return null;
    $token = tickex_mobile_random_token();
    $now = date('Y-m-d H:i:s'); $expires = date('Y-m-d H:i:s', time() + 86400);
    $insert = $pdo->prepare('INSERT INTO mobile_admin_tokens (token_hash, admin_id, created_at, expires_at) VALUES (:hash, :admin, :now, :expires)');
    $insert->execute(array(':hash' => hash('sha256', $token), ':admin' => (int)$admin['id'], ':now' => $now, ':expires' => $expires));
    return array('accessToken' => $token, 'expiresAt' => tickex_mobile_datetime($expires), 'profile' => tickex_mobile_admin_profile($admin));
}
function tickex_mobile_admin_profile($admin)
{
    return array('id' => (string)$admin['id'], 'name' => trim((string)$admin['nombre'] . ' ' . (string)$admin['apellido']), 'email' => (string)$admin['email'], 'role' => (string)$admin['tipo_global']);
}
function tickex_mobile_authenticated_admin($pdo, $token)
{
    if ($token === null) return null;
    $statement = $pdo->prepare("SELECT a.id, a.email, a.nombre, a.apellido, a.tipo_global FROM mobile_admin_tokens t JOIN usuarios_admin a ON a.id = t.admin_id WHERE t.token_hash = :hash AND t.revoked_at IS NULL AND t.expires_at > :now AND a.activo = 1 AND a.tipo_global IN ('admin_evento', 'super_admin') LIMIT 1");
    $statement->execute(array(':hash' => hash('sha256', $token), ':now' => date('Y-m-d H:i:s')));
    $admin = $statement->fetch(PDO::FETCH_ASSOC);
    if (!$admin) return null;
    $blocked = $pdo->prepare('SELECT 1 FROM user_blocks WHERE active = 1 AND lower(email) = lower(:email) LIMIT 1');
    $blocked->execute(array(':email' => $admin['email']));
    return $blocked->fetchColumn() ? null : $admin;
}
function tickex_mobile_admin_logout($pdo, $token)
{
    if ($token === null) return;
    $statement = $pdo->prepare('UPDATE mobile_admin_tokens SET revoked_at = :now WHERE token_hash = :hash AND revoked_at IS NULL');
    $statement->execute(array(':now' => date('Y-m-d H:i:s'), ':hash' => hash('sha256', $token)));
}
function tickex_mobile_admin_can_event($pdo, $admin, $eventId)
{
    if ($eventId <= 0) return false;
    $statement = $pdo->prepare("SELECT 1 FROM eventos WHERE id = :event AND (borrado_en IS NULL OR borrado_en = '') AND (:super = 1 OR creado_por_admin_id = :admin) LIMIT 1");
    $statement->bindValue(':event', (int)$eventId, PDO::PARAM_INT);
    $statement->bindValue(':super', $admin['tipo_global'] === 'super_admin' ? 1 : 0, PDO::PARAM_INT);
    $statement->bindValue(':admin', (int)$admin['id'], PDO::PARAM_INT);
    $statement->execute();
    return (bool)$statement->fetchColumn();
}
function tickex_mobile_admin_events($pdo, $admin)
{
    $sql = "SELECT id, nombre, fecha_desde, fecha_hasta, lugar, publicado_site FROM eventos WHERE (borrado_en IS NULL OR borrado_en = '')";
    if ($admin['tipo_global'] !== 'super_admin') $sql .= ' AND creado_por_admin_id = :admin';
    $sql .= ' ORDER BY fecha_desde DESC, id DESC LIMIT 100';
    $statement = $pdo->prepare($sql);
    if ($admin['tipo_global'] !== 'super_admin') $statement->bindValue(':admin', (int)$admin['id'], PDO::PARAM_INT);
    $statement->execute();
    $out = array();
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) $out[] = array('id' => (string)$row['id'], 'name' => (string)$row['nombre'], 'startsAt' => tickex_mobile_datetime($row['fecha_desde']), 'endsAt' => tickex_mobile_datetime($row['fecha_hasta']), 'venue' => (string)$row['lugar'], 'published' => (int)$row['publicado_site'] === 1);
    return $out;
}
function tickex_mobile_admin_event_detail($pdo, $admin, $eventId)
{
    if (!tickex_mobile_admin_can_event($pdo, $admin, $eventId)) return null;
    $read = $pdo->prepare('SELECT id, nombre, slug, descripcion, fecha_desde, fecha_hasta, lugar, publicado_site FROM eventos WHERE id = :event LIMIT 1');
    $read->execute(array(':event' => $eventId));
    $row = $read->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    return array(
        'id' => (string)$row['id'], 'name' => (string)$row['nombre'], 'slug' => (string)$row['slug'],
        'description' => (string)$row['descripcion'], 'startsAt' => tickex_mobile_datetime($row['fecha_desde']),
        'endsAt' => tickex_mobile_datetime($row['fecha_hasta']), 'venue' => (string)$row['lugar'],
        'published' => (int)$row['publicado_site'] === 1,
    );
}
function tickex_mobile_admin_event_fields($body, $creating)
{
    $name = isset($body['name']) ? trim((string)$body['name']) : '';
    $slug = isset($body['slug']) ? trim((string)$body['slug']) : '';
    $description = isset($body['description']) ? trim((string)$body['description']) : '';
    $venue = isset($body['venue']) ? trim((string)$body['venue']) : '';
    $start = isset($body['startsAt']) ? trim((string)$body['startsAt']) : '';
    $end = isset($body['endsAt']) ? trim((string)$body['endsAt']) : '';
    if ($name === '' || strlen($name) > 160 || strlen($slug) > 100 || ($creating && ($slug === '' || !preg_match('/^[a-z0-9-]+$/', $slug))) || strlen($description) > 6000 || strlen($venue) > 180) return null;
    if (preg_match('/[\x00-\x1F\x7F]/', $name . $slug . $venue)) return null;
    foreach (array($start, $end) as $dateValue) if ($dateValue !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}(?:[T ]\d{2}:\d{2}(?::\d{2})?(?:Z|[+-]\d{2}:\d{2})?)?$/', $dateValue)) return null;
    if ($start !== '' && strtotime($start) === false) return null;
    if ($end !== '' && strtotime($end) === false) return null;
    if ($start !== '' && $end !== '' && strtotime($end) < strtotime($start)) return null;
    return array(
        ':name' => $name, ':description' => $description !== '' ? $description : null,
        ':start' => $start !== '' ? date('Y-m-d H:i:s', strtotime($start)) : null,
        ':end' => $end !== '' ? date('Y-m-d H:i:s', strtotime($end)) : null,
        ':venue' => $venue,
        ':slug' => $slug,
    );
}
function tickex_mobile_admin_create_event($pdo, $admin, $body)
{
    $fields = tickex_mobile_admin_event_fields($body, true);
    if ($fields === null) return array('error' => 'invalid_event');
    $check = $pdo->prepare('SELECT 1 FROM eventos WHERE slug = :slug LIMIT 1');
    $check->execute(array(':slug' => $fields[':slug']));
    if ($check->fetchColumn()) return array('error' => 'slug_in_use');
    $insert = $pdo->prepare("INSERT INTO eventos (nombre, slug, descripcion, fecha_desde, fecha_hasta, lugar, creado_por_admin_id, creado_en, publicado_site) VALUES (:name, :slug, :description, :start, :end, :venue, :admin, datetime('now'), 0)");
    $fields[':admin'] = (int)$admin['id'];
    $insert->execute($fields);
    return tickex_mobile_admin_event_detail($pdo, $admin, (int)$pdo->lastInsertId());
}
function tickex_mobile_admin_edit_event($pdo, $admin, $eventId, $body)
{
    $current = tickex_mobile_admin_event_detail($pdo, $admin, $eventId);
    if ($current === null) return array('error' => 'not_found');
    $body['slug'] = $current['slug'];
    $fields = tickex_mobile_admin_event_fields($body, false);
    if ($fields === null) return array('error' => 'invalid_event');
    unset($fields[':slug']);
    $fields[':event'] = $eventId;
    $fields[':admin'] = (int)$admin['id'];
    $fields[':super'] = $admin['tipo_global'] === 'super_admin' ? 1 : 0;
    $update = $pdo->prepare("UPDATE eventos SET nombre = :name, descripcion = :description, fecha_desde = :start, fecha_hasta = :end, lugar = :venue, actualizado_en = CURRENT_TIMESTAMP WHERE id = :event AND (borrado_en IS NULL OR borrado_en = '') AND (CAST(:super AS INTEGER) = 1 OR creado_por_admin_id = :admin)");
    $update->execute($fields);
    return tickex_mobile_admin_event_detail($pdo, $admin, $eventId);
}
function tickex_mobile_admin_set_publication($pdo, $admin, $eventId, $published)
{
    if (!tickex_mobile_admin_can_event($pdo, $admin, $eventId)) return array('error' => 'forbidden');
    if (!is_bool($published)) return array('error' => 'invalid_state');
    if ($published) {
        $event = tickex_mobile_admin_event_detail($pdo, $admin, $eventId);
        if (!$event || trim((string)$event['name']) === '' || !$event['startsAt'] || !$event['endsAt'] || trim((string)$event['venue']) === '') return array('error' => 'event_incomplete');
        $columns = array();
        foreach ($pdo->query('PRAGMA table_info(eventos)')->fetchAll(PDO::FETCH_ASSOC) as $column) $columns[(string)$column['name']] = true;
        if (!isset($columns['capacidad_total']) || !isset($columns['hora_puertas'])) return array('error' => 'event_incomplete');
        $read = $pdo->prepare('SELECT capacidad_total, hora_puertas FROM eventos WHERE id = :event LIMIT 1');
        $read->execute(array(':event' => $eventId));
        $public = $read->fetch(PDO::FETCH_ASSOC);
        if (!$public || (int)$public['capacidad_total'] < 1 || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', (string)$public['hora_puertas'])) return array('error' => 'event_incomplete');
        require_once __DIR__ . '/event_capacity.php';
        if ((int)$public['capacidad_total'] < tickex_event_capacity_issued($pdo, $eventId)) return array('error' => 'capacity_below_issued');
        $ready = $pdo->prepare("SELECT 1 FROM tipos_entrada WHERE evento_id = :event AND visible_publico = 1 AND cantidad_disponible > 0 AND precio > 0 AND UPPER(tipo) <> 'PUERTA' LIMIT 1");
        $ready->execute(array(':event' => $eventId));
        $hasPaid = (bool)$ready->fetchColumn();
        $hasFree = false;
        if (!$hasPaid && $pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'event_free_checkout_configs' LIMIT 1")->fetchColumn()) {
            $typeColumns = array();
            foreach ($pdo->query('PRAGMA table_info(tipos_entrada)')->fetchAll(PDO::FETCH_ASSOC) as $column) $typeColumns[(string)$column['name']] = true;
            $free = $pdo->prepare("SELECT 1 FROM event_free_checkout_configs c JOIN tipos_entrada t ON t.id = c.ticket_type_id AND t.evento_id = c.evento_id WHERE c.evento_id = :event AND c.enabled = 1 AND UPPER(t.tipo) = 'FREE' AND t.precio = 0" . (isset($typeColumns['activo']) ? ' AND t.activo = 1' : '') . ' AND t.cantidad_disponible > 0 LIMIT 1');
            $free->execute(array(':event' => $eventId));
            $hasFree = (bool)$free->fetchColumn();
        }
        if (!$hasPaid && !$hasFree) return array('error' => 'no_public_tickets');
    }
    $update = $pdo->prepare("UPDATE eventos SET publicado_site = :published, actualizado_en = CURRENT_TIMESTAMP WHERE id = :event AND (borrado_en IS NULL OR borrado_en = '') AND (CAST(:super AS INTEGER) = 1 OR creado_por_admin_id = :admin)");
    $update->execute(array(':published' => $published ? 1 : 0, ':event' => $eventId, ':super' => $admin['tipo_global'] === 'super_admin' ? 1 : 0, ':admin' => (int)$admin['id']));
    return tickex_mobile_admin_event_detail($pdo, $admin, $eventId);
}
function tickex_mobile_admin_update_venue($pdo, $admin, $eventId, $venue)
{
    if (!is_string($venue)) return null;
    $venue = trim($venue);
    if ($eventId <= 0 || $venue === '' || strlen($venue) > 180 || preg_match('/[\x00-\x1F\x7F]/', $venue)) return null;
    $update = $pdo->prepare("UPDATE eventos SET lugar = :venue, actualizado_en = :now WHERE id = :event AND (borrado_en IS NULL OR borrado_en = '') AND (CAST(:super AS INTEGER) = 1 OR creado_por_admin_id = :admin)");
    $update->execute(array(':venue' => $venue, ':now' => date('Y-m-d H:i:s'), ':event' => $eventId, ':super' => $admin['tipo_global'] === 'super_admin' ? 1 : 0, ':admin' => (int)$admin['id']));
    if ($update->rowCount() < 1) return null;
    $read = $pdo->prepare('SELECT id, nombre, fecha_desde, fecha_hasta, lugar, publicado_site FROM eventos WHERE id = :event');
    $read->execute(array(':event' => $eventId));
    $row = $read->fetch(PDO::FETCH_ASSOC);
    return array('id' => (string)$row['id'], 'name' => (string)$row['nombre'], 'startsAt' => tickex_mobile_datetime($row['fecha_desde']), 'endsAt' => tickex_mobile_datetime($row['fecha_hasta']), 'venue' => (string)$row['lugar'], 'published' => (int)$row['publicado_site'] === 1);
}
function tickex_mobile_admin_dashboard($pdo, $eventId)
{
    $tickets = $pdo->prepare('SELECT COUNT(*) AS total, SUM(CASE WHEN checked_in = 1 THEN 1 ELSE 0 END) AS used FROM entradas WHERE evento_id = :event');
    $tickets->execute(array(':event' => $eventId));
    $t = $tickets->fetch(PDO::FETCH_ASSOC);
    $orders = $pdo->prepare("SELECT COUNT(*) AS total, SUM(CASE WHEN payment_status = 'confirmed' THEN 1 ELSE 0 END) AS confirmed, SUM(CASE WHEN payment_status = 'confirmed' THEN amount ELSE 0 END) AS gross, SUM(CASE WHEN payment_status = 'pending' AND payment_provider = 'mercadopago' THEN 1 ELSE 0 END) AS pending_mp FROM tc_orders WHERE evento_id = :event");
    $orders->execute(array(':event' => $eventId));
    $o = $orders->fetch(PDO::FETCH_ASSOC);
    return array('eventId' => (string)$eventId, 'tickets' => (int)$t['total'], 'checkedIn' => (int)$t['used'], 'orders' => (int)$o['total'], 'confirmedOrders' => (int)$o['confirmed'], 'confirmedGross' => (float)$o['gross'], 'pendingPaymentOrders' => (int)$o['pending_mp'], 'salesPaused' => tickex_event_sales_paused($pdo, $eventId));
}
function tickex_mobile_admin_sales($pdo, $eventId)
{
    $statement = $pdo->prepare('SELECT id, amount, payment_status, processing_status, created_at FROM tc_orders WHERE evento_id = :event ORDER BY id DESC LIMIT 50');
    $statement->execute(array(':event' => $eventId));
    $out = array();
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) $out[] = array('orderId' => (string)$row['id'], 'amount' => (float)$row['amount'], 'payment' => (string)$row['payment_status'], 'issuance' => (string)$row['processing_status'], 'createdAt' => tickex_mobile_datetime($row['created_at']));
    return $out;
}
function tickex_mobile_admin_ticket_types($pdo, $eventId)
{
    $statement = $pdo->prepare('SELECT id, nombre, precio, cantidad_total, cantidad_disponible, visible_publico, qr_quantity FROM tipos_entrada WHERE evento_id = :event ORDER BY id');
    $statement->execute(array(':event' => $eventId));
    $out = array();
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) $out[] = array('id' => (string)$row['id'], 'name' => (string)$row['nombre'], 'price' => (float)$row['precio'], 'total' => $row['cantidad_total'] === null ? null : (int)$row['cantidad_total'], 'available' => $row['cantidad_disponible'] === null ? null : (int)$row['cantidad_disponible'], 'visible' => (int)$row['visible_publico'] === 1, 'qrQuantity' => isset($row['qr_quantity']) ? max(1, (int)$row['qr_quantity']) : 1);
    return $out;
}
function tickex_mobile_admin_create_ticket_type($pdo, $admin, $eventId, $body)
{
    if (!tickex_mobile_admin_can_event($pdo, $admin, $eventId)) return array('error' => 'forbidden');
    $name = isset($body['name']) ? trim((string)$body['name']) : '';
    $channel = isset($body['channel']) ? (string)$body['channel'] : '';
    $price = isset($body['price']) ? filter_var($body['price'], FILTER_VALIDATE_INT, array('options' => array('min_range' => 0, 'max_range' => 100000000))) : false;
    $quantity = isset($body['quantity']) ? filter_var($body['quantity'], FILTER_VALIDATE_INT, array('options' => array('min_range' => 1, 'max_range' => 1000000))) : false;
    $qrQuantity = isset($body['qrQuantity']) ? filter_var($body['qrQuantity'], FILTER_VALIDATE_INT, array('options' => array('min_range' => 1, 'max_range' => 10))) : false;
    if ($name === '' || strlen($name) > 120 || preg_match('/[\x00-\x1F\x7F]/', $name) || !in_array($channel, array('public', 'door', 'free'), true) || $price === false || ($channel === 'public' && $price <= 0) || ($channel === 'free' && $price !== 0) || $quantity === false || $qrQuantity === false || (in_array($channel, array('door', 'free'), true) && $qrQuantity !== 1)) return array('error' => 'invalid_type');
    $columns = array();
    foreach ($pdo->query('PRAGMA table_info(tipos_entrada)')->fetchAll(PDO::FETCH_ASSOC) as $column) $columns[(string)$column['name']] = true;
    foreach (array('visible_publico', 'qr_quantity') as $required) if (!isset($columns[$required])) return array('error' => 'schema_incomplete');
    $type = $channel === 'door' ? 'PUERTA' : ($channel === 'free' ? 'FREE' : 'PAGA');
    $values = array('evento_id' => $eventId, 'nombre' => $name, 'tipo' => $type, 'precio' => $price, 'cantidad_total' => $quantity, 'cantidad_disponible' => $quantity, 'visible_publico' => $channel === 'public' ? 1 : 0, 'qr_quantity' => $qrQuantity);
    if (isset($columns['tipo_venta'])) $values['tipo_venta'] = $type;
    if (isset($columns['activo'])) $values['activo'] = 1;
    $names = array_keys($values);
    $markers = array(); $params = array();
    foreach ($names as $key) { $markers[] = ':' . $key; $params[':' . $key] = $values[$key]; }
    $insert = $pdo->prepare('INSERT INTO tipos_entrada (' . implode(',', $names) . ') VALUES (' . implode(',', $markers) . ')');
    $insert->execute($params);
    return tickex_mobile_admin_ticket_types($pdo, $eventId);
}
function tickex_mobile_admin_update_ticket_type($pdo, $admin, $eventId, $typeId, $body)
{
    if (!tickex_mobile_admin_can_event($pdo, $admin, $eventId)) return array('error' => 'forbidden');
    if ($typeId <= 0 || !isset($body['expectedAvailable']) || !isset($body['available']) || !isset($body['visible']) || !is_bool($body['visible'])) return array('error' => 'invalid_type');
    $expected = filter_var($body['expectedAvailable'], FILTER_VALIDATE_INT, array('options' => array('min_range' => 0, 'max_range' => 1000000)));
    $available = filter_var($body['available'], FILTER_VALIDATE_INT, array('options' => array('min_range' => 0, 'max_range' => 1000000)));
    if ($expected === false || $available === false) return array('error' => 'invalid_stock');
    $pdo->beginTransaction();
    try {
        $read = $pdo->prepare('SELECT cantidad_disponible, qr_quantity, tipo FROM tipos_entrada WHERE id = :type AND evento_id = :event LIMIT 1');
        $read->execute(array(':type' => $typeId, ':event' => $eventId));
        $current = $read->fetch(PDO::FETCH_ASSOC);
        if (!$current) { $pdo->rollBack(); return array('error' => 'not_found'); }
        if ((int)$current['cantidad_disponible'] !== $expected) { $pdo->rollBack(); return array('error' => 'stock_changed'); }
        $qrQuantity = array_key_exists('qrQuantity', $body) ? filter_var($body['qrQuantity'], FILTER_VALIDATE_INT, array('options' => array('min_range' => 1, 'max_range' => 10))) : (int)$current['qr_quantity'];
        $specialType = in_array(strtoupper((string)$current['tipo']), array('PUERTA', 'FREE'), true);
        if ($qrQuantity === false || ($specialType && ($qrQuantity !== 1 || $body['visible']))) { $pdo->rollBack(); return array('error' => 'invalid_type'); }
        $change = $available - $expected;
        $write = $pdo->prepare('UPDATE tipos_entrada SET cantidad_disponible = :available, cantidad_total = CASE WHEN cantidad_total IS NULL THEN NULL ELSE MAX(0, cantidad_total + :change) END, visible_publico = :visible, qr_quantity = :qr_quantity WHERE id = :type AND evento_id = :event AND cantidad_disponible = :expected');
        $write->execute(array(':available' => $available, ':change' => $change, ':visible' => $body['visible'] ? 1 : 0, ':qr_quantity' => $qrQuantity, ':type' => $typeId, ':event' => $eventId, ':expected' => $expected));
        if ($write->rowCount() !== 1) { $pdo->rollBack(); return array('error' => 'stock_changed'); }
        $pdo->commit();
    } catch (Exception $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
    return tickex_mobile_admin_ticket_types($pdo, $eventId);
}
function tickex_mobile_admin_attendees($pdo, $eventId, $query)
{
    $query = trim((string)$query);
    if (strlen($query) < 2 || strlen($query) > 80) return array();
    $statement = $pdo->prepare('SELECT id, nombre, tipo, checked_in FROM entradas WHERE evento_id = :event AND (nombre LIKE :name OR email LIKE :email) ORDER BY id DESC LIMIT 30');
    $like = '%' . str_replace(array('%', '_'), '', $query) . '%';
    $statement->execute(array(':event' => $eventId, ':name' => $like, ':email' => $like));
    $out = array();
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) $out[] = array('ticketId' => (string)$row['id'], 'holderName' => (string)$row['nombre'], 'typeName' => (string)$row['tipo'], 'checkedIn' => (int)$row['checked_in'] === 1);
    return $out;
}
function tickex_mobile_admin_event_owner($pdo, $admin, $eventId)
{
    if (!tickex_mobile_admin_can_event($pdo, $admin, $eventId)) return 0;
    $statement = $pdo->prepare('SELECT creado_por_admin_id FROM eventos WHERE id = :event LIMIT 1');
    $statement->execute(array(':event' => $eventId));
    return (int)$statement->fetchColumn();
}
function tickex_mobile_admin_staff($pdo, $admin, $eventId)
{
    $ownerId = tickex_mobile_admin_event_owner($pdo, $admin, $eventId);
    if ($ownerId <= 0) return null;
    $roles = $pdo->prepare('SELECT code, name, permissions_json FROM staff_roles WHERE owner_admin_id = :owner AND activo = 1 ORDER BY is_system DESC, name ASC');
    $roles->execute(array(':owner' => $ownerId));
    $roleList = array();
    foreach ($roles->fetchAll(PDO::FETCH_ASSOC) as $role) {
        $permissions = json_decode((string)$role['permissions_json'], true);
        $roleList[] = array('code' => (string)$role['code'], 'name' => (string)$role['name'], 'permissions' => is_array($permissions) ? array_values($permissions) : array());
    }
    $eventColumns = tickex_staff_operations_columns($pdo, 'staff_eventos');
    $eventRole = isset($eventColumns['rol_staff']) ? "COALESCE(NULLIF(se.rol_staff, ''), sa.rol_staff)" : 'sa.rol_staff';
    $members = $pdo->prepare("SELECT sa.cliente_id, " . $eventRole . " AS rol_staff, rp.nombre, rp.apellido, rp.apodo, rp.email, CASE WHEN se.staff_id IS NULL THEN 0 ELSE 1 END AS assigned FROM staff_admins sa JOIN registro_pendientes rp ON rp.id = sa.cliente_id LEFT JOIN staff_eventos se ON se.staff_id = sa.cliente_id AND se.evento_id = :event WHERE sa.owner_admin_id = :owner AND sa.activo = 1 ORDER BY assigned DESC, sa.id DESC LIMIT 100");
    $members->execute(array(':event' => $eventId, ':owner' => $ownerId));
    $memberList = array();
    foreach ($members->fetchAll(PDO::FETCH_ASSOC) as $member) {
        $name = trim((string)$member['nombre'] . ' ' . (string)$member['apellido']);
        if ($name === '') $name = trim((string)$member['apodo']);
        if ($name === '') $name = (string)$member['email'];
        $memberList[] = array('clientId' => (string)$member['cliente_id'], 'name' => $name, 'email' => (string)$member['email'], 'roleCode' => (string)$member['rol_staff'], 'assigned' => (int)$member['assigned'] === 1);
    }
    $pending = $pdo->prepare("SELECT id, email, created_at FROM staff_admin_invitaciones WHERE owner_admin_id = :owner AND estado = 'pending' ORDER BY id DESC LIMIT 100");
    $pending->execute(array(':owner' => $ownerId));
    $pendingList = array();
    foreach ($pending->fetchAll(PDO::FETCH_ASSOC) as $row) $pendingList[] = array('id' => (string)$row['id'], 'email' => (string)$row['email'], 'createdAt' => tickex_mobile_datetime($row['created_at']));
    return array('eventId' => (string)$eventId, 'members' => $memberList, 'roles' => $roleList, 'pendingInvitations' => $pendingList);
}
function tickex_mobile_admin_assign_staff($pdo, $admin, $eventId, $clientId, $roleCode, $assigned)
{
    $ownerId = tickex_mobile_admin_event_owner($pdo, $admin, $eventId);
    if ($ownerId <= 0 || $clientId <= 0 || !is_bool($assigned)) return array('error' => 'invalid_assignment');
    $member = $pdo->prepare('SELECT id FROM staff_admins WHERE owner_admin_id = :owner AND cliente_id = :client AND activo = 1 LIMIT 1');
    $member->execute(array(':owner' => $ownerId, ':client' => $clientId));
    if (!$member->fetchColumn()) return array('error' => 'staff_not_found');
    if ($assigned) {
        $role = $pdo->prepare('SELECT 1 FROM staff_roles WHERE owner_admin_id = :owner AND code = :code AND activo = 1 LIMIT 1');
        $role->execute(array(':owner' => $ownerId, ':code' => $roleCode));
        if (!$role->fetchColumn()) return array('error' => 'invalid_role');
    }
    if ($assigned) {
        $cost = 0;
        $eventColumns = tickex_staff_operations_columns($pdo, 'staff_eventos');
        if (isset($eventColumns['costo_servicio'])) {
            $current = $pdo->prepare('SELECT costo_servicio FROM staff_eventos WHERE staff_id = :client AND evento_id = :event LIMIT 1');
            $current->execute(array(':client' => $clientId, ':event' => $eventId));
            $stored = $current->fetchColumn();
            if ($stored !== false) $cost = max(0, (float)$stored);
        }
        tickex_staff_assign_event($pdo, $ownerId, (int)$admin['id'], $clientId, $eventId, $roleCode, $cost);
    }
    else tickex_staff_remove_event($pdo, $ownerId, (int)$admin['id'], $clientId, $eventId);
    return tickex_mobile_admin_staff($pdo, $admin, $eventId);
}
function tickex_mobile_admin_roles($pdo, $admin, $eventId)
{
    $ownerId = tickex_mobile_admin_event_owner($pdo, $admin, $eventId);
    if ($ownerId <= 0) return array('error' => 'forbidden');
    $rows = tickex_staff_roles_get_all($pdo, $ownerId);
    $roles = array();
    foreach ($rows as $row) $roles[] = array('id' => (string)$row['id'], 'code' => (string)$row['code'], 'name' => (string)$row['name'], 'permissions' => array_values($row['permissions']), 'isSystem' => (int)$row['is_system'] === 1);
    return array('eventId' => (string)$eventId, 'catalog' => tickex_staff_roles_permissions_catalog(), 'roles' => $roles);
}
function tickex_mobile_admin_role_fields($body)
{
    $name = isset($body['name']) ? trim((string)$body['name']) : '';
    $permissions = isset($body['permissions']) ? $body['permissions'] : null;
    if ($name === '' || strlen($name) > 80 || !is_array($permissions)) return null;
    $catalog = tickex_staff_roles_permissions_catalog();
    $valid = array();
    foreach ($permissions as $permission) {
        if (!is_string($permission) || !isset($catalog[$permission])) return null;
        $valid[$permission] = true;
    }
    return array('name' => $name, 'permissions' => array_keys($valid));
}
function tickex_mobile_admin_create_role($pdo, $admin, $eventId, $body)
{
    $ownerId = tickex_mobile_admin_event_owner($pdo, $admin, $eventId);
    if ($ownerId <= 0) return array('error' => 'forbidden');
    $fields = tickex_mobile_admin_role_fields($body);
    $code = isset($body['code']) ? trim((string)$body['code']) : '';
    if (!$fields || !preg_match('/^[a-z][a-z0-9_-]{1,39}$/', $code)) return array('error' => 'invalid_role');
    $existing = $pdo->prepare('SELECT 1 FROM staff_roles WHERE owner_admin_id = :owner AND code = :code LIMIT 1');
    $existing->execute(array(':owner' => $ownerId, ':code' => $code));
    if ($existing->fetchColumn()) return array('error' => 'role_exists');
    $insert = $pdo->prepare("INSERT INTO staff_roles (owner_admin_id, code, name, permissions_json, is_system, activo, created_at) VALUES (:owner, :code, :name, :permissions, 0, 1, datetime('now'))");
    $insert->execute(array(':owner' => $ownerId, ':code' => $code, ':name' => $fields['name'], ':permissions' => json_encode($fields['permissions'])));
    tickex_staff_audit($pdo, $ownerId, (int)$admin['id'], 'role_created', null, null, array('code' => $code, 'permissions' => $fields['permissions']));
    return tickex_mobile_admin_roles($pdo, $admin, $eventId);
}
function tickex_mobile_admin_update_role($pdo, $admin, $eventId, $roleId, $body)
{
    $ownerId = tickex_mobile_admin_event_owner($pdo, $admin, $eventId);
    if ($ownerId <= 0) return array('error' => 'forbidden');
    $fields = tickex_mobile_admin_role_fields($body);
    if (!$fields) return array('error' => 'invalid_role');
    $update = $pdo->prepare("UPDATE staff_roles SET name = :name, permissions_json = :permissions, updated_at = datetime('now') WHERE id = :id AND owner_admin_id = :owner AND activo = 1");
    $update->execute(array(':name' => $fields['name'], ':permissions' => json_encode($fields['permissions']), ':id' => $roleId, ':owner' => $ownerId));
    if (!$update->rowCount()) return array('error' => 'not_found');
    tickex_staff_audit($pdo, $ownerId, (int)$admin['id'], 'role_updated', null, null, array('role_id' => $roleId, 'permissions' => $fields['permissions']));
    return tickex_mobile_admin_roles($pdo, $admin, $eventId);
}
function tickex_mobile_admin_delete_role($pdo, $admin, $eventId, $roleId)
{
    $ownerId = tickex_mobile_admin_event_owner($pdo, $admin, $eventId);
    if ($ownerId <= 0) return array('error' => 'forbidden');
    $role = $pdo->prepare('SELECT code, is_system FROM staff_roles WHERE id = :id AND owner_admin_id = :owner AND activo = 1 LIMIT 1');
    $role->execute(array(':id' => $roleId, ':owner' => $ownerId));
    $found = $role->fetch(PDO::FETCH_ASSOC);
    if (!$found) return array('error' => 'not_found');
    if ((int)$found['is_system'] === 1) return array('error' => 'system_role');
    $used = $pdo->prepare("SELECT 1 FROM staff_admins WHERE owner_admin_id = :owner AND activo = 1 AND rol_staff = :code LIMIT 1");
    $used->execute(array(':owner' => $ownerId, ':code' => $found['code']));
    if ($used->fetchColumn()) return array('error' => 'role_in_use');
    $used = $pdo->prepare('SELECT 1 FROM staff_eventos se JOIN eventos e ON e.id = se.evento_id WHERE e.creado_por_admin_id = :owner AND se.rol_staff = :code LIMIT 1');
    $used->execute(array(':owner' => $ownerId, ':code' => $found['code']));
    if ($used->fetchColumn()) return array('error' => 'role_in_use');
    $delete = $pdo->prepare('DELETE FROM staff_roles WHERE id = :id AND owner_admin_id = :owner AND is_system = 0');
    $delete->execute(array(':id' => $roleId, ':owner' => $ownerId));
    tickex_staff_audit($pdo, $ownerId, (int)$admin['id'], 'role_deleted', null, null, array('code' => $found['code']));
    return tickex_mobile_admin_roles($pdo, $admin, $eventId);
}
function tickex_mobile_admin_send_ticket($pdo, $admin, $eventId, $body, $idempotencyKey)
{
    if (!tickex_mobile_admin_can_event($pdo, $admin, $eventId)) return array('error' => 'forbidden');
    if (!is_string($idempotencyKey) || !preg_match('/^[A-Za-z0-9._-]{12,128}$/', $idempotencyKey)) return array('error' => 'idempotency_key_required');
    $ticketTypeId = isset($body['ticketTypeId']) ? (int)$body['ticketTypeId'] : 0;
    $quantity = isset($body['quantity']) ? (int)$body['quantity'] : 0;
    $mode = isset($body['mode']) ? (string)$body['mode'] : 'courtesy';
    $recipient = isset($body['recipient']) ? trim((string)$body['recipient']) : '';
    $name = isset($body['name']) ? trim((string)$body['name']) : '';
    if ($ticketTypeId <= 0 || $quantity < 1 || $quantity > 20 || !in_array($mode, array('courtesy', 'manual_transfer'), true) || strlen($recipient) > 254 || strlen($name) > 160) return array('error' => 'invalid_request');
    if ($mode === 'manual_transfer' && (!isset($body['confirmRecordedPayment']) || $body['confirmRecordedPayment'] !== true)) return array('error' => 'payment_confirmation_required');
    $email = $recipient;
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $lookupId = preg_match('/^#?([1-9][0-9]*)$/', $recipient, $match) ? (int)$match[1] : 0;
        if ($lookupId > 0) {
            $lookup = $pdo->prepare('SELECT email, nombre, apellido FROM registro_pendientes WHERE id = :id LIMIT 1');
            $lookup->execute(array(':id' => $lookupId));
        } else {
            $lookup = $pdo->prepare('SELECT email, nombre, apellido FROM registro_pendientes WHERE lower(apodo) = lower(:apodo) LIMIT 1');
            $lookup->execute(array(':apodo' => $recipient));
        }
        $person = $lookup->fetch(PDO::FETCH_ASSOC);
        if (!$person) return array('error' => 'recipient_not_found');
        $email = (string)$person['email'];
        if ($name === '') $name = trim((string)$person['nombre'] . ' ' . (string)$person['apellido']);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return array('error' => 'invalid_recipient');
    require_once __DIR__ . '/manual_ticket_issuance.php';
    $requestId = 'mobile-manual-' . substr(hash('sha256', (int)$admin['id'] . '|' . $eventId . '|' . $idempotencyKey), 0, 32);
    try {
        $issued = tickex_manual_issue_package($pdo, array('evento_id' => $eventId, 'tipo_id' => $ticketTypeId, 'cantidad' => $quantity, 'modo' => $mode, 'email' => $email, 'nombre' => $name !== '' ? $name : $email, 'admin_id' => (int)$admin['id'], 'restrict_to_admin' => $admin['tipo_global'] !== 'super_admin', 'request_id' => $requestId));
    } catch (InvalidArgumentException $error) { return array('error' => 'invalid_request'); }
    catch (RuntimeException $error) { return array('error' => 'issuance_failed'); }
    return array('orderId' => (string)$issued['order_id'], 'issuedQuantity' => (int)$issued['issued_quantity'], 'emailStatus' => (string)$issued['email_status'], 'mode' => $mode);
}
