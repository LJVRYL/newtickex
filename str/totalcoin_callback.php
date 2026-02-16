<?php
require_once __DIR__.'/inc/bootstrap.php';
$title = 'TotalCoin Callback';
$state = $_GET['state'] ?? 'unknown';
$uuid  = $_GET['uuid'] ?? ($_GET['requestId'] ?? '');

include __DIR__.'/inc/layout_top.php';
?>
<div class="card">
  <h2 style="margin:0 0 8px;">Callback recibido</h2>
  <p style="color:var(--muted);margin:0 0 12px;">State: <strong><?php echo e($state); ?></strong> — UUID/RequestId: <strong><?php echo e($uuid); ?></strong></p>
  <p style="margin:0;">Acá deberíamos marcar la orden como pagada/pendiente según corresponda.</p>
</div>
<?php include __DIR__.'/inc/layout_bottom.php'; ?>
