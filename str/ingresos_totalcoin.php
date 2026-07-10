<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/order_processing.php';
require_once __DIR__ . '/inc/order_events.php';

require_login();

$cu = current_user();
$tipoGlobal = isset($cu['tipo_global']) ? (string)$cu['tipo_global'] : (isset($_SESSION['tipo_global']) ? (string)$_SESSION['tipo_global'] : '');
$isSuper = in_array($tipoGlobal, array('super_admin', 'superadmin'), true);
$isAllowed = (is_admin() && ($isSuper || $tipoGlobal === 'admin_evento'));

if (!$isAllowed) {
    http_response_code(403);
    include __DIR__ . '/inc/layout_top.php';
    echo '<div class="card" style="max-width:640px;margin:32px auto;"><h2>Acceso restringido</h2><p>Solo para administradores.</p></div>';
    include __DIR__ . '/inc/layout_bottom.php';
    exit;
}

$pdo = db();
$csrf = function_exists('tickex_csrf_token') ? (string)tickex_csrf_token() : '';
$adminId = 0;
if (isset($_SESSION['admin_id'])) $adminId = (int)$_SESSION['admin_id'];
elseif (isset($_SESSION['user_id'])) $adminId = (int)$_SESSION['user_id'];
elseif (isset($_SESSION['usuario_id'])) $adminId = (int)$_SESSION['usuario_id'];

$flashOk = '';
$flashErr = '';

if (!function_exists('tc_manual_scope_event_ids')) {
    function tc_manual_scope_event_ids($pdo, $adminId)
    {
        $adminId = (int)$adminId;
        if ($adminId <= 0) return array();
        $ids = array();
        try {
            $st = $pdo->prepare('SELECT id FROM eventos WHERE creado_por_admin_id = :aid');
            $st->execute(array(':aid' => $adminId));
            while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                $id = isset($r['id']) ? (int)$r['id'] : 0;
                if ($id > 0) $ids[$id] = $id;
            }
        } catch (Exception $e) {
            return array();
        }
        return array_values($ids);
    }
}

if (!function_exists('tc_manual_order_scope_sql')) {
    function tc_manual_order_scope_sql($isSuper, $eventIds)
    {
        if ($isSuper) {
            return array('1=1', array());
        }

        $eventIds = is_array($eventIds) ? $eventIds : array();
        if (empty($eventIds)) {
            return array('1=0', array());
        }

        $params = array();
        $parts = array();
        $i = 0;
        foreach ($eventIds as $eid) {
            $i++;
            $k = ':ev' . $i;
            $parts[] = $k;
            $params[$k] = (int)$eid;
        }

        return array('o.evento_id IN (' . implode(',', $parts) . ')', $params);
    }
}

$eventScopeIds = $isSuper ? array() : tc_manual_scope_event_ids($pdo, $adminId);
list($scopeSql, $scopeParams) = tc_manual_order_scope_sql($isSuper, $eventScopeIds);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $provided = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
    if (function_exists('tickex_csrf_verify') && !tickex_csrf_verify($provided)) {
        $flashErr = 'CSRF invalido. Recarga la pagina e intenta nuevamente.';
    } else {
        $action = isset($_POST['action']) ? (string)$_POST['action'] : '';
        if ($action === 'mark_success') {
            $orderId = isset($_POST['order_id']) ? (int)$_POST['order_id'] : 0;

            if ($orderId <= 0) {
                $flashErr = 'Orden invalida.';
            } else {
                try {
                    $stOrder = $pdo->prepare('SELECT o.* FROM tc_orders o WHERE o.id = :id AND ' . $scopeSql . ' LIMIT 1');
                    $stOrder->execute(array(':id' => $orderId) + $scopeParams);
                    $order = $stOrder->fetch(PDO::FETCH_ASSOC);

                    if (!$order) {
                        $flashErr = 'No se encontro la orden o no tienes permisos para operarla.';
                    } else {
                        $requestId = isset($order['request_id']) ? trim((string)$order['request_id']) : '';
                        if ($requestId === '') {
                            $flashErr = 'La orden no tiene request_id.';
                        } else {
                            $stUp = $pdo->prepare("UPDATE tc_orders SET state = 'success', updated_at = datetime('now') WHERE id = :id");
                            $stUp->execute(array(':id' => $orderId));

                            $result = process_tc_order_by_request_id($requestId);

                            try {
                                log_order_event($pdo, $orderId, $requestId, 'manual_mark_success', array(
                                    'updated_to_success' => true,
                                    'processed' => !empty($result['processed']),
                                    'debugMsg' => isset($result['debugMsg']) ? (string)$result['debugMsg'] : '',
                                    'executed_by_admin_id' => $adminId,
                                ));
                            } catch (Exception $e) {
                                // no bloquear la accion manual por logging
                            }

                            if (!empty($result['processed'])) {
                                $flashOk = 'Orden #' . $orderId . ' pasada a success y procesada correctamente.';
                            } else {
                                $flashErr = 'Orden #' . $orderId . ' pasada a success, pero el procesamiento no finalizo: ' . (isset($result['debugMsg']) ? (string)$result['debugMsg'] : 'sin detalle');
                            }
                        }
                    }
                } catch (Exception $e) {
                    $flashErr = 'No se pudo ejecutar la operacion manual: ' . $e->getMessage();
                }
            }
        }
    }
}

