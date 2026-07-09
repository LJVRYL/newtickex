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
        $processedAt = $order['processed_at'] ?? null;
        $ticketsJson = $order['selected_tickets_json'] ?? null;
        $debugMsg .= 'ProcessedAt: ' . ($processedAt === null ? 'NULL' : 'SET') . '. ';
        $debugMsg .= 'TicketsJson: ' . (empty($ticketsJson) ? 'EMPTY' : 'HAS_DATA') . '. ';

        if (!empty($processedAt)) {
            $debugMsg .= 'Orden ya procesada. ';
            log_order_event($pdo, (int)$order['id'], $requestId, 'order_already_processed', array('processed_at' => $processedAt));
            return array(
                'processed' => false,
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
            $expectedQty += max(0, (int)($ticket['qty'] ?? 0));
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
        $stExists = $pdo->prepare("SELECT COUNT(*) FROM entradas WHERE tc_order_request_id = :rid AND evento_id = :eid");
        $stExists->execute(array(':rid' => $requestId, ':eid' => $eventoId));
        $existingCount = (int)$stExists->fetchColumn();
        if ($existingCount > 0) {
            $debugMsg .= 'Entradas existentes para esta orden: ' . $existingCount . ' de ' . $expectedQty . '. ';
            if ($existingCount === $expectedQty) {
                $debugMsg .= 'Orden ya completada previo al marcado. ';
                $stMark = $pdo->prepare("UPDATE tc_orders SET processed_at = datetime('now') WHERE request_id = :rid AND processed_at IS NULL");
                $stMark->execute(array(':rid' => $requestId));
                log_order_event($pdo, (int)$order['id'], $requestId, 'order_already_completed', array('existing_count' => $existingCount, 'expected_qty' => $expectedQty));
                return array(
                    'processed' => true,
                    'debugMsg' => $debugMsg,
                    'order_id' => isset($order['id']) ? (int)$order['id'] : null,
                    'request_id' => $requestId,
                );
            }

            $debugMsg .= 'Orden parcialmente procesada; no reintentar para evitar duplicados. ';
            log_order_event($pdo, (int)$order['id'], $requestId, 'order_partial_existing_tickets', array('existing_count' => $existingCount, 'expected_qty' => $expectedQty));
            return array(
                'processed' => false,
                'debugMsg' => $debugMsg,
                'order_id' => isset($order['id']) ? (int)$order['id'] : null,
                'request_id' => $requestId,
            );
        }

        $deferredOrderEvents = array();
        $pdo->beginTransaction();
        $insertedEntries = array();
        try {
            $buyerName = trim(($order['buyer_first'] ?? '') . ' ' . ($order['buyer_last'] ?? ''));
            $buyerEmail = $order['buyer_email'] ?? '';
            $fechaReg = date('Y-m-d H:i:s');

            $entradasCreadas = 0;
            foreach ($tickets as $ticket) {
                $tipoId = (int)($ticket['id'] ?? 0);
                $tipoName = $ticket['name'] ?? 'General';
                $qty = max(0, (int)($ticket['qty'] ?? 1));
                $price = (int)($ticket['price'] ?? 0);

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

                    $stIns = $pdo->prepare("INSERT INTO entradas (evento_id, nombre, email, fecha_registro, codigo, checked_in, checked_in_at, tipo, monto_pagado, tc_order_request_id) VALUES (:eid, :nom, :em, :fec, :cod, 0, NULL, :tipo, :monto, :rid)");
                    $stIns->execute(array(
                        ':eid'  => $eventoId,
                        ':nom'  => $buyerName,
                        ':em'   => $buyerEmail,
                        ':fec'  => $fechaReg,
                        ':cod'  => $codigo,
                        ':tipo' => $tipoName,
                        ':monto'=> $price,
                        ':rid'  => $requestId,
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
                        } catch (Exception $_stockEx) {
                            // No revertir — el ticket es más importante que el contador
                            $debugMsg .= 'Stock warn tipo #' . $tipoId . ': ' . $_stockEx->getMessage() . '. ';
                            $deferredOrderEvents[] = array(
                                'tc_order_id' => isset($order['id']) ? (int)$order['id'] : null,
                                'request_id' => $requestId,
                                'event_type' => 'stock_decrement_failed',
                                'payload' => array(
                                    'tipo_id'   => $tipoId,
                                    'exception' => $_stockEx->getMessage(),
                                ),
                            );
                        }
                    }
                }
            }

            $stProc = $pdo->prepare("UPDATE tc_orders SET processed_at = datetime('now') WHERE request_id = :rid");
            $stProc->execute(array(':rid' => $requestId));

            $pdo->commit();
            $processed = true;
            $debugMsg .= 'Entradas creadas: ' . $entradasCreadas . '. ';
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
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
            $baseUrl = tickex_order_base_url();
            $mailsSent = 0;
            foreach ($insertedEntries as $entry) {
                try {
                    $ticketUrl = tickex_secure_ticket_url($pdo, $baseUrl, $entry['id'], $entry['codigo']);
                    $subject = 'Tu entrada para el evento';
                    $body  = "Hola " . $entry['nombre'] . ",\n\n";
                    $body .= "¡Tu pago fue aprobado! Aquí está tu entrada:\n\n";
                    $body .= "  Tipo: " . $entry['tipo'] . "\n";
                    $body .= "  Fecha de registro: " . $entry['fecha'] . "\n\n";
                    $body .= "Para ver tu QR de acceso, abrí este link:\n";
                    $body .= $ticketUrl . "\n\n";
                    $body .= "Mostrá este QR en la puerta del evento.\n";
                    $body .= "Guardá este mensaje hasta la fecha del evento.\n\n";
                    $body .= "Tickex\n";

                    $mailOk = tickex_send_mail_template(
                        $entry['email'],
                        'entrada_registro',
                        array(
                            'id'             => $entry['id'],
                            'nombre'         => $entry['nombre'],
                            'email'          => $entry['email'],
                            'tipo'           => $entry['tipo'],
                            'fecha_registro' => $entry['fecha'],
                            'ticket_url'     => $ticketUrl,
                            'codigo'         => $entry['codigo'],
                        ),
                        array(
                            'context'       => 'entrada_registro',
                            'related_table' => 'entradas',
                            'related_id'    => $entry['id'],
                        ),
                        array(
                            'subject'      => $subject,
                            'body'         => $body,
                            'from_email'   => 'no-reply@tickex.com.ar',
                            'from_name'    => 'Tickex',
                            'reply_to'     => 'no-reply@tickex.com.ar',
                            'is_html'      => 0,
                        )
                    );
                    if ($mailOk) $mailsSent++;
                } catch (Exception $_mailEx) {
                    // No revertir el procesamiento por fallo de mail
                    $debugMsg .= 'Mail error entrada #' . $entry['id'] . ': ' . $_mailEx->getMessage() . '. ';
                }
            }
            $debugMsg .= 'Mails enviados: ' . $mailsSent . '/' . count($insertedEntries) . '. ';
        }

        return array(
            'processed' => $processed,
            'debugMsg' => $debugMsg,
            'order_id' => isset($order['id']) ? (int)$order['id'] : null,
            'request_id' => $requestId,
        );
    }
}
