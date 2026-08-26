<?php
// Reusable helper for processing tc_orders after payment confirmation.

require_once __DIR__ . '/order_events.php';
require_once __DIR__ . '/secure_links.php';
require_once __DIR__ . '/mail.php';

if (!function_exists('tickex_order_base_url')) {
    function tickex_order_base_url()
    {
        $env = getenv('TICKEX_SITE_URL');
        if (is_string($env) && $env !== '') return rtrim($env, '/');
        $host = isset($_SERVER['HTTP_HOST']) ? (string)$_SERVER['HTTP_HOST'] : '';
        if ($host !== '') {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            return $scheme . '://' . $host;
        }
        return 'https://str.tickex.com.ar';
    }
}

if (!function_exists('tickex_order_email_was_sent')) {
    function tickex_order_email_was_sent($pdo, $entryId)
    {
        $st = $pdo->prepare("SELECT 1 FROM email_logs WHERE related_table = 'entradas' AND related_id = :id AND context = 'entrada_registro' AND mail_ok = 1 LIMIT 1");
        $st->execute(array(':id' => (int)$entryId));
        return (bool)$st->fetchColumn();
    }
}

if (!function_exists('tickex_order_send_emails')) {
    function tickex_order_send_emails($pdo, $order, $entries)
    {
        $sent = 0;
        $pending = 0;
        $lastError = '';
        foreach ($entries as $entry) {
            if (tickex_order_email_was_sent($pdo, $entry['id'])) {
                $sent++;
                continue;
            }

            try {
                $ticketUrl = tickex_secure_ticket_url($pdo, tickex_order_base_url(), $entry['id'], $entry['codigo']);
                $subject = 'Tu entrada para el evento';
                $body = "Hola " . $entry['nombre'] . ",\n\n";
                $body .= "¡Tu pago fue aprobado! Aquí está tu entrada:\n\n";
                $body .= "  Tipo: " . $entry['tipo'] . "\n";
                $body .= "  Fecha de registro: " . $entry['fecha'] . "\n\n";
                $body .= "Para ver tu QR de acceso, abrí este link:\n" . $ticketUrl . "\n\n";
                $body .= "Mostrá este QR en la puerta del evento.\n\nTickex\n";
                $mailOk = tickex_send_mail_template(
                    $entry['email'],
                    'entrada_registro',
                    array(
                        'id' => $entry['id'],
                        'nombre' => $entry['nombre'],
                        'email' => $entry['email'],
                        'tipo' => $entry['tipo'],
                        'fecha_registro' => $entry['fecha'],
                        'ticket_url' => $ticketUrl,
                        'codigo' => $entry['codigo'],
                    ),
                    array('context' => 'entrada_registro', 'related_table' => 'entradas', 'related_id' => $entry['id']),
                    array('subject' => $subject, 'body' => $body, 'from_email' => 'no-reply@tickex.com.ar', 'from_name' => 'Tickex', 'reply_to' => 'no-reply@tickex.com.ar', 'is_html' => 0)
                );
                if ($mailOk) {
                    $sent++;
                } else {
                    $pending++;
                    $lastError = 'mail_returned_false';
                }
            } catch (Exception $e) {
                $pending++;
                $lastError = $e->getMessage();
            }
        }

        $emailStatus = ($pending === 0 && $sent === count($entries)) ? 'sent' : 'pending';
        $st = $pdo->prepare('UPDATE tc_orders SET email_status = :status, email_attempts = COALESCE(email_attempts, 0) + 1, email_sent_at = :sent_at, email_last_error = :error WHERE id = :id');
        $st->execute(array(
            ':status' => $emailStatus,
            ':sent_at' => $emailStatus === 'sent' ? date('Y-m-d H:i:s') : null,
            ':error' => $lastError !== '' ? $lastError : null,
            ':id' => (int)$order['id'],
        ));
        return array('sent' => $sent, 'pending' => $pending, 'status' => $emailStatus, 'error' => $lastError);
    }
}

if (!function_exists('process_tc_order_by_request_id')) {
    function process_tc_order_by_request_id($requestId)
    {
        $requestId = trim((string)$requestId);
        if ($requestId === '') {
            return array(
                'processed' => false,
                'debugMsg' => 'RequestId vacío.',
                'request_id' => '',
                'order_id' => null,
            );
        }

        $pdo = db();
        $stOrd = $pdo->prepare("SELECT * FROM tc_orders WHERE request_id = :rid LIMIT 1");
        $stOrd->execute(array(':rid' => $requestId));
        $order = $stOrd->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            log_order_event($pdo, null, $requestId, 'order_not_found', array('request_id' => $requestId));
            return array(
                'processed' => false,
                'debugMsg' => 'Orden no encontrada. ',
                'request_id' => $requestId,
                'order_id' => null,
            );
        }

        $result = process_tc_order_row($pdo, $order);
        log_order_event($pdo, (int)$order['id'], $requestId, 'order_processing_result', array(
            'processed' => !empty($result['processed']),
            'debugMsg' => $result['debugMsg'],
        ));
        $result['request_id'] = $requestId;
        $result['order_id'] = isset($order['id']) ? (int)$order['id'] : null;
        return $result;
    }
}

