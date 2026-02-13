<?php
require_once __DIR__.'/inc/bootstrap.php';
require_once __DIR__.'/inc/unified_tickets.php';
require_once __DIR__.'/inc/senforms.php';
require_once __DIR__.'/inc/senforms_catalog.php';

require_login();
$cu = current_user();
$rol = isset($cu['tipo_global']) && $cu['tipo_global'] !== ''
    ? $cu['tipo_global']
    : (isset($cu['rol']) ? $cu['rol'] : (isset($_SESSION['tipo_global']) ? $_SESSION['tipo_global'] : ''));

$isSuper = in_array($rol, array('super_admin','superadmin'), true);
$isAdminEvento = ($rol === 'admin_evento');
if (!$isSuper && !$isAdminEvento) {
    http_response_code(403);
    include __DIR__.'/inc/layout_top.php';
    echo "<div class='card error'><h2>Acceso denegado</h2><p>Solo superadmin o admin_evento.</p></div>";
    include __DIR__.'/inc/layout_bottom.php';
    exit;
}

$eventoId = isset($_GET['evento_id']) ? (int)$_GET['evento_id'] : 0;
$pdoLocal = db();
$mappedSlugList = ($eventoId > 0) ? get_mapped_bridge_slugs($pdoLocal, $eventoId) : array();
$mappedSlug = !empty($mappedSlugList) ? $mappedSlugList[0] : '';

function cat_sf_event_allowed($ev, $mappedSlugList, $isSuper){
    if ($isSuper) return true;
    if (!$ev) return false;
    $site = isset($ev['SiteName']) ? $ev['SiteName'] : '';
    return in_array($site, $mappedSlugList, true);
}

