<?php
require_once __DIR__.'/inc/bootstrap.php';
require_once __DIR__.'/inc/unified_tickets.php';
require_once __DIR__.'/inc/senforms.php';

require_login();
$cu = current_user();
$rol = isset($cu['tipo_global']) && $cu['tipo_global'] !== ''
  ? $cu['tipo_global']
  : (isset($cu['rol']) ? $cu['rol'] : (isset($_SESSION['tipo_global']) ? $_SESSION['tipo_global'] : ''));

$eventoId = isset($_GET['evento_id']) ? (int)$_GET['evento_id'] : 0;
$pdo = db();
$mappedSlugList = ($eventoId > 0) ? get_mapped_bridge_slugs($pdo, $eventoId) : array();
$mappedSlug = !empty($mappedSlugList) ? $mappedSlugList[0] : '';

// Permisos: superadmin siempre; admin_evento solo si viene evento_id y tiene mapping
$isSuper = in_array($rol, array('super_admin','superadmin'), true);
$isAdminEvento = ($rol === 'admin_evento');
if (!$isSuper) {
    if (!$isAdminEvento || $eventoId <= 0 || empty($mappedSlugList)) {
        http_response_code(403);
        include __DIR__.'/inc/layout_top.php';
        echo "<div class='card error'><h2>Acceso denegado</h2><p>No tenés permiso para este editor de Tickex.</p></div>";
        include __DIR__.'/inc/layout_bottom.php';
        exit;
    }
}

// helper para validar que un evento SenForms esté dentro de los mapeados cuando es admin_evento
function sf_event_allowed($ev, $mappedSlugList, $isSuper){
  if ($isSuper) return true;
  if (!$ev) return false;
  $site = isset($ev['SiteName']) ? $ev['SiteName'] : '';
  return in_array($site, $mappedSlugList, true);
}

