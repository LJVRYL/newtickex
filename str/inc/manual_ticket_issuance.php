<?php

require_once __DIR__ . '/order_processing.php';

if (!function_exists('tickex_manual_ensure_order_columns')) {
    function tickex_manual_ensure_order_columns($pdo)
    {
        $columns = array();
        foreach ($pdo->query('PRAGMA table_info(tc_orders)')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $columns[(string)$row['name']] = true;
        }
        $wanted = array(
            'payment_status' => "TEXT NOT NULL DEFAULT 'pending'",
            'payment_confirmed_at' => 'TEXT',
            'processing_status' => "TEXT NOT NULL DEFAULT 'pending'",
            'processing_started_at' => 'TEXT',
            'email_status' => "TEXT NOT NULL DEFAULT 'pending'",
            'email_attempts' => 'INTEGER NOT NULL DEFAULT 0',
            'email_sent_at' => 'TEXT',
            'email_last_error' => 'TEXT',
            'payment_provider' => 'TEXT',
        );
        foreach ($wanted as $name => $definition) {
            if (!isset($columns[$name])) {
                $pdo->exec('ALTER TABLE tc_orders ADD COLUMN ' . $name . ' ' . $definition);
            }
        }
    }
}

if (!function_exists('tickex_manual_request_id')) {
    function tickex_manual_request_id()
    {
        try {
            return 'manual-' . bin2hex(random_bytes(12));
        } catch (Exception $e) {
            return 'manual-' . sha1(uniqid('', true));
        }
    }
}

