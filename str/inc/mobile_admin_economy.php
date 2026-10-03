<?php
require_once __DIR__ . '/manual_income.php';
function tickex_mobile_economy_scope($admin)
{
    return isset($admin['tipo_global']) && $admin['tipo_global'] === 'super_admin';
}

function tickex_mobile_economy_summary($pdo, $admin, $eventFilter, $calculator = null)
{
    if (!preg_match('/^(0|[1-9][0-9]*)$/', (string)$eventFilter)) return array('error' => 'invalid_filter');
    $eventId = (int)$eventFilter;
    if ($eventId > 0 && !tickex_mobile_admin_can_event($pdo, $admin, $eventId)) return array('error' => 'forbidden');
    $eventsSql = "SELECT id, nombre FROM eventos WHERE (borrado_en IS NULL OR borrado_en = '')";
    if (!tickex_mobile_economy_scope($admin)) $eventsSql .= ' AND creado_por_admin_id = :admin';
    if ($eventId > 0) $eventsSql .= ' AND id = :event';
    $events = $pdo->prepare($eventsSql . ' ORDER BY id');
    if (!tickex_mobile_economy_scope($admin)) $events->bindValue(':admin', (int)$admin['id'], PDO::PARAM_INT);
    if ($eventId > 0) $events->bindValue(':event', $eventId, PDO::PARAM_INT);
    $events->execute();
    $eventRows = $events->fetchAll(PDO::FETCH_ASSOC);

    $movementSql = "SELECT tipo, COALESCE(SUM(monto), 0) AS total FROM econ_movimientos WHERE incluye_en_totales = 1";
    if (!tickex_mobile_economy_scope($admin)) $movementSql .= ' AND creado_por = :admin';
    if ($eventId > 0) $movementSql .= ' AND evento_id = :event';
    $movementSql .= ' GROUP BY tipo';
    $movementStatement = $pdo->prepare($movementSql);
    if (!tickex_mobile_economy_scope($admin)) $movementStatement->bindValue(':admin', (int)$admin['id'], PDO::PARAM_INT);
    if ($eventId > 0) $movementStatement->bindValue(':event', $eventId, PDO::PARAM_INT);
    $movementStatement->execute();
    $movementTotals = array('gasto' => 0.0, 'inversion' => 0.0, 'presupuesto' => 0.0);
    foreach ($movementStatement->fetchAll(PDO::FETCH_ASSOC) as $movement) if (isset($movementTotals[$movement['tipo']])) $movementTotals[$movement['tipo']] = (float)$movement['total'];

    $gross = 0.0; $manualIncome = 0.0; $manualOutflow = 0.0; $processing = 0.0; $sold = 0; $checkout = 0.0; $door = 0.0; $byEvent = array();
    foreach ($eventRows as $event) {
        $stats = $calculator === null ? get_economic_stats($pdo, (int)$event['id']) : call_user_func($calculator, $pdo, (int)$event['id']);
        $eventGross = isset($stats['total_recaudado']) ? (float)$stats['total_recaudado'] : 0.0;
        $eventSold = isset($stats['entradas_vendidas']) ? (int)$stats['entradas_vendidas'] : 0;
        $gross += $eventGross; $sold += $eventSold;
        $manualIncome += isset($stats['manual_income']) ? (float)$stats['manual_income'] : 0.0;
        $manualOutflow += abs(isset($stats['manual_income_egresos']) ? (float)$stats['manual_income_egresos'] : 0.0);
        $processing += isset($stats['payment_processing_cost']) ? (float)$stats['payment_processing_cost'] : 0.0;
        if (isset($stats['por_tipo']) && is_array($stats['por_tipo'])) foreach ($stats['por_tipo'] as $type) {
            if (!is_array($type)) continue;
            if (isset($type['origen']) && $type['origen'] === 'TICKEX') $checkout += isset($type['monto']) ? (float)$type['monto'] : 0.0;
            if (isset($type['origen']) && $type['origen'] === 'STR') $door += isset($type['monto']) ? (float)$type['monto'] : 0.0;
        }
        $byEvent[] = array('eventId' => (string)$event['id'], 'eventName' => (string)$event['nombre'], 'grossRevenue' => $eventGross, 'ticketsSold' => $eventSold);
    }
    return array('estimateOnly' => true, 'eventId' => $eventId > 0 ? (string)$eventId : null, 'grossRevenue' => $gross, 'manualIncome' => $manualIncome, 'manualOutflow' => $manualOutflow, 'processingCost' => $processing, 'ticketsSold' => $sold, 'checkoutRevenue' => $checkout, 'doorRevenue' => $door, 'expenses' => $movementTotals['gasto'], 'investment' => $movementTotals['inversion'], 'budget' => $movementTotals['presupuesto'], 'estimatedBalance' => $gross + $movementTotals['presupuesto'] - $processing - $manualOutflow - $movementTotals['gasto'] - $movementTotals['inversion'], 'byEvent' => $byEvent);
}

