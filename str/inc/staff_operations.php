<?php

require_once __DIR__ . '/staff_roles.php';

if (!function_exists('tickex_staff_operations_columns')) {
    function tickex_staff_operations_columns($pdo, $table)
    {
        $columns = array();
        try {
            foreach ($pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $columns[(string)$row['name']] = true;
            }
        } catch (Exception $e) {}
        return $columns;
    }
}

if (!function_exists('tickex_staff_operations_ensure_schema')) {
    function tickex_staff_operations_ensure_schema($pdo)
    {
        $columns = tickex_staff_operations_columns($pdo, 'staff_eventos');
        $additions = array(
            'rol_staff' => 'TEXT',
            'attendance_status' => "TEXT NOT NULL DEFAULT 'pending'",
            'checked_in_at' => 'TEXT',
            'checked_out_at' => 'TEXT',
            'settlement_status' => "TEXT NOT NULL DEFAULT 'pending'",
            'settled_amount' => 'REAL NOT NULL DEFAULT 0',
            'settled_at' => 'TEXT',
            'notes' => 'TEXT'
        );
        foreach ($additions as $name => $definition) {
            if (!isset($columns[$name])) $pdo->exec('ALTER TABLE staff_eventos ADD COLUMN ' . $name . ' ' . $definition);
        }
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_staff_eventos_staff_event ON staff_eventos(staff_id,evento_id)');

        $pdo->exec("CREATE TABLE IF NOT EXISTS staff_shifts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            owner_admin_id INTEGER NOT NULL,
            staff_id INTEGER NOT NULL,
            evento_id INTEGER NOT NULL,
            starts_at TEXT NOT NULL,
            ends_at TEXT,
            area TEXT,
            notes TEXT,
            status TEXT NOT NULL DEFAULT 'scheduled',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_staff_shifts_event ON staff_shifts(owner_admin_id,evento_id,starts_at)');

        $pdo->exec("CREATE TABLE IF NOT EXISTS staff_tasks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            owner_admin_id INTEGER NOT NULL,
            evento_id INTEGER NOT NULL,
            assigned_staff_id INTEGER,
            role_code TEXT,
            title TEXT NOT NULL,
            notes TEXT,
            due_at TEXT,
            status TEXT NOT NULL DEFAULT 'pending',
            completed_at TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_staff_tasks_event ON staff_tasks(owner_admin_id,evento_id,status)');

        $pdo->exec("CREATE TABLE IF NOT EXISTS staff_audit_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            owner_admin_id INTEGER NOT NULL,
            actor_admin_id INTEGER NOT NULL,
            staff_id INTEGER,
            evento_id INTEGER,
            action TEXT NOT NULL,
            detail_json TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_staff_audit_owner_event ON staff_audit_log(owner_admin_id,evento_id,created_at)');
    }
}

if (!function_exists('tickex_staff_audit')) {
    function tickex_staff_audit($pdo, $ownerAdminId, $actorAdminId, $action, $staffId, $eventId, $details)
    {
        tickex_staff_operations_ensure_schema($pdo);
        $st = $pdo->prepare('INSERT INTO staff_audit_log (owner_admin_id,actor_admin_id,staff_id,evento_id,action,detail_json) VALUES (:owner,:actor,:staff,:event,:action,:details)');
        $st->execute(array(
            ':owner'=>(int)$ownerAdminId, ':actor'=>(int)$actorAdminId,
            ':staff'=>$staffId ? (int)$staffId : null, ':event'=>$eventId ? (int)$eventId : null,
            ':action'=>(string)$action, ':details'=>json_encode(is_array($details) ? $details : array('detail'=>$details))
        ));
    }
}

if (!function_exists('tickex_staff_assign_event')) {
    function tickex_staff_assign_event($pdo, $ownerAdminId, $actorAdminId, $staffId, $eventId, $roleCode, $cost)
    {
        tickex_staff_operations_ensure_schema($pdo);
        $staff = $pdo->prepare('SELECT 1 FROM staff_admins WHERE owner_admin_id=:owner AND cliente_id=:staff AND activo=1 LIMIT 1');
        $staff->execute(array(':owner'=>(int)$ownerAdminId, ':staff'=>(int)$staffId));
        if (!$staff->fetchColumn()) throw new RuntimeException('El miembro no pertenece a tu equipo.');
        $event = $pdo->prepare('SELECT 1 FROM eventos WHERE id=:event AND creado_por_admin_id=:owner LIMIT 1');
        $event->execute(array(':event'=>(int)$eventId, ':owner'=>(int)$ownerAdminId));
        if (!$event->fetchColumn()) throw new RuntimeException('El evento no pertenece a tu cuenta.');
        $roles = tickex_staff_roles_get_map($pdo, (int)$ownerAdminId);
        if (!isset($roles[$roleCode])) throw new RuntimeException('El rol seleccionado no existe.');

        $existing = $pdo->prepare('SELECT id FROM staff_eventos WHERE staff_id=:staff AND evento_id=:event LIMIT 1');
        $existing->execute(array(':staff'=>(int)$staffId, ':event'=>(int)$eventId));
        $id = (int)$existing->fetchColumn();
        if ($id > 0) {
            $st = $pdo->prepare('UPDATE staff_eventos SET rol_staff=:role,costo_servicio=:cost WHERE id=:id');
            $st->execute(array(':role'=>$roleCode, ':cost'=>max(0,(float)$cost), ':id'=>$id));
            $action = 'assignment_updated';
        } else {
            $st = $pdo->prepare('INSERT INTO staff_eventos (staff_id,evento_id,costo_servicio,rol_staff) VALUES (:staff,:event,:cost,:role)');
            $st->execute(array(':staff'=>(int)$staffId, ':event'=>(int)$eventId, ':cost'=>max(0,(float)$cost), ':role'=>$roleCode));
            $action = 'event_assigned';
        }
        tickex_staff_audit($pdo,$ownerAdminId,$actorAdminId,$action,$staffId,$eventId,array('role'=>$roleCode,'cost'=>max(0,(float)$cost)));
        return true;
    }
}

if (!function_exists('tickex_staff_remove_event')) {
    function tickex_staff_remove_event($pdo, $ownerAdminId, $actorAdminId, $staffId, $eventId)
    {
        tickex_staff_operations_ensure_schema($pdo);
        $st = $pdo->prepare('DELETE FROM staff_eventos WHERE staff_id=:staff AND evento_id=:event AND EXISTS (SELECT 1 FROM staff_admins sa WHERE sa.cliente_id=:staff AND sa.owner_admin_id=:owner) AND EXISTS (SELECT 1 FROM eventos e WHERE e.id=:event AND e.creado_por_admin_id=:owner)');
        $st->execute(array(':staff'=>(int)$staffId, ':event'=>(int)$eventId, ':owner'=>(int)$ownerAdminId));
        if ($st->rowCount() > 0) tickex_staff_audit($pdo,$ownerAdminId,$actorAdminId,'event_unassigned',$staffId,$eventId,array());
        return $st->rowCount() > 0;
    }
}

if (!function_exists('tickex_staff_operations_summary')) {
    function tickex_staff_operations_summary($pdo, $ownerAdminId, $eventId)
    {
        tickex_staff_operations_ensure_schema($pdo);
        $params = array(':owner'=>(int)$ownerAdminId, ':event'=>(int)$eventId);
        $st = $pdo->prepare("SELECT COUNT(*) assigned,
            SUM(CASE WHEN se.attendance_status='present' THEN 1 ELSE 0 END) present,
            SUM(CASE WHEN se.settlement_status='paid' THEN 1 ELSE 0 END) settled,
            COALESCE(SUM(se.costo_servicio),0) planned_cost,
            COALESCE(SUM(se.settled_amount),0) paid_cost
            FROM staff_eventos se JOIN staff_admins sa ON sa.cliente_id=se.staff_id
            WHERE sa.owner_admin_id=:owner AND se.evento_id=:event");
        $st->execute($params);
        $summary = $st->fetch(PDO::FETCH_ASSOC) ?: array();
        $tasks = $pdo->prepare("SELECT COUNT(*) total,SUM(CASE WHEN status='done' THEN 1 ELSE 0 END) done FROM staff_tasks WHERE owner_admin_id=:owner AND evento_id=:event");
        $tasks->execute($params);
        $taskSummary = $tasks->fetch(PDO::FETCH_ASSOC) ?: array();
        return array_merge(array('assigned'=>0,'present'=>0,'settled'=>0,'planned_cost'=>0,'paid_cost'=>0),$summary,array(
            'tasks'=>(int)(isset($taskSummary['total'])?$taskSummary['total']:0),
            'tasks_done'=>(int)(isset($taskSummary['done'])?$taskSummary['done']:0)
        ));
    }
}

if (!function_exists('tickex_staff_event_access_profile')) {
    function tickex_staff_event_access_profile($pdo, $staffId, $eventId)
    {
        tickex_staff_operations_ensure_schema($pdo);
        $st=$pdo->prepare("SELECT sa.owner_admin_id,COALESCE(NULLIF(se.rol_staff,''),sa.rol_staff,'puerta') role_code FROM staff_eventos se JOIN staff_admins sa ON sa.cliente_id=se.staff_id AND sa.activo=1 JOIN eventos e ON e.id=se.evento_id AND e.creado_por_admin_id=sa.owner_admin_id WHERE se.staff_id=:staff AND se.evento_id=:event LIMIT 1");
        $st->execute(array(':staff'=>(int)$staffId,':event'=>(int)$eventId));
        $row=$st->fetch(PDO::FETCH_ASSOC);
        if(!$row) return null;
        $row['permissions']=tickex_staff_role_permissions($pdo,(int)$row['owner_admin_id'],(string)$row['role_code']);
        return $row;
    }
}

if (!function_exists('tickex_staff_door_summary')) {
    function tickex_staff_door_summary($pdo, $ownerAdminId, $eventId)
    {
        tickex_staff_operations_ensure_schema($pdo);
        $staff = $pdo->prepare("SELECT COUNT(*) FROM staff_eventos se JOIN staff_admins sa ON sa.cliente_id=se.staff_id WHERE sa.owner_admin_id=:owner AND se.evento_id=:event AND COALESCE(NULLIF(se.rol_staff,''),sa.rol_staff)='puerta'");
        $staff->execute(array(':owner'=>(int)$ownerAdminId, ':event'=>(int)$eventId));
        $sales = $pdo->prepare("SELECT COUNT(*) quantity,COALESCE(SUM(e.monto_pagado),0) revenue FROM entradas e WHERE e.evento_id=:event AND e.monto_pagado>0 AND (UPPER(e.tipo) LIKE '%PUERTA%' OR e.issuance_key LIKE 'door-reservation:%')");
        $sales->execute(array(':event'=>(int)$eventId));
        $sale = $sales->fetch(PDO::FETCH_ASSOC) ?: array('quantity'=>0,'revenue'=>0);
        $checkins = $pdo->prepare('SELECT COUNT(*) FROM entradas WHERE evento_id=:event AND checked_in=1');
        $checkins->execute(array(':event'=>(int)$eventId));
        $reserved = 0;
        try {
            $st = $pdo->prepare("SELECT COUNT(*) FROM event_door_guest_reservations WHERE evento_id=:event AND status='reserved'");
            $st->execute(array(':event'=>(int)$eventId));
            $reserved = (int)$st->fetchColumn();
        } catch (Exception $e) {}
        return array('door_staff'=>(int)$staff->fetchColumn(),'door_sales'=>(int)$sale['quantity'],'door_revenue'=>(float)$sale['revenue'],'checkins'=>(int)$checkins->fetchColumn(),'reserved'=>$reserved);
    }
}
