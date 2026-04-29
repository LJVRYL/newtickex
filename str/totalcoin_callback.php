<?php
require_once __DIR__.'/inc/bootstrap.php';
$title = 'TotalCoin Callback';
$state = $_GET['state'] ?? 'unknown';
$uuid  = $_GET['uuid'] ?? ($_GET['requestId'] ?? '');

// Persistir estado si tenemos requestId
$updated = false;
$processed = false;
if ($uuid !== '') {
  try {
    $pdo = db();
    $st = $pdo->prepare("UPDATE tc_orders SET state = :st, updated_at = datetime('now') WHERE request_id = :rid");
    $st->execute(array(':st' => (string)$state, ':rid' => (string)$uuid));
    $updated = ($st->rowCount() > 0);

    // Si se actualizó a success y no está procesada, crear entradas
    if ($updated && $state === 'success') {
      $stOrd = $pdo->prepare("SELECT * FROM tc_orders WHERE request_id = :rid LIMIT 1");
      $stOrd->execute(array(':rid' => (string)$uuid));
      $order = $stOrd->fetch(PDO::FETCH_ASSOC);
      if ($order && empty($order['processed_at']) && !empty($order['selected_tickets_json'])) {
        $tickets = json_decode($order['selected_tickets_json'], true);
        if (is_array($tickets)) {
          $pdo->beginTransaction();
          try {
            $eventoId = (int)$order['evento_id'];
            $buyerName = trim($order['buyer_first'] . ' ' . $order['buyer_last']);
            $buyerEmail = $order['buyer_email'];
            $fechaReg = date('Y-m-d H:i:s');

            foreach ($tickets as $ticket) {
              $tipoId = (int)$ticket['id'];
              $tipoName = $ticket['name'];
              $qty = (int)$ticket['qty'];
              $price = (int)$ticket['price'];

              for ($i = 0; $i < $qty; $i++) {
                // Generar código único
                $codigo = bin2hex(random_bytes(5));

                // Insertar entrada
                $stIns = $pdo->prepare("INSERT INTO entradas (evento_id, nombre, email, fecha_registro, codigo, checked_in, checked_in_at, tipo, monto_pagado) VALUES (:eid, :nom, :em, :fec, :cod, 0, NULL, :tipo, :monto)");
                $stIns->execute(array(
                  ':eid' => $eventoId,
                  ':nom' => $buyerName,
                  ':em' => $buyerEmail,
                  ':fec' => $fechaReg,
                  ':cod' => $codigo,
                  ':tipo' => $tipoName,
                  ':monto' => $price
                ));

                // Actualizar cantidad disponible
                $stUpd = $pdo->prepare("UPDATE tipos_entrada SET cantidad_disponible = cantidad_disponible - 1 WHERE id = :tid AND cantidad_disponible > 0");
                $stUpd->execute(array(':tid' => $tipoId));
              }
            }

            // Marcar como procesada
            $stProc = $pdo->prepare("UPDATE tc_orders SET processed_at = datetime('now') WHERE request_id = :rid");
            $stProc->execute(array(':rid' => (string)$uuid));

            $pdo->commit();
            $processed = true;
          } catch (Exception $e) {
            $pdo->rollBack();
            // Log error
            error_log('Error procesando orden TotalCoin: ' . $e->getMessage());
          }
        }
      }
    }
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
    <?php if ($processed): ?>
      <p style="margin:0;color:green;">Entradas creadas exitosamente.</p>
    <?php endif; ?>
  <?php else: ?>
    <p style="margin:0;">Callback recibido sin RequestId.</p>
  <?php endif; ?>
</div>
<?php include __DIR__.'/inc/layout_bottom.php'; ?>
