<?php
require_once __DIR__.'/inc/bootstrap.php';
$title = 'TotalCoin Callback';
$state = $_GET['state'] ?? 'unknown';
$uuid  = $_GET['uuid'] ?? ($_GET['requestId'] ?? '');

// Persistir estado si tenemos requestId
$updated = false;
if ($uuid !== '') {
  try {
    $pdo = db();
    $st = $pdo->prepare("UPDATE tc_orders SET state = :st, updated_at = datetime('now') WHERE request_id = :rid");
    $st->execute(array(':st' => (string)$state, ':rid' => (string)$uuid));
    $updated = ($st->rowCount() > 0);
  } catch (Exception $e) {
    $updated = false;
  }
}

include __DIR__.'/inc/layout_top.php';
?>
<div class="card">
  <h2 style="margin:0 0 8px;">Callback recibido</h2>
  <p style="color:var(--muted);margin:0 0 12px;">State: <strong><?php echo e($state); ?></strong> — UUID/RequestId: <strong><?php echo e($uuid); ?></strong></p>
  <?php if ($uuid !== ''): ?>
    <p style="margin:0;"><?php echo $updated ? 'Estado actualizado en el sistema.' : 'No se encontró una orden local para este RequestId.'; ?></p>
  <?php else: ?>
    <p style="margin:0;">Callback recibido sin RequestId.</p>
  <?php endif; ?>
</div>
<?php include __DIR__.'/inc/layout_bottom.php'; ?>
