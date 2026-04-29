<?php
// Tickex (Bridge) block for panel_evento.php
// Requiere: $pdo (PDO sqlite), $eventoId (int), helper e()

if (!isset($pdo) || !($pdo instanceof PDO) || !isset($eventoId)) { return; }

try {
  // Chequeos mínimos (tabla mapeo + view)
  $hasBridgeMap = (bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='bridge_event_map' LIMIT 1")->fetchColumn();
  $hasLegacyMap = (bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='tickex_event_map' LIMIT 1")->fetchColumn();
  $hasView = (bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type IN ('view','table') AND name='v_senforms_bridge_status' LIMIT 1")->fetchColumn();
  if ((!$hasBridgeMap && !$hasLegacyMap) || !$hasView) { return; }

  $mapTable = $hasBridgeMap ? 'bridge_event_map' : 'tickex_event_map';
  $mapSql = $mapTable === 'bridge_event_map'
    ? "SELECT bridge_slug AS event_slug FROM bridge_event_map WHERE evento_id = :id LIMIT 1"
    : "SELECT event_slug, event_public_id FROM tickex_event_map WHERE str_event_id = :id LIMIT 1";

  $stMap = $pdo->prepare($mapSql);
  $stMap->execute(array(':id' => (int)$eventoId));
  $m = $stMap->fetch(PDO::FETCH_ASSOC);
  if (!$m || empty($m['event_slug'])) { return; }

  $tickex = array(
    'has' => false,
    'event_slug' => '',
    'event_public_id' => '',
    'total' => 0,
    'paid' => 0,
    'checkins' => 0,
  );
  $rowsTx = array();
  $tickexUrl = '';

  $tickex['has'] = true;
  $tickex['event_slug'] = (string)$m['event_slug'];
  $tickex['event_public_id'] = isset($m['event_public_id']) ? (string)$m['event_public_id'] : '';

  if ($tickex['event_public_id'] !== '') {
    $tickexUrl = "https://tickex.com.ar/Ticket/PublicTicket?EventPublicId=" . rawurlencode($tickex['event_public_id']);
  }

  $st1 = $pdo->prepare("SELECT COUNT(*) FROM v_senforms_bridge_status WHERE event_slug = :s");
  $st1->execute(array(':s' => $tickex['event_slug']));
  $tickex['total'] = (int)$st1->fetchColumn();

  $st2 = $pdo->prepare("SELECT COUNT(*) FROM v_senforms_bridge_status WHERE event_slug = :s AND is_paid = 1");
  $st2->execute(array(':s' => $tickex['event_slug']));
  $tickex['paid'] = (int)$st2->fetchColumn();

  $st3 = $pdo->prepare("SELECT COUNT(*) FROM v_senforms_bridge_status WHERE event_slug = :s AND is_checked_in = 1");
  $st3->execute(array(':s' => $tickex['event_slug']));
  $tickex['checkins'] = (int)$st3->fetchColumn();

  $st4 = $pdo->prepare("
    SELECT last_updated_at, ticket_ref, email, price, is_paid, is_checked_in
    FROM v_senforms_bridge_status
    WHERE event_slug = :s
    ORDER BY last_updated_at DESC
    LIMIT 100
  ");
  $st4->execute(array(':s' => $tickex['event_slug']));
  $rowsTx = $st4->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
  return;
}
?>

<div class="card" style="margin-top:12px;background:var(--panel-2);">
  <h3 style="margin-top:0;">Tickex (Bridge)</h3>

  <div class="muted">
    Evento Tickex (slug): <strong><?php echo e($tickex['event_slug']); ?></strong>
  </div>

  <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:10px;">
    <div class="card" style="flex:1 1 180px;min-width:180px;margin:0;">
      <div class="muted">Tickets</div>
      <div style="font-size:22px;font-weight:700;"><?php echo (int)$tickex['total']; ?></div>
    </div>
    <div class="card" style="flex:1 1 180px;min-width:180px;margin:0;">
      <div class="muted">Pagados</div>
      <div style="font-size:22px;font-weight:700;"><?php echo (int)$tickex['paid']; ?></div>
    </div>
    <div class="card" style="flex:1 1 180px;min-width:180px;margin:0;">
      <div class="muted">Check-ins</div>
      <div style="font-size:22px;font-weight:700;"><?php echo (int)$tickex['checkins']; ?></div>
    </div>
  </div>

  <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:10px;">
    <?php if ($tickexUrl !== ''): ?>
      <a class="btn secondary" href="<?php echo e($tickexUrl); ?>" target="_blank" rel="noopener">Abrir Tickex</a>
    <?php endif; ?>
    <a class="btn" href="comprar.php?id=<?php echo (int)$eventoId; ?>" target="_blank" rel="noopener">Comprar (redirige)</a>
    <a class="btn secondary" href="comprar_iframe.php?id=<?php echo (int)$eventoId; ?>" target="_blank" rel="noopener">Ver embebido</a>
  </div>

  <?php if (empty($rowsTx)): ?>
    <div class="muted" style="margin-top:10px;">Sin tickets sincronizados todavía.</div>
  <?php else: ?>
    <div style="overflow:auto;margin-top:10px;">
      <table class="table">
        <tr>
          <th>Fecha</th><th>Ref</th><th>Email</th><th>Precio</th><th>Pago</th><th>Check-in</th>
        </tr>
        <?php foreach($rowsTx as $tx): ?>
          <tr>
            <td><?php echo e((string)$tx['last_updated_at']); ?></td>
            <td><?php echo e((string)$tx['ticket_ref']); ?></td>
            <td><?php echo e((string)$tx['email']); ?></td>
            <td><?php echo e((string)$tx['price']); ?></td>
            <td><?php if ((int)$tx['is_paid']===1): ?><span style="color:var(--ok);font-weight:700;">OK</span><?php else: ?><span style="color:var(--warn);font-weight:700;">Pendiente</span><?php endif; ?></td>
            <td><?php if ((int)$tx['is_checked_in']===1): ?><span style="color:var(--ok);font-weight:700;">OK</span><?php else: ?><span style="color:var(--warn);font-weight:700;">Pendiente</span><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
    </div>
  <?php endif; ?>
</div>
