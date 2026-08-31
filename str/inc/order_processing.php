<?php
// Reusable helper for processing tc_orders after payment confirmation.

require_once __DIR__ . '/order_events.php';
require_once __DIR__ . '/secure_links.php';
require_once __DIR__ . '/mail.php';
require_once __DIR__ . '/ticket_packages.php';

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

if (!function_exists('tickex_order_email_context')) {
    function tickex_order_email_context($order)
    {
        return isset($order['payment_provider']) && $order['payment_provider'] === 'courtesy'
            ? 'tickex_cortesia'
            : 'entradas_compra';
    }
}

if (!function_exists('tickex_order_batch_email_was_sent')) {
    function tickex_order_batch_email_was_sent($pdo, $orderId, $context)
    {
        $st = $pdo->prepare("SELECT 1 FROM email_logs WHERE related_table = 'tc_orders' AND related_id = :id AND context = :context AND mail_ok = 1 LIMIT 1");
        $st->execute(array(':id' => (int)$orderId, ':context' => $context));
        return (bool)$st->fetchColumn();
    }
}

if (!function_exists('tickex_order_legacy_emails_were_sent')) {
    function tickex_order_legacy_emails_were_sent($pdo, $entries)
    {
        if (empty($entries)) return false;
        $st = $pdo->prepare("SELECT 1 FROM email_logs WHERE related_table = 'entradas' AND related_id = :id AND context = 'entrada_registro' AND mail_ok = 1 LIMIT 1");
        foreach ($entries as $entry) {
            $st->execute(array(':id' => (int)$entry['id']));
            if (!$st->fetchColumn()) return false;
        }
        return true;
    }
}