function tickex_mobile_economy_list($pdo, $admin, $filters)
{
    foreach (array('eventId', 'beforeId', 'limit') as $numericFilter) {
        if (isset($filters[$numericFilter]) && !preg_match('/^(0|[1-9][0-9]*)$/', (string)$filters[$numericFilter])) return array('error' => 'invalid_filter');
    }
    $eventId = isset($filters['eventId']) ? (int)$filters['eventId'] : 0;
    $beforeId = isset($filters['beforeId']) ? (int)$filters['beforeId'] : 0;
    $limit = isset($filters['limit']) ? (int)$filters['limit'] : 30;
    if ($eventId < 0 || $beforeId < 0 || $limit < 1 || $limit > 50) return array('error' => 'invalid_filter');
    if ($eventId > 0 && !tickex_mobile_admin_can_event($pdo, $admin, $eventId)) return array('error' => 'forbidden');
    $where = array();
    if (!tickex_mobile_economy_scope($admin)) $where[] = 'creado_por=:admin';
    if ($eventId > 0) $where[] = 'evento_id=:event';
    if ($beforeId > 0) $where[] = 'id<:before';
    $sql = 'SELECT id,tipo,concepto,categoria,monto,estado,evento_id,notas,creado_por,incluye_en_totales,created_at FROM econ_movimientos';
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY id DESC LIMIT :limit';
    $st = $pdo->prepare($sql);
    if (!tickex_mobile_economy_scope($admin)) $st->bindValue(':admin', (int)$admin['id'], PDO::PARAM_INT);
    if ($eventId > 0) $st->bindValue(':event', $eventId, PDO::PARAM_INT);
    if ($beforeId > 0) $st->bindValue(':before', $beforeId, PDO::PARAM_INT);
    $st->bindValue(':limit', $limit + 1, PDO::PARAM_INT);
    $st->execute();
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $hasMore = count($rows) > $limit;
    if ($hasMore) array_pop($rows);
    $items = array();
    foreach ($rows as $row) $items[] = tickex_mobile_economy_format($row);
    return array('items' => $items, 'nextBeforeId' => $hasMore && $rows ? (string)$rows[count($rows)-1]['id'] : null);
}

function tickex_mobile_economy_format($row)
{
    return array(
        'id' => (string)$row['id'], 'type' => (string)$row['tipo'], 'concept' => (string)$row['concepto'],
        'category' => (string)$row['categoria'], 'amount' => (float)$row['monto'], 'state' => (string)$row['estado'],
        'eventId' => $row['evento_id'] === null ? null : (string)$row['evento_id'], 'notes' => (string)$row['notas'],
        'createdBy' => (string)$row['creado_por'], 'included' => (int)$row['incluye_en_totales'] === 1,
        'createdAt' => tickex_mobile_datetime($row['created_at']),
    );
}

function tickex_mobile_economy_find($pdo, $admin, $id)
{
    $sql = 'SELECT id,tipo,concepto,categoria,monto,estado,evento_id,notas,creado_por,incluye_en_totales,created_at FROM econ_movimientos WHERE id=:id';
    if (!tickex_mobile_economy_scope($admin)) $sql .= ' AND creado_por=:admin';
    $st = $pdo->prepare($sql . ' LIMIT 1');
    $st->bindValue(':id', (int)$id, PDO::PARAM_INT);
    if (!tickex_mobile_economy_scope($admin)) $st->bindValue(':admin', (int)$admin['id'], PDO::PARAM_INT);
    $st->execute();
    return $st->fetch(PDO::FETCH_ASSOC);
}

