<?php
require_once __DIR__.'/inc/bootstrap.php';
require_once __DIR__.'/inc/unified_tickets.php';

$cu = current_user();
$rol = isset($cu['tipo_global']) && $cu['tipo_global'] !== ''
  ? $cu['tipo_global']
  : (isset($cu['rol']) ? $cu['rol'] : (isset($_SESSION['tipo_global']) ? $_SESSION['tipo_global'] : ''));

$adminContext = isset($_SESSION['auth_context']) && $_SESSION['auth_context'] === 'admin';
if (!$adminContext || !in_array($rol, array('admin_evento','super_admin','superadmin'), true)) {
    header('Location: /login_admin.php?next=' . urlencode($_SERVER['REQUEST_URI']), true, 302);
    exit;
}

$title = 'Mis clientes';
$q      = isset($_GET['q']) ? trim($_GET['q']) : '';
$sort   = isset($_GET['sort']) ? $_GET['sort'] : 'recent'; // recent | az
$group  = isset($_GET['group']) ? $_GET['group'] : 'all';   // all | registered | guests
$contact = isset($_GET['contact']) ? $_GET['contact'] : 'all'; // all | email | whatsapp
$export = isset($_GET['export']) ? $_GET['export'] : '';

$pdo = db();

// ------------------------------------------------------------------
// Datos auxiliares: usuarios registrados y eventos
// ------------------------------------------------------------------
$registeredByEmail = array();
try {
    $resUsers = $pdo->query("SELECT id, nombre, apellido, email, rol, creado_en FROM usuarios");
    $userRows = $resUsers ? $resUsers->fetchAll(PDO::FETCH_ASSOC) : array();
    foreach ($userRows as $u) {
        if (!empty($u['email'])) {
            $key = strtolower(trim($u['email']));
            $registeredByEmail[$key] = $u;
        }
    }
} catch (Exception $e) {
    // Usuarios no disponibles, continuar sin marcar registrados
}

$eventNames = array();
$eventSlugs = array();
$eventIds   = array();
try {
    $evRows = tickex_visible_events($pdo, $cu);
    foreach ($evRows as $ev) {
        $id = isset($ev['id']) ? (int)$ev['id'] : 0;
        if ($id > 0) {
            $eventIds[] = $id;
            $eventNames[$id] = isset($ev['nombre']) ? $ev['nombre'] : ('Evento ' . $id);
        }
        if (!empty($ev['slug'])) {
            $eventSlugs[strtolower($ev['slug'])] = $id;
        }
    }
} catch (Exception $e) {
    // no bloquear si tabla eventos no existe
}

$eventIds = array_values(array_unique($eventIds));

// ------------------------------------------------------------------
// Acumulador de clientes
// ------------------------------------------------------------------
$clientes = array();
$anonCounter = 1;

