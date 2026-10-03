<?php
require_once __DIR__ . '/mobile_admin.php';
require_once __DIR__ . '/event_presentation.php';
require_once __DIR__ . '/event_capacity.php';

function tickex_mobile_admin_event_presentation($pdo, $admin, $eventId)
{
    if (!tickex_mobile_admin_can_event($pdo, $admin, $eventId)) return array('error' => 'forbidden');
    tickex_event_presentation_ensure_schema($pdo);
    tickex_event_capacity_ensure_schema($pdo);
    $read = $pdo->prepare('SELECT capacidad_total, hora_puertas, hora_show, lugar, direccion, indicaciones_llegada, transporte_publico, politica_menores, movilidad_reducida, objetos_prohibidos, max_entradas_compra FROM eventos WHERE id = :event LIMIT 1');
    $read->execute(array(':event' => $eventId));
    $row = $read->fetch(PDO::FETCH_ASSOC);
    if (!$row) return array('error' => 'not_found');
    return array('eventId' => (string)$eventId, 'capacityTotal' => $row['capacidad_total'] === null ? null : (int)$row['capacidad_total'], 'doorsAt' => (string)$row['hora_puertas'], 'showAt' => (string)$row['hora_show'], 'venue' => (string)$row['lugar'], 'address' => (string)$row['direccion'], 'arrivalNotes' => (string)$row['indicaciones_llegada'], 'publicTransport' => (string)$row['transporte_publico'], 'minorsPolicy' => (string)$row['politica_menores'], 'accessibility' => (string)$row['movilidad_reducida'], 'prohibitedItems' => (string)$row['objetos_prohibidos'], 'maxPerOrder' => $row['max_entradas_compra'] === null ? 10 : (int)$row['max_entradas_compra']);
}

function tickex_mobile_admin_update_presentation($pdo, $admin, $eventId, $body)
{
    $current = tickex_mobile_admin_event_presentation($pdo, $admin, $eventId);
    if (isset($current['error'])) return $current;
    if (array_key_exists('maxPerOrder', $body) && filter_var($body['maxPerOrder'], FILTER_VALIDATE_INT, array('options' => array('min_range' => 1, 'max_range' => 20))) === false) return array('error' => 'invalid_presentation');
    $mapping = array('doorsAt' => 'hora_puertas', 'showAt' => 'hora_show', 'venue' => 'lugar', 'address' => 'direccion', 'arrivalNotes' => 'indicaciones_llegada', 'publicTransport' => 'transporte_publico', 'minorsPolicy' => 'politica_menores', 'accessibility' => 'movilidad_reducida', 'prohibitedItems' => 'objetos_prohibidos', 'maxPerOrder' => 'max_entradas_compra');
    $source = array();
    foreach ($mapping as $apiName => $dbName) $source[$dbName] = array_key_exists($apiName, $body) ? $body[$apiName] : $current[$apiName];
    $details = tickex_event_presentation_post($source);
    if (tickex_event_presentation_validate($details, true) !== '') return array('error' => 'invalid_presentation');
    $capacity = array_key_exists('capacityTotal', $body) ? filter_var($body['capacityTotal'], FILTER_VALIDATE_INT, array('options' => array('min_range' => 1, 'max_range' => 1000000))) : $current['capacityTotal'];
    if ($capacity === false || $capacity === null) return array('error' => 'invalid_capacity');
    if ($capacity < tickex_event_capacity_issued($pdo, $eventId)) return array('error' => 'capacity_below_issued');
    $params = tickex_event_presentation_params($details);
    $params[':capacity_total'] = $capacity;
    $params[':event'] = $eventId;
    $params[':admin'] = (int)$admin['id'];
    $params[':super'] = $admin['tipo_global'] === 'super_admin' ? 1 : 0;
    $set = array();
    foreach (tickex_event_presentation_columns() as $column => $type) $set[] = $column . ' = :' . $column;
    $write = $pdo->prepare("UPDATE eventos SET " . implode(', ', $set) . ", capacidad_total = :capacity_total, actualizado_en = CURRENT_TIMESTAMP WHERE id = :event AND (borrado_en IS NULL OR borrado_en = '') AND (CAST(:super AS INTEGER) = 1 OR creado_por_admin_id = :admin)");
    $write->execute($params);
    return tickex_mobile_admin_event_presentation($pdo, $admin, $eventId);
}
