<?php
require_once __DIR__.'/inc/bootstrap.php';
$title = 'TotalCoin Callback';
$state = $_GET['state'] ?? 'unknown';
$uuid  = $_GET['uuid'] ?? ($_GET['requestId'] ?? '');
$updated = false;
$processed = false;
$debugMsg = '';

// Persistir estado si tenemos requestId
$updated = false;
$processed = false;
$debugMsg = '';
if ($uuid !== '') {
  try {
    $pdo = db();
    $st = $pdo->prepare("UPDATE tc_orders SET state = :st, updated_at = datetime('now') WHERE request_id = :rid");
    $st->execute(array(':st' => (string)$state, ':rid' => (string)$uuid));
    $updated = ($st->rowCount() > 0);

    // Si se actualizó a success y no está procesada, crear entradas
    if ($updated && $state === 'success') {
      $debugMsg .= "Estado actualizado. ";
      $stOrd = $pdo->prepare("SELECT * FROM tc_orders WHERE request_id = :rid LIMIT 1");
      $stOrd->execute(array(':rid' => (string)$uuid));
      $order = $stOrd->fetch(PDO::FETCH_ASSOC);
      
      if (!$order) {
        $debugMsg .= "Orden no encontrada. ";
      } else {
        $debugMsg .= "Orden encontrada. ";
        $processedAt = $order['processed_at'] ?? $order['processed_at'] ?? null;
        $ticketsJson = $order['selected_tickets_json'] ?? null;
        $debugMsg .= "ProcessedAt: " . ($processedAt === null ? 'NULL' : 'SET') . ". ";
        $debugMsg .= "TicketsJson: " . (empty($ticketsJson) ? 'EMPTY' : 'HAS_DATA') . ". ";
        
        if (empty($processedAt) && !empty($ticketsJson)) {
          $tickets = json_decode($ticketsJson, true);
          $debugMsg .= "JSON válido: " . (is_array($tickets) ? 'YES' : 'NO') . ". ";
          if (is_array($tickets)) {
            $pdo->beginTransaction();
            try {
              $eventoId = (int)$order['evento_id'];
              $buyerName = trim(($order['buyer_first'] ?? '') . ' ' . ($order['buyer_last'] ?? ''));
              $buyerEmail = $order['buyer_email'] ?? '';
              $fechaReg = date('Y-m-d H:i:s');

              $entradAsCreadas = 0;
              foreach ($tickets as $ticket) {
                $tipoId = (int)($ticket['id'] ?? 0);
                $tipoName = $ticket['name'] ?? 'General';
                $qty = (int)($ticket['qty'] ?? 1);
                $price = (int)($ticket['price'] ?? 0);

                for ($i = 0; $i < $qty; $i++) {
                  // Generar código único (fallback si random_bytes no existe)
                  $codigo = '';
                  if (function_exists('random_bytes')) {
                    try {
                      $codigo = bin2hex(random_bytes(5));
                    } catch (Exception $_e) {
                      $codigo = substr(sha1(uniqid('', true)), 0, 10);
                    }
                  } else {
                    $codigo = substr(sha1(uniqid('', true)), 0, 10);
                  }

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
                  $entradAsCreadas++;

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
              $debugMsg .= "Entradas creadas: $entradAsCreadas. ";
            } catch (Exception $e) {
              if ($pdo->inTransaction()) {
                $pdo->rollBack();
              }
              $debugMsg .= "Error CREATE: " . $e->getMessage() . ". ";
              error_log('Error procesando orden TotalCoin ' . $uuid . ': ' . $e->getMessage());
            }
          }
        }
      }
    }
  } catch (Exception $e) {
    $updated = false;
    $debugMsg .= "Error UPDATE: " . $e->getMessage() . ". ";
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
      <p style="margin:0;color:green;">✅ Entradas creadas exitosamente.</p>
    <?php endif; ?>
    <?php if ($debugMsg): ?>
      <p style="margin:8px 0 0 0;font-size:12px;color:var(--muted);font-family:monospace;">Debug: <?php echo e($debugMsg); ?></p>
    <?php endif; ?>
  <?php else: ?>
    <p style="margin:0;">Callback recibido sin RequestId.</p>
  <?php endif; ?>
</div>
<?php include __DIR__.'/inc/layout_bottom.php'; ?>
