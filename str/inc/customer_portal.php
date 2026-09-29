<?php

if (!function_exists('tickex_customer_portal_ensure_schema')) {
    function tickex_customer_portal_ensure_schema($pdo)
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS entradas_ocultas_usuario (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            entrada_id INTEGER NOT NULL,
            email TEXT NOT NULL,
            creado_en TEXT NOT NULL DEFAULT (datetime('now'))
        )");
        $indexExists = $pdo->query("SELECT 1 FROM sqlite_master WHERE type='index' AND name='idx_entrada_oculta_usuario' LIMIT 1")->fetchColumn();
        if (!$indexExists) {
            $pdo->exec('DELETE FROM entradas_ocultas_usuario WHERE id NOT IN (SELECT MIN(id) FROM entradas_ocultas_usuario GROUP BY entrada_id, lower(email))');
            $pdo->exec('CREATE UNIQUE INDEX idx_entrada_oculta_usuario ON entradas_ocultas_usuario(entrada_id, email COLLATE NOCASE)');
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS customer_ticket_resends (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            usuario_id INTEGER NOT NULL,
            entrada_id INTEGER NOT NULL,
            email TEXT NOT NULL,
            mail_ok INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        )");
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_customer_ticket_resends_lookup ON customer_ticket_resends(usuario_id, entrada_id, created_at)');
    }
}