$selectedEvent = null;
$searchResults = array();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'pick_event') {
            $site = trim($_POST['site'] ?? '');
            if ($site !== '') {
                $ev = sf_find_event_by_site($site);
                if ($ev && sf_event_allowed($ev, $mappedSlugList, $isSuper)) {
                    $selectedEvent = $ev;
                } else {
                    flash('err', $ev ? 'No tenés permiso para ese evento.' : 'No se encontró evento con ese SiteName.');
                }
            }
        } elseif ($action === 'search') {
            $q = trim($_POST['q'] ?? '');
            if ($q !== '') {
                $searchResults = sf_find_events_like($q);
                if (!$isSuper && $searchResults) {
                    $searchResults = array_values(array_filter($searchResults, function($row) use ($mappedSlugList) {
                        return in_array($row['SiteName'], $mappedSlugList, true);
                    }));
                }
                if (!$searchResults) flash('warn', 'No se encontraron eventos que coincidan.');
            }
        } elseif ($action === 'update_limit') {
            $eid = (int)($_POST['event_id'] ?? 0);
            $ev = sf_get_event($eid);
            if ($ev && sf_event_allowed($ev, $mappedSlugList, $isSuper)) {
                $limit = $_POST['limit'] ?? '0';
                sf_update_event_limit($eid, $limit);
                flash('ok', 'Límite actualizado');
                $selectedEvent = sf_get_event($eid);
            } else {
                flash('err', $ev ? 'No tenés permiso para ese evento.' : 'Evento no encontrado.');
            }
        } elseif ($action === 'update_price') {
            $ttid = (int)($_POST['ticket_type_id'] ?? 0);
            $eid = (int)($_POST['event_id'] ?? 0);
            $ev = sf_get_event($eid);
            if ($ev && sf_event_allowed($ev, $mappedSlugList, $isSuper)) {
                $price = $_POST['price'] ?? '0';
                sf_update_ticket_type_price($ttid, $price);
                flash('ok', 'Precio actualizado');
                $selectedEvent = sf_get_event($eid);
            } else {
                flash('err', $ev ? 'No tenés permiso para ese evento.' : 'Evento no encontrado.');
            }
        } elseif ($action === 'create_ticket_type') {
            $eid = (int)($_POST['event_id'] ?? 0);
            $ev = sf_get_event($eid);
            if ($ev && sf_event_allowed($ev, $mappedSlugList, $isSuper)) {
                $name = trim($_POST['name'] ?? '');
                $price = $_POST['price'] ?? '0';
                $newId = sf_create_ticket_type($eid, $name, $price);
                flash('ok', 'Tipo creado (Id '.$newId.')');
                $selectedEvent = sf_get_event($eid);
            } else {
                flash('err', $ev ? 'No tenés permiso para ese evento.' : 'Evento no encontrado.');
            }
        } elseif ($action === 'delete_ticket_type') {
            $ttid = (int)($_POST['ticket_type_id'] ?? 0);
            $eid = (int)($_POST['event_id'] ?? 0);
            $ev = sf_get_event($eid);
            if ($ev && sf_event_allowed($ev, $mappedSlugList, $isSuper)) {
                sf_delete_ticket_type($ttid);
                flash('ok', 'Tipo eliminado (Id '.$ttid.')');
                $selectedEvent = sf_get_event($eid);
            } else {
                flash('err', $ev ? 'No tenés permiso para ese evento.' : 'Evento no encontrado.');
            }
        } elseif ($action === 'delete_ticket') {
            $tid = (int)($_POST['ticket_id'] ?? 0);
            $eid = (int)($_POST['event_id'] ?? 0);
            $ev = sf_get_event($eid);
            if ($ev && sf_event_allowed($ev, $mappedSlugList, $isSuper)) {
                if ($tid > 0) {
                    sf_delete_ticket($tid);
                    flash('ok', 'Ticket eliminado (Tickets.Id='.$tid.')');
                }
                $selectedEvent = sf_get_event($eid);
            } else {
                flash('err', $ev ? 'No tenés permiso para ese evento.' : 'Evento no encontrado.');
            }
        } elseif ($action === 'pick_by_id') {
            $eid = (int)($_POST['event_id'] ?? 0);
            if ($eid > 0) {
                $ev = sf_get_event($eid);
                if ($ev && sf_event_allowed($ev, $mappedSlugList, $isSuper)) {
                    $selectedEvent = $ev;
                } else {
                    flash('err', $ev ? 'No tenés permiso para ese evento.' : 'No se encontró ese Id de evento en SenForms.');
                }
            }
        } elseif ($action === 'delete_event') {
            $eid = (int)($_POST['event_id'] ?? 0);
            $ev = sf_get_event($eid);
            if ($ev && sf_event_allowed($ev, $mappedSlugList, $isSuper)) {
                sf_delete_event($eid);
                flash('ok', 'Evento eliminado (Id '.$eid.')');
                $selectedEvent = null;
            } else {
                flash('err', $ev ? 'No tenés permiso para ese evento.' : 'Evento no encontrado.');
            }
        }
    } catch (Exception $ex) {
        flash('err', 'Error: '.$ex->getMessage());
    }
}

// Si no hay selección y tenemos slug mapeado, intentar elegir ese evento
if (!$selectedEvent && $mappedSlug !== '') {
    $ev = sf_find_event_by_site($mappedSlug);
    if ($ev && sf_event_allowed($ev, $mappedSlugList, $isSuper)) {
      $selectedEvent = $ev;
    }
}

$flash = flash_get_all();
$title = 'Editar evento Tickex/SenForms';
include __DIR__.'/inc/layout_top.php';
?>

<div class="card">
  <h1 style="margin:0 0 8px;">Editar evento Tickex/SenForms</h1>
  <p class="muted" style="margin:0;">Actualiza precios de TicketType y el límite de entradas (TicketAmountLimit). Superadmin o admin con evento mapeado.</p>
  <?php if ($mappedSlug): ?>
    <div class="muted" style="margin-top:6px;">Slug mapeado desde STR: <strong><?php echo e($mappedSlug); ?></strong></div>
  <?php endif; ?>
</div>

<?php if ($flash): ?>
  <?php foreach ($flash as $f): ?>
    <div class="flash <?php echo e($f['type']); ?>"><?php echo e($f['msg']); ?></div>
  <?php endforeach; ?>
<?php endif; ?>