function agregar_cliente(&$clientes, &$anonCounter, $entry, $eventId, $eventLabel, $registeredByEmail)
{
    $email = isset($entry['email']) ? trim($entry['email']) : '';
    $nombre = isset($entry['nombre']) ? trim($entry['nombre']) : '';
    $createdRaw = isset($entry['created_at']) ? $entry['created_at'] : null;
    $createdTs = $createdRaw ? strtotime($createdRaw) : 0;
    $source = isset($entry['source']) ? $entry['source'] : 'STR';

    // Telefono/whatsapp si existiera en raw_row
    $telefono = '';
    if (isset($entry['raw_row']) && is_array($entry['raw_row'])) {
        $rr = $entry['raw_row'];
        foreach (array('telefono','tel','phone','whatsapp','celular','mobile') as $tc) {
            if (isset($rr[$tc]) && trim((string)$rr[$tc]) !== '') {
                $telefono = trim((string)$rr[$tc]);
                break;
            }
        }
        // si el raw_row trae fecha_registro / fecha
        if ($createdTs === 0) {
            foreach (array('fecha_registro','fecha','created_at','creado_en') as $fc) {
                if (isset($rr[$fc]) && trim((string)$rr[$fc]) !== '') {
                    $createdTs = strtotime($rr[$fc]);
                    $createdRaw = $rr[$fc];
                    break;
                }
            }
        }
    }

    // Key de agrupación: email > nombre > anon
    if ($email !== '') {
        $key = 'email:' . strtolower($email);
    } elseif ($nombre !== '') {
        $key = 'name:' . strtolower($nombre);
    } else {
        $key = 'anon:' . $anonCounter;
    }

    if (!isset($clientes[$key])) {
        $display = $nombre !== '' ? $nombre : ('Cliente sin datos #' . $anonCounter);
        if ($email !== '') {
            $display = $nombre !== '' ? $nombre : $email;
        }

        $isReg = ($email !== '' && isset($registeredByEmail[strtolower($email)]));
        $userId = $isReg ? (int)$registeredByEmail[strtolower($email)]['id'] : null;

        $clientes[$key] = array(
            'display'      => $display,
            'nombre'       => $nombre,
            'email'        => $email,
            'telefono'     => $telefono,
            'is_reg'       => $isReg,
            'user_id'      => $userId,
            'tickets'      => 0,
            'events'       => array(),
            'last_ts'      => 0,
            'last_raw'     => '',
            'sources'      => array(),
        );

        if ($key === 'anon:' . $anonCounter && $email === '' && $nombre === '') {
            $anonCounter++;
        }
    }

    $cli =& $clientes[$key];
    $cli['tickets'] += 1;

    if ($telefono !== '' && $cli['telefono'] === '') {
        $cli['telefono'] = $telefono;
    }

    if (!in_array($eventLabel, $cli['events'], true)) {
        $cli['events'][] = $eventLabel;
    }

    if (!in_array($source, $cli['sources'], true)) {
        $cli['sources'][] = $source;
    }

    if ($createdTs > $cli['last_ts']) {
        $cli['last_ts']  = $createdTs;
        $cli['last_raw'] = $createdRaw;
    }
}

// ------------------------------------------------------------------
// Recorrer eventos y entradas unificadas
// ------------------------------------------------------------------
foreach ($eventIds as $eid) {
    $entries = get_unified_entries($pdo, $eid);
    $eventLabel = isset($eventNames[$eid]) ? $eventNames[$eid] : ($eid ? ('Evento ' . $eid) : 'Evento');
    foreach ($entries as $en) {
        agregar_cliente($clientes, $anonCounter, $en, $eid, $eventLabel, $registeredByEmail);
    }
}

// ------------------------------------------------------------------
// Filtrar / ordenar
// ------------------------------------------------------------------
$clientesList = array_values($clientes);
$overviewTotal = count($clientesList);
$overviewRegistered = count(array_filter($clientesList, function($c){ return $c['is_reg']; }));
$overviewGuests = $overviewTotal - $overviewRegistered;
$overviewTickets = 0;
foreach ($clientesList as $overviewClient) $overviewTickets += (int)$overviewClient['tickets'];

if ($q !== '') {
    $qLower = mb_strtolower($q, 'UTF-8');
    $clientesList = array_filter($clientesList, function($c) use ($qLower) {
        $hay = array($c['display'], $c['email'], $c['telefono'], implode(' ', $c['events']));
        foreach ($hay as $h) {
            if ($h !== '' && mb_strpos(mb_strtolower($h, 'UTF-8'), $qLower) !== false) {
                return true;
            }
        }
        return false;
    });
}

if ($group === 'registered') {
    $clientesList = array_filter($clientesList, function($c){ return $c['is_reg']; });
} elseif ($group === 'guests') {
    $clientesList = array_filter($clientesList, function($c){ return !$c['is_reg']; });
}

if ($contact === 'email') {
  $clientesList = array_filter($clientesList, function($c){ return $c['email'] !== ''; });
} elseif ($contact === 'whatsapp') {
  $clientesList = array_filter($clientesList, function($c){ return $c['telefono'] !== ''; });
}

usort($clientesList, function($a, $b) use ($sort) {
    if ($sort === 'az') {
        return strcasecmp($a['display'], $b['display']);
    }
    // default: recent
    $ta = isset($a['last_ts']) ? (int)$a['last_ts'] : 0;
    $tb = isset($b['last_ts']) ? (int)$b['last_ts'] : 0;
    if ($ta === $tb) return 0;
    return ($ta > $tb) ? -1 : 1;
});

