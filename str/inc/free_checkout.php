<?php

require_once __DIR__ . '/secure_links.php';
require_once __DIR__ . '/mail.php';

if (!function_exists('tickex_free_checkout_ensure_schema')) {
    function tickex_free_checkout_ensure_schema($pdo)
    {
        static $done = false;
        if ($done) return;
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS event_free_checkout_configs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                evento_id INTEGER NOT NULL UNIQUE,
                enabled INTEGER NOT NULL DEFAULT 0,
                ticket_type_id INTEGER NOT NULL,
                max_uses INTEGER,
                captcha_required INTEGER NOT NULL DEFAULT 1,
                unique_email INTEGER NOT NULL DEFAULT 1,
                created_by_admin_id INTEGER,
                updated_by_admin_id INTEGER,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                updated_at TEXT
            )");
            $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_free_checkout_evento ON event_free_checkout_configs(evento_id)");
        } catch (Exception $e) {
            // ignore
        }

        try {
            $cols = $pdo->query('PRAGMA table_info(event_free_checkout_configs)')->fetchAll(PDO::FETCH_ASSOC);
            $have = array();
            foreach ($cols as $c) {
                if (isset($c['name'])) $have[$c['name']] = true;
            }
            if (!isset($have['enabled'])) $pdo->exec("ALTER TABLE event_free_checkout_configs ADD COLUMN enabled INTEGER NOT NULL DEFAULT 0");
            if (!isset($have['ticket_type_id'])) $pdo->exec("ALTER TABLE event_free_checkout_configs ADD COLUMN ticket_type_id INTEGER");
            if (!isset($have['max_uses'])) $pdo->exec("ALTER TABLE event_free_checkout_configs ADD COLUMN max_uses INTEGER");
            if (!isset($have['captcha_required'])) $pdo->exec("ALTER TABLE event_free_checkout_configs ADD COLUMN captcha_required INTEGER NOT NULL DEFAULT 1");
            if (!isset($have['unique_email'])) $pdo->exec("ALTER TABLE event_free_checkout_configs ADD COLUMN unique_email INTEGER NOT NULL DEFAULT 1");
            if (!isset($have['created_by_admin_id'])) $pdo->exec("ALTER TABLE event_free_checkout_configs ADD COLUMN created_by_admin_id INTEGER");
            if (!isset($have['updated_by_admin_id'])) $pdo->exec("ALTER TABLE event_free_checkout_configs ADD COLUMN updated_by_admin_id INTEGER");
            if (!isset($have['created_at'])) $pdo->exec("ALTER TABLE event_free_checkout_configs ADD COLUMN created_at TEXT");
            if (!isset($have['updated_at'])) $pdo->exec("ALTER TABLE event_free_checkout_configs ADD COLUMN updated_at TEXT");
        } catch (Exception $e) {
            // ignore
        }
        $done = true;
    }
}

if (!function_exists('tickex_free_checkout_tipos_cols')) {
    function tickex_free_checkout_tipos_cols($pdo)
    {
        static $cache = null;
        if ($cache !== null) return $cache;
        $cache = array();
        try {
            $cols = $pdo->query('PRAGMA table_info(tipos_entrada)')->fetchAll(PDO::FETCH_ASSOC);
            foreach ($cols as $c) {
                if (isset($c['name'])) $cache[$c['name']] = true;
            }
        } catch (Exception $e) {
            $cache = array();
        }
        return $cache;
    }
}

if (!function_exists('tickex_free_checkout_entry_cols')) {
    function tickex_free_checkout_entry_cols($pdo)
    {
        static $cache = null;
        if ($cache !== null) return $cache;
        $cache = array();
        try {
            $cols = $pdo->query('PRAGMA table_info(entradas)')->fetchAll(PDO::FETCH_ASSOC);
            foreach ($cols as $c) {
                if (isset($c['name'])) $cache[$c['name']] = true;
            }
        } catch (Exception $e) {
            $cache = array();
        }
        return $cache;
    }
}

if (!function_exists('tickex_free_checkout_base_url')) {
    function tickex_free_checkout_base_url()
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

if (!function_exists('tickex_free_checkout_generate_code')) {
    function tickex_free_checkout_generate_code($pdo, $eventoId)
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $stChk = $pdo->prepare('SELECT 1 FROM entradas WHERE codigo = :c LIMIT 1');
        for ($attempt = 0; $attempt < 40; $attempt++) {
            $rand = '';
            if (function_exists('random_bytes')) {
                try {
                    $bytes = random_bytes(10);
                    for ($i = 0; $i < 10; $i++) $rand .= $alphabet[ord($bytes[$i]) % strlen($alphabet)];
                } catch (Exception $e) {
                    $rand = substr(sha1(uniqid((string)mt_rand(), true)), 0, 10);
                }
            } else {
                for ($i = 0; $i < 10; $i++) $rand .= $alphabet[mt_rand(0, strlen($alphabet) - 1)];
            }
            $codigo = 'F' . (int)$eventoId . '-' . $rand;
            $stChk->execute(array(':c' => $codigo));
            if (!$stChk->fetchColumn()) return $codigo;
        }
        return '';
    }
}

