<?php
// Read-only staff event scope for consumer accounts invited by an organizer.

function tickex_mobile_staff_events($pdo, $clientId)
{
    $statement = $pdo->prepare("SELECT e.id, e.nombre, e.fecha_desde, e.fecha_hasta, e.lugar,
                                      s.owner_admin_id, COALESCE(NULLIF(s.rol_staff, ''), 'puerta') AS role_code,
                                      r.permissions_json
                               FROM staff_admins s
                               JOIN eventos e ON e.creado_por_admin_id = s.owner_admin_id
                               LEFT JOIN staff_roles r ON r.owner_admin_id = s.owner_admin_id
                                   AND r.code = COALESCE(NULLIF(s.rol_staff, ''), 'puerta') AND r.activo = 1
                               WHERE s.cliente_id = :client AND s.activo = 1
                                   AND (e.borrado_en IS NULL OR e.borrado_en = '')
                               ORDER BY e.fecha_desde DESC, e.id DESC LIMIT 100");
    $statement->execute(array(':client' => (int)$clientId));
    $events = array();
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $permissions = null;
        if ($row['permissions_json'] !== null) {
            $decoded = json_decode($row['permissions_json'], true);
            $permissions = is_array($decoded) ? $decoded : array();
        } else {
            $defaults = array(
                'puerta' => array('dashboard_view', 'checkin_scan', 'tickets_validate'),
                'acreditacion' => array('dashboard_view', 'tickets_validate'),
                'caja' => array('dashboard_view', 'sales_view'),
                'staff_evento' => array('dashboard_view', 'checkin_scan', 'tickets_validate', 'sales_view', 'reports_view'),
            );
            $permissions = isset($defaults[$row['role_code']]) ? $defaults[$row['role_code']] : array();
        }
        if (!in_array('dashboard_view', $permissions, true)) continue;
        $events[] = array(
            'id' => (string)$row['id'],
            'name' => (string)$row['nombre'],
            'startsAt' => tickex_mobile_datetime($row['fecha_desde']),
            'endsAt' => tickex_mobile_datetime($row['fecha_hasta']),
            'venue' => (string)$row['lugar'],
            'role' => (string)$row['role_code'],
            'permissions' => array_values(array_intersect($permissions, array('dashboard_view', 'checkin_scan', 'tickets_validate', 'sales_view', 'reports_view'))),
        );
    }
    return $events;
}