$totalClientes = count($clientesList);
$totalReg      = count(array_filter($clientesList, function($c){ return $c['is_reg']; }));
$totalGuests   = $totalClientes - $totalReg;

// ------------------------------------------------------------------
// Export CSV si se solicita
// ------------------------------------------------------------------
if ($export === 'csv' || $export === 'excel') {
  $isExcel = ($export === 'excel');
  $filename = $isExcel ? 'tickex-clientes-excel.csv' : 'tickex-clientes.csv';
  $delimiter = $isExcel ? ';' : ','; // Excel en ES suele preferir ;

  header('Content-Type: text/csv; charset=UTF-8');
  header('Content-Disposition: attachment; filename="' . $filename . '"');

  // BOM para que Excel detecte UTF-8
  echo "\xEF\xBB\xBF";

  $out = fopen('php://output', 'w');
  fputcsv($out, array('Nombre/Display','Email','WhatsApp','Registrado','Eventos','Entradas','Ultima compra'), $delimiter);
  foreach ($clientesList as $c) {
    $row = array(
      $c['display'],
      $c['email'],
      $c['telefono'],
      $c['is_reg'] ? 'Si' : 'No',
      implode(', ', $c['events']),
      (int)$c['tickets'],
      $c['last_raw'] !== '' ? $c['last_raw'] : ($c['last_ts'] ? date('Y-m-d H:i', $c['last_ts']) : ''),
    );
    fputcsv($out, $row, $delimiter);
  }
  fclose($out);
  exit;
}

include __DIR__.'/inc/layout_top.php';
?>
<?php include __DIR__.'/inc/client_directory_view.php'; ?>
<?php if (false): // Vista anterior mantenida como referencia temporal. ?>