$selectedEvent = null;
$searchResults = array();
$sfAdminUrl = getenv('SENFORMS_ADMIN_URL');
if (!$sfAdminUrl || trim($sfAdminUrl) === '') {
  // fallback común: host local con ruta senforms (ajusta si difiere)
  $sfAdminUrl = '/senforms/';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'pick_event') {
            $site = trim($_POST['site'] ?? '');
            if ($site !== '') {
                $ev = sf_find_event_by_site($site);
                if ($ev && cat_sf_event_allowed($ev, $mappedSlugList, $isSuper)) {
                    $selectedEvent = $ev;
                } else {
                    flash('err', $ev ? 'No tenés permiso para ese evento.' : 'No se encontró evento con ese SiteName.');
                }
            }
        } elseif ($action === 'pick_by_id') {
            $eid = (int)($_POST['event_id'] ?? 0);
            if ($eid > 0) {
                $ev = sf_get_event($eid);
                if ($ev && cat_sf_event_allowed($ev, $mappedSlugList, $isSuper)) {
                    $selectedEvent = $ev;
                } else {
                    flash('err', $ev ? 'No tenés permiso para ese evento.' : 'Evento no encontrado.');
                }
            }
        } elseif ($action === 'search' && $isSuper) {
            $q = trim($_POST['q'] ?? '');
            if ($q !== '') {
                $searchResults = sf_find_events_like($q);
                if (!$searchResults) flash('warn', 'No se encontraron eventos que coincidan.');
            }
        } elseif ($action === 'create_ticket_type') {
            $eid = (int)($_POST['event_id'] ?? 0);
            $ev = sf_get_event($eid);
            if ($ev && cat_sf_event_allowed($ev, $mappedSlugList, $isSuper)) {
                $name = trim($_POST['name'] ?? '');
                $price = $_POST['price'] ?? '0';
                $newId = sf_create_ticket_type($eid, $name, $price);
                set_local_tickettype_state($pdoLocal, $eid, $newId, true);
                flash('ok', 'Tipo creado (Id '.$newId.') y activado en STR.');
                $selectedEvent = $ev;
            } else {
                flash('err', $ev ? 'No tenés permiso para ese evento.' : 'Evento no encontrado.');
            }
        } elseif ($action === 'rename_ticket_type') {
            $ttid = (int)($_POST['ticket_type_id'] ?? 0);
            $eid = (int)($_POST['event_id'] ?? 0);
            $ev = sf_get_event($eid);
            if ($ev && cat_sf_event_allowed($ev, $mappedSlugList, $isSuper)) {
                $name = trim($_POST['name'] ?? '');
                sf_rename_ticket_type($ttid, $name);
                flash('ok', 'Nombre actualizado.');
                $selectedEvent = $ev;
            } else {
                flash('err', $ev ? 'No tenés permiso para ese evento.' : 'Evento no encontrado.');
            }
        } elseif ($action === 'update_price') {
            $ttid = (int)($_POST['ticket_type_id'] ?? 0);
            $eid = (int)($_POST['event_id'] ?? 0);
            $ev = sf_get_event($eid);
            if ($ev && cat_sf_event_allowed($ev, $mappedSlugList, $isSuper)) {
                $price = $_POST['price'] ?? '0';
                sf_update_ticket_type_price($ttid, $price);
                flash('ok', 'Precio actualizado.');
                $selectedEvent = $ev;
            } else {
                flash('err', $ev ? 'No tenés permiso para ese evento.' : 'Evento no encontrado.');
            }
        } elseif ($action === 'toggle_active') {
            $ttid = (int)($_POST['ticket_type_id'] ?? 0);
            $eid = (int)($_POST['event_id'] ?? 0);
            $ev = sf_get_event($eid);
            if ($ev && cat_sf_event_allowed($ev, $mappedSlugList, $isSuper)) {
                $newState = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 0;
                set_local_tickettype_state($pdoLocal, $eid, $ttid, $newState === 1);
                flash('ok', $newState === 1 ? 'Tipo activado en STR.' : 'Tipo desactivado en STR.');
                $selectedEvent = $ev;
            } else {
                flash('err', $ev ? 'No tenés permiso para ese evento.' : 'Evento no encontrado.');
            }
        } elseif ($action === 'import_tickets') {
            $eid = (int)($_POST['event_id'] ?? 0);
            $ev = sf_get_event($eid);
            if ($ev && cat_sf_event_allowed($ev, $mappedSlugList, $isSuper)) {
                $stats = sf_import_tickets_to_cache($pdoLocal, $eid);
                flash('ok', 'Importados '.$stats['processed'].' tickets (pagados '.$stats['paid'].').');
                $selectedEvent = $ev;
            } else {
                flash('err', $ev ? 'No tenés permiso para ese evento.' : 'Evento no encontrado.');
            }
        }
    } catch (Exception $ex) {
        flash('err', 'Error: '.$ex->getMessage());
    }
}

if (!$selectedEvent && $mappedSlug !== '') {
    $ev = sf_find_event_by_site($mappedSlug);
    if ($ev && cat_sf_event_allowed($ev, $mappedSlugList, $isSuper)) {
        $selectedEvent = $ev;
    }
}

$title = 'Catálogo SenForms';
include __DIR__.'/inc/layout_top.php';
?>

<div class="card">
  <h1 style="margin:0 0 6px;">Catálogo SenForms (Tickex)</h1>
  <p class="muted" style="margin:0;">Reglas: precio histórico no se toca; si hay ventas se crea un tipo nuevo. Activación se maneja en STR (tabla local).</p>
  <ul style="margin:8px 0 0 16px; padding-left:12px;">
    <li>TicketType.Price es inmutable si hubo ventas.</li>
    <li>Para cambiar precio, crea un TicketType nuevo.</li>
    <li>Activación local: STR muestra solo los tipos activos.</li>
    <li>Reportes usan SUM(Tickets.Price).</li>
  </ul>
  <div style="margin-top:10px;">
    <a class="btn" href="<?php echo e($sfAdminUrl); ?>" target="_blank" rel="noopener noreferrer">Ir al panel SenForms (login admin)</a>
    <span class="muted" style="font-size:12px;margin-left:8px;">Configura SENFORMS_ADMIN_URL si la ruta difiere.</span>
  </div>
