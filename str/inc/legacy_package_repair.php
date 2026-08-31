<?php

if (!function_exists('tickex_repair_random_code')) {
    function tickex_repair_random_code()
    {
        try {
            return 'RPR-' . strtoupper(bin2hex(random_bytes(6)));
        } catch (Exception $e) {
            return 'RPR-' . strtoupper(substr(sha1(uniqid('', true)), 0, 12));
        }
    }
}

if (!function_exists('tickex_repair_clone_entry')) {
    function tickex_repair_clone_entry($pdo, array $source, $amount, $requestId, $issuanceKey)
    {
        $st = $pdo->prepare("INSERT INTO entradas
            (evento_id,nombre,email,fecha_registro,codigo,checked_in,checked_in_at,tipo,monto_pagado,tc_order_request_id,issuance_key,oculto)
            VALUES (:evento,:nombre,:email,CURRENT_TIMESTAMP,:codigo,0,NULL,:tipo,:monto,:request,:issuance,:oculto)");
        $st->execute(array(
            ':evento' => (int)$source['evento_id'],
            ':nombre' => (string)$source['nombre'],
            ':email' => (string)$source['email'],
            ':codigo' => tickex_repair_random_code(),
            ':tipo' => (string)$source['tipo'],
            ':monto' => (float)$amount,
            ':request' => (string)$requestId,
            ':issuance' => (string)$issuanceKey,
            ':oculto' => isset($source['oculto']) ? (int)$source['oculto'] : 0,
        ));
        return (int)$pdo->lastInsertId();
    }
}

if (!function_exists('tickex_event15_package_repair_summary')) {
    function tickex_event15_package_repair_summary($pdo)
    {
        $summary = array();
        $summary['issued'] = (int)$pdo->query('SELECT COUNT(*) FROM entradas WHERE evento_id=15')->fetchColumn();
        $summary['paid_qr'] = (int)$pdo->query('SELECT COUNT(*) FROM entradas WHERE evento_id=15 AND monto_pagado>0')->fetchColumn();
        $summary['free_qr'] = $summary['issued'] - $summary['paid_qr'];
        $summary['revenue'] = (float)$pdo->query('SELECT COALESCE(SUM(monto_pagado),0) FROM entradas WHERE evento_id=15')->fetchColumn();
        $stock = $pdo->query('SELECT COALESCE(SUM(cantidad_total),0) total, COALESCE(SUM(cantidad_disponible),0) available FROM tipos_entrada WHERE evento_id=15')->fetch(PDO::FETCH_ASSOC);
        $summary['stock_total'] = (int)$stock['total'];
        $summary['available'] = (int)$stock['available'];
        return $summary;
    }
}

if (!function_exists('tickex_repair_event15_ticket_packages')) {
    function tickex_repair_event15_ticket_packages($pdo, $apply)
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS maintenance_migrations (name TEXT PRIMARY KEY, applied_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, details_json TEXT)");
        $marker = '20260831_event15_ticket_packages';
        $stMarker = $pdo->prepare('SELECT details_json FROM maintenance_migrations WHERE name=:name LIMIT 1');
        $stMarker->execute(array(':name' => $marker));
        $existing = $stMarker->fetchColumn();
        if ($existing !== false) {
            return array('already_applied' => true, 'summary' => tickex_event15_package_repair_summary($pdo));
        }

        $requiredTypes = array(
            39 => array('name' => 'General Puerta', 'price' => 15000),
            41 => array('name' => 'Promo 2x1', 'price' => 25000),
            42 => array('name' => 'Promo 3x4', 'price' => 40000),
            44 => array('name' => 'Cortesia', 'price' => 0),
        );
        $stType = $pdo->prepare('SELECT * FROM tipos_entrada WHERE id=:id AND evento_id=15 LIMIT 1');
        foreach ($requiredTypes as $id => $expected) {
            $stType->execute(array(':id' => $id));
            $row = $stType->fetch(PDO::FETCH_ASSOC);
            if (!$row || (string)$row['nombre'] !== $expected['name']) {
                throw new RuntimeException('El tipo #' . $id . ' no coincide con la configuración esperada.');
            }
        }

        $requiredEntries = array(880 => array('Promo 2x1', 25000), 882 => array('Promo 3x4', 40000), 883 => array('Promo 3x4', 40000), 893 => array('Promo 3x4', 0));
        $entries = array();
        $stEntry = $pdo->prepare('SELECT * FROM entradas WHERE id=:id AND evento_id=15 LIMIT 1');
        foreach ($requiredEntries as $id => $expected) {
            $stEntry->execute(array(':id' => $id));
            $row = $stEntry->fetch(PDO::FETCH_ASSOC);
            if (!$row || (string)$row['tipo'] !== $expected[0] || abs((float)$row['monto_pagado'] - (float)$expected[1]) > 0.001) {
                throw new RuntimeException('La entrada #' . $id . ' cambió y la reparación fue cancelada.');
            }
            $entries[$id] = $row;
        }

        if (!$apply) {
            return array('already_applied' => false, 'dry_run' => true, 'summary' => tickex_event15_package_repair_summary($pdo));
        }

        $requestTwo = '94304388-6386-404e-a32f-5bf803602c96';
        $requestFour = 'd0fa7a2d-9c4c-460c-97ba-0ec351e31ab4';
        $requestManual = 'manual-repair-event15-entry893';
        $pdo->beginTransaction();
        try {
            $pdo->exec("UPDATE tipos_entrada SET cantidad_total=60 WHERE id=39 AND evento_id=15 AND cantidad_total=0 AND cantidad_disponible=60");
            $pdo->exec("UPDATE tipos_entrada SET qr_quantity=2 WHERE id=41 AND evento_id=15");
            $pdo->exec("UPDATE tipos_entrada SET qr_quantity=4 WHERE id=42 AND evento_id=15");

            $ticketsTwo = json_encode(array(array('id' => 41, 'name' => 'Promo 2x1', 'qty' => 1, 'price' => 25000, 'qr_quantity' => 2)));
            $stOrderTwo = $pdo->prepare("UPDATE tc_orders SET selected_tickets_json=:tickets,payment_status='confirmed',payment_confirmed_at=COALESCE(payment_confirmed_at,processed_at,CURRENT_TIMESTAMP),processing_status='issued',state='success',updated_at=CURRENT_TIMESTAMP WHERE request_id=:request AND evento_id=15 AND amount=25000");
            $stOrderTwo->execute(array(':tickets' => $ticketsTwo, ':request' => $requestTwo));
            if ($stOrderTwo->rowCount() !== 1) throw new RuntimeException('No se pudo validar la orden 2x1.');
            $stUpdateEntry = $pdo->prepare('UPDATE entradas SET monto_pagado=:amount,tc_order_request_id=:request,issuance_key=:issuance WHERE id=:id');
            $stUpdateEntry->execute(array(':amount' => 12500, ':request' => $requestTwo, ':issuance' => $requestTwo . ':41:0', ':id' => 880));
            tickex_repair_clone_entry($pdo, $entries[880], 12500, $requestTwo, $requestTwo . ':41:1');

            $ticketsFour = json_encode(array(array('id' => 42, 'name' => 'Promo 3x4', 'qty' => 2, 'price' => 40000, 'qr_quantity' => 4)));
            $stOrderFour = $pdo->prepare("UPDATE tc_orders SET selected_tickets_json=:tickets,processing_status='issued',updated_at=CURRENT_TIMESTAMP WHERE request_id=:request AND evento_id=15 AND amount=80000 AND payment_status='confirmed'");
            $stOrderFour->execute(array(':tickets' => $ticketsFour, ':request' => $requestFour));
            if ($stOrderFour->rowCount() !== 1) throw new RuntimeException('No se pudo validar la orden 3x4.');
            $stUpdateEntry->execute(array(':amount' => 10000, ':request' => $requestFour, ':issuance' => $requestFour . ':42:0', ':id' => 882));
            $stUpdateEntry->execute(array(':amount' => 10000, ':request' => $requestFour, ':issuance' => $requestFour . ':42:1', ':id' => 883));
            for ($i = 2; $i < 8; $i++) tickex_repair_clone_entry($pdo, $entries[882], 10000, $requestFour, $requestFour . ':42:' . $i);

            $ticketsManual = json_encode(array(array('id' => 42, 'name' => 'Promo 3x4', 'qty' => 1, 'price' => 0, 'qr_quantity' => 4)));
            $stManual = $pdo->prepare("INSERT INTO tc_orders
                (request_id,state,evento_id,ref,concept,amount,buyer_first,buyer_last,buyer_email,selected_tickets_json,created_at,updated_at,processed_at,payment_status,payment_confirmed_at,processing_status,email_status,payment_provider)
                VALUES (:request,'manual_confirmed',15,:request,'Reparación emisión manual Promo 3x4',0,:name,'',:email,:tickets,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,'confirmed',CURRENT_TIMESTAMP,'issued','pending','courtesy')");
            $stManual->execute(array(':request' => $requestManual, ':name' => $entries[893]['nombre'], ':email' => $entries[893]['email'], ':tickets' => $ticketsManual));
            $stUpdateEntry->execute(array(':amount' => 0, ':request' => $requestManual, ':issuance' => $requestManual . ':42:0', ':id' => 893));
            for ($i = 1; $i < 4; $i++) tickex_repair_clone_entry($pdo, $entries[893], 0, $requestManual, $requestManual . ':42:' . $i);

            $stCount = $pdo->prepare('SELECT COUNT(*) FROM entradas WHERE evento_id=15 AND tipo=:type');
            $stStock = $pdo->prepare('UPDATE tipos_entrada SET cantidad_disponible=MAX(0,cantidad_total-:issued) WHERE id=:id AND evento_id=15');
            foreach ($requiredTypes as $typeId => $expected) {
                $stCount->execute(array(':type' => $expected['name']));
                $stStock->execute(array(':issued' => (int)$stCount->fetchColumn(), ':id' => $typeId));
            }

            $summary = tickex_event15_package_repair_summary($pdo);
            if ($summary['issued'] !== 22 || $summary['paid_qr'] !== 10 || abs($summary['revenue'] - 105000.0) > 0.001 || $summary['stock_total'] !== 260 || $summary['available'] !== 238) {
                throw new RuntimeException('Los totales finales no coinciden con la reparación esperada.');
            }
            $stMigration = $pdo->prepare('INSERT INTO maintenance_migrations (name,details_json) VALUES (:name,:details)');
            $stMigration->execute(array(':name' => $marker, ':details' => json_encode($summary)));
            $pdo->commit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        return array('already_applied' => false, 'applied' => true, 'summary' => tickex_event15_package_repair_summary($pdo));
    }
}

if (!function_exists('tickex_send_event15_package_repair_emails')) {
    function tickex_send_event15_package_repair_emails($pdo)
    {
        require_once __DIR__ . '/secure_links.php';
        require_once __DIR__ . '/mail.php';
        tickex_mail_ensure_schema($pdo);
        $requests = array(
            '94304388-6386-404e-a32f-5bf803602c96',
            'd0fa7a2d-9c4c-460c-97ba-0ec351e31ab4',
            'manual-repair-event15-entry893',
        );
        $results = array();
        $baseUrl = getenv('TICKEX_SITE_URL');
        if (!is_string($baseUrl) || trim($baseUrl) === '') $baseUrl = 'https://str.tickex.com.ar';
        foreach ($requests as $requestId) {
            $stOrder = $pdo->prepare('SELECT * FROM tc_orders WHERE request_id=:request AND evento_id=15 LIMIT 1');
            $stOrder->execute(array(':request' => $requestId));
            $order = $stOrder->fetch(PDO::FETCH_ASSOC);
            if (!$order) throw new RuntimeException('No se encontró la orden reparada ' . $requestId . '.');
            $orderId = (int)$order['id'];
            $stSent = $pdo->prepare("SELECT 1 FROM email_logs WHERE context='entradas_reparacion_paquete' AND related_table='tc_orders' AND related_id=:id AND mail_ok=1 LIMIT 1");
            $stSent->execute(array(':id' => $orderId));
            if ($stSent->fetchColumn()) {
                $results[] = array('request_id' => $requestId, 'status' => 'already_sent');
                continue;
            }
            $stEntries = $pdo->prepare('SELECT id,codigo,tipo FROM entradas WHERE tc_order_request_id=:request ORDER BY id');
            $stEntries->execute(array(':request' => $requestId));
            $entries = $stEntries->fetchAll(PDO::FETCH_ASSOC);
            if (empty($entries)) throw new RuntimeException('La orden reparada no tiene entradas.');
            $lines = array();
            foreach ($entries as $index => $entry) {
                $url = tickex_secure_ticket_url($pdo, rtrim($baseUrl, '/'), (int)$entry['id'], (string)$entry['codigo']);
                $lines[] = 'Entrada ' . ($index + 1) . ' — ' . $entry['tipo'] . "\n" . $url;
            }
            $name = trim((string)$order['buyer_first'] . ' ' . (string)$order['buyer_last']);
            $email = trim((string)$order['buyer_email']);
            $quantity = count($entries);
            $body = "Hola " . ($name !== '' ? $name : $email) . ",\n\n";
            $body .= "Actualizamos tu paquete de entradas para que incluya todos sus QR. A continuación tenés los " . $quantity . " enlaces independientes:\n\n";
            $body .= implode("\n\n", $lines);
            $body .= "\n\nCada persona debe presentar un QR diferente. Los enlaces que ya recibiste siguen siendo válidos.\n\nTickex\n";
            $ok = tickex_send_mail_template(
                $email,
                'entradas_reparacion_paquete',
                array('nombre' => $name, 'email' => $email, 'cantidad' => $quantity, 'entradas' => implode("\n\n", $lines)),
                array('context' => 'entradas_reparacion_paquete', 'related_table' => 'tc_orders', 'related_id' => $orderId),
                array('subject' => 'Tus ' . $quantity . ' entradas actualizadas', 'body' => $body, 'from_email' => 'no-reply@tickex.com.ar', 'from_name' => 'Tickex', 'reply_to' => 'no-reply@tickex.com.ar', 'is_html' => 0)
            );
            $results[] = array('request_id' => $requestId, 'email' => $email, 'quantity' => $quantity, 'status' => $ok ? 'sent' : 'failed');
        }
        return $results;
    }
}
