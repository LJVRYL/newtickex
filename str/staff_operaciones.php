<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/staff_operations.php';

$title = 'Operaciones de Staff';
$user = current_user();
$role = tickex_admin_role($user);
$adminContext = isset($_SESSION['auth_context']) && $_SESSION['auth_context'] === 'admin';
if (!$adminContext || (!tickex_is_super_admin($user) && $role !== 'admin_evento')) {
    $next = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '/staff_operaciones.php';
    header('Location: /login_admin.php?next=' . urlencode($next),true,302);
    exit;
}
$pdo = db();
$adminId = tickex_admin_id($user);
$eventId = isset($_REQUEST['evento_id']) ? (int)$_REQUEST['evento_id'] : 0;
if ($eventId <= 0) abort_404('Falta el evento.');
tickex_require_event_access($pdo,$eventId,$user);
$eventSt = $pdo->prepare('SELECT id,nombre,creado_por_admin_id FROM eventos WHERE id=:id LIMIT 1');
$eventSt->execute(array(':id'=>$eventId));
$event = $eventSt->fetch(PDO::FETCH_ASSOC);
if (!$event) abort_404('Evento no encontrado.');
$ownerId = (int)$event['creado_por_admin_id'];
tickex_staff_operations_ensure_schema($pdo);
tickex_staff_roles_seed_defaults($pdo,$ownerId);
$roles = tickex_staff_roles_get_all($pdo,$ownerId);
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!tickex_csrf_verify(isset($_POST['csrf']) ? $_POST['csrf'] : '')) {
        $error = 'La sesión venció. Recargá la página.';
    } else {
        $action = isset($_POST['action']) ? (string)$_POST['action'] : '';
        $staffId = isset($_POST['staff_id']) ? (int)$_POST['staff_id'] : 0;
        try {
            if ($action === 'add_shift') {
                $starts = trim((string)(isset($_POST['starts_at'])?$_POST['starts_at']:''));
                $ends = trim((string)(isset($_POST['ends_at'])?$_POST['ends_at']:''));
                if ($staffId<=0 || $starts==='') throw new RuntimeException('Elegí una persona y el inicio del turno.');
                $st=$pdo->prepare("INSERT INTO staff_shifts (owner_admin_id,staff_id,evento_id,starts_at,ends_at,area,notes) SELECT :owner,:staff,:event,:starts,:ends,:area,:notes WHERE EXISTS (SELECT 1 FROM staff_eventos se JOIN staff_admins sa ON sa.cliente_id=se.staff_id WHERE se.staff_id=:staff AND se.evento_id=:event AND sa.owner_admin_id=:owner)");
                $st->execute(array(':owner'=>$ownerId,':staff'=>$staffId,':event'=>$eventId,':starts'=>$starts,':ends'=>$ends!==''?$ends:null,':area'=>trim((string)($_POST['area']??'')),':notes'=>trim((string)($_POST['notes']??''))));
                if(!$st->rowCount()) throw new RuntimeException('La persona no está asignada a este evento.');
                tickex_staff_audit($pdo,$ownerId,$adminId,'shift_created',$staffId,$eventId,array('starts_at'=>$starts,'ends_at'=>$ends));
                $message='Turno agregado.';
            } elseif ($action === 'add_task') {
                $taskTitle=trim((string)($_POST['title']??''));
                $taskRole=trim((string)($_POST['role_code']??''));
                if($taskTitle==='') throw new RuntimeException('Escribí una tarea.');
                if($taskRole!=='' && !isset(tickex_staff_roles_get_map($pdo,$ownerId)[$taskRole])) throw new RuntimeException('El rol seleccionado no existe.');
                if($staffId>0){$valid=$pdo->prepare('SELECT 1 FROM staff_eventos WHERE staff_id=:staff AND evento_id=:event LIMIT 1');$valid->execute(array(':staff'=>$staffId,':event'=>$eventId));if(!$valid->fetchColumn())throw new RuntimeException('La persona no está asignada a este evento.');}
                $st=$pdo->prepare("INSERT INTO staff_tasks (owner_admin_id,evento_id,assigned_staff_id,role_code,title,notes,due_at) VALUES (:owner,:event,:staff,:role,:title,:notes,:due)");
                $st->execute(array(':owner'=>$ownerId,':event'=>$eventId,':staff'=>$staffId>0?$staffId:null,':role'=>$taskRole!==''?$taskRole:null,':title'=>$taskTitle,':notes'=>trim((string)($_POST['notes']??'')),':due'=>trim((string)($_POST['due_at']??''))?:null));
                tickex_staff_audit($pdo,$ownerId,$adminId,'task_created',$staffId,$eventId,array('title'=>$taskTitle,'role'=>$taskRole));
                $message='Tarea creada.';
            } elseif ($action === 'toggle_task') {
                $taskId=(int)($_POST['task_id']??0);
                $st=$pdo->prepare("UPDATE staff_tasks SET status=CASE status WHEN 'done' THEN 'pending' ELSE 'done' END,completed_at=CASE status WHEN 'done' THEN NULL ELSE CURRENT_TIMESTAMP END,updated_at=CURRENT_TIMESTAMP WHERE id=:id AND owner_admin_id=:owner AND evento_id=:event");
                $st->execute(array(':id'=>$taskId,':owner'=>$ownerId,':event'=>$eventId));
                tickex_staff_audit($pdo,$ownerId,$adminId,'task_status_changed',null,$eventId,array('task_id'=>$taskId));
                $message='Estado de la tarea actualizado.';
            } elseif ($action === 'attendance') {
                $status=trim((string)($_POST['status']??'pending'));
                if(!in_array($status,array('pending','present','absent'),true)) $status='pending';
                $st=$pdo->prepare("UPDATE staff_eventos SET attendance_status=:status,checked_in_at=CASE WHEN :status='present' THEN COALESCE(checked_in_at,CURRENT_TIMESTAMP) ELSE NULL END,checked_out_at=CASE WHEN :status='pending' THEN NULL ELSE checked_out_at END WHERE staff_id=:staff AND evento_id=:event");
                $st->execute(array(':status'=>$status,':staff'=>$staffId,':event'=>$eventId));
                tickex_staff_audit($pdo,$ownerId,$adminId,'attendance_changed',$staffId,$eventId,array('status'=>$status));
                $message='Asistencia actualizada.';
            } elseif ($action === 'settle') {
                $amount=max(0,(float)str_replace(',','.',(string)($_POST['amount']??0)));
                $paid=!empty($_POST['paid']);
                $st=$pdo->prepare("UPDATE staff_eventos SET settlement_status=:status,settled_amount=:amount,settled_at=CASE WHEN :status='paid' THEN CURRENT_TIMESTAMP ELSE NULL END WHERE staff_id=:staff AND evento_id=:event");
                $st->execute(array(':status'=>$paid?'paid':'pending',':amount'=>$amount,':staff'=>$staffId,':event'=>$eventId));
                tickex_staff_audit($pdo,$ownerId,$adminId,'settlement_changed',$staffId,$eventId,array('status'=>$paid?'paid':'pending','amount'=>$amount));
                $message='Liquidación actualizada.';
            }
        } catch (Exception $e) { $error=$e->getMessage(); }
    }
}

