<?php

require_once __DIR__ . '/secure_links.php';
require_once __DIR__ . '/mail.php';

if (!function_exists('tickex_access_uuid')) {
    function tickex_access_uuid()
    {
        if (function_exists('random_bytes')) {
            try {
                return bin2hex(random_bytes(16));
            } catch (Exception $e) {
                // ignore
            }
        }
        return sha1(uniqid((string)mt_rand(), true));
    }
}

if (!function_exists('tickex_access_trace_id')) {
    function tickex_access_trace_id()
    {
        if (function_exists('random_bytes')) {
            try {
                return bin2hex(random_bytes(8));
            } catch (Exception $e) {
                // ignore
            }
        }
        return substr(sha1(uniqid((string)mt_rand(), true)), 0, 16);
    }
}

if (!function_exists('tickex_access_slugify')) {
    function tickex_access_slugify($value)
    {
        $v = strtolower(trim((string)$value));
        $v = preg_replace('/[^a-z0-9\-]+/', '-', $v);
        $v = preg_replace('/\-+/', '-', $v);
        $v = trim($v, '-');
        return $v;
    }
}

if (!function_exists('tickex_access_code_available')) {
    function tickex_access_code_available($pdo, $code, $excludeId)
    {
        $st = $pdo->prepare('SELECT id FROM access_links WHERE code = :c LIMIT 1');
        $st->execute(array(':c' => (string)$code));
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return true;
        if ((int)$excludeId > 0 && (int)$row['id'] === (int)$excludeId) return true;
        return false;
    }
}

if (!function_exists('tickex_access_make_code')) {
    function tickex_access_make_code($pdo, $seed, $excludeId)
    {
        $base = tickex_access_slugify($seed);
        if ($base === '') $base = 'access-link';
        $code = $base;
        for ($i = 0; $i < 25; $i++) {
            if (tickex_access_code_available($pdo, $code, $excludeId)) {
                return $code;
            }
            $code = $base . '-' . ($i + 2);
        }
        return $base . '-' . substr(sha1((string)microtime(true)), 0, 6);
    }
}

if (!function_exists('tickex_access_normalize_email')) {
    function tickex_access_normalize_email($email)
    {
        return strtolower(trim((string)$email));
    }
}

if (!function_exists('tickex_access_normalize_dni')) {
    function tickex_access_normalize_dni($dni)
    {
        return preg_replace('/\D+/', '', (string)$dni);
    }
}

if (!function_exists('tickex_access_client_ip')) {
    function tickex_access_client_ip()
    {
        $keys = array('HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR');
        foreach ($keys as $k) {
            if (!isset($_SERVER[$k])) continue;
            $raw = trim((string)$_SERVER[$k]);
            if ($raw === '') continue;
            if ($k === 'HTTP_X_FORWARDED_FOR' && strpos($raw, ',') !== false) {
                $parts = explode(',', $raw);
                $raw = trim((string)$parts[0]);
            }
            return $raw;
        }
        return '';
    }
}

if (!function_exists('tickex_access_used_count')) {
    function tickex_access_used_count($pdo, $linkId)
    {
        $st = $pdo->prepare('SELECT COUNT(*) FROM access_link_issues WHERE access_link_id = :id');
        $st->execute(array(':id' => (int)$linkId));
        return (int)$st->fetchColumn();
    }
}

if (!function_exists('tickex_access_effective_status')) {
    function tickex_access_effective_status($row)
    {
        $status = isset($row['status']) ? strtolower(trim((string)$row['status'])) : 'draft';
        if ($status === '') $status = 'draft';
        if ($status === 'disabled' || $status === 'paused' || $status === 'draft' || $status === 'expired') {
            return $status;
        }
        if ($status !== 'active') return 'disabled';

        $now = time();
        $startsAt = isset($row['starts_at']) ? trim((string)$row['starts_at']) : '';
        $expiresAt = isset($row['expires_at']) ? trim((string)$row['expires_at']) : '';

        if ($startsAt !== '') {
            $st = strtotime($startsAt);
            if ($st !== false && $now < $st) {
                return 'draft';
            }
        }
        if ($expiresAt !== '') {
            $et = strtotime($expiresAt);
            if ($et !== false && $now > $et) {
                return 'expired';
            }
        }
        return 'active';
    }
}