if (!function_exists('tickex_order_send_emails')) {
    function tickex_order_send_emails($pdo, $order, $entries)
    {
        $orderId = isset($order['id']) ? (int)$order['id'] : 0;
        $emailContext = tickex_order_email_context($order);
        if (tickex_order_batch_email_was_sent($pdo, $orderId, $emailContext) || tickex_order_legacy_emails_were_sent($pdo, $entries)) {
            $stDone = $pdo->prepare("UPDATE tc_orders SET email_status = 'sent', email_sent_at = COALESCE(email_sent_at, :sent_at), email_last_error = NULL WHERE id = :id");
            $stDone->execute(array(':sent_at' => date('Y-m-d H:i:s'), ':id' => $orderId));
            return array('sent' => 1, 'pending' => 0, 'status' => 'sent', 'error' => '');
        }

        $lastError = '';
        $buyerName = trim((isset($order['buyer_first']) ? $order['buyer_first'] : '') . ' ' . (isset($order['buyer_last']) ? $order['buyer_last'] : ''));
        $buyerEmail = isset($order['buyer_email']) ? (string)$order['buyer_email'] : '';
        $ticketLines = array();
        $firstDate = '';
        foreach ($entries as $entry) {
            $ticketUrl = tickex_secure_ticket_url($pdo, tickex_order_base_url(), $entry['id'], $entry['codigo']);
            $lineNumber = count($ticketLines) + 1;
            $ticketLines[] = 'Entrada ' . $lineNumber . ': ' . $entry['tipo'] . "\n" . $ticketUrl;
            if ($firstDate === '' && isset($entry['fecha'])) $firstDate = (string)$entry['fecha'];
        }

        $quantity = count($entries);
        $ticketsText = implode("\n\n", $ticketLines);
        $isCourtesy = isset($order['payment_provider']) && $order['payment_provider'] === 'courtesy';
        $isTransfer = isset($order['payment_provider']) && $order['payment_provider'] === 'manual_transfer';
        $subject = $isCourtesy
            ? ($quantity === 1 ? 'Recibiste una entrada de cortesía' : 'Recibiste ' . $quantity . ' entradas de cortesía')
            : ($quantity === 1 ? 'Tu entrada para el evento' : 'Tus ' . $quantity . ' entradas para el evento');
        $body = "Hola " . $buyerName . ",\n\n";
        if ($isCourtesy) {
            $body .= "Te asignamos " . $quantity . " entrada" . ($quantity === 1 ? '' : 's') . " de cortesía:\n\n";
        } elseif ($isTransfer) {
            $body .= "Registramos tu pago por transferencia. Recibiste " . $quantity . " entrada" . ($quantity === 1 ? '' : 's') . ":\n\n";
        } else {
            $body .= "¡Tu pago fue aprobado! Esta compra incluye " . $quantity . " entrada" . ($quantity === 1 ? '' : 's') . ":\n\n";
        }
        $body .= $ticketsText . "\n\n";
        $body .= "Cada enlace muestra un QR independiente. Podés compartir cada enlace con la persona que usará esa entrada.\n\nTickex\n";

        try {
            $mailOk = tickex_send_mail_template(
                $buyerEmail,
                $emailContext,
                array(
                    'nombre' => $buyerName,
                    'email' => $buyerEmail,
                    'cantidad' => $quantity,
                    'entradas' => $ticketsText,
                    'fecha_registro' => $firstDate,
                ),
                array('context' => $emailContext, 'related_table' => 'tc_orders', 'related_id' => $orderId),
                array('subject' => $subject, 'body' => $body, 'from_email' => 'no-reply@tickex.com.ar', 'from_name' => 'Tickex', 'reply_to' => 'no-reply@tickex.com.ar', 'is_html' => 0)
            );
        } catch (Exception $e) {
            $mailOk = false;
            $lastError = $e->getMessage();
        }

        if (!$mailOk && $lastError === '') $lastError = 'mail_returned_false';
        $sent = $mailOk ? 1 : 0;
        $pending = $mailOk ? 0 : 1;
        $emailStatus = $mailOk ? 'sent' : 'pending';
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
            $packageQty = max(0, (int)(isset($ticket['qty']) ? $ticket['qty'] : 0));
            $qrQuantity = tickex_ticket_qr_quantity(isset($ticket['qr_quantity']) ? $ticket['qr_quantity'] : 1);
            $expectedQty += tickex_ticket_issued_quantity($packageQty, $qrQuantity);
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
            $entryColumns = array();
            foreach ($pdo->query('PRAGMA table_info(entradas)')->fetchAll(PDO::FETCH_ASSOC) as $entryColumn) {
                $entryColumns[(string)$entryColumn['name']] = true;
            }
            $hiddenSqlColumn = isset($entryColumns['oculto']) ? ', oculto' : '';
            $hiddenSqlValue = isset($entryColumns['oculto']) ? ', :oculto' : '';

            $entradasCreadas = 0;
            foreach ($tickets as $ticket) {
                $tipoId = (int)(isset($ticket['id']) ? $ticket['id'] : 0);
                $tipoName = isset($ticket['name']) ? $ticket['name'] : 'General';
                $packageQty = max(0, (int)(isset($ticket['qty']) ? $ticket['qty'] : 1));
                $qrQuantity = tickex_ticket_qr_quantity(isset($ticket['qr_quantity']) ? $ticket['qr_quantity'] : 1);
                $qty = tickex_ticket_issued_quantity($packageQty, $qrQuantity);
                $packagePrice = (float)(isset($ticket['price']) ? $ticket['price'] : 0);
                $pricePerQr = tickex_ticket_amount_per_qr($packagePrice, $qrQuantity);
                $hidden = !empty($ticket['hidden']) ? 1 : 0;

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
                    $stIns = $pdo->prepare("INSERT INTO entradas (evento_id, nombre, email, fecha_registro, codigo, checked_in, checked_in_at, tipo, monto_pagado, tc_order_request_id, issuance_key" . $hiddenSqlColumn . ") VALUES (:eid, :nom, :em, :fec, :cod, 0, NULL, :tipo, :monto, :rid, :ikey" . $hiddenSqlValue . ")");
                    $entryParams = array(
                        ':eid'  => $eventoId,
                        ':nom'  => $buyerName,
                        ':em'   => $buyerEmail,
                        ':fec'  => $fechaReg,
                        ':cod'  => $codigo,
                        ':tipo' => $tipoName,
                        ':monto'=> $pricePerQr,
                        ':rid'  => $requestId,
                        ':ikey' => $issuanceKey,
                    );
                    if (isset($entryColumns['oculto'])) $entryParams[':oculto'] = $hidden;
                    $stIns->execute($entryParams);
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

        // Generar links seguros y enviar todos los QR en un único mail (fuera de la transacción)
        if ($processed && !empty($insertedEntries)) {
            $mailResult = tickex_order_send_emails($pdo, $order, $insertedEntries);
            $debugMsg .= 'Mail de entradas enviado: ' . $mailResult['sent'] . '/1. ';
        }

        return array(
            'processed' => $processed,
            'debugMsg' => $debugMsg,
            'order_id' => isset($order['id']) ? (int)$order['id'] : null,
            'request_id' => $requestId,
        );
    }
}
