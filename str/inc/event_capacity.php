<?php

if (!function_exists('tickex_event_capacity_ensure_schema')) {
    function tickex_event_capacity_ensure_schema($pdo)
    {
        $hasEvents = (bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='eventos' LIMIT 1")->fetchColumn();
        if (!$hasEvents) return false;
        $columns = array();
        foreach ($pdo->query('PRAGMA table_info(eventos)')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $columns[(string)$row['name']] = true;
        }
        if (!isset($columns['capacidad_total'])) {
            $pdo->exec('ALTER TABLE eventos ADD COLUMN capacidad_total INTEGER');
        }
        return true;
    }
}

if (!function_exists('tickex_event_capacity_issued')) {
    function tickex_event_capacity_issued($pdo, $eventId)
    {
        $hasEntries = (bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='entradas' LIMIT 1")->fetchColumn();
        if (!$hasEntries) return 0;
        $st = $pdo->prepare('SELECT COUNT(*) FROM entradas WHERE evento_id=:event');
        $st->execute(array(':event'=>(int)$eventId));
        return (int)$st->fetchColumn();
    }
}

if (!function_exists('tickex_event_capacity_legacy_limit')) {
    function tickex_event_capacity_legacy_limit($pdo, $eventId)
    {
        $hasTypes = (bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='tipos_entrada' LIMIT 1")->fetchColumn();
        if (!$hasTypes) return null;
        $columns = array();
        foreach ($pdo->query('PRAGMA table_info(tipos_entrada)')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $columns[(string)$row['name']] = true;
        }
        if (!isset($columns['cantidad_total'])) return null;
        $st = $pdo->prepare('SELECT COALESCE(SUM(cantidad_total),0) FROM tipos_entrada WHERE evento_id=:event');
        $st->execute(array(':event'=>(int)$eventId));
        $total = (int)$st->fetchColumn();
        return $total > 0 ? $total : null;
    }
}

if (!function_exists('tickex_event_capacity_status')) {
    function tickex_event_capacity_status($pdo, $eventId)
    {
        $explicit = null;
        if (tickex_event_capacity_ensure_schema($pdo)) {
            $st = $pdo->prepare('SELECT capacidad_total FROM eventos WHERE id=:event LIMIT 1');
            $st->execute(array(':event'=>(int)$eventId));
            $value = $st->fetchColumn();
            if ($value !== false && $value !== null && $value !== '' && (int)$value > 0) $explicit = (int)$value;
        }
        $issued = tickex_event_capacity_issued($pdo, $eventId);
        $legacy = tickex_event_capacity_legacy_limit($pdo, $eventId);
        $limit = $explicit !== null ? $explicit : $legacy;
        return array(
            'configured'=>(int)$explicit,
            'legacy'=>(int)$legacy,
            'limit'=>$limit === null ? null : (int)$limit,
            'issued'=>$issued,
            'available'=>$limit === null ? null : max(0, (int)$limit - $issued),
        );
    }
}

if (!function_exists('tickex_event_capacity_assert_available')) {
    function tickex_event_capacity_assert_available($pdo, $eventId, $quantity)
    {
        $quantity = max(0, (int)$quantity);
        $status = tickex_event_capacity_status($pdo, $eventId);
        if ($status['limit'] !== null && $quantity > (int)$status['available']) {
            throw new RuntimeException('Cupo global insuficiente: se necesitan ' . $quantity . ' lugares y quedan ' . (int)$status['available'] . '.');
        }
        return $status;
    }
}

if (!function_exists('tickex_event_capacity_set')) {
    function tickex_event_capacity_set($pdo, $eventId, $capacity)
    {
        tickex_event_capacity_ensure_schema($pdo);
        $capacity = (int)$capacity;
        $issued = tickex_event_capacity_issued($pdo, $eventId);
        if ($capacity < 1) throw new InvalidArgumentException('El cupo total debe ser mayor a cero.');
        if ($capacity < $issued) throw new InvalidArgumentException('El cupo total no puede ser menor que los ' . $issued . ' QR ya emitidos.');
        $st = $pdo->prepare('UPDATE eventos SET capacidad_total=:capacity WHERE id=:event');
        $st->execute(array(':capacity'=>$capacity, ':event'=>(int)$eventId));
        if ($st->rowCount() !== 1) throw new RuntimeException('No se pudo actualizar el cupo del evento.');
        return tickex_event_capacity_status($pdo, $eventId);
    }
}