$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$fState = isset($_GET['f_state']) ? trim((string)$_GET['f_state']) : '';

$where = array($scopeSql);
$params = $scopeParams;
if ($q !== '') {
    $where[] = '(o.request_id LIKE :q OR o.buyer_email LIKE :q OR o.ref LIKE :q OR o.concept LIKE :q)';
    $params[':q'] = '%' . $q . '%';
}
if ($fState !== '') {
    $where[] = 'o.state = :st';
    $params[':st'] = $fState;
}

$sql = 'SELECT o.* FROM tc_orders o WHERE ' . implode(' AND ', $where) . ' ORDER BY o.id DESC LIMIT 300';
$stList = $pdo->prepare($sql);
$stList->execute($params);
$rows = $stList->fetchAll(PDO::FETCH_ASSOC);

$title = 'Ingresos TotalCoin';
include __DIR__ . '/inc/layout_top.php';
?>

<div class="card" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
  <a class="btn secondary" href="panel_admin.php">Volver</a>
  <div>
    <div class="muted" style="font-size:12px;letter-spacing:.08em;text-transform:uppercase;">Operaciones</div>
    <h2 style="margin:0;">Ingresos TotalCoin</h2>
  </div>
  <span class="muted">Gestion manual para callbacks que no llegaron a success.</span>
</div>

<?php if ($flashOk !== ''): ?>
  <div class="flash ok"><?php echo e($flashOk); ?></div>
<?php endif; ?>
<?php if ($flashErr !== ''): ?>
  <div class="flash err"><?php echo e($flashErr); ?></div>
<?php endif; ?>

<div class="card" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
  <form method="get" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
    <input name="q" placeholder="Buscar por request_id, email, ref o concepto" value="<?php echo e($q); ?>" style="min-width:320px;">
    <select name="f_state">
      <option value="">Estado: todos</option>
      <?php foreach (array('created','pending','success','failed','cancelled','bridge_synced') as $st): ?>
        <option value="<?php echo e($st); ?>" <?php echo $fState === $st ? 'selected' : ''; ?>><?php echo e($st); ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn secondary" type="submit">Filtrar</button>
    <?php if ($q !== '' || $fState !== ''): ?>
      <a class="btn secondary" href="ingresos_totalcoin.php">Limpiar</a>
    <?php endif; ?>
  </form>
</div>

<div class="card" style="overflow:auto;">
  <table class="table" style="width:100%;font-size:14px;">
    <thead>
      <tr>
        <th>ID</th>
        <th>Request ID</th>
        <th>Estado</th>
        <th>Evento</th>
        <th>Email</th>
        <th>Importe</th>
        <th>Creada</th>
        <th>Actualizada</th>
        <th>Processed At</th>
        <th>Accion</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($rows)): ?>
        <tr><td colspan="10" class="muted">No hay ingresos para mostrar.</td></tr>
      <?php else: ?>
        <?php foreach ($rows as $r): ?>
          <?php
            $isDone = !empty($r['processed_at']);
            $canManual = ((string)$r['state'] !== 'success') || !$isDone;
          ?>
          <tr>
            <td>#<?php echo (int)$r['id']; ?></td>
            <td><?php echo e(isset($r['request_id']) ? $r['request_id'] : ''); ?></td>
            <td><?php echo e(isset($r['state']) ? $r['state'] : ''); ?></td>
            <td><?php echo (int)(isset($r['evento_id']) ? $r['evento_id'] : 0); ?></td>
            <td><?php echo e(isset($r['buyer_email']) ? $r['buyer_email'] : ''); ?></td>
            <td><?php echo e(isset($r['amount']) ? $r['amount'] : ''); ?></td>
            <td><?php echo e(isset($r['created_at']) ? $r['created_at'] : ''); ?></td>
            <td><?php echo e(isset($r['updated_at']) ? $r['updated_at'] : ''); ?></td>
            <td><?php echo e(isset($r['processed_at']) ? $r['processed_at'] : ''); ?></td>
            <td>
              <?php if ($canManual): ?>
                <form method="post" action="ingresos_totalcoin.php" onsubmit="return confirm('Se marcara la orden como success y se ejecutara el procesamiento manual. Continuar?');" style="display:inline;">
                  <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
                  <input type="hidden" name="order_id" value="<?php echo (int)$r['id']; ?>">
                  <button class="btn" type="submit" name="action" value="mark_success">Marcar success y procesar</button>
                </form>
              <?php else: ?>
                <span class="muted">Sin accion</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<?php include __DIR__ . '/inc/layout_bottom.php'; ?>
