<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/door_guest_list.php';

$title = 'Lista de puerta';
require_login();
$pdo = db();
$user = current_user();
$role = tickex_admin_role($user);
$eventRole = isset($user['rol_evento']) ? (string)$user['rol_evento'] : '';
$isManager = tickex_is_super_admin($user) || $role === 'admin_evento';
$isDoorStaff = $role === 'staff_evento' && $eventRole === 'puerta';
if (!$isManager && !$isDoorStaff) {
    http_response_code(403);
    include __DIR__ . '/inc/layout_top.php';
    echo "<div class='card error'><h2>Acceso denegado</h2><p>Este panel es sólo para administración y Puerta.</p></div>";
    include __DIR__ . '/inc/layout_bottom.php';
    exit;
}

$eventId = isset($_REQUEST['evento_id']) ? (int)$_REQUEST['evento_id'] : (isset($_SESSION['evento_id']) ? (int)$_SESSION['evento_id'] : 0);
if ($eventId <= 0) abort_404('Falta el evento.');
tickex_require_event_access($pdo, $eventId, $user);
$_SESSION['evento_id'] = $eventId;

$stEvent = $pdo->prepare('SELECT id,nombre,slug FROM eventos WHERE id=:id LIMIT 1');
$stEvent->execute(array(':id'=>$eventId));
$event = $stEvent->fetch(PDO::FETCH_ASSOC);
if (!$event) abort_404('Evento no encontrado.');

