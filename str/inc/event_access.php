<?php

if (!function_exists('tickex_admin_id')) {
    function tickex_admin_id($user = null)
    {
        if (!is_array($user) && function_exists('current_user')) $user = current_user();
        foreach (array('admin_id', 'user_id', 'id') as $key) {
            if (isset($user[$key]) && (int)$user[$key] > 0) return (int)$user[$key];
        }
        foreach (array('admin_id', 'user_id') as $key) {
            if (isset($_SESSION[$key]) && (int)$_SESSION[$key] > 0) return (int)$_SESSION[$key];
        }
        return 0;
    }
}

if (!function_exists('tickex_admin_role')) {
    function tickex_admin_role($user = null)
    {
        if (!is_array($user) && function_exists('current_user')) $user = current_user();
        if (isset($user['tipo_global'])) return (string)$user['tipo_global'];
        return isset($_SESSION['tipo_global']) ? (string)$_SESSION['tipo_global'] : '';
    }
}

if (!function_exists('tickex_is_super_admin')) {
    function tickex_is_super_admin($user = null)
    {
        return in_array(tickex_admin_role($user), array('super_admin', 'superadmin'), true);
    }
}

if (!function_exists('tickex_can_access_event')) {
    function tickex_can_access_event($pdo, $eventId, $user = null)
    {
        $eventId = (int)$eventId;
        $adminId = tickex_admin_id($user);
        $role = tickex_admin_role($user);
        if ($eventId <= 0 || $adminId <= 0) return false;
        if (tickex_is_super_admin($user)) return true;

        if ($role === 'admin_evento') {
            $st = $pdo->prepare('SELECT 1 FROM eventos WHERE id=:event AND creado_por_admin_id=:admin LIMIT 1');
            $st->execute(array(':event'=>$eventId, ':admin'=>$adminId));
            return (bool)$st->fetchColumn();
        }

        if ($role === 'staff_evento') {
            $st = $pdo->prepare('SELECT 1 FROM staff_eventos WHERE evento_id=:event AND staff_id=:admin LIMIT 1');
            $st->execute(array(':event'=>$eventId, ':admin'=>$adminId));
            if ($st->fetchColumn()) return true;
            $st = $pdo->prepare("SELECT 1 FROM usuarios_admin WHERE id=:admin AND evento_id=:event AND tipo_global='staff_evento' LIMIT 1");
            $st->execute(array(':event'=>$eventId, ':admin'=>$adminId));
            return (bool)$st->fetchColumn();
        }
        return false;
    }
}

if (!function_exists('tickex_require_event_access')) {
    function tickex_require_event_access($pdo, $eventId, $user = null)
    {
        if (tickex_can_access_event($pdo, $eventId, $user)) return;
        http_response_code(404);
        if (function_exists('abort_404')) abort_404('Evento no encontrado o sin permiso.');
        echo 'Evento no encontrado o sin permiso.';
        exit;
    }
}

if (!function_exists('tickex_visible_events')) {
    function tickex_visible_events($pdo, $user = null, $includeDeleted = false)
    {
        $where = array();
        $params = array();
        if (!$includeDeleted) $where[] = 'borrado_en IS NULL';
        if (!tickex_is_super_admin($user)) {
            $where[] = 'creado_por_admin_id=:admin';
            $params[':admin'] = tickex_admin_id($user);
        }
        $sql = 'SELECT * FROM eventos' . ($where ? ' WHERE '.implode(' AND ', $where) : '') . ' ORDER BY id DESC';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}