if (!function_exists('process_tc_order_row')) {
    function process_tc_order_row($pdo, array $order)
    {
        $debugMsg = '';
        $processed = false;

        $requestId = isset($order['request_id']) ? (string)$order['request_id'] : '';
        $processedAt = isset($order['processed_at']) ? $order['processed_at'] : null;
        $paymentStatus = isset($order['payment_status']) ? (string)$order['payment_status'] : '';
        if ($paymentStatus !== 'confirmed') {
            return array('processed' => false, 'debugMsg' => 'Pago no confirmado por webhook. ', 'order_id' => (int)$order['id'], 'request_id' => $requestId);
        }
        $ticketsJson = isset($order['selected_tickets_json']) ? $order['selected_tickets_json'] : null;
        $debugMsg .= 'ProcessedAt: ' . ($processedAt === null ? 'NULL' : 'SET') . '. ';
        $debugMsg .= 'TicketsJson: ' . (empty($ticketsJson) ? 'EMPTY' : 'HAS_DATA') . '. ';

        if (!empty($processedAt)) {
            $stEntries = $pdo->prepare('SELECT id, codigo, nombre, email, tipo, fecha_registro AS fecha FROM entradas WHERE tc_order_request_id = :rid ORDER BY id ASC');
            $stEntries->execute(array(':rid' => $requestId));
            $existingEntries = $stEntries->fetchAll(PDO::FETCH_ASSOC);
            if (count($existingEntries) > 0) tickex_order_send_emails($pdo, $order, $existingEntries);
            $debugMsg .= 'Orden ya procesada. ';
            log_order_event($pdo, (int)$order['id'], $requestId, 'order_already_processed', array('processed_at' => $processedAt));
            return array(
                'processed' => true,
                'debugMsg' => $debugMsg,
                'order_id' => isset($order['id']) ? (int)$order['id'] : null,
                'request_id' => $requestId,
            );
        }

        if (empty($ticketsJson)) {
            $debugMsg .= 'Sin tickets. ';
            log_order_event($pdo, (int)$order['id'], $requestId, 'order_no_tickets', array());
            return array(
                'processed' => false,
                'debugMsg' => $debugMsg,
                'order_id' => isset($order['id']) ? (int)$order['id'] : null,
                'request_id' => $requestId,
            );
        }

        $tickets = json_decode($ticketsJson, true);
        $debugMsg .= 'JSON válido: ' . (is_array($tickets) ? 'YES' : 'NO') . '. ';
        if (!is_array($tickets)) {
            log_order_event($pdo, (int)$order['id'], $requestId, 'order_invalid_tickets_json', array('selected_tickets_json' => $ticketsJson));
            return array(
                'processed' => false,
                'debugMsg' => $debugMsg,
                'order_id' => isset($order['id']) ? (int)$order['id'] : null,
                'request_id' => $requestId,
            );
        }

        $expectedQty = 0;
        foreach ($tickets as $ticket) {
            $expectedQty += max(0, (int)(isset($ticket['qty']) ? $ticket['qty'] : 0));
        }
        if ($expectedQty <= 0) {
            $debugMsg .= 'Cantidad de tickets inválida. ';
            return array(
                'processed' => false,
                'debugMsg' => $debugMsg,
                'order_id' => isset($order['id']) ? (int)$order['id'] : null,
                'request_id' => $requestId,
            );
        }

        $eventoId = (int)$order['evento_id'];
        $deferredOrderEvents = array();
        $claim = $pdo->prepare("UPDATE tc_orders SET processing_status = 'processing', processing_started_at = CURRENT_TIMESTAMP WHERE id = :id AND payment_status = 'confirmed' AND (processing_status IS NULL OR processing_status = 'pending' OR (processing_status = 'processing' AND processing_started_at < datetime('now', '-15 minutes')))");
        $claim->execute(array(':id' => (int)$order['id']));
        if ($claim->rowCount() !== 1) {
            return array('processed' => false, 'debugMsg' => 'Orden ya reclamada por otro proceso. ', 'order_id' => (int)$order['id'], 'request_id' => $requestId);
        }

        $pdo->beginTransaction();
        $insertedEntries = array();
        try {
            $buyerName = trim((isset($order['buyer_first']) ? $order['buyer_first'] : '') . ' ' . (isset($order['buyer_last']) ? $order['buyer_last'] : ''));
            $buyerEmail = isset($order['buyer_email']) ? $order['buyer_email'] : '';
            $fechaReg = date('Y-m-d H:i:s');

            $entradasCreadas = 0;
            foreach ($tickets as $ticket) {
                $tipoId = (int)(isset($ticket['id']) ? $ticket['id'] : 0);
                $tipoName = isset($ticket['name']) ? $ticket['name'] : 'General';
                $qty = max(0, (int)(isset($ticket['qty']) ? $ticket['qty'] : 1));
                $price = (int)(isset($ticket['price']) ? $ticket['price'] : 0);

                for ($i = 0; $i < $qty; $i++) {
                    $codigo = '';
                    if (function_exists('random_bytes')) {
                        try {
                            $codigo = bin2hex(random_bytes(5));
                        } catch (Exception $_e) {
                            $codigo = substr(sha1(uniqid('', true)), 0, 10);
                        }
                    } else {
                        $codigo = substr(sha1(uniqid('', true)), 0, 10);
                    }

                    $issuanceKey = $requestId . ':' . $tipoId . ':' . $i;
                    $stIns = $pdo->prepare("INSERT INTO entradas (evento_id, nombre, email, fecha_registro, codigo, checked_in, checked_in_at, tipo, monto_pagado, tc_order_request_id, issuance_key) VALUES (:eid, :nom, :em, :fec, :cod, 0, NULL, :tipo, :monto, :rid, :ikey)");
                    $stIns->execute(array(
                        ':eid'  => $eventoId,
                        ':nom'  => $buyerName,
                        ':em'   => $buyerEmail,
                        ':fec'  => $fechaReg,
                        ':cod'  => $codigo,
                        ':tipo' => $tipoName,
                        ':monto'=> $price,
                        ':rid'  => $requestId,
                        ':ikey' => $issuanceKey,
                    ));
                    $entradaId = (int)$pdo->lastInsertId();
                    $entradasCreadas++;

                    $insertedEntries[] = array(
                        'id'     => $entradaId,
                        'codigo' => $codigo,
                        'nombre' => $buyerName,
                        'email'  => $buyerEmail,
                        'tipo'   => $tipoName,
                        'fecha'  => $fechaReg,
                    );

                    // Descontar stock — nunca debe bloquear la entrega de la entrada
                    if ($tipoId > 0) {
                        try {
                            $stUpd = $pdo->prepare("UPDATE tipos_entrada SET cantidad_disponible = cantidad_disponible - 1 WHERE id = :tid AND cantidad_disponible > 0");
                            $stUpd->execute(array(':tid' => $tipoId));
                            if ($stUpd->rowCount() !== 1) {
                                throw new Exception('Stock insuficiente para tipo #' . $tipoId . '.');
                            }
                        } catch (Exception $_stockEx) {
                            throw $_stockEx;
                        }
                    }
                }
            }

            $stProc = $pdo->prepare("UPDATE tc_orders SET processed_at = datetime('now') WHERE request_id = :rid");
            $stProc->execute(array(':rid' => $requestId));
            $stState = $pdo->prepare("UPDATE tc_orders SET processing_status = 'issued' WHERE id = :id");
            $stState->execute(array(':id' => (int)$order['id']));

            $pdo->commit();
            $processed = true;
            $debugMsg .= 'Entradas creadas: ' . $entradasCreadas . '. ';
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            try {
                $stReset = $pdo->prepare("UPDATE tc_orders SET processing_status = 'pending', processing_started_at = NULL WHERE id = :id AND processing_status = 'processing'");
                $stReset->execute(array(':id' => (int)$order['id']));
            } catch (Exception $_resetEx) {
            }
            $insertedEntries = array();
            $debugMsg .= 'Error CREATE: ' . $e->getMessage() . '. ';
            log_order_event($pdo, (int)$order['id'], $requestId, 'order_processing_failed', array('exception' => $e->getMessage()));
        }

        // Escribir eventos diferidos fuera del tramo transaccional principal.
        if (!empty($deferredOrderEvents)) {
            foreach ($deferredOrderEvents as $evt) {
                log_order_event(
                    $pdo,
                    isset($evt['tc_order_id']) ? $evt['tc_order_id'] : null,
                    isset($evt['request_id']) ? $evt['request_id'] : null,
                    isset($evt['event_type']) ? $evt['event_type'] : 'order_event',
                    isset($evt['payload']) ? $evt['payload'] : array()
                );
            }
        }

        // Generar token y enviar mail por cada entrada creada (fuera de la transacción)
        if ($processed && !empty($insertedEntries)) {
            $mailResult = tickex_order_send_emails($pdo, $order, $insertedEntries);
            $debugMsg .= 'Mails enviados: ' . $mailResult['sent'] . '/' . count($insertedEntries) . '. ';
        }

        return array(
            'processed' => $processed,
            'debugMsg' => $debugMsg,
            'order_id' => isset($order['id']) ? (int)$order['id'] : null,
            'request_id' => $requestId,
        );
    }
}
