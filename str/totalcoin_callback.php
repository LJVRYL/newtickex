<?php
// Retorno visual: asocia la orden y consulta a TotalCoin antes de confirmar.
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/totalcoin.php';
require_once __DIR__ . '/inc/totalcoin_callback_auth.php';
require_once __DIR__ . '/inc/totalcoin_confirmation.php';
require_once __DIR__ . '/inc/order_processing.php';
require_once __DIR__ . '/inc/order_events.php';

$title = 'TotalCoin Callback';
$state = isset($_GET['state']) ? strtolower(trim((string)$_GET['state'])) : 'unknown';
$reference = isset($_GET['ref']) ? trim((string)$_GET['ref']) : '';
$callbackToken = isset($_GET['token']) ? trim((string)$_GET['token']) : '';
$requestId = isset($_GET['requestId']) ? trim((string)$_GET['requestId']) : '';
if ($requestId === '' && isset($_GET['request_id'])) $requestId = trim((string)$_GET['request_id']);
if ($requestId === '' && isset($_GET['uuid'])) $requestId = trim((string)$_GET['uuid']);
$order = null;
$message = 'Estamos confirmando tu pago.';

try {
    $pdo = db();
    if ($reference !== '' && tickex_totalcoin_callback_is_valid($reference, $state, $callbackToken)) {
        $st = $pdo->prepare('SELECT * FROM tc_orders WHERE ref = :ref LIMIT 2');
        $st->execute(array(':ref' => $reference));
        $matches = $st->fetchAll(PDO::FETCH_ASSOC);
        if (count($matches) === 1) {
            $order = $matches[0];
            $requestId = (string)$order['request_id'];

            if ($state === 'success') {
                $confirmation = tickex_totalcoin_confirm_from_status($pdo, $order);
                if (!empty($confirmation['confirmed'])) {
                    process_tc_order_by_request_id($requestId);
                    $message = 'Pago confirmado. Tus entradas fueron procesadas.';
                } else {
                    $message = 'TotalCoin aun no informa el pago como aprobado.';
                }
            }

            $stRefresh = $pdo->prepare('SELECT * FROM tc_orders WHERE id = :id LIMIT 1');
            $stRefresh->execute(array(':id' => (int)$order['id']));
            $order = $stRefresh->fetch(PDO::FETCH_ASSOC);
        } else {
            $message = 'No se encontro una unica orden para esta referencia.';
        }
    } elseif ($requestId !== '') {
        // Compatibilidad visual para checkouts creados antes de las URLs firmadas.
        $st = $pdo->prepare('SELECT * FROM tc_orders WHERE request_id = :rid LIMIT 1');
        $st->execute(array(':rid' => $requestId));
        $order = $st->fetch(PDO::FETCH_ASSOC);
    } elseif ($reference !== '') {
        $message = 'No se pudo validar el retorno del checkout.';
    }
} catch (Exception $e) {
    error_log('[TotalCoin callback] ' . $e->getMessage());
    $message = 'No pudimos consultar el pago en este momento. La orden sigue pendiente para reintento automatico.';
}

include __DIR__ . '/inc/layout_top.php';
?>
<div class="card">
  <h2 style="margin:0 0 8px;">Estado del pago</h2>
  <?php if ($order): ?>
    <?php $paymentStatus = isset($order['payment_status']) ? (string)$order['payment_status'] : 'pending'; ?>
    <p><?php echo e($message); ?></p>
    <p class="muted">Estado de emision: <?php echo e(isset($order['processing_status']) ? $order['processing_status'] : 'pending'); ?> | Email: <?php echo e(isset($order['email_status']) ? $order['email_status'] : 'pending'); ?></p>
  <?php else: ?>
    <p><?php echo e($message); ?></p>
  <?php endif; ?>
  <p class="muted">Estado visual recibido: <?php echo e($state); ?></p>
</div>
<?php include __DIR__ . '/inc/layout_bottom.php'; ?>
