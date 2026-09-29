<?php
// The same owned entries and check-in payload used by panel_usuario.php and ticket.php.

function tickex_mobile_tickets($pdo, $email, $ticketId)
{
    $entryColumns = tickex_mobile_columns($pdo, 'entradas');
    $eventColumns = tickex_mobile_columns($pdo, 'eventos');
    $select = array('e.id', 'e.evento_id', 'e.nombre', 'e.tipo', 'e.codigo', 'e.checked_in', 'e.fecha_registro', 'ev.nombre AS event_title');
    foreach (array('fecha_desde', 'lugar', 'ubicacion') as $column) {
        if (isset($eventColumns[$column])) $select[] = 'ev.' . $column . ' AS event_' . $column;
    }
    foreach (array('oculto', 'anulado', 'estado') as $column) {
        if (isset($entryColumns[$column])) $select[] = 'e.' . $column . ' AS entry_' . $column;
    }
    $where = array('lower(e.email) = lower(:email)');
    $hiddenTable = (bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'entradas_ocultas_usuario' LIMIT 1")->fetchColumn();
    if ($hiddenTable) $where[] = 'NOT EXISTS (SELECT 1 FROM entradas_ocultas_usuario hidden WHERE hidden.entrada_id = e.id AND lower(hidden.email) = lower(:hidden_email))';
    if ($ticketId !== null) $where[] = 'e.id = :id';
    $sql = 'SELECT ' . implode(', ', $select) . ' FROM entradas e LEFT JOIN eventos ev ON ev.id = e.evento_id WHERE ' . implode(' AND ', $where) . ' ORDER BY e.fecha_registro DESC, e.id DESC LIMIT ' . ($ticketId === null ? '100' : '1');
    $statement = $pdo->prepare($sql);
    $statement->bindValue(':email', $email);
    if ($hiddenTable) $statement->bindValue(':hidden_email', $email);
    if ($ticketId !== null) $statement->bindValue(':id', $ticketId, PDO::PARAM_INT);
    $statement->execute();
    $result = array();
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $cancelled = (isset($row['entry_anulado']) && (int)$row['entry_anulado'] === 1) || (isset($row['entry_estado']) && in_array(strtolower((string)$row['entry_estado']), array('cancelled', 'anulada', 'anulado'), true));
        $status = $cancelled ? 'cancelled' : ((int)$row['checked_in'] === 1 ? 'used' : 'valid');
        $code = (string)tickex_mobile_value($row, 'codigo', '');
        $result[] = array(
            'id' => (string)$row['id'],
            'eventId' => (string)tickex_mobile_value($row, 'evento_id', ''),
            'eventTitle' => (string)tickex_mobile_value($row, 'event_title', 'Evento Tickex'),
            'startsAt' => tickex_mobile_datetime(tickex_mobile_value($row, 'event_fecha_desde', tickex_mobile_value($row, 'fecha_registro', null))),
            'venue' => (string)tickex_mobile_value($row, 'event_lugar', tickex_mobile_value($row, 'event_ubicacion', 'Lugar por confirmar')),
            'holderName' => (string)tickex_mobile_value($row, 'nombre', ''),
            'typeName' => (string)tickex_mobile_value($row, 'tipo', 'Entrada'),
            'status' => $status,
            'qrPayload' => $status === 'valid' && $code !== '' ? 'https://str.tickex.com.ar/checkin.php?c=' . rawurlencode($code) : '',
        );
    }
    return $result;
}