if (!function_exists('tickex_free_checkout_find_event')) {
    function tickex_free_checkout_find_event($pdo, $eventoId, $slug)
    {
        tickex_free_checkout_ensure_schema($pdo);
        if ((int)$eventoId > 0) {
            $st = $pdo->prepare('SELECT * FROM eventos WHERE id = :id LIMIT 1');
            $st->execute(array(':id' => (int)$eventoId));
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row) return $row;
        }
        $slug = trim((string)$slug);
        if ($slug !== '') {
            $st = $pdo->prepare('SELECT * FROM eventos WHERE slug = :slug LIMIT 1');
            $st->execute(array(':slug' => $slug));
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row) return $row;
        }
        return null;
    }
}

if (!function_exists('tickex_free_checkout_load_config')) {
    function tickex_free_checkout_load_config($pdo, $eventoId)
    {
        tickex_free_checkout_ensure_schema($pdo);
        $teCols = tickex_free_checkout_tipos_cols($pdo);
        $stockDisp = isset($teCols['cantidad_disponible']) ? 't.cantidad_disponible' : 'NULL';
        $stockTot = isset($teCols['cantidad_total']) ? 't.cantidad_total' : 'NULL';
        try {
            $st = $pdo->prepare('SELECT c.*, t.nombre AS ticket_type_nombre, ' . $stockDisp . ' AS cantidad_disponible, ' . $stockTot . ' AS cantidad_total, e.nombre AS evento_nombre, e.slug AS evento_slug
                FROM event_free_checkout_configs c
                LEFT JOIN tipos_entrada t ON t.id = c.ticket_type_id
                LEFT JOIN eventos e ON e.id = c.evento_id
                WHERE c.evento_id = :eid LIMIT 1');
            $st->execute(array(':eid' => (int)$eventoId));
            $row = $st->fetch(PDO::FETCH_ASSOC);
            return $row ? $row : null;
        } catch (Exception $e) {
            return null;
        }
    }
}

if (!function_exists('tickex_free_checkout_count_issued')) {
    function tickex_free_checkout_count_issued($pdo, $eventoId)
    {
        tickex_free_checkout_ensure_schema($pdo);
        $cols = tickex_free_checkout_entry_cols($pdo);
        if (isset($cols['payment_method'])) {
            $st = $pdo->prepare("SELECT COUNT(*) FROM entradas WHERE evento_id = :eid AND payment_method = 'free'");
            $st->execute(array(':eid' => (int)$eventoId));
            return (int)$st->fetchColumn();
        }
        return 0;
    }
}

if (!function_exists('tickex_free_checkout_email_exists')) {
    function tickex_free_checkout_email_exists($pdo, $eventoId, $email)
    {
        tickex_free_checkout_ensure_schema($pdo);
        $cols = tickex_free_checkout_entry_cols($pdo);
        $email = strtolower(trim((string)$email));
        if ($email === '') return false;
        if (isset($cols['payment_method'])) {
            $st = $pdo->prepare("SELECT COUNT(*) FROM entradas WHERE evento_id = :eid AND payment_method = 'free' AND lower(email) = :em");
            $st->execute(array(':eid' => (int)$eventoId, ':em' => $email));
            return ((int)$st->fetchColumn() > 0);
        }
        return false;
    }
}

if (!function_exists('tickex_free_checkout_issue')) {
    function tickex_free_checkout_issue($pdo, $config, $data)
    {
        tickex_free_checkout_ensure_schema($pdo);
        $ticketTypeId = isset($config['ticket_type_id']) ? (int)$config['ticket_type_id'] : 0;
        $eventoId = isset($config['evento_id']) ? (int)$config['evento_id'] : 0;
        if ($ticketTypeId <= 0 || $eventoId <= 0) {
            return array('ok' => false, 'error' => 'Configuración inválida del checkout free.');
        }

        $teCols = tickex_free_checkout_tipos_cols($pdo);
        $selectCols = array('id', 'nombre');
        if (isset($teCols['cantidad_disponible'])) $selectCols[] = 'cantidad_disponible';
        if (isset($teCols['cantidad_total'])) $selectCols[] = 'cantidad_total';
        $stType = $pdo->prepare('SELECT ' . implode(',', $selectCols) . ' FROM tipos_entrada WHERE id = :id AND evento_id = :eid LIMIT 1');
        $stType->execute(array(':id' => $ticketTypeId, ':eid' => $eventoId));
        $typeRow = $stType->fetch(PDO::FETCH_ASSOC);
        if (!$typeRow) {
            return array('ok' => false, 'error' => 'La entrada configurada no existe para este evento.');
        }

        $available = null;
        if (isset($typeRow['cantidad_disponible']) && $typeRow['cantidad_disponible'] !== null) {
            $available = (int)$typeRow['cantidad_disponible'];
        } elseif (isset($typeRow['cantidad_total']) && $typeRow['cantidad_total'] !== null) {
            $available = (int)$typeRow['cantidad_total'];
        }
        if ($available !== null && $available <= 0) {
            return array('ok' => false, 'error' => 'No hay cupo disponible para este checkout.');
        }

        $maxUses = (isset($config['max_uses']) && $config['max_uses'] !== null && $config['max_uses'] !== '') ? (int)$config['max_uses'] : 0;
        if ($maxUses > 0) {
            $issued = tickex_free_checkout_count_issued($pdo, $eventoId);
            if ($issued >= $maxUses) {
                return array('ok' => false, 'error' => 'Este checkout free alcanzó su cupo máximo.');
            }
        }

        if (!empty($config['unique_email']) && tickex_free_checkout_email_exists($pdo, $eventoId, isset($data['email']) ? $data['email'] : '')) {
            return array('ok' => false, 'error' => 'Este email ya recibió una entrada gratuita para este evento.');
        }

        $codigo = tickex_free_checkout_generate_code($pdo, $eventoId);
        if ($codigo === '') {
            return array('ok' => false, 'error' => 'No se pudo generar el código de la entrada.');
        }

        $cols = tickex_free_checkout_entry_cols($pdo);
        $fullName = trim((isset($data['nombre']) ? (string)$data['nombre'] : '') . ' ' . (isset($data['apellido']) ? (string)$data['apellido'] : ''));
        if ($fullName === '') $fullName = (string)(isset($data['email']) ? $data['email'] : 'Invitado');

        $insert = array(
            'evento_id' => $eventoId,
            'nombre' => $fullName,
            'email' => isset($data['email']) ? trim((string)$data['email']) : '',
            'fecha_registro' => date('Y-m-d H:i:s'),
            'codigo' => $codigo,
            'checked_in' => 0,
            'tipo' => isset($typeRow['nombre']) ? (string)$typeRow['nombre'] : 'FREE',
            'monto_pagado' => 0,
        );
        if (isset($cols['payment_method'])) $insert['payment_method'] = 'free';

        $sqlCols = array();
        $sqlVals = array();
        $params = array();
        foreach ($insert as $k => $v) {
            if (!isset($cols[$k])) continue;
            $sqlCols[] = $k;
            $sqlVals[] = ':' . $k;
            $params[':' . $k] = $v;
        }

        try {
            $pdo->beginTransaction();

            if (isset($typeRow['cantidad_disponible'])) {
                $stUpd = $pdo->prepare('UPDATE tipos_entrada SET cantidad_disponible = cantidad_disponible - 1 WHERE id = :id AND evento_id = :eid AND cantidad_disponible > 0');
                $stUpd->execute(array(':id' => $ticketTypeId, ':eid' => $eventoId));
                if ($stUpd->rowCount() <= 0) {
                    $pdo->rollBack();
                    return array('ok' => false, 'error' => 'No hay cupo disponible para este checkout.');
                }
            }

            $sql = 'INSERT INTO entradas (' . implode(',', $sqlCols) . ') VALUES (' . implode(',', $sqlVals) . ')';
            $stIns = $pdo->prepare($sql);
            $stIns->execute($params);
            $entryId = (int)$pdo->lastInsertId();
            $pdo->commit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return array('ok' => false, 'error' => 'No se pudo emitir la entrada.');
        }

        $ticketUrl = tickex_secure_ticket_url($pdo, tickex_free_checkout_base_url(), $entryId, $codigo);

        try {
            tickex_send_mail_template(
                isset($data['email']) ? trim((string)$data['email']) : '',
                'entrada_registro',
                array(
                    'id' => $entryId,
                    'nombre' => $fullName,
                    'email' => isset($data['email']) ? trim((string)$data['email']) : '',
                    'tipo' => isset($typeRow['nombre']) ? (string)$typeRow['nombre'] : 'FREE',
                    'fecha_registro' => date('Y-m-d H:i:s'),
                    'ticket_url' => $ticketUrl,
                    'codigo' => $codigo,
                ),
                array(
                    'context' => 'entrada_registro',
                    'related_table' => 'entradas',
                    'related_id' => $entryId,
                )
            );
        } catch (Exception $e) {
            // no bloquear la emisión por fallo de mail
        }

        return array('ok' => true, 'entry_id' => $entryId, 'ticket_url' => $ticketUrl);
    }
}