</div>

<?php if ($isSuper): ?>
  <div class="card" style="margin-top:12px;">
    <h3 style="margin-top:0;">Seleccionar evento SenForms</h3>
    <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end;">
      <input type="hidden" name="action" value="pick_event">
      <div>
        <label>SiteName (slug)</label>
        <input type="text" name="site" value="<?php echo e($mappedSlug); ?>" placeholder="ej: savetherave7-3">
      </div>
      <div>
        <button class="btn" type="submit">Cargar</button>
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
          <tr><th>Id</th><th>Nombre</th><th>SiteName</th><th>Fechas</th><th></th></tr>
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
                  <button class="btn secondary" type="submit">Usar</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </table>
      </div>
    <?php endif; ?>
  </div>
<?php else: ?>
  <?php if ($mappedSlug): ?>
    <div class="card" style="margin-top:12px;">
      <h3 style="margin:0 0 4px;">Evento mapeado</h3>
      <p class="muted" style="margin:0;">Slug STR → SenForms: <strong><?php echo e($mappedSlug); ?></strong></p>
    </div>
  <?php endif; ?>
<?php endif; ?>

<?php if ($selectedEvent): ?>
  <?php
    $types = sf_get_ticket_types_with_sales($selectedEvent['Id']);
    $stateMap = get_local_tickettype_states($pdoLocal, $selectedEvent['Id']);
    $cacheSummary = sf_cache_summary($pdoLocal, $selectedEvent['Id']);
    $ventasReales = sf_sales_report_by_type($selectedEvent['Id']);
  ?>
  <div class="card" style="margin-top:12px;">
    <h3 style="margin-top:0;">Evento: <?php echo e($selectedEvent['Name']); ?> (Id <?php echo (int)$selectedEvent['Id']; ?>)</h3>
    <div class="muted" style="font-size:12px;">SiteName: <?php echo e($selectedEvent['SiteName']); ?> | Límite: <?php echo (int)$selectedEvent['TicketAmountLimit']; ?> | Activo: <?php echo $selectedEvent['Active'] ? 'Sí' : 'No'; ?></div>
  </div>

  <div class="card" style="margin-top:12px;overflow:auto;">
    <h4 style="margin-top:0;">Tipos de entrada (Price inmutable si ventas&gt;0)</h4>
    <table class="table">
      <tr><th>ID</th><th>Nombre</th><th>Precio</th><th>Ventas</th><th>Activo en STR</th></tr>
      <?php foreach ($types as $t): ?>
        <?php $sales = isset($t['sales_count']) ? (int)$t['sales_count'] : 0; ?>
        <?php $isActive = array_key_exists((int)$t['Id'], $stateMap) ? $stateMap[(int)$t['Id']] : true; ?>
        <tr>
          <td><?php echo (int)$t['Id']; ?></td>
          <td>
            <form method="post" style="display:flex;gap:6px;align-items:center;">
              <input type="hidden" name="action" value="rename_ticket_type">
              <input type="hidden" name="ticket_type_id" value="<?php echo (int)$t['Id']; ?>">
              <input type="hidden" name="event_id" value="<?php echo (int)$selectedEvent['Id']; ?>">
              <input type="text" name="name" value="<?php echo e($t['Name']); ?>" style="width:180px;">
              <button class="btn secondary" type="submit">Renombrar</button>
            </form>
          </td>
          <td>
            <?php if ($sales > 0): ?>
              <div class="muted">$<?php echo htmlspecialchars($t['Price'], ENT_QUOTES, 'UTF-8'); ?> (bloqueado por ventas)</div>
              <div class="muted" style="font-size:12px;">Crea un tipo nuevo para cambiar precio.</div>
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
            <form method="post" style="margin:0;">
              <input type="hidden" name="action" value="toggle_active">
              <input type="hidden" name="ticket_type_id" value="<?php echo (int)$t['Id']; ?>">
              <input type="hidden" name="event_id" value="<?php echo (int)$selectedEvent['Id']; ?>">
              <input type="hidden" name="is_active" value="<?php echo $isActive ? '0' : '1'; ?>">
              <button class="btn <?php echo $isActive ? 'secondary' : ''; ?>" type="submit"><?php echo $isActive ? 'Desactivar' : 'Activar'; ?></button>
              <div class="muted" style="font-size:11px;"><?php echo $isActive ? 'Visible en STR' : 'Oculto en STR'; ?></div>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
  </div>

  <div class="card" style="margin-top:12px;">
    <h4 style="margin:0 0 6px;">Crear nuevo tipo</h4>
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
        <button class="btn" type="submit">Crear y activar</button>
      </div>
    </form>
    <div class="muted" style="margin-top:6px;font-size:12px;">Nuevos tipos se activan en STR por defecto (tabla local).</div>
  </div>

  <div class="card" style="margin-top:12px;display:grid;gap:10px;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));align-items:center;">
    <div>
      <h4 style="margin:0 0 4px;">Importar tickets a cache local</h4>
      <p class="muted" style="margin:0;">Guarda Tickets (Price, SelectedType, PaymentState) en SQLite para reportes.</p>
      <div class="muted" style="font-size:12px;margin-top:4px;">Última sync: <?php echo $cacheSummary['last_sync'] ? e($cacheSummary['last_sync']) : '—'; ?> | Filas: <?php echo (int)$cacheSummary['rows']; ?> | Pagados: <?php echo (int)$cacheSummary['paid']; ?></div>
    </div>
    <form method="post" style="justify-self:end;">
      <input type="hidden" name="action" value="import_tickets">
      <input type="hidden" name="event_id" value="<?php echo (int)$selectedEvent['Id']; ?>">
      <button class="btn" type="submit">Importar ahora</button>
    </form>
  </div>

  <div class="card" style="margin-top:12px;overflow:auto;">
    <h4 style="margin-top:0;">Ventas reales por SelectedType (Tickets.Price)</h4>
    <?php if ($ventasReales): ?>
      <table class="table">
        <tr><th>SelectedType</th><th>Nombre (opcional)</th><th>Cantidad</th><th>Total real</th></tr>
        <?php $sumTotal = 0; ?>
        <?php foreach ($ventasReales as $r): ?>
          <?php $sumTotal += (float)$r['total_real']; ?>
          <tr>
            <td><?php echo e($r['selected_type']); ?></td>
            <td><?php echo e($r['ticket_type_name'] ?? ''); ?></td>
            <td><?php echo (int)$r['qty']; ?></td>
            <td>$<?php echo number_format((float)$r['total_real'], 2, '.', ','); ?></td>
          </tr>
        <?php endforeach; ?>
        <tr>
          <td colspan="3" style="text-align:right;font-weight:bold;">Total</td>
          <td style="font-weight:bold;">$<?php echo number_format($sumTotal, 2, '.', ','); ?></td>
        </tr>
      </table>
    <?php else: ?>
      <div class="muted">Sin ventas pagadas registradas.</div>
    <?php endif; ?>
  </div>
<?php else: ?>
  <div class="card" style="margin-top:12px;">
    <p class="muted" style="margin:0;">Selecciona un evento para gestionar su catálogo.</p>
  </div>
<?php endif; ?>

<div class="card" style="margin-top:12px;">
  <h4 style="margin:0 0 6px;">Recordatorio</h4>
  <ul style="margin:0 0 0 16px; padding-left:12px;">
    <li>No edites precios históricos: crea un TicketType nuevo.</li>
    <li>Activar/desactivar se controla en STR (local_tickettype_state).</li>
    <li>Reportes usan SUM(Tickets.Price) agrupado por SelectedType.</li>
  </ul>
</div>

<?php include __DIR__.'/inc/layout_bottom.php'; ?>