if (!function_exists('tickex_manual_issue_package')) {
    function tickex_manual_issue_package($pdo, array $data)
    {
        $eventId = isset($data['evento_id']) ? (int)$data['evento_id'] : 0;
        $ticketTypeId = isset($data['tipo_id']) ? (int)$data['tipo_id'] : 0;
        $packageQuantity = max(1, min(20, isset($data['cantidad']) ? (int)$data['cantidad'] : 1));
        $mode = isset($data['modo']) ? (string)$data['modo'] : 'courtesy';
        $email = trim(isset($data['email']) ? (string)$data['email'] : '');
        $name = trim(isset($data['nombre']) ? (string)$data['nombre'] : '');
        $adminId = isset($data['admin_id']) ? (int)$data['admin_id'] : 0;
        $restrictToAdmin = !empty($data['restrict_to_admin']);
        $hidden = !empty($data['oculto']) ? 1 : 0;

        if ($eventId <= 0 || $ticketTypeId <= 0) throw new InvalidArgumentException('Evento o tipo de entrada inválido.');
        if (!in_array($mode, array('courtesy', 'manual_transfer'), true)) throw new InvalidArgumentException('Modalidad de emisión inválida.');
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('El email del destinatario no es válido.');
        if ($name === '') $name = $email;

        $eventSql = 'SELECT id, nombre, creado_por_admin_id FROM eventos WHERE id = :id';
        if ($restrictToAdmin) $eventSql .= ' AND creado_por_admin_id = :admin';
        $stEvent = $pdo->prepare($eventSql . ' LIMIT 1');
        $eventParams = array(':id' => $eventId);
        if ($restrictToAdmin) $eventParams[':admin'] = $adminId;
        $stEvent->execute($eventParams);
        $event = $stEvent->fetch(PDO::FETCH_ASSOC);
        if (!$event) throw new RuntimeException('No tenés acceso al evento seleccionado.');

        $stType = $pdo->prepare('SELECT id, evento_id, nombre, tipo, precio, cantidad_disponible, qr_quantity FROM tipos_entrada WHERE id = :id AND evento_id = :evento LIMIT 1');
        $stType->execute(array(':id' => $ticketTypeId, ':evento' => $eventId));
        $ticketType = $stType->fetch(PDO::FETCH_ASSOC);
        if (!$ticketType) throw new RuntimeException('El tipo de entrada no pertenece al evento seleccionado.');

        $qrQuantity = tickex_ticket_qr_quantity(isset($ticketType['qr_quantity']) ? $ticketType['qr_quantity'] : 1);
        $issuedQuantity = tickex_ticket_issued_quantity($packageQuantity, $qrQuantity);
        $available = isset($ticketType['cantidad_disponible']) ? (int)$ticketType['cantidad_disponible'] : 0;
        if ($available < $issuedQuantity) {
            throw new RuntimeException('Stock insuficiente: se necesitan ' . $issuedQuantity . ' lugares y quedan ' . $available . '.');
        }

        $packagePrice = $mode === 'manual_transfer' ? max(0, (float)$ticketType['precio']) : 0.0;
        $total = round($packagePrice * $packageQuantity, 2);
        $requestId = tickex_manual_request_id();
        $selected = json_encode(array(array(
            'id' => (int)$ticketType['id'],
            'name' => (string)$ticketType['nombre'],
            'qty' => $packageQuantity,
            'price' => $packagePrice,
            'qr_quantity' => $qrQuantity,
            'hidden' => $hidden,
        )));
        if ($selected === false) throw new RuntimeException('No se pudo preparar el paquete de entradas.');

        tickex_manual_ensure_order_columns($pdo);
        $stInsert = $pdo->prepare("INSERT INTO tc_orders
            (request_id, state, evento_id, ref, concept, amount, buyer_first, buyer_last, buyer_email,
             selected_tickets_json, created_at, updated_at, payment_status, payment_confirmed_at,
             processing_status, email_status, payment_provider)
            VALUES
            (:request_id, 'manual_confirmed', :evento_id, :ref, :concept, :amount, :buyer_first, '', :buyer_email,
             :tickets, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, 'confirmed', CURRENT_TIMESTAMP,
             'pending', 'pending', :provider)");
        $stInsert->execute(array(
            ':request_id' => $requestId,
            ':evento_id' => $eventId,
            ':ref' => $requestId,
            ':concept' => 'Emisión manual - ' . (string)$ticketType['nombre'],
            ':amount' => $total,
            ':buyer_first' => $name,
            ':buyer_email' => $email,
            ':tickets' => $selected,
            ':provider' => $mode,
        ));
        $orderId = (int)$pdo->lastInsertId();
        $order = $pdo->query('SELECT * FROM tc_orders WHERE id = ' . $orderId)->fetch(PDO::FETCH_ASSOC);
        $result = process_tc_order_row($pdo, $order);
        if (empty($result['processed'])) {
            $stFail = $pdo->prepare("UPDATE tc_orders SET state='manual_failed', processing_status='failed', updated_at=CURRENT_TIMESTAMP WHERE id=:id");
            $stFail->execute(array(':id' => $orderId));
            throw new RuntimeException('No se pudieron emitir las entradas. ' . trim(isset($result['debugMsg']) ? $result['debugMsg'] : ''));
        }

        $stCount = $pdo->prepare('SELECT COUNT(*) FROM entradas WHERE tc_order_request_id = :request_id');
        $stCount->execute(array(':request_id' => $requestId));
        $actualIssued = (int)$stCount->fetchColumn();
        $stStatus = $pdo->prepare('SELECT email_status, email_last_error FROM tc_orders WHERE id = :id');
        $stStatus->execute(array(':id' => $orderId));
        $status = $stStatus->fetch(PDO::FETCH_ASSOC);

        return array(
            'order_id' => $orderId,
            'request_id' => $requestId,
            'issued_quantity' => $actualIssued,
            'package_quantity' => $packageQuantity,
            'qr_quantity' => $qrQuantity,
            'total' => $total,
            'mode' => $mode,
            'email_status' => isset($status['email_status']) ? $status['email_status'] : 'pending',
            'email_error' => isset($status['email_last_error']) ? $status['email_last_error'] : '',
        );
    }
}
