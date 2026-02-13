<?php
require_once __DIR__.'/inc/bootstrap.php';
require_once __DIR__.'/inc/senforms_bridge_admin.php';
require_login();

$cu = current_user();
$rol = isset($cu['tipo_global']) && $cu['tipo_global'] !== ''
  ? $cu['tipo_global']
  : (isset($cu['rol']) ? $cu['rol'] : (isset($_SESSION['tipo_global']) ? $_SESSION['tipo_global'] : ''));

if (!in_array($rol, array('super_admin','superadmin'), true)) {
    http_response_code(403);
    include __DIR__.'/inc/layout_top.php';
    echo "<div class='card error'><h2>Acceso denegado</h2><p>Solo super_admin puede usar Bridge SenForms.</p></div>";
    include __DIR__.'/inc/layout_bottom.php';
    exit;
}

$currentEventId  = (int)(getenv('SENFORMS_EVENT_ID') ?: 18);
$archiveEventId  = (int)(getenv('SENFORMS_ARCHIVE_EVENT_ID') ?: 21);
$selectedEventId = isset($_GET['event_id']) ? (int)$_GET['event_id'] : $currentEventId;

$flash = array();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'move_archive') {
            $ttId = (int)($_POST['ticket_type_id'] ?? 0);
            $from = (int)($_POST['from_event_id'] ?? 0);
            if ($ttId <= 0 || $from <= 0) throw new InvalidArgumentException('Datos incompletos');
            sba_move_ticket_type($ttId, $from, $archiveEventId);
            $flash[] = array('type'=>'ok','msg'=>'TicketType movido al evento archivo (#'.$archiveEventId.')');
            $selectedEventId = $from; // permanecer en evento actual
        } elseif ($action === 'create_tt') {
            $evId = isset($_POST['event_id']) && $_POST['event_id'] !== '' ? (int)$_POST['event_id'] : $currentEventId;
            $name = $_POST['name'] ?? '';
            $price = $_POST['price'] ?? '0';
            sba_create_ticket_type($evId, $name, $price);
            $flash[] = array('type'=>'ok','msg'=>'TicketType creado en evento #'.$evId);
            $selectedEventId = $evId;
        } elseif ($action === 'rename_tt') {
            $ttId = (int)($_POST['ticket_type_id'] ?? 0);
            $name = $_POST['name'] ?? '';
            sba_rename_ticket_type($ttId, $name);
            $flash[] = array('type'=>'ok','msg'=>'Nombre actualizado');
        }
    } catch (Exception $e) {
        $flash[] = array('type'=>'err','msg'=>'Error: '.$e->getMessage());
    }
}

$events = sba_get_events_by_ids(array($currentEventId, $archiveEventId));
$eventsById = array();
foreach ($events as $ev) { $eventsById[(int)$ev['Id']] = $ev; }

$ticketTypes = array();
try {
    $ticketTypes = sba_get_ticket_types($selectedEventId);
} catch (Exception $e) {
    $flash[] = array('type'=>'err','msg'=>'No se pudieron listar TicketTypes: '.$e->getMessage());
}

$title = 'Bridge SenForms';
include __DIR__.'/inc/layout_top.php';
?>

<div class="card" style="margin-top:12px;">
  <h2 style="margin:0;">Bridge → SenForms</h2>
  <div style="color:var(--muted);margin-top:4px;">Mover TicketTypes al evento archivo o crear nuevas tandas sin tocar SenForms.</div>
  <div style="margin-top:6px;font-size:12px;color:var(--muted);">
    Evento activo: #<?php echo (int)$currentEventId; ?> &nbsp; | &nbsp; Evento archivo: #<?php echo (int)$archiveEventId; ?>
  </div>
</div>

<?php foreach ($flash as $f): ?>
  <div class="flash <?php echo e($f['type']); ?>" style="margin-top:12px;"><?php echo e($f['msg']); ?></div>
<?php endforeach; ?>