tickex_door_list_ensure_schema($pdo);
$message = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!tickex_csrf_verify(isset($_POST['_csrf']) ? $_POST['_csrf'] : '')) {
        $error = 'La sesión venció. Recargá la página.';
    } else {
        $action = isset($_POST['action']) ? (string)$_POST['action'] : '';
        try {
            if ($action === 'save_list') {
                if (!$isManager) throw new RuntimeException('Sólo el administrador puede configurar la lista.');
                tickex_door_save_list(
                    $pdo,
                    $eventId,
                    isset($_POST['nombre']) ? $_POST['nombre'] : '',
                    isset($_POST['precio']) ? str_replace(',', '.', $_POST['precio']) : 0,
                    isset($_POST['ticket_type_id']) ? (int)$_POST['ticket_type_id'] : 0,
                    tickex_admin_id($user)
                );
                $message = 'Lista de puerta configurada.';
            } elseif ($action === 'import_guests') {
                if (!$isManager) throw new RuntimeException('Sólo el administrador puede cargar nombres.');
                $list = tickex_door_list_for_event($pdo, $eventId);
                if (!$list) throw new RuntimeException('Configurá primero la lista.');
                $result = tickex_door_import_guests($pdo, (int)$list['id'], isset($_POST['nombres']) ? $_POST['nombres'] : '', tickex_admin_id($user));
                $message = 'Nombres agregados: ' . (int)$result['added'] . '. Omitidos por duplicado: ' . (int)$result['skipped'] . '.';
            } elseif ($action === 'confirm_paid_checkin') {
                $result = tickex_door_confirm_paid_checkin($pdo, isset($_POST['reservation_id']) ? (int)$_POST['reservation_id'] : 0, $eventId, tickex_admin_id($user));
                $message = !empty($result['already_processed']) ? 'La persona ya estaba cobrada e ingresada.' : 'Pago registrado y check-in realizado.';
            } elseif ($action === 'cancel_reservation') {
                if (!$isManager) throw new RuntimeException('Sólo el administrador puede quitar personas.');
                $changed = tickex_door_cancel_reservation($pdo, isset($_POST['reservation_id']) ? (int)$_POST['reservation_id'] : 0, $eventId);
                $message = $changed ? 'Persona quitada de la lista.' : 'La reserva ya había sido procesada.';
            }
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

$list = tickex_door_list_for_event($pdo, $eventId);
$stTypes = $pdo->prepare('SELECT id,nombre,precio,cantidad_disponible FROM tipos_entrada WHERE evento_id=:event ORDER BY id');
$stTypes->execute(array(':event'=>$eventId));
$ticketTypes = $stTypes->fetchAll(PDO::FETCH_ASSOC);

$summary = array('reserved'=>0, 'paid_checked_in'=>0, 'cancelled'=>0, 'revenue'=>0);
$rows = array();
$query = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$statusFilter = isset($_GET['estado']) ? trim((string)$_GET['estado']) : '';
if ($list) {
    $stSummary = $pdo->prepare("SELECT status,COUNT(*) qty,COALESCE(SUM(CASE WHEN status='paid_checked_in' THEN price ELSE 0 END),0) revenue FROM event_door_guest_reservations WHERE list_id=:list GROUP BY status");
    $stSummary->execute(array(':list'=>(int)$list['id']));
    foreach ($stSummary->fetchAll(PDO::FETCH_ASSOC) as $item) {
        $summary[(string)$item['status']] = (int)$item['qty'];
        if ($item['status'] === 'paid_checked_in') $summary['revenue'] = (float)$item['revenue'];
    }

    $where = array('list_id=:list', "status!='cancelled'");
    $params = array(':list'=>(int)$list['id']);
    if ($query !== '') {
        $where[] = '(guest_name LIKE :query OR notes LIKE :query)';
        $params[':query'] = '%' . $query . '%';
    }
    if (in_array($statusFilter, array('reserved','paid_checked_in'), true)) {
        $where[] = 'status=:status';
        $params[':status'] = $statusFilter;
    }
    $sql = 'SELECT * FROM event_door_guest_reservations WHERE ' . implode(' AND ', $where) . " ORDER BY CASE status WHEN 'reserved' THEN 0 ELSE 1 END, guest_name COLLATE NOCASE";
    $stRows = $pdo->prepare($sql);
    $stRows->execute($params);
    $rows = $stRows->fetchAll(PDO::FETCH_ASSOC);
}

$csrf = tickex_csrf_token();
include __DIR__ . '/inc/layout_top.php';
?>

<style>
.door-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}.door-kpis{display:grid;grid-template-columns:repeat(4,minmax(130px,1fr));gap:10px}.door-kpi{padding:14px;border:1px solid var(--line);border-radius:12px;background:var(--panel-2)}.door-kpi strong{display:block;font-size:23px;margin-top:4px}.door-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.door-list{display:grid;gap:8px}.door-person{display:grid;grid-template-columns:minmax(180px,1fr) auto auto;gap:10px;align-items:center;padding:12px;border:1px solid var(--line);border-radius:12px;background:var(--panel-2)}.door-status{font-size:12px;font-weight:800}.door-paid{color:var(--ok)}.door-pending{color:var(--warn)}
@media(max-width:760px){.door-kpis{grid-template-columns:1fr 1fr}.door-grid{grid-template-columns:1fr}.door-person{grid-template-columns:1fr}.door-person form .btn{width:100%}}
</style>

<div class="card">
  <div class="door-actions">
    <a class="btn secondary" href="<?php echo $isDoorStaff ? 'puerta.php?evento_id='.(int)$eventId : 'panel_evento.php?evento_id='.(int)$eventId; ?>">← Volver</a>
    <div><div class="muted">Puerta · <?php echo e($event['nombre']); ?></div><h2 style="margin:2px 0;">Lista de reservas</h2></div>
  </div>
</div>

<?php if ($message !== ''): ?><div class="card" style="border-color:var(--ok);color:var(--ok);"><strong><?php echo e($message); ?></strong></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="card error"><strong><?php echo e($error); ?></strong></div><?php endif; ?>

<?php if ($isManager): ?>
<div class="card">
  <h3>Configuración</h3>
  <p class="muted">La reserva no cuenta como venta. Se registra el ingreso solamente cuando Puerta confirma que cobró.</p>
  <form method="post" class="door-grid">
    <input type="hidden" name="_csrf" value="<?php echo e($csrf); ?>"><input type="hidden" name="evento_id" value="<?php echo (int)$eventId; ?>"><input type="hidden" name="action" value="save_list">
    <label>Nombre de la lista<input name="nombre" required value="<?php echo e($list ? $list['nombre'] : 'Lista puerta $10.000'); ?>"></label>
    <label>Valor por persona ($)<input type="number" name="precio" min="1" step="0.01" required value="<?php echo e($list ? $list['precio'] : '10000'); ?>"></label>
    <label>Stock que utilizará<select name="ticket_type_id" required><option value="">Seleccionar…</option><?php foreach ($ticketTypes as $type): ?><option value="<?php echo (int)$type['id']; ?>"<?php echo $list && (int)$list['ticket_type_id']===(int)$type['id'] ? ' selected' : ''; ?>><?php echo e($type['nombre']); ?> · disponibles <?php echo (int)$type['cantidad_disponible']; ?></option><?php endforeach; ?></select></label>
    <div style="align-self:end;"><button class="btn" type="submit">Guardar lista</button></div>
  </form>
</div>
<?php endif; ?>

<?php if ($list): ?>
<div class="card door-kpis">
  <div class="door-kpi"><span class="muted">En lista</span><strong><?php echo (int)$summary['reserved'] + (int)$summary['paid_checked_in']; ?></strong></div>
  <div class="door-kpi"><span class="muted">Pendientes</span><strong><?php echo (int)$summary['reserved']; ?></strong></div>
  <div class="door-kpi"><span class="muted">Pagaron e ingresaron</span><strong><?php echo (int)$summary['paid_checked_in']; ?></strong></div>
  <div class="door-kpi"><span class="muted">Recaudado en puerta</span><strong>$<?php echo e(number_format((float)$summary['revenue'], 0, ',', '.')); ?></strong></div>
</div>

<?php if ($isManager): ?>
<div class="card">
  <h3>Cargar nombres</h3><p class="muted">Pegá un nombre por línea. Los nombres repetidos se omiten.</p>
  <form method="post"><input type="hidden" name="_csrf" value="<?php echo e($csrf); ?>"><input type="hidden" name="evento_id" value="<?php echo (int)$eventId; ?>"><input type="hidden" name="action" value="import_guests"><textarea name="nombres" rows="8" required placeholder="Juan Pérez&#10;María Gómez"></textarea><button class="btn" type="submit" style="margin-top:10px;">Agregar a la lista</button></form>
</div>
<?php endif; ?>

<div class="card">
  <h3>Buscar en puerta</h3>
  <form method="get" class="door-actions"><input type="hidden" name="evento_id" value="<?php echo (int)$eventId; ?>"><input name="q" value="<?php echo e($query); ?>" placeholder="Nombre…" autofocus style="flex:1 1 240px;"><select name="estado"><option value="">Todos</option><option value="reserved"<?php echo $statusFilter==='reserved'?' selected':''; ?>>Pendientes</option><option value="paid_checked_in"<?php echo $statusFilter==='paid_checked_in'?' selected':''; ?>>Ya ingresaron</option></select><button class="btn secondary">Buscar</button></form>
</div>

<div class="card"><h3>Personas</h3><div class="door-list">
<?php if (!$rows): ?><p class="muted">No hay personas para mostrar.</p><?php endif; ?>
<?php foreach ($rows as $row): ?><div class="door-person">
  <div><strong><?php echo e($row['guest_name']); ?></strong><div class="muted">$<?php echo e(number_format((float)$row['price'],0,',','.')); ?> · <?php echo $row['status']==='paid_checked_in' ? 'Cobrado ' . e($row['paid_at']) : 'Todavía no pagó'; ?></div></div>
  <span class="door-status <?php echo $row['status']==='paid_checked_in'?'door-paid':'door-pending'; ?>"><?php echo $row['status']==='paid_checked_in'?'PAGÓ · INGRESÓ':'PENDIENTE'; ?></span>
  <?php if ($row['status']==='reserved'): ?><div class="door-actions"><form method="post" onsubmit="return confirm('Confirmá que recibiste $<?php echo e(number_format((float)$row['price'],0,',','.')); ?> y que la persona va a ingresar.');"><input type="hidden" name="_csrf" value="<?php echo e($csrf); ?>"><input type="hidden" name="evento_id" value="<?php echo (int)$eventId; ?>"><input type="hidden" name="reservation_id" value="<?php echo (int)$row['id']; ?>"><input type="hidden" name="action" value="confirm_paid_checkin"><button class="btn" type="submit">Cobrar $<?php echo e(number_format((float)$row['price'],0,',','.')); ?> e ingresar</button></form><?php if ($isManager): ?><form method="post" onsubmit="return confirm('¿Quitar a esta persona de la lista?');"><input type="hidden" name="_csrf" value="<?php echo e($csrf); ?>"><input type="hidden" name="evento_id" value="<?php echo (int)$eventId; ?>"><input type="hidden" name="reservation_id" value="<?php echo (int)$row['id']; ?>"><input type="hidden" name="action" value="cancel_reservation"><button class="btn danger" type="submit">Quitar</button></form><?php endif; ?></div><?php else: ?><a class="btn secondary" href="ticket.php?c=<?php $stCode=$pdo->prepare('SELECT codigo FROM entradas WHERE id=:id');$stCode->execute(array(':id'=>(int)$row['entrada_id']));echo urlencode((string)$stCode->fetchColumn()); ?>" target="_blank">Ver entrada</a><?php endif; ?>
</div><?php endforeach; ?>
</div></div>
<?php elseif (!$isManager): ?>
<div class="card error"><h3>La lista todavía no está configurada</h3><p>Pedile al administrador del evento que la habilite.</p></div>
<?php endif; ?>

<?php include __DIR__ . '/inc/layout_bottom.php'; ?>
