<?php
// Public, read-only mobile event projection. PHP 5 compatible.

function tickex_mobile_columns($pdo, $table)
{
    $columns = array();
    $statement = $pdo->query('PRAGMA table_info(' . $table . ')');
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) $columns[$row['name']] = true;
    return $columns;
}

function tickex_mobile_value($row, $key, $fallback)
{
    return isset($row[$key]) && $row[$key] !== '' ? $row[$key] : $fallback;
}

function tickex_mobile_datetime($value)
{
    if ($value === null || trim((string)$value) === '') return null;
    $timestamp = strtotime((string)$value);
    return $timestamp === false ? null : date('c', $timestamp);
}

function tickex_mobile_flyer($row)
{
    foreach (array('flyer_filename', 'flyer') as $column) {
        if (empty($row[$column])) continue;
        $value = trim((string)$row[$column]);
        if (preg_match('~^https://[^\s]+$~i', $value)) return $value;
        if (preg_match('~^http://[^\s]+$~i', $value)) continue;
        $path = ltrim($value, '/');
        if ($path === '' || strpos($path, '..') !== false || strpos($path, '\\') !== false) continue;
        if ($column === 'flyer_filename' && !is_file(__DIR__ . '/../' . $path)) continue;
        return 'https://str.tickex.com.ar/' . $path;
    }
    return null;
}

function tickex_mobile_public_events($pdo, $id)
{
    $columns = tickex_mobile_columns($pdo, 'eventos');
    // A missing publication flag is a schema error, never implicit publication.
    if (!isset($columns['publicado_site'])) throw new RuntimeException('publication_column_missing');
    $where = array('publicado_site = 1');
    if (isset($columns['borrado_en'])) $where[] = 'borrado_en IS NULL';
    if (isset($columns['fecha_hasta'])) $where[] = "(fecha_hasta IS NULL OR fecha_hasta = '' OR fecha_hasta >= :today)";
    if ($id !== null) $where[] = 'id = :id';
    $sql = 'SELECT * FROM eventos WHERE ' . implode(' AND ', $where) . ' ORDER BY fecha_desde ASC, id DESC';
    if ($id !== null) $sql .= ' LIMIT 1';
    else $sql .= ' LIMIT 100';
    $statement = $pdo->prepare($sql);
    if (isset($columns['fecha_hasta'])) $statement->bindValue(':today', date('Y-m-d'));
    if ($id !== null) $statement->bindValue(':id', $id, PDO::PARAM_INT);
    $statement->execute();
    return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function tickex_mobile_global_available($pdo, $event)
{
    $limit = isset($event['capacidad_total']) && (int)$event['capacidad_total'] > 0 ? (int)$event['capacidad_total'] : null;
    if ($limit === null) {
        $typeColumns = tickex_mobile_columns($pdo, 'tipos_entrada');
        if (isset($typeColumns['cantidad_total'])) {
            $statement = $pdo->prepare('SELECT COALESCE(SUM(cantidad_total), 0) FROM tipos_entrada WHERE evento_id = :event');
            $statement->execute(array(':event' => (int)$event['id']));
            $legacy = (int)$statement->fetchColumn();
            if ($legacy > 0) $limit = $legacy;
        }
    }
    if ($limit === null) return null;
    $issued = 0;
    if ($pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'entradas' LIMIT 1")->fetchColumn()) {
        $statement = $pdo->prepare('SELECT COUNT(*) FROM entradas WHERE evento_id = :event');
        $statement->execute(array(':event' => (int)$event['id']));
        $issued = (int)$statement->fetchColumn();
    }
    return max(0, $limit - $issued);
}

function tickex_mobile_event_is_current($event)
{
    $raw = !empty($event['fecha_hasta']) ? trim((string)$event['fecha_hasta']) : trim((string)tickex_mobile_value($event, 'fecha_desde', ''));
    if ($raw === '') return false;
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) $raw .= ' 23:59:59';
    $end = strtotime($raw);
    return $end !== false && $end >= time();
}

