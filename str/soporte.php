<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/support_center.php';
require_once __DIR__ . '/inc/notificaciones.php';
require_login();
$pdo = db(); $cu = current_user();
$adminId = tickex_admin_id($cu); $role = tickex_admin_role($cu); $isSuper = tickex_is_super_admin($cu);
if ($isSuper) { header('Location: superadmin_soporte.php'); exit; }
if ($adminId <= 0 || $role !== 'admin_evento') abort_404('No tenés permiso para acceder al soporte.');
tickex_support_ensure_schema($pdo);
$categories = tickex_support_categories(); $statuses = tickex_support_statuses(); $priorities = tickex_support_priorities();
$events = tickex_support_admin_events($pdo, $adminId, false); $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!tickex_csrf_verify(isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '')) $error = 'La sesión venció. Actualizá la página.';
    else {
        $action = isset($_POST['action']) ? (string)$_POST['action'] : '';
        if ($action === 'create') {
            list($ok,$message,$newId) = tickex_support_create_ticket($pdo,$adminId,$_POST);
            if ($ok) {
                $createdTicket = tickex_support_get_ticket($pdo,$newId,$adminId,false);
                try {
                    $superadmins = $pdo->query("SELECT id FROM usuarios_admin WHERE tipo_global IN ('super_admin','superadmin') AND COALESCE(activo,1)=1")->fetchAll(PDO::FETCH_COLUMN);
                    if ($createdTicket) foreach ($superadmins as $superId) add_notification((int)$superId,'Nueva consulta de soporte '.$createdTicket['public_id'].'.','support',array('url'=>'superadmin_soporte.php?id='.$newId),$pdo);
                } catch (Exception $e) {}
                flash('ok',$message); header('Location: soporte.php?id='.(int)$newId); exit;
            }
            $error = $message;
        } elseif ($action === 'reply') {
            $ticketId = isset($_POST['ticket_id']) ? (int)$_POST['ticket_id'] : 0;
            list($ok,$message) = tickex_support_add_message($pdo,$ticketId,$adminId,false,isset($_POST['body'])?$_POST['body']:'');
            if ($ok) { flash('ok',$message); header('Location: soporte.php?id='.$ticketId); exit; }
            $error = $message;
        } elseif ($action === 'set_status') {
            $ticketId = isset($_POST['ticket_id']) ? (int)$_POST['ticket_id'] : 0;
            $status = isset($_POST['status']) ? (string)$_POST['status'] : '';
            list($ok,$message) = tickex_support_update_ticket($pdo,$ticketId,$adminId,false,$status);
            if ($ok) { flash('ok',$message); header('Location: soporte.php?id='.$ticketId); exit; }
            $error = $message;
        }
    }
}