function tickex_mobile_economy_create($pdo, $admin, $body)
{
    if (isset($body['eventId']) && $body['eventId'] !== '' && !preg_match('/^(0|[1-9][0-9]*)$/', (string)$body['eventId'])) return array('error' => 'invalid_movement');
    $type = isset($body['type']) ? trim((string)$body['type']) : '';
    $concept = isset($body['concept']) ? trim((string)$body['concept']) : '';
    $category = isset($body['category']) ? trim((string)$body['category']) : '';
    $state = isset($body['state']) ? trim((string)$body['state']) : '';
    $notes = isset($body['notes']) ? trim((string)$body['notes']) : '';
    $eventId = isset($body['eventId']) && $body['eventId'] !== '' ? (int)$body['eventId'] : 0;
    $requestId = isset($body['requestId']) ? trim((string)$body['requestId']) : '';
    $amount = isset($body['amount']) && is_numeric($body['amount']) ? (float)$body['amount'] : 0;
    if (!in_array($type, array('gasto','presupuesto','inversion'), true) || $concept === '' || strlen($concept) > 180 || strlen($category) > 80 || strlen($state) > 80 || strlen($notes) > 1000 || !is_finite($amount) || $amount <= 0 || $amount > 1000000000 || $eventId < 0 || !preg_match('/^[A-Za-z0-9_-]{16,100}$/', $requestId)) return array('error' => 'invalid_movement');
    if ($eventId > 0 && !tickex_mobile_admin_can_event($pdo, $admin, $eventId)) return array('error' => 'forbidden');
    $canonical = array($type, $concept, $category, round($amount,2), $state, $eventId, $notes);
    $payloadHash = hash('sha256', json_encode($canonical));
    $pdo->exec('CREATE TABLE IF NOT EXISTS mobile_economy_requests (admin_id INTEGER NOT NULL, request_id TEXT NOT NULL, payload_hash TEXT NOT NULL, movement_id INTEGER NOT NULL, PRIMARY KEY(admin_id,request_id))');
    $pdo->beginTransaction();
    try {
        $old = $pdo->prepare('SELECT payload_hash,movement_id FROM mobile_economy_requests WHERE admin_id=:admin AND request_id=:request LIMIT 1');
        $old->execute(array(':admin'=>(int)$admin['id'], ':request'=>$requestId));
        $prior = $old->fetch(PDO::FETCH_ASSOC);
        if ($prior) {
            $pdo->commit();
            if ($prior['payload_hash'] !== $payloadHash) return array('error' => 'request_conflict');
            $movement = tickex_mobile_economy_find($pdo, $admin, (int)$prior['movement_id']);
            return $movement ? tickex_mobile_economy_format($movement) : array('error' => 'not_found');
        }
        $insert = $pdo->prepare('INSERT INTO econ_movimientos(tipo,concepto,categoria,monto,estado,evento_id,notas,creado_por) VALUES(:type,:concept,:category,:amount,:state,:event,:notes,:admin)');
        $insert->bindValue(':type', $type);
        $insert->bindValue(':concept', $concept);
        $insert->bindValue(':category', $category);
        $insert->bindValue(':amount', round($amount, 2));
        $insert->bindValue(':state', $state);
        $insert->bindValue(':event', $eventId > 0 ? $eventId : null, $eventId > 0 ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $insert->bindValue(':notes', $notes);
        $insert->bindValue(':admin', (int)$admin['id'], PDO::PARAM_INT);
        $insert->execute();
        $movementId = (int)$pdo->lastInsertId();
        $save = $pdo->prepare('INSERT INTO mobile_economy_requests(admin_id,request_id,payload_hash,movement_id) VALUES(:admin,:request,:hash,:movement)');
        $save->execute(array(':admin'=>(int)$admin['id'], ':request'=>$requestId, ':hash'=>$payloadHash, ':movement'=>$movementId));
        $pdo->commit();
        return tickex_mobile_economy_format(tickex_mobile_economy_find($pdo, $admin, $movementId));
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return array('error' => 'storage_error');
    }
}

function tickex_mobile_economy_set_included($pdo, $admin, $id, $expected, $included)
{
    if ($id <= 0 || !is_bool($expected) || !is_bool($included)) return array('error' => 'invalid_movement');
    $existing = tickex_mobile_economy_find($pdo, $admin, $id);
    if (!$existing) return array('error' => 'not_found');
    if (((int)$existing['incluye_en_totales'] === 1) !== $expected) return array('error' => 'conflict');
    $sql = 'UPDATE econ_movimientos SET incluye_en_totales=:new WHERE id=:id AND incluye_en_totales=:expected';
    if (!tickex_mobile_economy_scope($admin)) $sql .= ' AND creado_por=:admin';
    $st = $pdo->prepare($sql);
    $st->bindValue(':new', $included ? 1 : 0, PDO::PARAM_INT);
    $st->bindValue(':id', (int)$id, PDO::PARAM_INT);
    $st->bindValue(':expected', $expected ? 1 : 0, PDO::PARAM_INT);
    if (!tickex_mobile_economy_scope($admin)) $st->bindValue(':admin', (int)$admin['id'], PDO::PARAM_INT);
    $st->execute();
    if (!$st->rowCount() && $expected !== $included) return array('error' => 'conflict');
    return tickex_mobile_economy_format(tickex_mobile_economy_find($pdo, $admin, $id));
}

function tickex_mobile_economy_delete($pdo, $admin, $id, $confirmed)
{
    if ($id <= 0 || $confirmed !== true) return array('error' => 'confirmation_required');
    if (!tickex_mobile_economy_find($pdo, $admin, $id)) return array('error' => 'not_found');
    $sql = 'DELETE FROM econ_movimientos WHERE id=:id';
    if (!tickex_mobile_economy_scope($admin)) $sql .= ' AND creado_por=:admin';
    $st = $pdo->prepare($sql);
    $st->bindValue(':id', (int)$id, PDO::PARAM_INT);
    if (!tickex_mobile_economy_scope($admin)) $st->bindValue(':admin', (int)$admin['id'], PDO::PARAM_INT);
    $st->execute();
    return array('deleted' => $st->rowCount() === 1);
}
