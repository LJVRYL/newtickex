<?php

/* Reservas nominadas de puerta: no son ventas hasta cobrar e ingresar. */

if (!function_exists('tickex_door_list_ensure_schema')) {
    function tickex_door_list_ensure_schema($pdo)
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS event_door_guest_lists (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            evento_id INTEGER NOT NULL,
            nombre TEXT NOT NULL,
            precio REAL NOT NULL,
            ticket_type_id INTEGER NOT NULL,
            activa INTEGER NOT NULL DEFAULT 1,
            created_by_admin_id INTEGER,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_door_guest_list_event ON event_door_guest_lists(evento_id)');

        $pdo->exec("CREATE TABLE IF NOT EXISTS event_door_guest_reservations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            list_id INTEGER NOT NULL,
            evento_id INTEGER NOT NULL,
            guest_name TEXT NOT NULL,
            normalized_name TEXT NOT NULL,
            notes TEXT,
            status TEXT NOT NULL DEFAULT 'reserved',
            price REAL NOT NULL,
            entrada_id INTEGER,
            paid_at TEXT,
            checked_in_at TEXT,
            processed_by_admin_id INTEGER,
            created_by_admin_id INTEGER,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_door_guest_res_event_status ON event_door_guest_reservations(evento_id,status)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_door_guest_res_list_name ON event_door_guest_reservations(list_id,normalized_name)');
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_door_guest_res_entry ON event_door_guest_reservations(entrada_id)');
    }
}

if (!function_exists('tickex_door_normalize_name')) {
    function tickex_door_normalize_name($name)
    {
        $name = preg_replace('/\s+/u', ' ', trim((string)$name));
        return function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
    }
}

if (!function_exists('tickex_door_list_for_event')) {
    function tickex_door_list_for_event($pdo, $eventId)
    {
        tickex_door_list_ensure_schema($pdo);
        $st = $pdo->prepare('SELECT l.*,t.nombre AS ticket_type_name,t.cantidad_disponible FROM event_door_guest_lists l LEFT JOIN tipos_entrada t ON t.id=l.ticket_type_id AND t.evento_id=l.evento_id WHERE l.evento_id=:event AND l.activa=1 LIMIT 1');
        $st->execute(array(':event' => (int)$eventId));
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('tickex_door_save_list')) {
    function tickex_door_save_list($pdo, $eventId, $name, $price, $ticketTypeId, $adminId)
    {
        tickex_door_list_ensure_schema($pdo);
        $eventId = (int)$eventId;
        $ticketTypeId = (int)$ticketTypeId;
        $name = trim((string)$name);
        $price = round((float)$price, 2);
        if ($eventId <= 0 || $ticketTypeId <= 0 || $name === '' || $price <= 0) {
            throw new InvalidArgumentException('Completá nombre, valor y tipo de entrada.');
        }
        $stType = $pdo->prepare('SELECT 1 FROM tipos_entrada WHERE id=:type AND evento_id=:event LIMIT 1');
        $stType->execute(array(':type'=>$ticketTypeId, ':event'=>$eventId));
        if (!$stType->fetchColumn()) throw new RuntimeException('El tipo de entrada no pertenece al evento.');

        $current = tickex_door_list_for_event($pdo, $eventId);
        if ($current) {
            $st = $pdo->prepare('UPDATE event_door_guest_lists SET nombre=:name,precio=:price,ticket_type_id=:type,updated_at=CURRENT_TIMESTAMP WHERE id=:id');
            $st->execute(array(':name'=>$name, ':price'=>$price, ':type'=>$ticketTypeId, ':id'=>(int)$current['id']));
            return (int)$current['id'];
        }
        $st = $pdo->prepare('INSERT INTO event_door_guest_lists (evento_id,nombre,precio,ticket_type_id,created_by_admin_id) VALUES (:event,:name,:price,:type,:admin)');
        $st->execute(array(':event'=>$eventId, ':name'=>$name, ':price'=>$price, ':type'=>$ticketTypeId, ':admin'=>(int)$adminId));
        return (int)$pdo->lastInsertId();
    }
}

if (!function_exists('tickex_door_import_guests')) {
    function tickex_door_import_guests($pdo, $listId, $rawNames, $adminId)
    {
        tickex_door_list_ensure_schema($pdo);
        $stList = $pdo->prepare('SELECT * FROM event_door_guest_lists WHERE id=:id AND activa=1 LIMIT 1');
        $stList->execute(array(':id'=>(int)$listId));
        $list = $stList->fetch(PDO::FETCH_ASSOC);
        if (!$list) throw new RuntimeException('La lista de puerta no está activa.');

        $existing = array();
        $stExisting = $pdo->prepare("SELECT normalized_name FROM event_door_guest_reservations WHERE list_id=:list AND status!='cancelled'");
        $stExisting->execute(array(':list'=>(int)$listId));
        foreach ($stExisting->fetchAll(PDO::FETCH_COLUMN) as $normalized) $existing[(string)$normalized] = true;

        $insert = $pdo->prepare("INSERT INTO event_door_guest_reservations (list_id,evento_id,guest_name,normalized_name,status,price,created_by_admin_id) VALUES (:list,:event,:name,:normalized,'reserved',:price,:admin)");
        $added = 0;
        $skipped = 0;
        $lines = preg_split('/\R/u', (string)$rawNames);
        foreach ($lines as $line) {
            $name = preg_replace('/^\s*(?:[-*•]+|\d+[.)-])\s*/u', '', trim((string)$line));
            $normalized = tickex_door_normalize_name($name);
            if ($normalized === '') continue;
            if (isset($existing[$normalized])) {
                $skipped++;
                continue;
            }
            $insert->execute(array(
                ':list'=>(int)$listId,
                ':event'=>(int)$list['evento_id'],
                ':name'=>$name,
                ':normalized'=>$normalized,
                ':price'=>(float)$list['precio'],
                ':admin'=>(int)$adminId,
            ));
            $existing[$normalized] = true;
            $added++;
        }
        return array('added'=>$added, 'skipped'=>$skipped);
    }
}

if (!function_exists('tickex_door_entry_code')) {
    function tickex_door_entry_code($pdo, $eventId)
    {
        $st = $pdo->prepare('SELECT 1 FROM entradas WHERE codigo=:code LIMIT 1');
        for ($attempt = 0; $attempt < 30; $attempt++) {
            try {
                $suffix = strtoupper(bin2hex(random_bytes(6)));
            } catch (Exception $e) {
                $suffix = strtoupper(substr(sha1(uniqid('', true)), 0, 12));
            }
            $code = 'P' . (int)$eventId . '-' . $suffix;
            $st->execute(array(':code'=>$code));
            if (!$st->fetchColumn()) return $code;
        }
        throw new RuntimeException('No se pudo generar el código de la entrada.');
    }
}

if (!function_exists('tickex_door_table_columns')) {
    function tickex_door_table_columns($pdo, $table)
    {
        $out = array();
        foreach ($pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(string)$row['name']] = true;
        }
        return $out;
    }
}

if (!function_exists('tickex_door_confirm_paid_checkin')) {
    function tickex_door_confirm_paid_checkin($pdo, $reservationId, $eventId, $adminId)
    {
        tickex_door_list_ensure_schema($pdo);
        $ownTransaction = !$pdo->inTransaction();
        if ($ownTransaction) $pdo->beginTransaction();
        try {
            $st = $pdo->prepare('SELECT r.*,l.nombre AS list_name,l.ticket_type_id FROM event_door_guest_reservations r JOIN event_door_guest_lists l ON l.id=r.list_id WHERE r.id=:id AND r.evento_id=:event LIMIT 1');
            $st->execute(array(':id'=>(int)$reservationId, ':event'=>(int)$eventId));
            $reservation = $st->fetch(PDO::FETCH_ASSOC);
            if (!$reservation) throw new RuntimeException('La persona no pertenece a esta lista.');
            if ($reservation['status'] === 'paid_checked_in' && (int)$reservation['entrada_id'] > 0) {
                if ($ownTransaction) $pdo->commit();
                return array('ok'=>true, 'already_processed'=>true, 'entrada_id'=>(int)$reservation['entrada_id']);
            }
            if ($reservation['status'] !== 'reserved') throw new RuntimeException('La reserva ya no está disponible.');

            $stType = $pdo->prepare('SELECT id,cantidad_disponible FROM tipos_entrada WHERE id=:type AND evento_id=:event LIMIT 1');
            $stType->execute(array(':type'=>(int)$reservation['ticket_type_id'], ':event'=>(int)$eventId));
            $type = $stType->fetch(PDO::FETCH_ASSOC);
            if (!$type || (int)$type['cantidad_disponible'] <= 0) throw new RuntimeException('No queda stock disponible para esta lista.');

            $now = date('Y-m-d H:i:s');
            $columns = tickex_door_table_columns($pdo, 'entradas');
            $data = array(
                'evento_id'=>(int)$eventId,
                'nombre'=>(string)$reservation['guest_name'],
                'email'=>'',
                'fecha_registro'=>$now,
                'codigo'=>tickex_door_entry_code($pdo, $eventId),
                'checked_in'=>1,
                'checked_in_at'=>$now,
                'tipo'=>(string)$reservation['list_name'],
                'monto_pagado'=>(float)$reservation['price'],
            );
            if (isset($columns['payment_method'])) $data['payment_method'] = 'cash_door';
            if (isset($columns['issuance_key'])) $data['issuance_key'] = 'door-reservation:' . (int)$reservation['id'];
            if (isset($columns['oculto'])) $data['oculto'] = 0;

            $sqlColumns = array();
            $placeholders = array();
            $params = array();
            foreach ($data as $key=>$value) {
                if (!isset($columns[$key])) continue;
                $sqlColumns[] = $key;
                $placeholders[] = ':' . $key;
                $params[':' . $key] = $value;
            }
            $insert = $pdo->prepare('INSERT INTO entradas (' . implode(',', $sqlColumns) . ') VALUES (' . implode(',', $placeholders) . ')');
            $insert->execute($params);
            $entryId = (int)$pdo->lastInsertId();

            $stock = $pdo->prepare('UPDATE tipos_entrada SET cantidad_disponible=cantidad_disponible-1 WHERE id=:type AND evento_id=:event AND cantidad_disponible>0');
            $stock->execute(array(':type'=>(int)$reservation['ticket_type_id'], ':event'=>(int)$eventId));
            if ($stock->rowCount() !== 1) throw new RuntimeException('El stock cambió mientras se procesaba el cobro.');

            $update = $pdo->prepare("UPDATE event_door_guest_reservations SET status='paid_checked_in',entrada_id=:entry,paid_at=:now,checked_in_at=:now,processed_by_admin_id=:admin,updated_at=CURRENT_TIMESTAMP WHERE id=:id AND status='reserved'");
            $update->execute(array(':entry'=>$entryId, ':now'=>$now, ':admin'=>(int)$adminId, ':id'=>(int)$reservationId));
            if ($update->rowCount() !== 1) throw new RuntimeException('La reserva fue procesada simultáneamente.');

            if ($ownTransaction) $pdo->commit();
            return array('ok'=>true, 'already_processed'=>false, 'entrada_id'=>$entryId);
        } catch (Exception $e) {
            if ($ownTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}

if (!function_exists('tickex_door_cancel_reservation')) {
    function tickex_door_cancel_reservation($pdo, $reservationId, $eventId)
    {
        $st = $pdo->prepare("UPDATE event_door_guest_reservations SET status='cancelled',updated_at=CURRENT_TIMESTAMP WHERE id=:id AND evento_id=:event AND status='reserved'");
        $st->execute(array(':id'=>(int)$reservationId, ':event'=>(int)$eventId));
        return $st->rowCount() === 1;
    }
}