if (!function_exists('tickex_customer_portal_user')) {
    function tickex_customer_portal_user($pdo, $usuarioId)
    {
        $st = $pdo->prepare("SELECT id, COALESCE(nombre,'') AS nombre, COALESCE(apellido,'') AS apellido,
            lower(trim(email)) AS email, COALESCE(apodo,'') AS apodo, COALESCE(dni,'') AS dni,
            COALESCE(genero,'') AS genero, COALESCE(creado_en,'') AS creado_en,
            COALESCE(completado_en,'') AS completado_en, COALESCE(password_hash,'') AS password_hash
            FROM registro_pendientes WHERE id=:id LIMIT 1");
        $st->execute(array(':id' => (int)$usuarioId));
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ? $row : null;
    }
}

if (!function_exists('tickex_customer_portal_ticket_state')) {
    function tickex_customer_portal_ticket_state($ticket, $nowTimestamp = null)
    {
        if (!empty($ticket['entrada_oculta'])) return 'cancelada';
        if (!empty($ticket['checked_in'])) return 'utilizada';
        $now = $nowTimestamp === null ? time() : (int)$nowTimestamp;
        $end = trim(isset($ticket['fecha_hasta']) ? (string)$ticket['fecha_hasta'] : '');
        if ($end === '') $end = trim(isset($ticket['fecha_desde']) ? (string)$ticket['fecha_desde'] : '');
        if ($end !== '') {
            $endTs = strtotime(substr($end, 0, 10) . ' 23:59:59');
            if ($endTs !== false && $endTs < $now) return 'vencida';
        }
        return 'vigente';
    }
}

if (!function_exists('tickex_customer_portal_load_tickets')) {
    function tickex_customer_portal_load_tickets($pdo, $email)
    {
        tickex_customer_portal_ensure_schema($pdo);
        $st = $pdo->prepare("SELECT e.id, e.codigo, e.evento_id, e.fecha_registro, e.tipo,
            COALESCE(e.monto_pagado,0) AS monto_pagado, COALESCE(e.checked_in,0) AS checked_in,
            e.checked_in_at, COALESCE(e.oculto,0) AS entrada_oculta,
            COALESCE(ev.nombre,'Evento') AS evento_nombre, COALESCE(ev.slug,'') AS evento_slug,
            COALESCE(ev.fecha_desde,'') AS fecha_desde, COALESCE(ev.fecha_hasta,'') AS fecha_hasta,
            COALESCE(ev.flyer_filename,'') AS flyer_filename,
            CASE WHEN eu.id IS NULL THEN 0 ELSE 1 END AS oculta_usuario
            FROM entradas e
            LEFT JOIN eventos ev ON ev.id=e.evento_id
            LEFT JOIN entradas_ocultas_usuario eu ON eu.entrada_id=e.id AND lower(eu.email)=lower(:hidden_email)
            WHERE lower(trim(e.email))=lower(trim(:owner_email))
            ORDER BY COALESCE(NULLIF(ev.fecha_desde,''),e.fecha_registro) DESC, e.id DESC");
        $normalized = strtolower(trim((string)$email));
        $st->execute(array(':hidden_email' => $normalized, ':owner_email' => $normalized));
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) $row['portal_state'] = tickex_customer_portal_ticket_state($row);
        unset($row);
        return $rows;
    }
}

if (!function_exists('tickex_customer_portal_filter_tickets')) {
    function tickex_customer_portal_filter_tickets($tickets, $filter)
    {
        $allowed = array('vigentes', 'utilizadas', 'vencidas', 'canceladas', 'ocultas', 'todas');
        $filter = in_array($filter, $allowed, true) ? $filter : 'vigentes';
        $result = array();
        foreach ($tickets as $ticket) {
            $hidden = !empty($ticket['oculta_usuario']);
            if ($filter === 'ocultas' && !$hidden) continue;
            if ($filter !== 'ocultas' && $filter !== 'todas' && $hidden) continue;
            if ($filter !== 'ocultas' && $filter !== 'todas' && (string)$ticket['portal_state'] !== rtrim($filter, 's')) continue;
            $result[] = $ticket;
        }
        return $result;
    }
}

if (!function_exists('tickex_customer_portal_counts')) {
    function tickex_customer_portal_counts($tickets)
    {
        $counts = array('todas' => 0, 'vigentes' => 0, 'utilizadas' => 0, 'vencidas' => 0, 'canceladas' => 0, 'ocultas' => 0);
        foreach ($tickets as $ticket) {
            $counts['todas']++;
            if (!empty($ticket['oculta_usuario'])) {
                $counts['ocultas']++;
                continue;
            }
            $key = (string)$ticket['portal_state'] . 's';
            if (isset($counts[$key])) $counts[$key]++;
        }
        return $counts;
    }
}

if (!function_exists('tickex_customer_portal_set_hidden')) {
    function tickex_customer_portal_set_hidden($pdo, $usuarioEmail, $entradaId, $hidden)
    {
        tickex_customer_portal_ensure_schema($pdo);
        $email = strtolower(trim((string)$usuarioEmail));
        $check = $pdo->prepare('SELECT id FROM entradas WHERE id=:id AND lower(trim(email))=lower(trim(:email)) LIMIT 1');
        $check->execute(array(':id' => (int)$entradaId, ':email' => $email));
        if (!$check->fetchColumn()) return false;
        if ($hidden) {
            $st = $pdo->prepare("INSERT OR IGNORE INTO entradas_ocultas_usuario(entrada_id,email,creado_en) VALUES(:id,:email,datetime('now'))");
        } else {
            $st = $pdo->prepare('DELETE FROM entradas_ocultas_usuario WHERE entrada_id=:id AND lower(email)=lower(:email)');
        }
        return $st->execute(array(':id' => (int)$entradaId, ':email' => $email));
    }
}

if (!function_exists('tickex_customer_portal_verify_password')) {
    function tickex_customer_portal_verify_password($plain, $stored)
    {
        $stored = (string)$stored;
        if ($stored === '') return false;
        if (function_exists('password_verify') && strpos($stored, '$2') === 0) return password_verify((string)$plain, $stored);
        if (strlen($stored) === 32 && ctype_xdigit($stored)) return hash_equals(strtolower($stored), md5((string)$plain));
        return hash_equals($stored, (string)$plain);
    }
}

if (!function_exists('tickex_customer_portal_change_password')) {
    function tickex_customer_portal_change_password($pdo, $usuarioId, $current, $next, $repeat)
    {
        $user = tickex_customer_portal_user($pdo, $usuarioId);
        if (!$user) return array(false, 'No encontramos la cuenta.');
        if (!tickex_customer_portal_verify_password($current, $user['password_hash'])) return array(false, 'La contraseña actual no es correcta.');
        if (strlen((string)$next) < 10) return array(false, 'La nueva contraseña debe tener al menos 10 caracteres.');
        if ((string)$next !== (string)$repeat) return array(false, 'Las contraseñas nuevas no coinciden.');
        if (hash_equals((string)$current, (string)$next)) return array(false, 'Elegí una contraseña diferente a la actual.');
        $hash = password_hash((string)$next, PASSWORD_DEFAULT);
        if (!$hash) return array(false, 'No pudimos proteger la contraseña nueva.');
        $st = $pdo->prepare('UPDATE registro_pendientes SET password_hash=:hash WHERE id=:id');
        $st->execute(array(':hash' => $hash, ':id' => (int)$usuarioId));
        return array(true, 'Contraseña actualizada correctamente.');
    }
}

if (!function_exists('tickex_customer_portal_resend_allowed')) {
    function tickex_customer_portal_resend_allowed($pdo, $usuarioId, $entradaId)
    {
        tickex_customer_portal_ensure_schema($pdo);
        $recent = $pdo->prepare("SELECT COUNT(*) FROM customer_ticket_resends
            WHERE usuario_id=:uid AND entrada_id=:eid AND created_at>=datetime('now','-10 minutes')");
        $recent->execute(array(':uid' => (int)$usuarioId, ':eid' => (int)$entradaId));
        if ((int)$recent->fetchColumn() > 0) return false;
        $hour = $pdo->prepare("SELECT COUNT(*) FROM customer_ticket_resends
            WHERE usuario_id=:uid AND created_at>=datetime('now','-1 hour')");
        $hour->execute(array(':uid' => (int)$usuarioId));
        return (int)$hour->fetchColumn() < 5;
    }
}

if (!function_exists('tickex_customer_portal_resend')) {
    function tickex_customer_portal_resend($pdo, $usuarioId, $usuarioEmail, $entradaId, $ticketUrl, $sender)
    {
        $email = strtolower(trim((string)$usuarioEmail));
        $st = $pdo->prepare("SELECT e.id,e.nombre,e.email,e.codigo,e.tipo,e.fecha_registro,
            COALESCE(ev.nombre,'Tickex') AS evento_nombre
            FROM entradas e LEFT JOIN eventos ev ON ev.id=e.evento_id
            WHERE e.id=:id AND lower(trim(e.email))=lower(trim(:email)) LIMIT 1");
        $st->execute(array(':id' => (int)$entradaId, ':email' => $email));
        $ticket = $st->fetch(PDO::FETCH_ASSOC);
        if (!$ticket) return array(false, 'La entrada no pertenece a tu cuenta.');
        if (!tickex_customer_portal_resend_allowed($pdo, $usuarioId, $entradaId)) {
            return array(false, 'Ya reenviamos esta entrada recientemente. Revisá tu correo o esperá unos minutos.');
        }
        $ok = (bool)call_user_func($sender, $ticket, (string)$ticketUrl);
        $log = $pdo->prepare("INSERT INTO customer_ticket_resends(usuario_id,entrada_id,email,mail_ok,created_at)
            VALUES(:uid,:eid,:email,:ok,datetime('now'))");
        $log->execute(array(':uid' => (int)$usuarioId, ':eid' => (int)$entradaId, ':email' => $email, ':ok' => $ok ? 1 : 0));
        return $ok
            ? array(true, 'Te reenviamos la entrada a ' . $email . '.')
            : array(false, 'No pudimos enviar el correo. Probá nuevamente más tarde.');
    }
}