<div class="card" style="margin-top:12px;">
  <form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
    <div>
      <label>Evento SenForms</label>
      <select name="event_id">
        <option value="<?php echo (int)$currentEventId; ?>" <?php echo $selectedEventId===$currentEventId?'selected':''; ?>>Evento actual (#<?php echo (int)$currentEventId; ?>)</option>
        <option value="<?php echo (int)$archiveEventId; ?>" <?php echo $selectedEventId===$archiveEventId?'selected':''; ?>>Evento archivo (#<?php echo (int)$archiveEventId; ?>)</option>
      </select>
    </div>
    <div>
      <button class="btn" type="submit">Ver TicketTypes</button>
    </div>
  </form>
</div>

<div class="card" style="margin-top:12px;">
  <h3 style="margin-top:0;">Crear TicketType</h3>
  <form method="post" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;">
    <input type="hidden" name="action" value="create_tt">
    <div>
      <label>Evento</label>
      <select name="event_id">
        <option value="<?php echo (int)$currentEventId; ?>">Evento actual (#<?php echo (int)$currentEventId; ?>)</option>
        <option value="<?php echo (int)$archiveEventId; ?>">Evento archivo (#<?php echo (int)$archiveEventId; ?>)</option>
      </select>
    </div>
    <div>
      <label>Nombre</label>
      <input type="text" name="name" required placeholder="Ej: Promo $20000">
    </div>
    <div>
      <label>Precio</label>
      <input type="number" step="0.01" min="0" name="price" required>
    </div>
    <div>
      <button class="btn" type="submit">Crear</button>
    </div>
  </form>
</div>

<div class="card" style="margin-top:12px;">
  <h3 style="margin-top:0;">TicketTypes del evento #<?php echo (int)$selectedEventId; ?></h3>
  <div style="overflow:auto;">
    <table class="table" style="min-width:720px;">
      <thead>
        <tr>
          <th>ID</th>
          <th>Nombre</th>
          <th>Precio</th>
          <th>Ventas</th>
          <th>Acciones</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($ticketTypes)): ?>
          <tr><td colspan="5" style="text-align:center;color:var(--muted);">Sin TicketTypes para este evento.</td></tr>
        <?php else: ?>
          <?php foreach ($ticketTypes as $tk): ?>
            <?php $sales = isset($tk['sales_count']) ? (int)$tk['sales_count'] : 0; ?>
            <tr>
              <td><?php echo (int)$tk['Id']; ?></td>
              <td>
                <form method="post" style="display:flex;gap:6px;align-items:center;">
                  <input type="hidden" name="action" value="rename_tt">
                  <input type="hidden" name="ticket_type_id" value="<?php echo (int)$tk['Id']; ?>">
                  <input type="text" name="name" value="<?php echo e($tk['Name']); ?>" style="min-width:200px;">
                  <button class="btn secondary" type="submit" style="padding:4px 10px;font-size:12px;">Renombrar</button>
                </form>
              </td>
              <td>$<?php echo htmlspecialchars(number_format((float)$tk['Price'], 2), ENT_QUOTES, 'UTF-8'); ?></td>
              <td><?php echo $sales; ?></td>
              <td style="display:flex;gap:6px;flex-wrap:wrap;">
                <?php if ((int)$selectedEventId !== (int)$archiveEventId): ?>
                  <form method="post" onsubmit="return confirm('Mover TicketType #<?php echo (int)$tk['Id']; ?> al evento archivo?');">
                    <input type="hidden" name="action" value="move_archive">
                    <input type="hidden" name="ticket_type_id" value="<?php echo (int)$tk['Id']; ?>">
                    <input type="hidden" name="from_event_id" value="<?php echo (int)$selectedEventId; ?>">
                    <button class="btn secondary" type="submit" style="padding:4px 10px;font-size:12px;">Mover a ARCHIVO</button>
                  </form>
                <?php else: ?>
                  <span class="pill" style="background:var(--panel-2);border:1px solid var(--line);font-size:12px;">Ya en archivo</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card" style="margin-top:12px;">
  <h3 style="margin-top:0;">Auditoría (últimos registros)</h3>
  <div style="overflow:auto;">
    <table class="table" style="min-width:640px;">
      <thead><tr><th>Fecha</th><th>Acción</th><th>Payload</th><th>Resultado</th><th>Error</th></tr></thead>
      <tbody>
        <?php
          $aud = array();
          try {
            $pdoA = sba_ensure_audit_table();
            $aud = $pdoA->query("SELECT * FROM senforms_admin_audit ORDER BY ts DESC, id DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
          } catch (Exception $e) {}
          if (empty($aud)) {
            echo "<tr><td colspan='5' style='text-align:center;color:var(--muted);'>Sin registros</td></tr>";
          } else {
            foreach ($aud as $a) {
              echo '<tr>';
              echo '<td>'.e($a['ts']).'</td>';
              echo '<td>'.e($a['action']).'</td>';
              echo '<td><code style="font-size:11px;">'.e($a['payload_json']).'</code></td>';
              echo '<td>'.e($a['result']).'</td>';
              echo '<td>'.e($a['error']).'</td>';
              echo '</tr>';
            }
          }
        ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card" style="margin:12px 0 24px 0;">
  <h3 style="margin-top:0;">Checklist / Riesgos</h3>
  <ul style="margin:0 0 0 18px;">
    <li>El histórico no se rompe: Tickets guarda Price y SelectedType; el bridge resuelve por Id.</li>
    <li>Mover a ARCHIVO solo cambia EventId del TicketType; las ventas pasadas siguen visibles.</li>
    <li>Solo super_admin puede operar este módulo.</li>
    <li>Acciones quedan registradas en senforms_admin_audit (SQLite).</li>
  </ul>
</div>

<?php include __DIR__.'/inc/layout_bottom.php'; ?>
