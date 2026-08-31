<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/mercadopago_marketplace.php';
require_once __DIR__ . '/inc/order_processing.php';
require_once __DIR__ . '/inc/order_events.php';

$title = 'Estado del pago';
$ref = isset($_GET['ref']) ? trim((string)$_GET['ref']) : '';
$paymentId = isset($_GET['payment_id']) ? trim((string)$_GET['payment_id']) : (isset($_GET['collection_id']) ? trim((string)$_GET['collection_id']) : '');
$visualState = isset($_GET['state']) ? trim((string)$_GET['state']) : '';
$order = null;
$message = 'Estamos verificando el pago con Mercado Pago.';
try {
    $pdo = db();
    tickex_mp_ensure_schema($pdo);
    if ($ref !== '') {
        $st = $pdo->prepare("SELECT * FROM tc_orders WHERE payment_provider='mercadopago' AND ref=:ref LIMIT 1");
        $st->execute(array(':ref' => $ref));
        $order = $st->fetch(PDO::FETCH_ASSOC);
    }
    if ($order && $paymentId !== '') {
        $payment = tickex_mp_get_payment($pdo, (int)$order['seller_admin_id'], $paymentId);
        $result = tickex_mp_confirm_payment($pdo, $order, $payment);
        if (!empty($result['confirmed'])) {
            process_tc_order_by_request_id((string)$order['request_id']);
            $message = 'Pago confirmado. Tus entradas fueron procesadas.';
        } else {
            $message = 'El pago todavia no figura aprobado. Lo seguiremos verificando.';
        }
        $st->execute(array(':ref' => $ref));
        $order = $st->fetch(PDO::FETCH_ASSOC);
    } elseif ($order && isset($order['payment_status']) && $order['payment_status'] === 'confirmed') {
        $message = 'Pago confirmado. Tus entradas fueron procesadas.';
    } elseif ($visualState === 'failure') {
        $message = 'El pago no se completo. Podes volver al evento e intentarlo nuevamente.';
    }
} catch (Exception $e) {
    error_log('[MercadoPago return] ' . $e->getMessage());
    $message = 'La orden sigue registrada y sera verificada automaticamente.';
}
include __DIR__ . '/inc/layout_top.php';
?>
<div class="card">
  <h2 style="margin-top:0;">Estado del pago</h2>
  <p><?php echo e($message); ?></p>
  <?php if ($order): ?>
    <p class="muted">Pago: <?php echo e(isset($order['payment_status']) ? $order['payment_status'] : 'pending'); ?> · Entradas: <?php echo e(isset($order['processing_status']) ? $order['processing_status'] : 'pending'); ?> · Email: <?php echo e(isset($order['email_status']) ? $order['email_status'] : 'pending'); ?></p>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/inc/layout_bottom.php'; ?>