$selectedId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$selected = $selectedId > 0 ? tickex_support_get_ticket($pdo,$selectedId,$adminId,false) : null;
if ($selectedId > 0 && !$selected) abort_404('Consulta no encontrada.');
$tickets = tickex_support_list_tickets($pdo,$adminId,false);
$counts = tickex_support_counts($pdo,$adminId,false); $flashes = flash_get_all(); $csrf = tickex_csrf_token();
$title = 'Ayuda y soporte';
include __DIR__ . '/inc/layout_top.php';
?>
<style>
.support-shell{max-width:1180px;margin:0 auto;display:grid;gap:18px}.support-hero{padding:28px;background:radial-gradient(circle at 90% 10%,rgba(55,207,236,.13),transparent 32%),linear-gradient(135deg,#151a35,#302064)}.support-hero h1{margin:5px 0;font-size:clamp(30px,5vw,46px)}.support-kicker{color:#5edbef;text-transform:uppercase;font-weight:900;letter-spacing:.12em;font-size:11px}.support-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-top:20px}.support-stat{padding:13px;border:1px solid rgba(255,255,255,.1);border-radius:13px;background:rgba(4,8,22,.28)}.support-stat span{display:block;color:var(--muted);font-size:11px;text-transform:uppercase;font-weight:800}.support-stat strong{font-size:24px}.support-grid{display:grid;grid-template-columns:minmax(0,1.35fr) minmax(300px,.65fr);gap:16px;align-items:start}.support-guides{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.support-guide{padding:15px;border:1px solid var(--line);border-radius:14px;background:rgba(255,255,255,.025)}.support-guide strong{display:block;margin-bottom:5px}.support-guide p{margin:0;color:var(--muted);font-size:13px}.support-form{display:grid;gap:11px}.support-form .two{display:grid;grid-template-columns:1fr 1fr;gap:10px}.support-list{display:grid;gap:9px}.support-row{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:12px;padding:14px;border:1px solid var(--line);border-radius:13px;text-decoration:none;color:inherit;background:rgba(255,255,255,.02)}.support-row:hover{border-color:#745be8}.support-row h3{font-size:15px;margin:3px 0}.support-meta{color:var(--muted);font-size:12px}.support-badge{display:inline-flex;padding:5px 8px;border-radius:999px;border:1px solid var(--line);font-size:10px;font-weight:850;text-transform:uppercase;white-space:nowrap}.support-thread{display:grid;gap:10px}.support-message{max-width:86%;padding:13px 15px;border-radius:15px;background:#11182d;border:1px solid var(--line)}.support-message.support{margin-left:auto;background:rgba(108,73,213,.2);border-color:rgba(139,92,246,.35)}.support-message small{display:block;color:var(--muted);margin-bottom:6px}.support-message p{white-space:pre-wrap;margin:0}.support-detail-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap}.support-actions{display:flex;gap:8px;flex-wrap:wrap}.support-empty{padding:26px;text-align:center;color:var(--muted)}
@media(max-width:850px){.support-grid{grid-template-columns:1fr}.support-stats{grid-template-columns:repeat(3,1fr)}}@media(max-width:560px){.support-guides,.support-form .two{grid-template-columns:1fr}.support-stats{grid-template-columns:1fr}.support-message{max-width:95%}}
</style>
<main class="support-shell">
 <section class="card support-hero"><div class="support-kicker">Centro de ayuda</div><h1>¿Cómo podemos ayudarte?</h1><p class="muted">Encontrá respuestas rápidas o dejá una consulta con todo su seguimiento en un solo lugar.</p><div class="support-stats"><div class="support-stat"><span>Consultas abiertas</span><strong><?php echo (int)($counts['open']+$counts['in_progress']); ?></strong></div><div class="support-stat"><span>Esperan tu respuesta</span><strong><?php echo (int)$counts['waiting_client']; ?></strong></div><div class="support-stat"><span>Historial total</span><strong><?php echo (int)$counts['all']; ?></strong></div></div></section>
 <?php foreach($flashes as $f):?><div class="flash <?php echo e($f['type']);?>"><?php echo e($f['msg']);?></div><?php endforeach;?><?php if($error!==''):?><div class="flash err"><?php echo e($error);?></div><?php endif;?>
 <?php if($selected):?>
 <section class="card"><div class="support-detail-head"><div><a href="soporte.php" class="muted">← Volver a mis consultas</a><div class="support-kicker" style="margin-top:14px"><?php echo e($selected['public_id']);?></div><h2 style="margin:5px 0"><?php echo e($selected['subject']);?></h2><div class="support-meta"><?php echo e($categories[$selected['category']]);?><?php if(!empty($selected['event_name'])):?> · <?php echo e($selected['event_name']);?><?php endif;?></div></div><span class="support-badge"><?php echo e($statuses[$selected['status']]);?></span></div>
 <div class="support-thread" style="margin-top:22px"><?php foreach($selected['messages'] as $m):?><article class="support-message <?php echo $m['author_role']==='support'?'support':'';?>"><small><?php echo $m['author_role']==='support'?'Equipo Tickex':'Vos';?> · <?php echo e(date('d/m/Y H:i',strtotime($m['created_at'])));?></small><p><?php echo e($m['body']);?></p></article><?php endforeach;?></div>
 <?php if(!in_array($selected['status'],array('resolved','closed'),true)):?><form method="post" class="support-form" style="margin-top:18px"><input type="hidden" name="_csrf" value="<?php echo e($csrf);?>"><input type="hidden" name="action" value="reply"><input type="hidden" name="ticket_id" value="<?php echo (int)$selected['id'];?>"><label>Agregar respuesta<textarea name="body" rows="5" maxlength="8000" required placeholder="Escribí la información que falta..."></textarea></label><div class="support-actions"><button class="btn" type="submit">Enviar respuesta</button></div></form><?php else:?><form method="post" style="margin-top:18px"><input type="hidden" name="_csrf" value="<?php echo e($csrf);?>"><input type="hidden" name="action" value="set_status"><input type="hidden" name="ticket_id" value="<?php echo (int)$selected['id'];?>"><input type="hidden" name="status" value="open"><button class="btn secondary" type="submit">Reabrir consulta</button></form><?php endif;?>
 </section>
 <?php else:?>
 <section class="support-grid"><div class="card"><h2 style="margin-top:0">Guías rápidas</h2><p class="muted">Accesos directos a las tareas más habituales.</p><div class="support-guides"><a class="support-guide" href="crear_evento.php"><strong>Crear y publicar un evento</strong><p>Configurá fechas, entradas y visibilidad.</p></a><a class="support-guide" href="mercadopago_config.php"><strong>Conectar Mercado Pago</strong><p>Vinculá la cuenta que recibirá tus ventas.</p></a><a class="support-guide" href="secundarios.php"><strong>Organizar el staff</strong><p>Roles, permisos y asignaciones por evento.</p></a><a class="support-guide" href="superadmin_emails_db.php"><strong>Comunicar a tu audiencia</strong><p>Contactos, campañas y seguimiento.</p></a></div></div>
 <aside class="card"><h2 style="margin-top:0">Nueva consulta</h2><p class="muted">Describí una situación concreta para poder ayudarte mejor.</p><form method="post" class="support-form"><input type="hidden" name="_csrf" value="<?php echo e($csrf);?>"><input type="hidden" name="action" value="create"><label>Tipo<select name="category" required><option value="">Seleccionar</option><?php foreach($categories as $k=>$v):?><option value="<?php echo e($k);?>"><?php echo e($v);?></option><?php endforeach;?></select></label><label>Evento relacionado<select name="event_id"><option value="0">Consulta general</option><?php foreach($events as $ev):?><option value="<?php echo (int)$ev['id'];?>"><?php echo e($ev['nombre']);?></option><?php endforeach;?></select></label><label>Asunto<input name="subject" minlength="5" maxlength="140" required placeholder="Ej: No puedo configurar una entrada"></label><label>Detalle<textarea name="body" rows="6" minlength="10" maxlength="8000" required placeholder="Qué estabas haciendo, qué esperabas y qué ocurrió..."></textarea></label><label>Urgencia<select name="priority"><option value="normal">Normal</option><option value="high">Alta — bloquea una operación</option><option value="urgent">Urgente — evento en curso</option><option value="low">Baja — consulta o sugerencia</option></select></label><button class="btn" type="submit">Crear consulta</button></form></aside></section>
 <section class="card"><div class="support-detail-head"><div><div class="support-kicker">Seguimiento</div><h2 style="margin:5px 0">Mis consultas</h2></div></div><?php if(!$tickets):?><div class="support-empty">Todavía no abriste ninguna consulta.</div><?php else:?><div class="support-list" style="margin-top:15px"><?php foreach($tickets as $t):?><a class="support-row" href="soporte.php?id=<?php echo (int)$t['id'];?>"><div><div class="support-meta"><?php echo e($t['public_id']);?> · <?php echo e($categories[$t['category']]);?></div><h3><?php echo e($t['subject']);?></h3><div class="support-meta"><?php echo (int)$t['message_count'];?> mensaje<?php echo (int)$t['message_count']===1?'':'s';?> · Actualizada <?php echo e(date('d/m/Y H:i',strtotime($t['last_activity_at'])));?></div></div><span class="support-badge"><?php echo e($statuses[$t['status']]);?></span></a><?php endforeach;?></div><?php endif;?></section>
 <?php endif;?>
</main>
<?php include __DIR__ . '/inc/layout_bottom.php'; ?>
