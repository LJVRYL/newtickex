<?php
// Callback visual de TotalCoin. No confirma pagos ni procesa ordenes.
require_once __DIR__ . '/inc/bootstrap.php';

$title = 'TotalCoin Callback';
$state = isset($_GET['state']) ? trim((string)$_GET['state']) : 'unknown';
$requestId = isset($_GET['requestId']) ? trim((string)$_GET['requestId']) : '';
if ($requestId === '' && isset($_GET['request_id'])) $requestId = trim((string)$_GET['request_id']);
if ($requestId === '' && isset($_GET['uuid'])) $requestId = trim((string)$_GET['uuid']);
$order = null;

if ($requestId !== '') {
    try {
        $pdo = db();
        $st = $pdo->prepare('SELECT id, payment_status, processing_status, email_status FROM tc_orders WHERE request_id = :rid LIMIT 1');
        $st->execute(array(':rid' => $requestId));
        $order = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $order = null;
    }
}

include __DIR__ . '/inc/layout_top.php';
?>
<div class="card">
  <h2 style="margin:0 0 8px;">Estado del pago</h2>
  <?php if ($order): ?>
    <?php $paymentStatus = isset($order['payment_status']) ? (string)$order['payment_status'] : 'pending'; ?>
    <?php if ($paymentStatus === 'confirmed'): ?>
      <p>El pago fue confirmado. Estamos preparando tus entradas.</p>
    <?php else: ?>
      <p>Estamos confirmando tu pago. La confirmacion se procesa automaticamente aunque no regreses desde la pagina de pago.</p>
    <?php endif; ?>
    <p class="muted">Estado de emision: <?php echo e(isset($order['processing_status']) ? $order['processing_status'] : 'pending'); ?> | Email: <?php echo e(isset($order['email_status']) ? $order['email_status'] : 'pending'); ?></p>
  <?php else: ?>
    <p>Estamos confirmando tu pago. La confirmacion se procesa automaticamente aunque no regreses desde la pagina de pago.</p>
  <?php endif; ?>
  <p class="muted">Estado visual recibido: <?php echo e($state); ?></p>
</div>
<?php include __DIR__ . '/inc/layout_bottom.php'; ?>