<style>
  .tickex-clients-table {
    width: 100%;
    min-width: 780px;
    table-layout: fixed;
  }
  .tickex-clients-table th:nth-child(1) { width: 22%; }
  .tickex-clients-table th:nth-child(2) { width: 22%; }
  .tickex-clients-table th:nth-child(3) { width: 14%; }
  .tickex-clients-table th:nth-child(4) { width: 22%; }
  .tickex-clients-table th:nth-child(5) { width: 8%; }
  .tickex-clients-table th:nth-child(6) { width: 12%; }
  .tickex-client-name,
  .tickex-client-contact {
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
  .tickex-client-events {
    display: flex;
    align-items: center;
    gap: 7px;
    min-width: 0;
    max-width: 100%;
  }
  .tickex-client-event-name {
    display: block;
    min-width: 0;
    max-width: 100%;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    color: var(--text);
    font-weight: 650;
  }
  .tickex-client-event-count {
    flex: 0 0 auto;
    padding: 2px 7px;
    border: 1px solid var(--line);
    border-radius: 999px;
    background: var(--panel-2);
    color: var(--muted);
    font-size: 11px;
    font-weight: 750;
    line-height: 1.4;
  }
  @media (max-width: 760px) {
    .tickex-clients-table { min-width: 700px; }
    .tickex-clients-table th:nth-child(3),
    .tickex-clients-table td:nth-child(3) { display: none; }
  }
</style>

<div class="card" style="margin-top:16px;">
  <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between;">
    <div>
      <h2 style="margin:0;">Mis clientes</h2>
      <div style="color:var(--muted);margin-top:4px;">Lista unificada de compradores (registrados y puerta).</div>
    </div>
    <div style="display:flex;gap:8px;">
      <div class="pill">Total: <?php echo (int)$totalClientes; ?></div>
      <div class="pill">Registrados: <?php echo (int)$totalReg; ?></div>
      <div class="pill">Puerta/otros: <?php echo (int)$totalGuests; ?></div>
    </div>
  </div>
</div>

<div class="card" style="margin-top:12px;">
  <form method="get" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;">
    <div style="flex:1 1 240px;min-width:220px;">
      <label>Buscar</label>
      <input type="text" name="q" value="<?php echo e($q); ?>" placeholder="Nombre, email, evento...">
    </div>
    <div style="min-width:160px;">
      <label>Ordenar</label>
      <select name="sort">
        <option value="recent" <?php echo $sort==='recent'?'selected':''; ?>>Última compra</option>
        <option value="az" <?php echo $sort==='az'?'selected':''; ?>>A-Z</option>
      </select>
    </div>
    <div style="min-width:180px;">
      <label>Contacto</label>
      <select name="contact">
        <option value="all" <?php echo $contact==='all'?'selected':''; ?>>Todos</option>
        <option value="email" <?php echo $contact==='email'?'selected':''; ?>>Solo con email</option>
        <option value="whatsapp" <?php echo $contact==='whatsapp'?'selected':''; ?>>Solo con WhatsApp</option>
      </select>
    </div>
    <div style="min-width:160px;">
      <label>Grupo</label>
      <select name="group">
        <option value="all" <?php echo $group==='all'?'selected':''; ?>>Todos</option>
        <option value="registered" <?php echo $group==='registered'?'selected':''; ?>>Registrados</option>
        <option value="guests" <?php echo $group==='guests'?'selected':''; ?>>Puerta / no registrados</option>
      </select>
    </div>
    <div>
      <button class="btn" type="submit">Aplicar</button>
    </div>
    <div>
      <button class="btn secondary" type="submit" name="export" value="csv">Exportar CSV</button>
    </div>
    <div>
      <button class="btn secondary" type="submit" name="export" value="excel">Exportar Excel</button>
    </div>
  </form>
</div>

<div class="card" style="padding:0;overflow-x:auto;">
  <table class="table tickex-clients-table">
    <thead>
      <tr>
        <th>Cliente</th>
        <th>Email</th>
        <th>WhatsApp</th>
        <th>Eventos</th>
        <th>Entradas</th>
        <th>Última compra</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($clientesList)): ?>
        <tr><td colspan="6" style="text-align:center;color:var(--muted);padding:16px;">No hay resultados.</td></tr>
      <?php else: ?>
        <?php foreach ($clientesList as $c): ?>
          <tr>
            <td>
              <div style="display:flex;align-items:center;gap:8px;">
                <div class="tickex-client-name" style="font-weight:600;">
                  <?php echo e($c['display']); ?>
                </div>
                <?php if ($c['is_reg']): ?>
                  <span class="pill" style="background:var(--panel-2);border:1px solid var(--line);font-size:12px;">Registrado</span>
                <?php else: ?>
                  <span class="pill" style="background:var(--panel-2);border:1px solid var(--line);font-size:12px;">Puerta</span>
                <?php endif; ?>
              </div>
            </td>
            <td><div class="tickex-client-contact" title="<?php echo e($c['email']); ?>"><?php echo $c['email'] !== '' ? e($c['email']) : '<span style="color:var(--muted);">—</span>'; ?></div></td>
            <td><div class="tickex-client-contact" title="<?php echo e($c['telefono']); ?>"><?php echo $c['telefono'] !== '' ? e($c['telefono']) : '<span style="color:var(--muted);">—</span>'; ?></div></td>
            <td>
              <?php
                $clientEvents = array_values($c['events']);
                $clientEventCount = count($clientEvents);
                $clientEventsTitle = implode(' · ', $clientEvents);
              ?>
              <?php if ($clientEventCount > 0): ?>
                <div class="tickex-client-events" title="<?php echo e($clientEventsTitle); ?>">
                  <span class="tickex-client-event-name"><?php echo e($clientEvents[0]); ?></span>
                  <?php if ($clientEventCount > 1): ?>
                    <span class="tickex-client-event-count">+<?php echo (int)($clientEventCount - 1); ?></span>
                  <?php endif; ?>
                </div>
              <?php else: ?>
                <span style="color:var(--muted);">—</span>
              <?php endif; ?>
            </td>
            <td><?php echo (int)$c['tickets']; ?></td>
            <td>
              <?php
                if (!empty($c['last_raw'])) {
                    echo e($c['last_raw']);
                } elseif (!empty($c['last_ts'])) {
                    echo date('Y-m-d H:i', $c['last_ts']);
                } else {
                    echo '<span style="color:var(--muted);">—</span>';
                }
              ?>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
<?php include __DIR__.'/inc/layout_bottom.php'; ?>
