<?php
require_once __DIR__ . '/support_center.php';

if (!function_exists('tickex_superadmin_scalar')) {
    function tickex_superadmin_scalar($pdo, $sql, $params = array())
    {
        try { $st=$pdo->prepare($sql);$st->execute($params);return $st->fetchColumn(); }
        catch (Exception $e) { return 0; }
    }
}

if (!function_exists('tickex_superadmin_rows')) {
    function tickex_superadmin_rows($pdo, $sql, $params = array())
    {
        try { $st=$pdo->prepare($sql);$st->execute($params);return $st->fetchAll(PDO::FETCH_ASSOC); }
        catch (Exception $e) { return array(); }
    }
}

if (!function_exists('tickex_superadmin_dashboard_data')) {
    function tickex_superadmin_dashboard_data($pdo, $today = null)
    {
        tickex_support_ensure_schema($pdo);
        $today = $today ?: date('Y-m-d');
        $data = array();
        $data['metrics'] = array(
            'organizers' => (int)tickex_superadmin_scalar($pdo,"SELECT COUNT(*) FROM usuarios_admin WHERE tipo_global='admin_evento' AND COALESCE(activo,1)=1"),
            'buyers' => (int)tickex_superadmin_scalar($pdo,"SELECT COUNT(*) FROM registro_pendientes WHERE completado_en IS NOT NULL AND trim(completado_en)<>''"),
            'events' => (int)tickex_superadmin_scalar($pdo,'SELECT COUNT(*) FROM eventos WHERE borrado_en IS NULL'),
            'active_events' => (int)tickex_superadmin_scalar($pdo,"SELECT COUNT(*) FROM eventos WHERE borrado_en IS NULL AND ((fecha_hasta IS NOT NULL AND substr(fecha_hasta,1,10)>=:today) OR ((fecha_hasta IS NULL OR trim(fecha_hasta)='') AND fecha_desde IS NOT NULL AND substr(fecha_desde,1,10)>=:today))",array(':today'=>$today)),
            'issued' => (int)tickex_superadmin_scalar($pdo,'SELECT COUNT(*) FROM entradas WHERE COALESCE(oculto,0)=0'),
            'checkins' => (int)tickex_superadmin_scalar($pdo,'SELECT COUNT(*) FROM entradas WHERE COALESCE(oculto,0)=0 AND COALESCE(checked_in,0)=1'),
            'revenue' => (float)tickex_superadmin_scalar($pdo,"SELECT COALESCE(SUM(amount),0) FROM tc_orders WHERE payment_status='confirmed'"),
            'open_support' => (int)tickex_superadmin_scalar($pdo,"SELECT COUNT(*) FROM support_tickets WHERE status IN ('open','in_progress')"),
        );
        $data['alerts'] = array(
            'payments_pending' => (int)tickex_superadmin_scalar($pdo,"SELECT COUNT(*) FROM tc_orders WHERE payment_status='pending'"),
            'support_waiting' => (int)tickex_superadmin_scalar($pdo,"SELECT COUNT(*) FROM support_tickets WHERE status='open'"),
            'mp_missing' => (int)tickex_superadmin_scalar($pdo,"SELECT COUNT(*) FROM usuarios_admin a LEFT JOIN mercadopago_marketplace_accounts m ON m.admin_id=a.id AND m.status='connected' WHERE a.tipo_global='admin_evento' AND COALESCE(a.activo,1)=1 AND m.admin_id IS NULL"),
            'campaigns_attention' => (int)tickex_superadmin_scalar($pdo,"SELECT COUNT(*) FROM communication_campaigns WHERE removed_at IS NULL AND status IN ('failed','cancelled')"),
        );
        $data['organizers'] = tickex_superadmin_rows($pdo,"SELECT a.id,COALESCE(NULLIF(trim(a.nombre||' '||COALESCE(a.apellido,'')),''),a.email,a.username) AS display_name,a.email,COALESCE(a.activo,1) AS active,(SELECT COUNT(*) FROM eventos e WHERE e.creado_por_admin_id=a.id AND e.borrado_en IS NULL) AS event_count,COALESCE(m.status,'not_connected') AS mp_status FROM usuarios_admin a LEFT JOIN mercadopago_marketplace_accounts m ON m.admin_id=a.id WHERE a.tipo_global='admin_evento' AND COALESCE(a.activo,1)=1 ORDER BY a.id DESC LIMIT 6");
        $data['events'] = tickex_superadmin_rows($pdo,"SELECT e.id,e.nombre,e.fecha_desde,e.fecha_hasta,e.publicado_site,a.email AS owner_email,(SELECT COUNT(*) FROM entradas en WHERE en.evento_id=e.id AND COALESCE(en.oculto,0)=0) AS issued FROM eventos e LEFT JOIN usuarios_admin a ON a.id=e.creado_por_admin_id WHERE e.borrado_en IS NULL ORDER BY CASE WHEN e.fecha_desde IS NULL OR trim(e.fecha_desde)='' THEN 2 WHEN substr(e.fecha_desde,1,10)>=:today THEN 0 ELSE 1 END,datetime(e.fecha_desde) ASC,e.id DESC LIMIT 6",array(':today'=>$today));
        $data['payments'] = tickex_superadmin_rows($pdo,"SELECT o.id,o.request_id,o.amount,o.payment_status,o.payment_provider,o.created_at,e.nombre AS event_name,a.email AS owner_email FROM tc_orders o LEFT JOIN eventos e ON e.id=o.evento_id LEFT JOIN usuarios_admin a ON a.id=COALESCE(o.seller_admin_id,e.creado_por_admin_id) ORDER BY o.id DESC LIMIT 7");
        $data['support'] = tickex_superadmin_rows($pdo,"SELECT t.id,t.public_id,t.subject,t.priority,t.status,t.last_activity_at,a.email AS owner_email FROM support_tickets t LEFT JOIN usuarios_admin a ON a.id=t.admin_id WHERE t.status NOT IN ('resolved','closed') ORDER BY CASE t.priority WHEN 'urgent' THEN 1 WHEN 'high' THEN 2 ELSE 3 END,datetime(t.last_activity_at) DESC LIMIT 5");
        return $data;
    }
}
