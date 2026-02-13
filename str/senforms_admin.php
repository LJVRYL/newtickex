<?php
require_once __DIR__.'/inc/bootstrap.php';
require_once __DIR__.'/inc/senforms.php';

require_login();
$cu = current_user();
$rol = isset($cu['tipo_global']) && $cu['tipo_global'] !== ''
    ? $cu['tipo_global']
    : (isset($cu['rol']) ? $cu['rol'] : (isset($_SESSION['tipo_global']) ? $_SESSION['tipo_global'] : ''));

if (!in_array($rol, array('super_admin','superadmin'), true)) {
    http_response_code(403);
    include __DIR__.'/inc/layout_top.php';
    echo "<div class='card error'><h2>Acceso denegado</h2><p>Solo superadmin puede editar SenForms.</p></div>";
    include __DIR__.'/inc/layout_bottom.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    try {
        if ($action === 'update_price') {
            $id = (int)($_POST['ticket_type_id'] ?? 0);
            $price = $_POST['price'] ?? '0';
            sf_update_ticket_type_price($id, $price);
            flash('ok', 'Precio actualizado');
        } elseif ($action === 'update_limit') {
            $eventId = (int)($_POST['event_id'] ?? 0);
            $limit = $_POST['limit'] ?? '0';
            sf_update_event_limit($eventId, $limit);
            flash('ok', 'Límite actualizado');
        } elseif ($action === 'create_event') {
            $data = array(
                'name'         => trim($_POST['name'] ?? ''),
                'start'        => trim($_POST['start'] ?? ''),
                'end'          => trim($_POST['end'] ?? ''),
                'location'     => trim($_POST['location'] ?? ''),
                'site'         => trim($_POST['site'] ?? ''),
                'flyer'        => trim($_POST['flyer'] ?? ''),
                'limit'        => (int)($_POST['limit'] ?? 0),
                'active'       => !empty($_POST['active']) ? 1 : 0,
                'ticket_name'  => trim($_POST['ticket_name'] ?? ''),
                'ticket_price' => $_POST['ticket_price'] ?? '0',
            );
            $newId = sf_create_event($data);
            flash('ok', 'Evento creado con ID '.$newId);
        }
    } catch (Exception $e) {
        flash('err', 'Error: '.$e->getMessage());
    }
    header('Location: senforms_admin.php');
    exit;
}

$events = sf_get_events();

$flash = flash_get_all();
$title = 'Admin SenForms';
include __DIR__.'/inc/layout_top.php';
?>

<div class="card">
  <h1 style="margin:0 0 10px;">Admin SenForms</h1>
  <p class="muted">Editar eventos y tipos de entrada en la base MySQL/MariaDB de SenForms.</p>
</div>

<?php if ($flash): ?>
  <?php foreach ($flash as $f): ?>
    <div class="flash <?php echo e($f['type']); ?>"><?php echo e($f['msg']); ?></div>
  <?php endforeach; ?>
<?php endif; ?>

<div class="card" style="margin-top:12px;">
  <h3 style="margin-top:0;">Crear evento</h3>
  <form method="post" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;">
    <input type="hidden" name="action" value="create_event">
    <div>
      <label>Nombre</label>
      <input type="text" name="name" required>
    </div>
    <div>
      <label>SiteName (slug)</label>
      <input type="text" name="site" placeholder="ej: savetherave7-3">
    </div>
    <div>
      <label>Fecha inicio</label>
      <input type="datetime-local" name="start">
    </div>
    <div>
      <label>Fecha fin</label>
      <input type="datetime-local" name="end">
    </div>
    <div>
      <label>Ubicación</label>
      <input type="text" name="location" placeholder="Rincón 1330">
    </div>
    <div>
      <label>Flyer (URL)</label>
      <input type="text" name="flyer" placeholder="http://...">
    </div>
    <div>
      <label>Límite de entradas</label>
      <input type="number" name="limit" min="0" value="0">
    </div>
    <div style="display:flex;align-items:center;gap:8px;margin-top:20px;">
      <input type="checkbox" name="active" value="1" id="evt_active">
      <label for="evt_active" style="margin:0;">Activo</label>
    </div>
    <div>
      <label>Ticket inicial - Nombre</label>
      <input type="text" name="ticket_name" placeholder="General">
    </div>
    <div>
      <label>Ticket inicial - Precio</label>
      <input type="number" step="0.01" min="0" name="ticket_price" value="0">
    </div>
    <div style="display:flex;align-items:flex-end;">
      <button class="btn" type="submit">Crear evento</button>
    </div>
  </form>
</div>

<?php foreach ($events as $ev): ?>
  <?php $tks = sf_get_ticket_types_with_sales($ev['Id']); ?>
  <div class="card" style="margin-top:12px;">
    <h3 style="margin-top:0;">Evento #<?php echo (int)$ev['Id']; ?> — <?php echo e($ev['Name']); ?></h3>
    <div class="muted" style="font-size:12px;">SiteName: <?php echo e($ev['SiteName']); ?> | Límite: <?php echo (int)$ev['TicketAmountLimit']; ?> | Activo: <?php echo $ev['Active'] ? 'Sí' : 'No'; ?></div>

    <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;">
      <input type="hidden" name="action" value="update_limit">
      <input type="hidden" name="event_id" value="<?php echo (int)$ev['Id']; ?>">
      <div>
        <label>Nuevo límite</label>
        <input type="number" name="limit" min="0" value="<?php echo (int)$ev['TicketAmountLimit']; ?>">
      </div>
      <div style="align-self:flex-end;">
        <button class="btn secondary" type="submit">Actualizar límite</button>
      </div>
    </form>

    <?php if ($tks): ?>
      <div style="margin-top:10px;overflow:auto;">
        <table class="table">
          <tr>
            <th>ID</th>
            <th>Nombre</th>
            <th>Precio</th>
            <th>Ventas</th>
          </tr>
          <?php foreach ($tks as $tk): ?>
            <?php $sales = isset($tk['sales_count']) ? (int)$tk['sales_count'] : 0; ?>
            <tr>
              <td><?php echo (int)$tk['Id']; ?></td>
              <td><?php echo e($tk['Name']); ?></td>
              <td>
                <?php if ($sales > 0): ?>
                  <div class="muted">$<?php echo htmlspecialchars($tk['Price'], ENT_QUOTES, 'UTF-8'); ?> (bloqueado por ventas)</div>
                <?php else: ?>
                  <form method="post" style="display:flex;gap:6px;align-items:center;">
                    <input type="hidden" name="action" value="update_price">
                    <input type="hidden" name="ticket_type_id" value="<?php echo (int)$tk['Id']; ?>">
                    <input type="number" step="0.01" min="0" name="price" value="<?php echo htmlspecialchars($tk['Price'], ENT_QUOTES, 'UTF-8'); ?>" style="width:120px;">
                    <button class="btn secondary" type="submit">Guardar</button>
                  </form>
                <?php endif; ?>
              </td>
              <td><?php echo $sales; ?></td>
            </tr>
          <?php endforeach; ?>
        </table>
      </div>
    <?php else: ?>
      <div class="muted" style="margin-top:10px;">Sin tipos de entrada.</div>
    <?php endif; ?>
  </div>
<?php endforeach; ?>

<?php include __DIR__.'/inc/layout_bottom.php'; ?>