function tickex_mobile_ticket_types($pdo, $eventId, $globalAvailable)
{
    $columns = tickex_mobile_columns($pdo, 'tipos_entrada');
    $where = array('evento_id = :event_id');
    if (isset($columns['activo'])) $where[] = 'activo = 1';
    foreach (array('publico', 'visible_publico', 'venta_publico') as $publicColumn) {
        if (isset($columns[$publicColumn])) { $where[] = $publicColumn . ' = 1'; break; }
    }
    $statement = $pdo->prepare('SELECT * FROM tipos_entrada WHERE ' . implode(' AND ', $where) . ' ORDER BY id ASC');
    $statement->bindValue(':event_id', $eventId, PDO::PARAM_INT);
    $statement->execute();
    $freeTypeId = 0;
    if ($pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'event_free_checkout_configs' LIMIT 1")->fetchColumn()) {
        $free = $pdo->prepare('SELECT ticket_type_id FROM event_free_checkout_configs WHERE evento_id = :event LIMIT 1');
        $free->execute(array(':event' => $eventId));
        $freeTypeId = (int)$free->fetchColumn();
    }
    $now = time();
    $types = array();
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $price = (float)tickex_mobile_value($row, 'precio', 0);
        $logicalType = strtoupper(trim((string)tickex_mobile_value($row, 'tipo', '')));
        if ((int)$row['id'] === $freeTypeId || $price <= 0 || in_array($logicalType, array('FREE', 'GRATIS', 'CORTESIA', 'CORTESÍA'), true)) continue;
        $qrQuantity = max(1, min(10, (int)tickex_mobile_value($row, 'qr_quantity', 1)));
        $availableQr = tickex_mobile_value($row, 'cantidad_disponible', tickex_mobile_value($row, 'cantidad_total', null));
        $available = $availableQr === null ? null : (int)floor(max(0, (int)$availableQr) / $qrQuantity);
        if ($globalAvailable !== null) {
            $globalPackages = (int)floor($globalAvailable / $qrQuantity);
            $available = $available === null ? $globalPackages : min($available, $globalPackages);
        }
        $starts = tickex_mobile_datetime(tickex_mobile_value($row, 'venta_desde', null));
        $ends = tickex_mobile_datetime(tickex_mobile_value($row, 'venta_hasta', null));
        $saleStart = $starts === null ? false : strtotime($starts);
        $saleEnd = $ends === null ? false : strtotime($ends);
        // Legacy venta_hasta is often a date with no time: it includes that full day.
        if ($ends !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$row['venta_hasta'])) $saleEnd += 86399;
        $status = $available === 0 ? 'sold_out' : (($saleStart !== false && $now < $saleStart) ? 'upcoming' : (($saleEnd !== false && $now > $saleEnd) ? 'closed' : 'on_sale'));
        $types[] = array(
            'id' => (string)$row['id'],
            'name' => (string)tickex_mobile_value($row, 'nombre', 'Entrada'),
            'price' => array('amount' => $price, 'currency' => 'ARS'),
            'available' => $available,
            'qrQuantity' => $qrQuantity,
            'saleStartsAt' => $starts,
            'saleEndsAt' => $ends,
            'status' => $status,
        );
    }
    return $types;
}

function tickex_mobile_free_offer($pdo, $eventId, $globalAvailable)
{
    if (!$pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'event_free_checkout_configs' LIMIT 1")->fetchColumn()) return null;
    $typeColumns = tickex_mobile_columns($pdo, 'tipos_entrada');
    $read = $pdo->prepare("SELECT c.max_uses, t.nombre, t.cantidad_disponible FROM event_free_checkout_configs c JOIN tipos_entrada t ON t.id = c.ticket_type_id AND t.evento_id = c.evento_id WHERE c.evento_id = :event AND c.enabled = 1 AND UPPER(t.tipo) = 'FREE' AND t.precio = 0" . (isset($typeColumns['activo']) ? ' AND t.activo = 1' : '') . ' LIMIT 1');
    $read->execute(array(':event' => $eventId));
    $row = $read->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    $available = max(0, (int)$row['cantidad_disponible']);
    if ($globalAvailable !== null) $available = min($available, max(0, (int)$globalAvailable));
    if ($row['max_uses'] !== null) {
        $entryColumns = tickex_mobile_columns($pdo, 'entradas');
        if (!isset($entryColumns['payment_method'])) return null;
        $issued = $pdo->prepare("SELECT COUNT(*) FROM entradas WHERE evento_id = :event AND payment_method = 'free'");
        $issued->execute(array(':event' => $eventId));
        $available = min($available, max(0, (int)$row['max_uses'] - (int)$issued->fetchColumn()));
    }
    if (function_exists('tickex_event_sales_paused') && tickex_event_sales_paused($pdo, $eventId)) $available = 0;
    return array('name' => (string)$row['nombre'], 'available' => $available, 'checkoutUrl' => 'https://str.tickex.com.ar/acceso.php?evento_id=' . (int)$eventId);
}

function tickex_mobile_event($row, $types, $freeOffer)
{
    $eventEnded = !tickex_mobile_event_is_current($row);
    if ($eventEnded) {
        foreach ($types as &$type) $type['status'] = 'closed';
        unset($type);
    }
    $onSale = false; $upcoming = false; $soldOut = false;
    foreach ($types as $type) {
        if ($type['status'] === 'on_sale') $onSale = true;
        if ($type['status'] === 'upcoming') $upcoming = true;
        if ($type['status'] === 'sold_out') $soldOut = true;
    }
    $freeAvailable = $freeOffer !== null && $freeOffer['available'] > 0;
    $status = $eventEnded ? 'closed' : ($onSale || $freeAvailable ? 'on_sale' : ($upcoming || (count($types) === 0 && $freeOffer === null) ? 'upcoming' : ($soldOut || $freeOffer !== null ? 'sold_out' : 'closed')));
    return array(
        'id' => (string)$row['id'],
        'slug' => (string)tickex_mobile_value($row, 'slug', (string)$row['id']),
        'title' => (string)tickex_mobile_value($row, 'nombre', 'Evento'),
        'startsAt' => tickex_mobile_datetime(tickex_mobile_value($row, 'fecha_desde', null)),
        'endsAt' => tickex_mobile_datetime(tickex_mobile_value($row, 'fecha_hasta', null)),
        'venue' => (string)tickex_mobile_value($row, 'lugar', tickex_mobile_value($row, 'ubicacion', 'Lugar por confirmar')),
        'city' => (string)tickex_mobile_value($row, 'ciudad', ''),
        'description' => (string)tickex_mobile_value($row, 'descripcion', ''),
        'imageUrl' => tickex_mobile_flyer($row),
        'status' => $status,
        'maxPerOrder' => isset($row['max_entradas_compra']) && (int)$row['max_entradas_compra'] > 0 ? max(1, min(20, (int)$row['max_entradas_compra'])) : 10,
        'ticketTypes' => $types,
        'freeCheckout' => $freeOffer,
    );
}