<div class="card" style="margin-top:12px;">
  <h3 style="margin-top:0;">Buscar/Seleccionar evento</h3>
  <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end;">
    <input type="hidden" name="action" value="pick_event">
    <div>
      <label>SiteName (slug)</label>
      <input type="text" name="site" value="<?php echo e($mappedSlug); ?>" placeholder="ej: savetherave7-3">
    </div>
    <div>
      <button class="btn" type="submit">Cargar por slug</button>
    </div>
  </form>

  <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end;margin-top:10px;">
    <input type="hidden" name="action" value="pick_by_id">
    <div>
      <label>Event Id</label>
      <input type="number" name="event_id" min="1" placeholder="Id numérico">
    </div>
    <div>
      <button class="btn secondary" type="submit">Cargar por Id</button>
    </div>
  </form>

  <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end;margin-top:10px;">
    <input type="hidden" name="action" value="search">
    <div>
      <label>Buscar (nombre o slug)</label>
      <input type="text" name="q" placeholder="texto">
    </div>
    <div>
      <button class="btn secondary" type="submit">Buscar</button>
    </div>
  </form>

  <?php if ($searchResults): ?>
    <div style="margin-top:10px;overflow:auto;">
      <table class="table">
        <tr><th>Id</th><th>Nombre</th><th>SiteName</th><th>Fecha</th><th>Elegir</th></tr>
        <?php foreach ($searchResults as $row): ?>
          <tr>
            <td><?php echo (int)$row['Id']; ?></td>
            <td><?php echo e($row['Name']); ?></td>
            <td><?php echo e($row['SiteName']); ?></td>
            <td class="muted" style="font-size:12px;">
              <?php echo e($row['EventStartDate']); ?> → <?php echo e($row['EventEndDate']); ?>
            </td>
            <td>
              <form method="post" style="margin:0;">
                <input type="hidden" name="action" value="pick_by_id">
                <input type="hidden" name="event_id" value="<?php echo (int)$row['Id']; ?>">
                <button class="btn secondary" type="submit">Seleccionar</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php if ($selectedEvent): ?>
  <?php $types = sf_get_ticket_types_with_sales($selectedEvent['Id']); ?>
  <div class="card" style="margin-top:12px;">
    <h3 style="margin-top:0;">Evento cargado: <?php echo e($selectedEvent['Name']); ?> (Id <?php echo (int)$selectedEvent['Id']; ?>)</h3>
    <div class="muted" style="font-size:12px;">SiteName: <?php echo e($selectedEvent['SiteName']); ?> | Límite: <?php echo (int)$selectedEvent['TicketAmountLimit']; ?> | Activo: <?php echo $selectedEvent['Active'] ? 'Sí' : 'No'; ?></div>

    <form method="post" style="margin-top:10px;" onsubmit="return confirm('¿Eliminar este evento de SenForms? Esto eliminará sus tickets/tipos.');">
      <input type="hidden" name="action" value="delete_event">
      <input type="hidden" name="event_id" value="<?php echo (int)$selectedEvent['Id']; ?>">
      <button class="btn danger" type="submit">Eliminar evento</button>
      <span class="muted" style="font-size:12px;margin-left:8px;">Irreversible, usa con cuidado.</span>
    </form>

    <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;">
      <input type="hidden" name="action" value="update_limit">
      <input type="hidden" name="event_id" value="<?php echo (int)$selectedEvent['Id']; ?>">
      <div>
        <label>Nuevo límite</label>
        <input type="number" name="limit" min="0" value="<?php echo (int)$selectedEvent['TicketAmountLimit']; ?>">
      </div>
      <div style="align-self:flex-end;">
        <button class="btn" type="submit">Guardar límite</button>
      </div>
    </form>

    <?php if ($types): ?>
      <div style="margin-top:12px;overflow:auto;">
        <table class="table">
          <tr><th>ID</th><th>Nombre</th><th>Precio</th><th>Ventas</th><th>Notas</th><th>Borrar</th></tr>
          <?php foreach ($types as $t): ?>
            <?php $sales = isset($t['sales_count']) ? (int)$t['sales_count'] : 0; ?>
            <tr>
              <td><?php echo (int)$t['Id']; ?></td>
              <td><?php echo e($t['Name']); ?></td>
              <td>
                <?php if ($sales > 0): ?>
                  <div class="muted">$<?php echo htmlspecialchars($t['Price'], ENT_QUOTES, 'UTF-8'); ?> (bloqueado)</div>
                <?php else: ?>
                  <form method="post" style="display:flex;gap:6px;align-items:center;">
                    <input type="hidden" name="action" value="update_price">
                    <input type="hidden" name="ticket_type_id" value="<?php echo (int)$t['Id']; ?>">
                    <input type="hidden" name="event_id" value="<?php echo (int)$selectedEvent['Id']; ?>">
                    <input type="number" step="0.01" min="0" name="price" value="<?php echo htmlspecialchars($t['Price'], ENT_QUOTES, 'UTF-8'); ?>" style="width:120px;">
                    <button class="btn secondary" type="submit">Guardar</button>
                  </form>
                <?php endif; ?>
              </td>
              <td><?php echo $sales; ?></td>
              <td>
                <?php if ($sales === 0): ?>
                  <div class="muted" style="font-size:12px;">Sin ventas, se puede editar.</div>
                <?php else: ?>
                  <div class="muted" style="font-size:12px;">Crea un tipo nuevo para cambiar precio.</div>
                <?php endif; ?>
              </td>
              <td>
                <form method="post" onsubmit="return confirm('¿Eliminar este tipo de entrada?');" style="margin:0;">
                  <input type="hidden" name="action" value="delete_ticket_type">
                  <input type="hidden" name="ticket_type_id" value="<?php echo (int)$t['Id']; ?>">
                  <input type="hidden" name="event_id" value="<?php echo (int)$selectedEvent['Id']; ?>">
                  <button class="btn danger" type="submit" style="font-size:12px;">Borrar</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </table>
      </div>
    <?php else: ?>
      <div class="muted" style="margin-top:10px;">Sin tipos de entrada.</div>
    <?php endif; ?>

    <div style="margin-top:12px;">
      <h4 style="margin:0 0 6px;">Crear nuevo tipo de entrada</h4>
      <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end;">
        <input type="hidden" name="action" value="create_ticket_type">
        <input type="hidden" name="event_id" value="<?php echo (int)$selectedEvent['Id']; ?>">
        <div>
          <label>Nombre</label>
          <input type="text" name="name" required>
        </div>
        <div>
          <label>Precio</label>
          <input type="number" step="0.01" min="0" name="price" value="0">
        </div>
        <div>
          <button class="btn" type="submit">Crear tipo</button>
        </div>
      </form>
    </div>
  </div>

  <?php $tickets = sf_get_tickets_by_event($selectedEvent['Id'], 20); ?>
  <div class="card" style="margin-top:12px;">
    <h3 style="margin-top:0;">Tickets recientes (20) — borrar puntual</h3>
    <?php if ($tickets): ?>
      <div style="overflow:auto;">
        <table class="table">
          <tr><th>Id</th><th>Nombre</th><th>Email</th><th>Tipo</th><th>Precio</th><th>Pago</th><th>Acción</th></tr>
          <?php foreach ($tickets as $tk): ?>
            <tr>
              <td><?php echo (int)$tk['Id']; ?></td>
              <td><?php echo e($tk['FirstName'].' '.$tk['LastName']); ?></td>
              <td><?php echo e($tk['Email']); ?></td>
              <td><?php echo e($tk['SelectedType']); ?></td>
              <td><?php echo htmlspecialchars($tk['Price'], ENT_QUOTES, 'UTF-8'); ?></td>
              <td><?php echo e($tk['PaymentState']); ?></td>
              <td>
                <form method="post" onsubmit="return confirm('¿Eliminar este ticket?');">
                  <input type="hidden" name="action" value="delete_ticket">
                  <input type="hidden" name="ticket_id" value="<?php echo (int)$tk['Id']; ?>">
                  <input type="hidden" name="event_id" value="<?php echo (int)$selectedEvent['Id']; ?>">
                  <button class="btn danger" type="submit" style="font-size:12px;">Eliminar</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </table>
      </div>
      <div class="muted" style="margin-top:8px;font-size:12px;">Nota: borrar aquí elimina el registro en SenForms.Tickets (use con cuidado).</div>
    <?php else: ?>
      <div class="muted">No hay tickets listados.</div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php include __DIR__.'/inc/layout_bottom.php'; ?>