if (!function_exists('tickex_access_find_by_public')) {
    function tickex_access_find_by_public($pdo, $publicId)
    {
        $v = trim((string)$publicId);
        if ($v === '') return null;
        $st = $pdo->prepare('SELECT l.*, e.nombre AS evento_nombre, e.slug AS evento_slug, t.nombre AS ticket_type_nombre
            FROM access_links l
            LEFT JOIN eventos e ON e.id = l.evento_id
            LEFT JOIN tipos_entrada t ON t.id = l.ticket_type_id
            WHERE l.code = :v OR l.uuid = :v
            LIMIT 1');
        $st->execute(array(':v' => $v));
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ? $row : null;
    }
}

if (!function_exists('tickex_access_base_url')) {
    function tickex_access_base_url()
    {
        $env = getenv('TICKEX_SITE_URL');
        if (is_string($env) && trim($env) !== '') return rtrim(trim($env), '/');
        $host = isset($_SERVER['HTTP_HOST']) ? (string)$_SERVER['HTTP_HOST'] : '';
        if ($host !== '') {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            return $scheme . '://' . $host;
        }
        return 'https://str.tickex.com.ar';
    }
}

if (!function_exists('tickex_access_log_attempt')) {
    function tickex_access_log_attempt($pdo, $data)
    {
        try {
            $st = $pdo->prepare('INSERT INTO access_link_attempts
                (trace_id, access_link_id, evento_id, ip_address, email_normalized, dni_normalized, captcha_ok, result, detail, created_at)
                VALUES
                (:trace_id, :link_id, :evento_id, :ip, :email, :dni, :captcha_ok, :result, :detail, :created_at)');
            $st->execute(array(
                ':trace_id' => isset($data['trace_id']) ? (string)$data['trace_id'] : '',
                ':link_id' => isset($data['access_link_id']) ? (int)$data['access_link_id'] : null,
                ':evento_id' => isset($data['evento_id']) ? (int)$data['evento_id'] : null,
                ':ip' => isset($data['ip_address']) ? (string)$data['ip_address'] : '',
                ':email' => isset($data['email_normalized']) ? (string)$data['email_normalized'] : '',
                ':dni' => isset($data['dni_normalized']) ? (string)$data['dni_normalized'] : '',
                ':captcha_ok' => !empty($data['captcha_ok']) ? 1 : 0,
                ':result' => isset($data['result']) ? (string)$data['result'] : 'unknown',
                ':detail' => isset($data['detail']) ? (string)$data['detail'] : '',
                ':created_at' => date('Y-m-d H:i:s'),
            ));
        } catch (Exception $e) {
            // no-op
        }
    }
}

if (!function_exists('tickex_access_build_entry_insert')) {
    function tickex_access_build_entry_insert($pdo, $payload)
    {
        $colsInfo = $pdo->query("PRAGMA table_info(entradas)")->fetchAll(PDO::FETCH_ASSOC);
        $colMap = array();
        foreach ($colsInfo as $c) {
            if (isset($c['name'])) $colMap[$c['name']] = true;
        }

        $insert = array(
            'evento_id' => isset($payload['evento_id']) ? (int)$payload['evento_id'] : 0,
            'nombre' => isset($payload['nombre']) ? (string)$payload['nombre'] : '',
            'email' => isset($payload['email']) ? (string)$payload['email'] : '',
            'fecha_registro' => isset($payload['fecha_registro']) ? (string)$payload['fecha_registro'] : date('Y-m-d H:i:s'),
            'codigo' => isset($payload['codigo']) ? (string)$payload['codigo'] : '',
            'checked_in' => 0,
            'checked_in_at' => null,
            'tipo' => isset($payload['tipo']) ? (string)$payload['tipo'] : 'General',
            'monto_pagado' => 0,
        );

        if (isset($colMap['payment_method'])) $insert['payment_method'] = isset($payload['payment_method']) ? (string)$payload['payment_method'] : 'free';
        if (isset($colMap['buyer_dni'])) $insert['buyer_dni'] = isset($payload['buyer_dni']) ? (string)$payload['buyer_dni'] : '';
        if (isset($colMap['buyer_phone'])) $insert['buyer_phone'] = isset($payload['buyer_phone']) ? (string)$payload['buyer_phone'] : '';
        if (isset($colMap['access_link_id'])) $insert['access_link_id'] = isset($payload['access_link_id']) ? (int)$payload['access_link_id'] : null;

        $cols = array();
        $ph = array();
        $params = array();
        foreach ($insert as $k => $v) {
            if (!isset($colMap[$k])) continue;
            $cols[] = $k;
            $ph[] = ':' . $k;
            $params[':' . $k] = $v;
        }

        return array(
            'sql' => 'INSERT INTO entradas (' . implode(',', $cols) . ') VALUES (' . implode(',', $ph) . ')',
            'params' => $params,
        );
    }
}

if (!function_exists('tickex_access_generate_codigo')) {
    function tickex_access_generate_codigo($pdo, $eventoId)
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $stChk = $pdo->prepare('SELECT 1 FROM entradas WHERE codigo = :c LIMIT 1');
        for ($attempt = 0; $attempt < 40; $attempt++) {
            $out = '';
            if (function_exists('random_bytes')) {
                try {
                    $bytes = random_bytes(10);
                    for ($i = 0; $i < 10; $i++) {
                        $out .= $alphabet[ord($bytes[$i]) % strlen($alphabet)];
                    }
                } catch (Exception $e) {
                    $out = substr(sha1(uniqid((string)mt_rand(), true)), 0, 10);
                }
            } else {
                for ($i = 0; $i < 10; $i++) {
                    $out .= $alphabet[mt_rand(0, strlen($alphabet) - 1)];
                }
            }
            $codigo = 'A' . (int)$eventoId . '-' . $out;
            $stChk->execute(array(':c' => $codigo));
            if (!$stChk->fetchColumn()) return $codigo;
        }
        return '';
    }
}

if (!function_exists('tickex_access_issue_entry')) {
    function tickex_access_issue_entry($pdo, $linkRow, $input, $ctx)
    {
        $traceId = isset($ctx['trace_id']) ? (string)$ctx['trace_id'] : tickex_access_trace_id();
        $ip = isset($ctx['ip']) ? (string)$ctx['ip'] : '';
        $ua = isset($ctx['user_agent']) ? (string)$ctx['user_agent'] : '';
        $issuedBy = isset($ctx['issued_by']) ? (string)$ctx['issued_by'] : 'public';

        $emailNorm = tickex_access_normalize_email(isset($input['email']) ? $input['email'] : '');
        $dniNorm = tickex_access_normalize_dni(isset($input['dni']) ? $input['dni'] : '');
        $captchaOk = !empty($ctx['captcha_ok']) ? 1 : 0;

        $baseAttempt = array(
            'trace_id' => $traceId,
            'access_link_id' => isset($linkRow['id']) ? (int)$linkRow['id'] : 0,
            'evento_id' => isset($linkRow['evento_id']) ? (int)$linkRow['evento_id'] : 0,
            'ip_address' => $ip,
            'email_normalized' => $emailNorm,
            'dni_normalized' => $dniNorm,
            'captcha_ok' => $captchaOk,
        );

        $effectiveStatus = tickex_access_effective_status($linkRow);
        if ($effectiveStatus !== 'active') {
            $r = 'blocked_' . $effectiveStatus;
            tickex_access_log_attempt($pdo, array_merge($baseAttempt, array('result' => $r, 'detail' => 'El link no está activo.')));
            return array('ok' => false, 'error' => 'El link no está disponible.', 'trace_id' => $traceId);
        }

        if ((int)$linkRow['captcha_required'] === 1 && !$captchaOk) {
            tickex_access_log_attempt($pdo, array_merge($baseAttempt, array('result' => 'blocked_captcha', 'detail' => 'Captcha obligatorio no válido.')));
            return array('ok' => false, 'error' => 'Captcha inválido.', 'trace_id' => $traceId);
        }

        $rateWindow = isset($linkRow['rate_limit_window_seconds']) ? (int)$linkRow['rate_limit_window_seconds'] : 0;
        $rateMax = isset($linkRow['rate_limit_max_requests']) ? (int)$linkRow['rate_limit_max_requests'] : 0;
        if ($ip !== '' && $rateWindow > 0 && $rateMax > 0) {
            $stRate = $pdo->prepare('SELECT COUNT(*) FROM access_link_attempts WHERE access_link_id = :lid AND ip_address = :ip AND created_at >= datetime(\'now\', :mod)');
            $stRate->execute(array(':lid' => (int)$linkRow['id'], ':ip' => $ip, ':mod' => '-' . $rateWindow . ' seconds'));
            $countRate = (int)$stRate->fetchColumn();
            if ($countRate >= $rateMax) {
                tickex_access_log_attempt($pdo, array_merge($baseAttempt, array('result' => 'blocked_rate_limit', 'detail' => 'Rate limit excedido.')));
                return array('ok' => false, 'error' => 'Demasiados intentos. Probá más tarde.', 'trace_id' => $traceId);
            }
        }

        $ipWindow = isset($linkRow['ip_limit_window_seconds']) ? (int)$linkRow['ip_limit_window_seconds'] : 0;
        $ipMaxUses = isset($linkRow['ip_limit_max_uses']) ? (int)$linkRow['ip_limit_max_uses'] : 0;
        if ($ip !== '' && $ipWindow > 0 && $ipMaxUses > 0) {
            $stIp = $pdo->prepare('SELECT COUNT(*) FROM access_link_issues WHERE access_link_id = :lid AND ip_address = :ip AND created_at >= datetime(\'now\', :mod)');
            $stIp->execute(array(':lid' => (int)$linkRow['id'], ':ip' => $ip, ':mod' => '-' . $ipWindow . ' seconds'));
            $countIp = (int)$stIp->fetchColumn();
            if ($countIp >= $ipMaxUses) {
                tickex_access_log_attempt($pdo, array_merge($baseAttempt, array('result' => 'blocked_ip_limit', 'detail' => 'Límite por IP excedido.')));
                return array('ok' => false, 'error' => 'Superaste el límite de emisiones para esta conexión.', 'trace_id' => $traceId);
            }
        }

        if ((int)$linkRow['unique_email'] === 1 && $emailNorm !== '') {
            $stUniE = $pdo->prepare('SELECT COUNT(*) FROM access_link_issues WHERE access_link_id = :lid AND email_normalized = :em');
            $stUniE->execute(array(':lid' => (int)$linkRow['id'], ':em' => $emailNorm));
            if ((int)$stUniE->fetchColumn() > 0) {
                tickex_access_log_attempt($pdo, array_merge($baseAttempt, array('result' => 'blocked_unique_email', 'detail' => 'Email ya emitido para este link.')));
                return array('ok' => false, 'error' => 'Este email ya obtuvo un acceso con este link.', 'trace_id' => $traceId);
            }
        }

        if ((int)$linkRow['unique_dni'] === 1 && $dniNorm !== '') {
            $stUniD = $pdo->prepare('SELECT COUNT(*) FROM access_link_issues WHERE access_link_id = :lid AND dni_normalized = :dni');
            $stUniD->execute(array(':lid' => (int)$linkRow['id'], ':dni' => $dniNorm));
            if ((int)$stUniD->fetchColumn() > 0) {
                tickex_access_log_attempt($pdo, array_merge($baseAttempt, array('result' => 'blocked_unique_dni', 'detail' => 'DNI ya emitido para este link.')));
                return array('ok' => false, 'error' => 'Este DNI ya obtuvo un acceso con este link.', 'trace_id' => $traceId);
            }
        }

        $ticketTypeId = isset($linkRow['ticket_type_id']) ? (int)$linkRow['ticket_type_id'] : 0;
        if ($ticketTypeId <= 0) {
            tickex_access_log_attempt($pdo, array_merge($baseAttempt, array('result' => 'blocked_invalid_config', 'detail' => 'ticket_type_id inválido.')));
            return array('ok' => false, 'error' => 'Configuración inválida del link.', 'trace_id' => $traceId);
        }

        $stType = $pdo->prepare('SELECT id, nombre, precio, cantidad_disponible FROM tipos_entrada WHERE id = :id AND evento_id = :eid LIMIT 1');
        $stType->execute(array(':id' => $ticketTypeId, ':eid' => (int)$linkRow['evento_id']));
        $typeRow = $stType->fetch(PDO::FETCH_ASSOC);
        if (!$typeRow) {
            tickex_access_log_attempt($pdo, array_merge($baseAttempt, array('result' => 'blocked_invalid_config', 'detail' => 'Tipo de entrada inexistente en el evento.')));
            return array('ok' => false, 'error' => 'Tipo de entrada no válido para este evento.', 'trace_id' => $traceId);
        }
        if ((int)$typeRow['cantidad_disponible'] <= 0) {
            tickex_access_log_attempt($pdo, array_merge($baseAttempt, array('result' => 'blocked_stock', 'detail' => 'Stock agotado.')));
            return array('ok' => false, 'error' => 'No hay cupo disponible.', 'trace_id' => $traceId);
        }

        $maxUses = isset($linkRow['max_uses']) && $linkRow['max_uses'] !== null && $linkRow['max_uses'] !== '' ? (int)$linkRow['max_uses'] : 0;
        $used = tickex_access_used_count($pdo, (int)$linkRow['id']);
        if ($maxUses > 0 && $used >= $maxUses) {
            tickex_access_log_attempt($pdo, array_merge($baseAttempt, array('result' => 'blocked_quota', 'detail' => 'Cupo del link agotado.')));
            return array('ok' => false, 'error' => 'Este link ya agotó su cupo.', 'trace_id' => $traceId);
        }

        $first = trim((string)(isset($input['first_name']) ? $input['first_name'] : ''));
        $last = trim((string)(isset($input['last_name']) ? $input['last_name'] : ''));
        $full = trim($first . ' ' . $last);
        if ($full === '') $full = 'Invitado';

        $email = trim((string)(isset($input['email']) ? $input['email'] : ''));
        $phone = trim((string)(isset($input['phone']) ? $input['phone'] : ''));
        $dniRaw = trim((string)(isset($input['dni']) ? $input['dni'] : ''));

        $entryId = 0;
        $codigo = '';
        $fechaReg = date('Y-m-d H:i:s');

        try {
            $pdo->beginTransaction();

            $usedTx = tickex_access_used_count($pdo, (int)$linkRow['id']);
            if ($maxUses > 0 && $usedTx >= $maxUses) {
                $pdo->rollBack();
                tickex_access_log_attempt($pdo, array_merge($baseAttempt, array('result' => 'blocked_quota', 'detail' => 'Cupo agotado durante emisión.')));
                return array('ok' => false, 'error' => 'Este link ya agotó su cupo.', 'trace_id' => $traceId);
            }

            if ((int)$linkRow['unique_email'] === 1 && $emailNorm !== '') {
                $stUniETx = $pdo->prepare('SELECT COUNT(*) FROM access_link_issues WHERE access_link_id = :lid AND email_normalized = :em');
                $stUniETx->execute(array(':lid' => (int)$linkRow['id'], ':em' => $emailNorm));
                if ((int)$stUniETx->fetchColumn() > 0) {
                    $pdo->rollBack();
                    tickex_access_log_attempt($pdo, array_merge($baseAttempt, array('result' => 'blocked_unique_email', 'detail' => 'Email duplicado durante emisión.')));
                    return array('ok' => false, 'error' => 'Este email ya obtuvo un acceso con este link.', 'trace_id' => $traceId);
                }
            }

            if ((int)$linkRow['unique_dni'] === 1 && $dniNorm !== '') {
                $stUniDTx = $pdo->prepare('SELECT COUNT(*) FROM access_link_issues WHERE access_link_id = :lid AND dni_normalized = :dni');
                $stUniDTx->execute(array(':lid' => (int)$linkRow['id'], ':dni' => $dniNorm));
                if ((int)$stUniDTx->fetchColumn() > 0) {
                    $pdo->rollBack();
                    tickex_access_log_attempt($pdo, array_merge($baseAttempt, array('result' => 'blocked_unique_dni', 'detail' => 'DNI duplicado durante emisión.')));
                    return array('ok' => false, 'error' => 'Este DNI ya obtuvo un acceso con este link.', 'trace_id' => $traceId);
                }
            }

            $stStock = $pdo->prepare('UPDATE tipos_entrada SET cantidad_disponible = cantidad_disponible - 1 WHERE id = :id AND evento_id = :eid AND cantidad_disponible > 0');
            $stStock->execute(array(':id' => $ticketTypeId, ':eid' => (int)$linkRow['evento_id']));
            if ($stStock->rowCount() <= 0) {
                $pdo->rollBack();
                tickex_access_log_attempt($pdo, array_merge($baseAttempt, array('result' => 'blocked_stock', 'detail' => 'Sin stock al confirmar.')));
                return array('ok' => false, 'error' => 'No hay cupo disponible.', 'trace_id' => $traceId);
            }

            $codigo = tickex_access_generate_codigo($pdo, (int)$linkRow['evento_id']);
            if ($codigo === '') {
                $pdo->rollBack();
                tickex_access_log_attempt($pdo, array_merge($baseAttempt, array('result' => 'blocked_internal', 'detail' => 'No se pudo generar código único.')));
                return array('ok' => false, 'error' => 'No se pudo emitir la entrada.', 'trace_id' => $traceId);
            }

            $insertBuild = tickex_access_build_entry_insert($pdo, array(
                'evento_id' => (int)$linkRow['evento_id'],
                'nombre' => $full,
                'email' => $email,
                'fecha_registro' => $fechaReg,
                'codigo' => $codigo,
                'tipo' => isset($typeRow['nombre']) ? (string)$typeRow['nombre'] : 'General',
                'payment_method' => isset($linkRow['access_type']) ? (string)$linkRow['access_type'] : 'free',
                'buyer_dni' => $dniRaw,
                'buyer_phone' => $phone,
                'access_link_id' => (int)$linkRow['id'],
            ));
            $stIns = $pdo->prepare($insertBuild['sql']);
            $stIns->execute($insertBuild['params']);
            $entryId = (int)$pdo->lastInsertId();

            $stIssue = $pdo->prepare('INSERT INTO access_link_issues
                (access_link_id, evento_id, entrada_id, email_normalized, dni_normalized, ip_address, user_agent, issued_by, created_at)
                VALUES
                (:lid, :eid, :entry_id, :email, :dni, :ip, :ua, :issued_by, :created_at)');
            $stIssue->execute(array(
                ':lid' => (int)$linkRow['id'],
                ':eid' => (int)$linkRow['evento_id'],
                ':entry_id' => $entryId,
                ':email' => $emailNorm,
                ':dni' => $dniNorm,
                ':ip' => $ip,
                ':ua' => $ua,
                ':issued_by' => $issuedBy,
                ':created_at' => date('Y-m-d H:i:s'),
            ));

            $pdo->commit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            tickex_access_log_attempt($pdo, array_merge($baseAttempt, array('result' => 'blocked_internal', 'detail' => $e->getMessage())));
            return array('ok' => false, 'error' => 'No se pudo emitir la entrada.', 'trace_id' => $traceId);
        }

        $ticketUrl = '';
        $mailOk = false;
        try {
            $ticketUrl = tickex_secure_ticket_url($pdo, tickex_access_base_url(), $entryId, $codigo);
            $subject = 'Tu entrada para el evento';
            $body = "Hola " . $full . ",\n\n";
            $body .= "¡Tu acceso fue confirmado! Aquí está tu entrada:\n\n";
            $body .= "  Tipo: " . (isset($typeRow['nombre']) ? $typeRow['nombre'] : 'General') . "\n";
            $body .= "  Fecha de registro: " . $fechaReg . "\n\n";
            $body .= "Para ver tu QR de acceso, abrí este link:\n";
            $body .= $ticketUrl . "\n\n";
            $body .= "Mostrá este QR en la puerta del evento.\n\n";
            $body .= "Tickex\n";

            $mailOk = tickex_send_mail_template(
                $email,
                'entrada_registro',
                array(
                    'id' => $entryId,
                    'nombre' => $full,
                    'email' => $email,
                    'tipo' => isset($typeRow['nombre']) ? (string)$typeRow['nombre'] : 'General',
                    'fecha_registro' => $fechaReg,
                    'ticket_url' => $ticketUrl,
                    'codigo' => $codigo,
                ),
                array(
                    'context' => 'entrada_registro',
                    'related_table' => 'entradas',
                    'related_id' => $entryId,
                ),
                array(
                    'subject' => $subject,
                    'body' => $body,
                    'from_email' => 'no-reply@tickex.com.ar',
                    'from_name' => 'Tickex',
                    'reply_to' => 'no-reply@tickex.com.ar',
                    'is_html' => 0,
                )
            );
        } catch (Exception $e) {
            $mailOk = false;
        }

        tickex_access_log_attempt($pdo, array_merge($baseAttempt, array('result' => 'accepted', 'detail' => 'Entrada emitida #' . $entryId)));

        return array(
            'ok' => true,
            'trace_id' => $traceId,
            'entry_id' => $entryId,
            'ticket_url' => $ticketUrl,
            'mail_ok' => $mailOk ? 1 : 0,
        );
    }
}
