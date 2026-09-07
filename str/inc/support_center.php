<?php

if (!function_exists('tickex_support_ensure_schema')) {
    function tickex_support_ensure_schema($pdo)
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS support_tickets (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            public_id TEXT NOT NULL UNIQUE,
            admin_id INTEGER NOT NULL,
            event_id INTEGER,
            category TEXT NOT NULL,
            subject TEXT NOT NULL,
            priority TEXT NOT NULL DEFAULT 'normal',
            status TEXT NOT NULL DEFAULT 'open',
            created_at TEXT NOT NULL DEFAULT (datetime('now')),
            updated_at TEXT NOT NULL DEFAULT (datetime('now')),
            last_activity_at TEXT NOT NULL DEFAULT (datetime('now')),
            closed_at TEXT
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS support_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ticket_id INTEGER NOT NULL,
            author_admin_id INTEGER NOT NULL,
            author_role TEXT NOT NULL,
            body TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        )");
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_support_tickets_admin ON support_tickets(admin_id, last_activity_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_support_tickets_status ON support_tickets(status, priority, last_activity_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_support_messages_ticket ON support_messages(ticket_id, id)');
    }
}

if (!function_exists('tickex_support_public_id')) {
    function tickex_support_public_id()
    {
        try { $random = strtoupper(bin2hex(random_bytes(3))); }
        catch (Exception $e) { $random = strtoupper(substr(sha1(uniqid('', true)), 0, 6)); }
        return 'SOP-' . date('ymd') . '-' . $random;
    }
}

if (!function_exists('tickex_support_categories')) {
    function tickex_support_categories()
    {
        return array(
            'payments' => 'Pagos y acreditaciones',
            'tickets' => 'Entradas y QR',
            'events' => 'Configuración del evento',
            'communication' => 'Comunicación y emails',
            'staff' => 'Staff y accesos',
            'billing' => 'Facturación',
            'idea' => 'Sugerencia o mejora',
            'other' => 'Otra consulta',
        );
    }
}

if (!function_exists('tickex_support_statuses')) {
    function tickex_support_statuses()
    {
        return array('open'=>'Abierta', 'in_progress'=>'En revisión', 'waiting_client'=>'Esperando respuesta', 'resolved'=>'Resuelta', 'closed'=>'Cerrada');
    }
}

if (!function_exists('tickex_support_priorities')) {
    function tickex_support_priorities()
    {
        return array('low'=>'Baja', 'normal'=>'Normal', 'high'=>'Alta', 'urgent'=>'Urgente');
    }
}

if (!function_exists('tickex_support_admin_events')) {
    function tickex_support_admin_events($pdo, $adminId, $isSuper = false)
    {
        $sql = 'SELECT id,nombre FROM eventos WHERE borrado_en IS NULL';
        $params = array();
        if (!$isSuper) { $sql .= ' AND creado_por_admin_id=:admin'; $params[':admin'] = (int)$adminId; }
        $sql .= ' ORDER BY id DESC';
        $st = $pdo->prepare($sql); $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('tickex_support_event_is_owned')) {
    function tickex_support_event_is_owned($pdo, $adminId, $eventId)
    {
        if ((int)$eventId <= 0) return true;
        $st = $pdo->prepare('SELECT 1 FROM eventos WHERE id=:event AND creado_por_admin_id=:admin AND borrado_en IS NULL LIMIT 1');
        $st->execute(array(':event'=>(int)$eventId, ':admin'=>(int)$adminId));
        return (bool)$st->fetchColumn();
    }
}

if (!function_exists('tickex_support_create_ticket')) {
    function tickex_support_create_ticket($pdo, $adminId, $data)
    {
        tickex_support_ensure_schema($pdo);
        $adminId = (int)$adminId;
        $eventId = isset($data['event_id']) ? (int)$data['event_id'] : 0;
        $category = isset($data['category']) ? trim((string)$data['category']) : '';
        $subject = isset($data['subject']) ? trim((string)$data['subject']) : '';
        $body = isset($data['body']) ? trim((string)$data['body']) : '';
        $priority = isset($data['priority']) ? trim((string)$data['priority']) : 'normal';
        if ($adminId <= 0) return array(false, 'Cuenta inválida.', 0);
        if (!isset(tickex_support_categories()[$category])) return array(false, 'Elegí un tipo de consulta.', 0);
        if (!isset(tickex_support_priorities()[$priority])) $priority = 'normal';
        if ($subject === '' || strlen($subject) < 5 || strlen($subject) > 140) return array(false, 'El asunto debe tener entre 5 y 140 caracteres.', 0);
        if ($body === '' || strlen($body) < 10 || strlen($body) > 8000) return array(false, 'Contanos el problema con un poco más de detalle.', 0);
        if (!tickex_support_event_is_owned($pdo, $adminId, $eventId)) return array(false, 'El evento seleccionado no pertenece a tu cuenta.', 0);
        $pdo->beginTransaction();
        try {
            $publicId = tickex_support_public_id();
            $st = $pdo->prepare("INSERT INTO support_tickets(public_id,admin_id,event_id,category,subject,priority,status,created_at,updated_at,last_activity_at) VALUES(:public,:admin,:event,:category,:subject,:priority,'open',datetime('now'),datetime('now'),datetime('now'))");
            $st->execute(array(':public'=>$publicId, ':admin'=>$adminId, ':event'=>$eventId > 0 ? $eventId : null, ':category'=>$category, ':subject'=>$subject, ':priority'=>$priority));
            $ticketId = (int)$pdo->lastInsertId();
            $msg = $pdo->prepare("INSERT INTO support_messages(ticket_id,author_admin_id,author_role,body,created_at) VALUES(:ticket,:author,'client',:body,datetime('now'))");
            $msg->execute(array(':ticket'=>$ticketId, ':author'=>$adminId, ':body'=>$body));
            $pdo->commit();
            return array(true, 'Consulta creada correctamente.', $ticketId);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return array(false, 'No pudimos crear la consulta.', 0);
        }
    }
}

if (!function_exists('tickex_support_list_tickets')) {
    function tickex_support_list_tickets($pdo, $adminId, $isSuper, $filters = array())
    {
        tickex_support_ensure_schema($pdo);
        $where = array('1=1'); $params = array();
        if (!$isSuper) { $where[] = 't.admin_id=:admin'; $params[':admin'] = (int)$adminId; }
        foreach (array('status','priority','category') as $key) {
            if (!empty($filters[$key])) { $where[] = 't.' . $key . '=:' . $key; $params[':' . $key] = (string)$filters[$key]; }
        }
        $sql = "SELECT t.*,e.nombre AS event_name,a.email AS admin_email,a.apodo AS admin_name,
            (SELECT COUNT(*) FROM support_messages m WHERE m.ticket_id=t.id) AS message_count
            FROM support_tickets t LEFT JOIN eventos e ON e.id=t.event_id LEFT JOIN usuarios_admin a ON a.id=t.admin_id
            WHERE " . implode(' AND ', $where) . " ORDER BY CASE t.priority WHEN 'urgent' THEN 1 WHEN 'high' THEN 2 WHEN 'normal' THEN 3 ELSE 4 END, datetime(t.last_activity_at) DESC, t.id DESC LIMIT 250";
        $st = $pdo->prepare($sql); $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('tickex_support_get_ticket')) {
    function tickex_support_get_ticket($pdo, $ticketId, $adminId, $isSuper)
    {
        tickex_support_ensure_schema($pdo);
        $sql = "SELECT t.*,e.nombre AS event_name,a.email AS admin_email,a.apodo AS admin_name FROM support_tickets t LEFT JOIN eventos e ON e.id=t.event_id LEFT JOIN usuarios_admin a ON a.id=t.admin_id WHERE t.id=:id";
        $params = array(':id'=>(int)$ticketId);
        if (!$isSuper) { $sql .= ' AND t.admin_id=:admin'; $params[':admin'] = (int)$adminId; }
        $sql .= ' LIMIT 1';
        $st = $pdo->prepare($sql); $st->execute($params); $ticket = $st->fetch(PDO::FETCH_ASSOC);
        if (!$ticket) return null;
        $messages = $pdo->prepare('SELECT m.*,a.email AS author_email,a.apodo AS author_name FROM support_messages m LEFT JOIN usuarios_admin a ON a.id=m.author_admin_id WHERE m.ticket_id=:ticket ORDER BY m.id ASC');
        $messages->execute(array(':ticket'=>(int)$ticket['id']));
        $ticket['messages'] = $messages->fetchAll(PDO::FETCH_ASSOC);
        return $ticket;
    }
}

if (!function_exists('tickex_support_add_message')) {
    function tickex_support_add_message($pdo, $ticketId, $adminId, $isSuper, $body)
    {
        $ticket = tickex_support_get_ticket($pdo, $ticketId, $adminId, $isSuper);
        $body = trim((string)$body);
        if (!$ticket) return array(false, 'Consulta no encontrada.');
        if ($body === '' || strlen($body) < 2 || strlen($body) > 8000) return array(false, 'Escribí un mensaje válido.');
        if (!$isSuper && in_array($ticket['status'], array('resolved','closed'), true)) return array(false, 'Reabrí la consulta antes de responder.');
        $pdo->beginTransaction();
        try {
            $role = $isSuper ? 'support' : 'client';
            $st = $pdo->prepare('INSERT INTO support_messages(ticket_id,author_admin_id,author_role,body,created_at) VALUES(:ticket,:author,:role,:body,datetime(\'now\'))');
            $st->execute(array(':ticket'=>(int)$ticketId, ':author'=>(int)$adminId, ':role'=>$role, ':body'=>$body));
            $newStatus = $isSuper ? 'waiting_client' : 'open';
            $up = $pdo->prepare("UPDATE support_tickets SET status=:status,updated_at=datetime('now'),last_activity_at=datetime('now'),closed_at=NULL WHERE id=:id");
            $up->execute(array(':status'=>$newStatus, ':id'=>(int)$ticketId));
            $pdo->commit();
            return array(true, 'Respuesta agregada.');
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return array(false, 'No pudimos guardar la respuesta.');
        }
    }
}

if (!function_exists('tickex_support_update_ticket')) {
    function tickex_support_update_ticket($pdo, $ticketId, $adminId, $isSuper, $status, $priority = '')
    {
        $ticket = tickex_support_get_ticket($pdo, $ticketId, $adminId, $isSuper);
        if (!$ticket) return array(false, 'Consulta no encontrada.');
        if (!$isSuper) {
            if (!in_array($status, array('open','closed'), true)) return array(false, 'Estado inválido.');
            $priority = (string)$ticket['priority'];
        }
        if (!isset(tickex_support_statuses()[$status])) return array(false, 'Estado inválido.');
        if (!isset(tickex_support_priorities()[$priority])) $priority = (string)$ticket['priority'];
        $closedAt = in_array($status, array('resolved','closed'), true) ? date('Y-m-d H:i:s') : null;
        $st = $pdo->prepare("UPDATE support_tickets SET status=:status,priority=:priority,closed_at=:closed,updated_at=datetime('now'),last_activity_at=datetime('now') WHERE id=:id");
        $st->execute(array(':status'=>$status, ':priority'=>$priority, ':closed'=>$closedAt, ':id'=>(int)$ticketId));
        return array(true, 'Estado actualizado.');
    }
}

if (!function_exists('tickex_support_counts')) {
    function tickex_support_counts($pdo, $adminId, $isSuper)
    {
        $counts = array('all'=>0, 'open'=>0, 'in_progress'=>0, 'waiting_client'=>0, 'resolved'=>0, 'closed'=>0);
        tickex_support_ensure_schema($pdo);
        $sql = 'SELECT status,COUNT(*) AS total FROM support_tickets';
        $params = array();
        if (!$isSuper) { $sql .= ' WHERE admin_id=:admin'; $params[':admin'] = (int)$adminId; }
        $sql .= ' GROUP BY status';
        $st = $pdo->prepare($sql); $st->execute($params);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $total = (int)$row['total']; $counts['all'] += $total;
            if (isset($counts[$row['status']])) $counts[$row['status']] = $total;
        }
        return $counts;
    }
}
