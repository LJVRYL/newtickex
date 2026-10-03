<?php
// Read-only staff event scope for consumer accounts invited by an organizer.
require_once __DIR__ . '/staff_operations.php';

function tickex_mobile_staff_events($pdo, $clientId)
{
    $eventColumns = tickex_staff_operations_columns($pdo, 'staff_eventos');
    $roleCode = isset($eventColumns['rol_staff']) ? "COALESCE(NULLIF(se.rol_staff, ''), NULLIF(s.rol_staff, ''), 'puerta')" : "COALESCE(NULLIF(s.rol_staff, ''), 'puerta')";
    $statement = $pdo->prepare("SELECT e.id, e.nombre, e.fecha_desde, e.fecha_hasta, e.lugar,
                                      s.owner_admin_id, " . $roleCode . " AS role_code,
                                      r.permissions_json
                               FROM staff_admins s
                               JOIN staff_eventos se ON se.staff_id = s.cliente_id
                               JOIN eventos e ON e.creado_por_admin_id = s.owner_admin_id
                                   AND e.id = se.evento_id
                               LEFT JOIN staff_roles r ON r.owner_admin_id = s.owner_admin_id
                                   AND r.code = " . $roleCode . " AND r.activo = 1
                               WHERE s.cliente_id = :client AND s.activo = 1
                                   AND (e.borrado_en IS NULL OR e.borrado_en = '')
                               ORDER BY e.fecha_desde DESC, e.id DESC LIMIT 100");
    $statement->execute(array(':client' => (int)$clientId));
    $events = array();
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $decoded = json_decode((string)$row['permissions_json'], true);
        $permissions = is_array($decoded) ? $decoded : array();
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