$peopleSt=$pdo->prepare("SELECT se.*,ua.email,ua.nombre,ua.apellido,ua.apodo,sa.rol_staff AS global_role,COALESCE(NULLIF(se.rol_staff,''),sa.rol_staff,'puerta') event_role FROM staff_eventos se JOIN staff_admins sa ON sa.cliente_id=se.staff_id JOIN usuarios_admin ua ON ua.id=se.staff_id WHERE se.evento_id=:event AND sa.owner_admin_id=:owner ORDER BY ua.apodo,ua.nombre,ua.email");
$peopleSt->execute(array(':event'=>$eventId,':owner'=>$ownerId));
$people=$peopleSt->fetchAll(PDO::FETCH_ASSOC);
$shiftsSt=$pdo->prepare("SELECT s.*,ua.email,ua.nombre,ua.apellido,ua.apodo FROM staff_shifts s JOIN usuarios_admin ua ON ua.id=s.staff_id WHERE s.owner_admin_id=:owner AND s.evento_id=:event ORDER BY s.starts_at");
$shiftsSt->execute(array(':owner'=>$ownerId,':event'=>$eventId));
$shifts=$shiftsSt->fetchAll(PDO::FETCH_ASSOC);
$tasksSt=$pdo->prepare("SELECT t.*,ua.email,ua.nombre,ua.apellido,ua.apodo FROM staff_tasks t LEFT JOIN usuarios_admin ua ON ua.id=t.assigned_staff_id WHERE t.owner_admin_id=:owner AND t.evento_id=:event ORDER BY CASE t.status WHEN 'done' THEN 1 ELSE 0 END,t.due_at,t.id DESC");
$tasksSt->execute(array(':owner'=>$ownerId,':event'=>$eventId));
$tasks=$tasksSt->fetchAll(PDO::FETCH_ASSOC);
$auditSt=$pdo->prepare("SELECT l.*,ua.email actor_email,su.email staff_email FROM staff_audit_log l LEFT JOIN usuarios_admin ua ON ua.id=l.actor_admin_id LEFT JOIN usuarios_admin su ON su.id=l.staff_id WHERE l.owner_admin_id=:owner AND l.evento_id=:event ORDER BY l.id DESC LIMIT 25");
$auditSt->execute(array(':owner'=>$ownerId,':event'=>$eventId));
$audit=$auditSt->fetchAll(PDO::FETCH_ASSOC);
$summary=tickex_staff_operations_summary($pdo,$ownerId,$eventId);
$door=tickex_staff_door_summary($pdo,$ownerId,$eventId);
$csrf=tickex_csrf_token();
function tickex_staff_person_name($row){$name=trim((string)($row['apodo']??''));if($name==='')$name=trim((string)($row['nombre']??'').' '.(string)($row['apellido']??''));return $name!==''?$name:(string)($row['email']??'Staff');}
include __DIR__ . '/inc/layout_top.php';
?>
<style>
.ops-wrap{max-width:1180px;margin:0 auto;display:grid;gap:14px}.ops-hero{padding:25px;background:linear-gradient(135deg,rgba(88,63,190,.32),rgba(15,21,38,.96));border:1px solid rgba(139,119,255,.28)}.ops-head,.ops-actions{display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap}.ops-head h1{margin:5px 0}.ops-kpis{display:grid;grid-template-columns:repeat(5,1fr);gap:10px}.ops-kpi{padding:14px;border:1px solid var(--line);border-radius:14px;background:var(--panel-2)}.ops-kpi strong{font-size:24px;display:block;margin-top:5px}.ops-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}.ops-form{display:grid;grid-template-columns:1fr 1fr;gap:9px}.ops-form .wide{grid-column:1/-1}.ops-list{display:grid;gap:8px}.ops-row{padding:12px;border:1px solid var(--line);border-radius:12px;background:var(--panel-2)}.ops-person{display:grid;grid-template-columns:minmax(170px,1fr) 160px 220px;gap:9px;align-items:end}.ops-muted{font-size:12px;color:var(--muted)}.ops-door{border-color:rgba(45,212,191,.3)}.ops-door-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:9px}.ops-door-grid div{padding:10px;background:rgba(45,212,191,.07);border-radius:10px}.ops-door-grid strong{display:block;font-size:20px}.done{opacity:.62;text-decoration:line-through}@media(max-width:850px){.ops-kpis,.ops-door-grid{grid-template-columns:1fr 1fr}.ops-grid,.ops-form,.ops-person{grid-template-columns:1fr}.ops-form .wide{grid-column:auto}}
</style>
<main class="ops-wrap">
 <section class="card ops-hero"><div class="ops-head"><div><div class="tx-kicker"><i></i> Operaciones de equipo</div><h1><?php echo e($event['nombre']); ?></h1><p>Personas, turnos, tareas, asistencia y pagos en un solo lugar.</p></div><div class="ops-actions"><a class="btn secondary" href="panel_evento.php?evento_id=<?php echo $eventId; ?>">Volver al evento</a><a class="btn" href="secundarios.php?evento_id=<?php echo $eventId; ?>">Gestionar equipo</a></div></div></section>
 <?php if($message!==''): ?><div class="card" style="border-color:var(--ok)"><?php echo e($message); ?></div><?php endif; ?><?php if($error!==''): ?><div class="card error"><?php echo e($error); ?></div><?php endif; ?>
 <section class="ops-kpis"><div class="ops-kpi"><span>Asignados</span><strong><?php echo (int)$summary['assigned']; ?></strong></div><div class="ops-kpi"><span>Presentes</span><strong><?php echo (int)$summary['present']; ?></strong></div><div class="ops-kpi"><span>Tareas</span><strong><?php echo (int)$summary['tasks_done']; ?>/<?php echo (int)$summary['tasks']; ?></strong></div><div class="ops-kpi"><span>Costo previsto</span><strong>$<?php echo e(number_format((float)$summary['planned_cost'],0,',','.')); ?></strong></div><div class="ops-kpi"><span>Liquidado</span><strong>$<?php echo e(number_format((float)$summary['paid_cost'],0,',','.')); ?></strong></div></section>
 <section class="card ops-door"><div class="ops-head"><div><h2>Puerta en vivo</h2><p class="ops-muted">Resumen operativo del acceso y las ventas presenciales.</p></div><a class="btn" href="puerta_lista.php?evento_id=<?php echo $eventId; ?>">Abrir lista de puerta</a></div><div class="ops-door-grid"><div><span>Equipo Puerta</span><strong><?php echo (int)$door['door_staff']; ?></strong></div><div><span>Reservas esperando</span><strong><?php echo (int)$door['reserved']; ?></strong></div><div><span>Ventas puerta</span><strong><?php echo (int)$door['door_sales']; ?></strong></div><div><span>Recaudado</span><strong>$<?php echo e(number_format((float)$door['door_revenue'],0,',','.')); ?></strong></div><div><span>Check-ins totales</span><strong><?php echo (int)$door['checkins']; ?></strong></div></div></section>
 <section class="card"><div class="ops-head"><div><h2>Asistencia y liquidación</h2><p class="ops-muted">Marcá quién vino y qué importe ya fue pagado.</p></div></div><div class="ops-list"><?php if(!$people): ?><div class="ops-muted">No hay personas asignadas.</div><?php endif; ?><?php foreach($people as $person): ?><div class="ops-row ops-person"><div><strong><?php echo e(tickex_staff_person_name($person)); ?></strong><div class="ops-muted"><?php echo e(tickex_staff_role_label($pdo,$ownerId,$person['event_role'])); ?> · <?php echo e($person['email']); ?></div></div><form method="post"><input type="hidden" name="csrf" value="<?php echo e($csrf); ?>"><input type="hidden" name="action" value="attendance"><input type="hidden" name="staff_id" value="<?php echo (int)$person['staff_id']; ?>"><label>Asistencia<select name="status" onchange="this.form.submit()"><option value="pending"<?php echo $person['attendance_status']==='pending'?' selected':''; ?>>Pendiente</option><option value="present"<?php echo $person['attendance_status']==='present'?' selected':''; ?>>Presente</option><option value="absent"<?php echo $person['attendance_status']==='absent'?' selected':''; ?>>Ausente</option></select></label></form><form method="post" class="ops-actions"><input type="hidden" name="csrf" value="<?php echo e($csrf); ?>"><input type="hidden" name="action" value="settle"><input type="hidden" name="staff_id" value="<?php echo (int)$person['staff_id']; ?>"><label>Importe<input type="number" name="amount" min="0" step="0.01" value="<?php echo e(number_format((float)($person['settled_amount']?:$person['costo_servicio']),2,'.','')); ?>"></label><label><input type="checkbox" name="paid" value="1"<?php echo $person['settlement_status']==='paid'?' checked':''; ?>> Pagado</label><button class="btn secondary">Guardar</button></form></div><?php endforeach; ?></div></section>
 <section class="ops-grid">
  <div class="card"><h2>Turnos</h2><form method="post" class="ops-form"><input type="hidden" name="csrf" value="<?php echo e($csrf); ?>"><input type="hidden" name="action" value="add_shift"><label class="wide">Persona<select name="staff_id" required><option value="">Elegir…</option><?php foreach($people as $p): ?><option value="<?php echo (int)$p['staff_id']; ?>"><?php echo e(tickex_staff_person_name($p)); ?></option><?php endforeach; ?></select></label><label>Inicio<input type="datetime-local" name="starts_at" required></label><label>Fin<input type="datetime-local" name="ends_at"></label><label>Área<input name="area" placeholder="Ej: ingreso principal"></label><label>Nota<input name="notes"></label><button class="btn wide">Agregar turno</button></form><div class="ops-list" style="margin-top:12px"><?php foreach($shifts as $shift): ?><div class="ops-row"><strong><?php echo e(tickex_staff_person_name($shift)); ?></strong><div class="ops-muted"><?php echo e($shift['starts_at']); ?><?php echo $shift['ends_at']?' → '.e($shift['ends_at']):''; ?><?php echo $shift['area']?' · '.e($shift['area']):''; ?></div></div><?php endforeach; ?></div></div>
  <div class="card"><h2>Tareas</h2><form method="post" class="ops-form"><input type="hidden" name="csrf" value="<?php echo e($csrf); ?>"><input type="hidden" name="action" value="add_task"><label class="wide">Tarea<input name="title" required placeholder="Ej: preparar pulseras"></label><label>Persona<select name="staff_id"><option value="0">Todo el rol/equipo</option><?php foreach($people as $p): ?><option value="<?php echo (int)$p['staff_id']; ?>"><?php echo e(tickex_staff_person_name($p)); ?></option><?php endforeach; ?></select></label><label>Rol<select name="role_code"><option value="">Sin rol</option><?php foreach($roles as $r): ?><option value="<?php echo e($r['code']); ?>"><?php echo e($r['name']); ?></option><?php endforeach; ?></select></label><label>Vence<input type="datetime-local" name="due_at"></label><label>Nota<input name="notes"></label><button class="btn wide">Crear tarea</button></form><div class="ops-list" style="margin-top:12px"><?php foreach($tasks as $task): ?><form method="post" class="ops-row <?php echo $task['status']==='done'?'done':''; ?>"><input type="hidden" name="csrf" value="<?php echo e($csrf); ?>"><input type="hidden" name="action" value="toggle_task"><input type="hidden" name="task_id" value="<?php echo (int)$task['id']; ?>"><div class="ops-head"><div><strong><?php echo e($task['title']); ?></strong><div class="ops-muted"><?php echo $task['assigned_staff_id']?e(tickex_staff_person_name($task)):e($task['role_code']?tickex_staff_role_label($pdo,$ownerId,$task['role_code']):'Todo el equipo'); ?><?php echo $task['due_at']?' · '.$task['due_at']:''; ?></div></div><button class="btn secondary"><?php echo $task['status']==='done'?'Reabrir':'Completar'; ?></button></div></form><?php endforeach; ?></div></div>
 </section>
 <details class="card"><summary><strong>Historial de cambios</strong> · últimas <?php echo count($audit); ?> acciones</summary><div class="ops-list" style="margin-top:12px"><?php foreach($audit as $log): ?><div class="ops-row"><strong><?php echo e($log['action']); ?></strong><div class="ops-muted"><?php echo e($log['created_at']); ?> · <?php echo e($log['actor_email']?:('Usuario #'.$log['actor_admin_id'])); ?><?php echo $log['staff_email']?' · '.$log['staff_email']:''; ?></div></div><?php endforeach; ?></div></details>
</main>
<?php include __DIR__ . '/inc/layout_bottom.php'; ?>
