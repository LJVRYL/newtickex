<?php
require_once __DIR__.'/inc/bootstrap.php';
require_once __DIR__.'/inc/unified_tickets.php';
require_once __DIR__.'/inc/manual_income.php';
require_once __DIR__.'/inc/produccion.php';
require_once __DIR__.'/inc/venues.php';
require_once __DIR__.'/inc/senforms.php';

require_login();
$csrf = function_exists('tickex_csrf_token') ? tickex_csrf_token() : '';

// roles permitidos (compat con tu DB)
$cu = current_user();

// Priorizar tipo_global si existe; fallback a rol
$rol = isset($cu['tipo_global']) && $cu['tipo_global'] !== ''
  ? $cu['tipo_global']
  : (isset($cu['rol']) ? $cu['rol'] : (isset($_SESSION['tipo_global']) ? $_SESSION['tipo_global'] : ''));

if (!in_array($rol, array('admin_evento','super_admin','superadmin'), true)) {
    http_response_code(403);
    include __DIR__.'/inc/layout_top.php';
    echo "<div class='card error'><h2>Acceso denegado</h2><p>No tenés permiso para ver este panel.</p></div>";
    include __DIR__.'/inc/layout_bottom.php';
    exit;
}

$adminId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : (isset($cu['id'])?(int)$cu['id']:0);

// aceptar id o evento_id
$eventoId = 0;
if (isset($_GET['id'])) $eventoId = (int)$_GET['id'];
if (isset($_GET['evento_id'])) $eventoId = (int)$_GET['evento_id'];

$pdo = db();

// Columnas del evento (para ownership)
$colsEv = $pdo->query("PRAGMA table_info(eventos)")->fetchAll(PDO::FETCH_ASSOC);
$hasCreadoPor = false;
foreach ($colsEv as $c) {
  if (isset($c['name']) && $c['name'] === 'creado_por_admin_id') {
    $hasCreadoPor = true;
    break;
  }
}

// Columnas de entradas (check-in)
$colsEn = $pdo->query("PRAGMA table_info(entradas)")->fetchAll(PDO::FETCH_ASSOC);
$colCheck = 'checked_in';
foreach ($colsEn as $c) {
    if (isset($c['name']) && $c['name'] === 'checkin') {
        $colCheck = 'checkin';
        break;
    }
}

// Detectar si existe tipos_entrada y sus columnas de stock
$hasTipos = false; $hasCantDisp = false; $hasCantTotal = false;
$stmtTipos = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='tipos_entrada' LIMIT 1");
if ($stmtTipos && $stmtTipos->fetch(PDO::FETCH_ASSOC)) {
    $hasTipos = true;
    $colsTE = $pdo->query("PRAGMA table_info(tipos_entrada)")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($colsTE as $c) {
        if (isset($c['name']) && $c['name'] === 'cantidad_disponible') $hasCantDisp = true;
        if (isset($c['name']) && $c['name'] === 'cantidad_total') $hasCantTotal = true;
    }
}

// Stats por evento
function stats_evento($pdo, $eventoId, $colCheck, $hasTipos, $hasCantDisp, $hasCantTotal) {
  $out = array('total'=>0,'checkins'=>0,'faltan'=>0,'disponibles'=>null,'stock_total'=>null);

  $hiddenClause = build_hidden_entries_where_clause($pdo, $colCheck);

  $sqlTotal = "SELECT COUNT(*) FROM entradas WHERE evento_id = ?" . $hiddenClause;
  $stmtT = $pdo->prepare($sqlTotal);
  $stmtT->execute(array($eventoId));
  $out['total'] = (int)$stmtT->fetchColumn();

  $sqlCheck = "SELECT COUNT(*) FROM entradas WHERE evento_id = ? AND $colCheck = 1" . $hiddenClause;
  $stmtC = $pdo->prepare($sqlCheck);
  $stmtC->execute(array($eventoId));
  $out['checkins'] = (int)$stmtC->fetchColumn();
  $out['faltan'] = max(0, $out['total'] - $out['checkins']);

  if ($hasTipos) {
    $cols = array();
    if ($hasCantDisp) $cols[] = 'SUM(cantidad_disponible) AS disp';
    if ($hasCantTotal) $cols[] = 'SUM(cantidad_total) AS tot';
    if ($cols) {
      $sql = "SELECT ".implode(',', $cols)." FROM tipos_entrada WHERE evento_id = ?";
      $st = $pdo->prepare($sql);
      $st->execute(array($eventoId));
      $row = $st->fetch(PDO::FETCH_ASSOC);
      if ($row) {
        if ($hasCantDisp) $out['disponibles'] = isset($row['disp']) ? (int)$row['disp'] : null;
        if ($hasCantTotal) $out['stock_total'] = isset($row['tot']) ? (int)$row['tot'] : null;
      }
    }
  }
  return $out;
}

function get_staff_cost_by_event($pdo, $eventoId) {
  try {
    $stSe = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='staff_eventos' LIMIT 1");
    if ($stSe && $stSe->fetch(PDO::FETCH_ASSOC)) {
      $colsSe = array();
      $stCols = $pdo->query("PRAGMA table_info(staff_eventos)");
      if ($stCols) {
        foreach ($stCols->fetchAll(PDO::FETCH_ASSOC) as $ci) {
          if (isset($ci['name'])) $colsSe[$ci['name']] = true;
        }
      }
      if (isset($colsSe['costo_servicio'])) {
        $stmtSe = $pdo->prepare("SELECT COUNT(*) AS cnt, SUM(COALESCE(costo_servicio,0)) AS total FROM staff_eventos WHERE evento_id = :eid");
        $stmtSe->execute(array(':eid' => $eventoId));
        $rowSe = $stmtSe->fetch(PDO::FETCH_ASSOC);
        $cntSe = isset($rowSe['cnt']) ? (int)$rowSe['cnt'] : 0;
        $sumSe = isset($rowSe['total']) ? (float)$rowSe['total'] : 0;
        if ($cntSe > 0) return $sumSe;
      }
    }

    $cols = array();
    $st = $pdo->query("PRAGMA table_info(usuarios_admin)");
    if ($st) {
      foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $ci) { $cols[$ci['name']] = true; }
    }
    if (!isset($cols['costo_servicio'])) return 0;
    $stmt = $pdo->prepare("SELECT SUM(COALESCE(costo_servicio,0)) FROM usuarios_admin WHERE tipo_global='staff_evento' AND evento_id = :eid");
    $stmt->execute(array(':eid'=>$eventoId));
    $val = $stmt->fetchColumn();
    return $val ? (float)$val : 0;
  } catch (Exception $e) {
    return 0;
  }
}

// Lista de eventos (vista general)
$qList = ($eventoId <= 0 && isset($_GET['q'])) ? trim($_GET['q']) : '';

if ($rol === 'super_admin' || $rol === 'superadmin') {
  if ($qList === '') {
    $stmtList = $pdo->query("SELECT * FROM eventos ORDER BY id DESC");
  } else {
    $stmtList = $pdo->prepare("SELECT * FROM eventos WHERE nombre LIKE :q OR slug LIKE :q ORDER BY id DESC");
    $stmtList->execute(array(':q' => '%'.$qList.'%'));
  }
  $eventos = $stmtList ? $stmtList->fetchAll(PDO::FETCH_ASSOC) : array();
} elseif ($hasCreadoPor) {
  if ($qList === '') {
    $stmtList = $pdo->prepare("SELECT * FROM eventos WHERE creado_por_admin_id = :aid ORDER BY id DESC");
    $stmtList->execute(array(':aid' => $adminId));
  } else {
    $stmtList = $pdo->prepare("SELECT * FROM eventos WHERE creado_por_admin_id = :aid AND (nombre LIKE :q OR slug LIKE :q) ORDER BY id DESC");
    $stmtList->execute(array(':aid' => $adminId, ':q' => '%'.$qList.'%'));
  }
  $eventos = $stmtList->fetchAll(PDO::FETCH_ASSOC);
} else {
  if ($qList === '') {
    $stmtList = $pdo->query("SELECT * FROM eventos ORDER BY id DESC");
  } else {
    $stmtList = $pdo->prepare("SELECT * FROM eventos WHERE nombre LIKE :q OR slug LIKE :q ORDER BY id DESC");
    $stmtList->execute(array(':q' => '%'.$qList.'%'));
  }
  $eventos = $stmtList ? $stmtList->fetchAll(PDO::FETCH_ASSOC) : array();
}

// contadores de eventos
$hasFechaDesde = false; $hasFechaHasta = false;
foreach ($colsEv as $c) {
    if ($c['name'] === 'fecha_desde') $hasFechaDesde = true;
    if ($c['name'] === 'fecha_hasta') $hasFechaHasta = true;
}

$eventosCreados = 0; $eventosActivos = 0; $eventosTotal = count($eventos);
$now = time();
foreach ($eventos as $ev) {
    if ($hasCreadoPor && isset($ev['creado_por_admin_id']) && (int)$ev['creado_por_admin_id'] === $adminId) {
        $eventosCreados++;
    }

    if ($hasFechaDesde || $hasFechaHasta) {
        $fd = ($hasFechaDesde && isset($ev['fecha_desde'])) ? trim((string)$ev['fecha_desde']) : '';
        $fh = ($hasFechaHasta && isset($ev['fecha_hasta'])) ? trim((string)$ev['fecha_hasta']) : '';
        $fdTs = $fd !== '' ? strtotime($fd) : false;
        $fhTs = $fh !== '' ? strtotime($fh) : false;
        $isActive = false;
        if ($fhTs !== false) {
            $isActive = ($fhTs >= $now);
        } elseif ($fdTs !== false) {
            $isActive = ($fdTs >= $now) || ($fdTs <= $now);
        } else {
            $isActive = true; // sin fechas, lo consideramos activo
        }
        if ($isActive) $eventosActivos++;
    } else {
        $eventosActivos++;
    }
}
if (!$hasCreadoPor) {
    $eventosCreados = $eventosTotal; // fallback si no existe la columna
}

// Vista: listado de cards
if ($eventoId <= 0) {
    $title = 'Mis eventos';
    include __DIR__.'/inc/layout_top.php';
    ?>
    <div class="card" style="max-width:1100px;margin:16px auto;">
      <h1 style="margin-top:0;">Mis eventos</h1>
      <p class="muted" style="margin:6px 0 0 0;">Vista rápida de tus eventos con ventas y check-ins.</p>
    </div>

    <div class="card" style="max-width:1100px;margin:16px auto;">
      <div style="display:flex;gap:12px;flex-wrap:wrap;">
        <div class="card" style="flex:1 1 200px;min-width:200px;">
          <div class="muted">Eventos creados</div>
          <div style="font-size:24px;font-weight:700;">
            <?php echo (int)$eventosCreados; ?>
          </div>
        </div>
        <div class="card" style="flex:1 1 200px;min-width:200px;">
          <div class="muted">Eventos activos</div>
          <div style="font-size:24px;font-weight:700;">
            <?php echo (int)$eventosActivos; ?>
          </div>
        </div>
        <div class="card" style="flex:1 1 200px;min-width:200px;">
          <div class="muted">Total eventos</div>
          <div style="font-size:24px;font-weight:700;">
            <?php echo (int)$eventosTotal; ?>
          </div>
        </div>
      </div>
    </div>

    <div class="card" style="max-width:1100px;margin:16px auto;">
      <form method="get" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
        <label for="q" class="muted" style="margin:0;">Buscar eventos</label>
        <input type="text" id="q" name="q" value="<?php echo e($qList); ?>" placeholder="nombre o slug" style="flex:1 1 240px;">
        <button class="btn" type="submit">Buscar</button>
        <?php if ($qList !== ''): ?>
          <a class="btn secondary" href="panel_evento.php">Limpiar</a>
        <?php endif; ?>
      </form>
    </div>

    <div class="card" style="max-width:1100px;margin:16px auto;">
      <?php if (empty($eventos)): ?>
        <div class="muted">No se encontraron eventos.</div>
      <?php else: ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px;">
          <?php foreach ($eventos as $ev): ?>
            <?php $st = stats_evento($pdo, (int)$ev['id'], $colCheck, $hasTipos, $hasCantDisp, $hasCantTotal); ?>
            <div class="card" style="margin:0;">
              <div style="display:flex;gap:12px;">
                <div style="width:80px;height:80px;border:1px solid var(--line);border-radius:8px;overflow:hidden;background:#000;flex-shrink:0;display:flex;align-items:center;justify-content:center;">
                  <?php $fly = isset($ev['flyer_filename']) ? $ev['flyer_filename'] : ''; ?>
                  <?php if ($fly && file_exists(__DIR__ . '/' . $fly)): ?>
                    <img src="<?php echo e($fly); ?>" alt="Flyer" style="width:100%;height:100%;object-fit:cover;">
                  <?php else: ?>
                    <span class="muted" style="font-size:12px;">Sin flyer</span>
                  <?php endif; ?>
                </div>
                <div style="flex:1 1 auto;min-width:0;">
                  <div style="font-weight:700;"><?php echo e($ev['nombre']); ?></div>
                  <div class="muted" style="font-size:12px;">Slug: <?php echo e($ev['slug']); ?></div>
                  <div class="muted" style="font-size:12px;margin-top:4px;">
                    Fecha: <?php
                      $fd = isset($ev['fecha_desde']) ? $ev['fecha_desde'] : '';
                      $fh = isset($ev['fecha_hasta']) ? $ev['fecha_hasta'] : '';
                      if ($fd === '' && $fh === '') {
                        echo 'Sin fecha';
                      } else {
                        echo e($fd);
                        if ($fh !== '') echo ' → '.e($fh);
                      }
                    ?>
                  </div>
                </div>
              </div>

              <div style="margin-top:10px;font-size:13px;display:grid;grid-template-columns:repeat(3,1fr);gap:8px;">
                <div><span class="muted">Vendidas</span><br><strong><?php echo (int)$st['total']; ?></strong></div>
                <div><span class="muted">Check-ins</span><br><strong><?php echo (int)$st['checkins']; ?></strong></div>
                <div><span class="muted">Faltan</span><br><strong><?php echo (int)$st['faltan']; ?></strong></div>
                <div><span class="muted">Disponibles</span><br><strong><?php echo ($st['disponibles'] !== null ? (int)$st['disponibles'] : '-'); ?></strong></div>
                <div><span class="muted">Stock total</span><br><strong><?php echo ($st['stock_total'] !== null ? (int)$st['stock_total'] : '-'); ?></strong></div>
              </div>

              <div style="display:flex;gap:8px;margin-top:12px;flex-wrap:wrap;">
                <a class="btn secondary" style="display:inline-flex;align-items:center;gap:6px;" href="editar_evento.php?id=<?php echo (int)$ev['id']; ?>">✏ Editar</a>
                <a class="btn danger" style="display:inline-flex;align-items:center;gap:6px;" href="eliminar_evento.php?id=<?php echo (int)$ev['id']; ?>" onclick="return confirm('¿Seguro que querés eliminar este evento?');">🗑 Borrar</a>
                <a class="btn" style="display:inline-flex;align-items:center;gap:6px;" href="panel_evento.php?evento_id=<?php echo (int)$ev['id']; ?>">Ver panel</a>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
    <?php
    include __DIR__.'/inc/layout_bottom.php';
    exit;
}
$stmtEv = $pdo->prepare("SELECT * FROM eventos WHERE id=:id");
$stmtEv->execute(array(':id'=>$eventoId));
$evento = $stmtEv->fetch(PDO::FETCH_ASSOC);

if (!$evento) {
  abort_404("Evento no encontrado.");
}

// admin_evento: solo su evento creado por él (si la columna existe)
if ($rol === 'admin_evento' && $hasCreadoPor) {
  $creador = isset($evento['creado_por_admin_id']) ? (int)$evento['creado_por_admin_id'] : 0;
  if ($creador !== $adminId) {
    http_response_code(403);
    include __DIR__.'/inc/layout_top.php';
    echo "<div class='card error'><h2>Evento no autorizado</h2><p>No tenés permiso para este evento.</p></div>";
    include __DIR__.'/inc/layout_bottom.php';
    exit;
  }
}

// filtros
$q       = isset($_GET['q'])      ? trim($_GET['q'])       : '';
$fTipo   = isset($_GET['tipo'])   ? trim($_GET['tipo'])    : '';
$fEstado = isset($_GET['estado']) ? trim($_GET['estado'])  : '';

// Obtener entradas unificadas (STR + TICKEX)
$filters = array(
    'q'      => $q,
    'tipo'   => $fTipo,
    'estado' => $fEstado,
);
$rows = get_unified_entries($pdo, $eventoId, $filters);

// Producción: asignaciones
$produArtistas = get_produccion_artistas($pdo);
$produAssigns = get_produccion_assignments($pdo, $eventoId);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_artist_assign') {
  $artistaId = isset($_POST['artista_id']) ? (int)$_POST['artista_id'] : 0;
  $precio    = isset($_POST['precio']) && $_POST['precio'] !== '' ? (float)$_POST['precio'] : null;
  $notasA    = trim(isset($_POST['notas_asignacion']) ? $_POST['notas_asignacion'] : '');
  if ($artistaId <= 0) {
    flash('warn','Elegí un artista.');
  } else {
    $ok = add_produccion_assignment($pdo, $eventoId, $artistaId, $precio, $notasA);
    flash($ok ? 'ok' : 'err', $ok ? 'Artista asignado al evento.' : 'No se pudo asignar artista.');
    header('Location: panel_evento.php?evento_id='.(int)$eventoId);
    exit;
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'del_artist_assign') {
  $assignId = isset($_POST['assign_id']) ? (int)$_POST['assign_id'] : 0;
  if ($assignId > 0) {
    $ok = delete_produccion_assignment($pdo, $assignId, $eventoId);
    flash($ok ? 'ok' : 'err', $ok ? 'Artista removido del evento.' : 'No se pudo eliminar.');
  }
  header('Location: panel_evento.php?evento_id='.(int)$eventoId);
  exit;
}

// estadísticas
$stats = count_unified_entries($rows);
$total     = $stats['total'];
$checkins  = $stats['checkins'];
$faltan    = $stats['pendiente'];
$paid      = $stats['paid'];

// métricas globales del evento (no filtradas) - UNIFICADAS (STR + TICKEX)
$stEvento = get_unified_stats($pdo, $eventoId);
$staffCostEvent = get_staff_cost_by_event($pdo, $eventoId);
$artistCostEvent = get_artist_cost_by_event($pdo, $eventoId);
$venueCostEvent = get_venue_cost_by_event($pdo, $eventoId);
$gratis = 0;
if (!empty($rows)) {
  foreach ($rows as $r) {
    if (empty($r['is_paid'])) $gratis++;
  }
} else {
  $gratis = max(0, (int)$stEvento['total'] - (int)$stEvento['paid']);
}

$title = "Panel del Evento – " . (isset($evento['nombre']) ? $evento['nombre'] : 'Evento');
include __DIR__.'/inc/layout_top.php';
?>

<style>
  .pe-wrap{max-width:1100px;margin:0 auto}
  .pe-table-wrap{overflow:auto;margin-top:10px}
  .pe-table-wrap .table{min-width:860px}
  .pe-action-btn{min-width:160px;justify-content:center;background:var(--panel-2);color:var(--text);border:1px solid var(--line);box-shadow:none;font-size:13px;font-weight:600;padding:10px 12px;border-radius:10px;display:inline-flex;align-items:center;gap:6px}
  .pe-action-primary{min-width:160px;justify-content:center;background:var(--ok);color:#fff;border:1px solid var(--ok);box-shadow:none;font-size:13px;font-weight:700;padding:10px 12px;border-radius:10px;display:inline-flex;align-items:center;gap:6px}
  .pe-manual-form-grid .pe-submit-wrap{display:flex;align-items:flex-end}
  .pe-manual-form-grid .pe-submit-wrap .btn{width:100%}

  @media (max-width: 768px){
    .pe-wrap{max-width:100%}
    .pe-actions{display:grid !important;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px !important}
    .pe-actions .btn{min-width:0 !important;width:100% !important;font-size:12px !important;padding:8px 9px !important;line-height:1.2}
    .pe-actions .pe-action-primary{grid-column:1 / -1}

    .pe-kpis{display:grid !important;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px !important}
    .pe-kpis > .card{min-width:0 !important;padding:9px !important;margin:0 !important}
    .pe-kpis > .card .muted{font-size:11px !important;line-height:1.2}
    .pe-kpis > .card div[style*='font-size:22px']{font-size:16px !important;line-height:1.15}

    .pe-econ-grid{grid-template-columns:repeat(2,minmax(0,1fr)) !important;gap:8px !important;margin-bottom:12px !important}
    .pe-econ-grid .card{padding:9px !important}
    .pe-econ-grid .card .muted{font-size:11px !important;line-height:1.2}
    .pe-econ-grid .card div[style*='font-size:28px']{font-size:18px !important;line-height:1.15}
    .pe-econ-grid .card div[style*='font-size:24px']{font-size:17px !important;line-height:1.15}

    .pe-manual-form-grid{grid-template-columns:repeat(2,minmax(0,1fr)) !important;gap:8px !important;align-items:end !important}
    .pe-manual-form-grid > div{min-width:0}
    .pe-manual-form-grid > div:first-child{grid-column:1 / -1}
    .pe-manual-form-grid .pe-submit-wrap{grid-column:1 / -1}

    .pe-filter-grid{grid-template-columns:1fr !important;gap:8px !important}
    .pe-table-wrap .table{min-width:720px !important}
    .pe-table-wrap .table th,
    .pe-table-wrap .table td{padding:6px 6px !important;font-size:12px !important;line-height:1.25}
    .pe-table-wrap .btn-ref-toggle,
    .pe-table-wrap .btn-delete-entry{padding:4px 7px !important;font-size:11px !important}

    #ingresos-egresos .btn{font-size:12px !important;padding:8px 10px !important}

    #quickLoadFrame{min-height:420px !important}
  }

  @media (max-width: 480px){
    .pe-kpis{grid-template-columns:repeat(3,minmax(0,1fr)) !important}
    .pe-econ-grid{grid-template-columns:repeat(2,minmax(0,1fr)) !important}
  }
</style>

<div class="pe-wrap">

<div class="card">
  <div style="display:flex;flex-wrap:wrap;gap:16px;align-items:flex-start;justify-content:space-between;">
    <div style="flex:1 1 320px;min-width:260px;">
      <div class="muted" style="letter-spacing:0.06em;font-size:12px;text-transform:uppercase;">Evento</div>
      <h2 style="margin:4px 0 8px;"><?php echo e($evento['nombre']); ?></h2>
      <?php
        // Mostrar mapping actual hacia bridge (si existe)
        $currentMap = get_mapped_bridge_slugs($pdo, $eventoId);
      ?>
      <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:4px;">
        <span style="padding:6px 10px;border-radius:999px;border:1px solid var(--line);background:var(--panel-2);font-size:12px;">Slug: <strong><?php echo e($evento['slug']); ?></strong></span>
        <span style="padding:6px 10px;border-radius:999px;border:1px solid var(--line);background:var(--panel-2);font-size:12px;display:inline-flex;gap:6px;align-items:center;">
          <span>Bridge:</span>
          <?php if (!empty($currentMap)): ?>
            <strong><?php echo e(implode(', ', $currentMap)); ?></strong>
          <?php else: ?>
            <span class="muted">(no mapeado)</span>
          <?php endif; ?>
        </span>
      </div>
    </div>

  </div>

  <div class="pe-actions" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:12px;justify-content:flex-start;">
    <button class="btn pe-action-primary" id="toggleQuickLoad">+ Cargar entrada</button>
    <a class="btn pe-action-btn" href="configurar_entradas_evento.php?id=<?php echo (int)$eventoId; ?>">Entradas disponibles</a>
    <a class="btn pe-action-btn" href="secundarios.php?evento_id=<?php echo (int)$eventoId; ?>">Asignar staff</a>
    <a class="btn pe-action-btn" href="produccion.php?evento_id=<?php echo (int)$eventoId; ?>" target="_blank" rel="noopener">Asignar artística</a>
    <a class="btn pe-action-btn" href="venues.php?evento_id=<?php echo (int)$eventoId; ?>">Venue</a>
    <a class="btn pe-action-btn" href="editar_evento.php?id=<?php echo (int)$eventoId; ?>">Editar evento</a>
    <a class="btn danger" href="eliminar_evento.php?id=<?php echo (int)$eventoId; ?>&csrf=<?php echo urlencode($csrf); ?>" onclick="return confirm('¿Seguro que querés eliminar este evento?');">🗑 Eliminar</a>
    <a class="btn pe-action-btn" href="panel_evento.php" title="Volver">
      ⬅ Volver
    </a>
  </div>

  <div id="quickLoadPanel" style="display:none;margin-top:14px;padding:12px;border:1px dashed var(--line);border-radius:12px;background:var(--panel-2);">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:8px;">
      <div style="font-weight:700;">Carga rápida de entradas</div>
      <a class="link" href="cargar_entrada.php?evento_id=<?php echo (int)$eventoId; ?>&embed=1" target="_blank" rel="noopener">Abrir en otra pestaña</a>
    </div>
    <iframe id="quickLoadFrame" title="Cargar entrada" src="" style="width:100%;min-height:540px;border:1px solid var(--line);border-radius:10px;background:#000;" loading="lazy"></iframe>
  </div>
</div>

<div class="card pe-kpis" style="display:flex;gap:12px;flex-wrap:wrap;">
  <div class="card" style="flex:1 1 180px;min-width:180px;">
    <div class="muted">Emitidas</div>
    <div style="font-size:22px;font-weight:700;">
      <?php echo (int)$stEvento['total']; ?>
    </div>
  </div>
  <div class="card" style="flex:1 1 180px;min-width:180px;">
    <div class="muted">Pagadas</div>
    <div style="font-size:22px;font-weight:700;">
      <?php echo (int)$stEvento['paid']; ?>
    </div>
  </div>
  <div class="card" style="flex:1 1 180px;min-width:180px;">
    <div class="muted">Gratis</div>
    <div style="font-size:22px;font-weight:700;">
      <?php echo $gratis; ?>
    </div>
  </div>
  <div class="card" style="flex:1 1 180px;min-width:180px;">
    <div class="muted">Disponibles</div>
    <div style="font-size:22px;font-weight:700;">
      <?php echo ($stEvento['disponibles'] !== null ? (int)$stEvento['disponibles'] : '-'); ?>
    </div>
  </div>
  <div class="card" style="flex:1 1 180px;min-width:180px;">
    <div class="muted">Sin escanear</div>
    <div style="font-size:22px;font-weight:700;">
      <?php echo (int)$stEvento['pendiente']; ?>
    </div>
  </div>
  <div class="card" style="flex:1 1 180px;min-width:180px;">
    <div class="muted">Check-ins</div>
    <div style="font-size:22px;font-weight:700;">
      <?php echo (int)$stEvento['checkins']; ?>
    </div>
  </div>
  <div class="card" style="flex:1 1 180px;min-width:180px;">
    <div class="muted">Stock Total</div>
    <div style="font-size:22px;font-weight:700;">
      <?php echo ($stEvento['stock_total'] !== null ? (int)$stEvento['stock_total'] : '-'); ?>
    </div>
  </div>
</div>

<!-- === ESTADÍSTICAS ECONÓMICAS === -->
<div class="card">
  <h3>Economía del evento</h3>
  
  <?php 
    // Obtener estadísticas económicas
    $ecoStats = get_economic_stats($pdo, $eventoId);
  ?>
  
  <div class="pe-econ-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin-bottom:20px;">
    <div class="card" style="margin:0;background:var(--panel-2);">
      <div class="muted" style="font-size:12px;">Entradas vendidas</div>
      <div style="font-size:28px;font-weight:700;margin-top:4px;">
        <?php echo (int)$ecoStats['entradas_vendidas']; ?>
      </div>
    </div>
    
    <div class="card" style="margin:0;background:var(--panel-2);">
      <div class="muted" style="font-size:12px;">Total recaudado</div>
      <div style="font-size:28px;font-weight:700;margin-top:4px;color:var(--ok);">
        $<?php echo number_format($ecoStats['total_recaudado'], 2); ?>
      </div>
    </div>

    <div class="card" style="margin:0;background:var(--panel-2);">
      <div class="muted" style="font-size:12px;">Costo servicio bridge (3%)</div>
      <div style="font-size:24px;font-weight:700;margin-top:4px;color:var(--warn);">
        $<?php echo number_format((float)($ecoStats['bridge_fee_3pct'] ?? 0), 2); ?>
      </div>
    </div>

    <div class="card" style="margin:0;background:var(--panel-2);">
      <div class="muted" style="font-size:12px;">Costo staff asignado</div>
      <div style="font-size:24px;font-weight:700;margin-top:4px;color:var(--warn);">
        $<?php echo number_format($staffCostEvent, 2); ?>
      </div>
    </div>

    <div class="card" style="margin:0;background:var(--panel-2);">
      <div class="muted" style="font-size:12px;">Costo artística</div>
      <div style="font-size:24px;font-weight:700;margin-top:4px;color:var(--warn);">
        $<?php echo number_format($artistCostEvent, 2); ?>
      </div>
    </div>

    <div class="card" style="margin:0;background:var(--panel-2);">
      <div class="muted" style="font-size:12px;">Costo venue</div>
      <div style="font-size:24px;font-weight:700;margin-top:4px;color:var(--warn);">
        $<?php echo number_format($venueCostEvent, 2); ?>
      </div>
    </div>

    <?php if ($ecoStats['manual_income'] != 0): ?>
    <div class="card" style="margin:0;background:var(--panel-2);">
      <div class="muted" style="font-size:12px;">Manual (otros/varios)</div>
      <div style="font-size:28px;font-weight:700;margin-top:4px;color:<?php echo ($ecoStats['manual_income'] >= 0 ? 'var(--info)' : 'var(--warn)'); ?>;">
        $<?php echo number_format($ecoStats['manual_income'], 2); ?>
      </div>
    </div>
    <?php endif; ?>

    <?php $resultadoNeto = (float)$ecoStats['total_recaudado'] - (float)$staffCostEvent - (float)$artistCostEvent - (float)$venueCostEvent; ?>
    <div class="card" style="margin:0;background:var(--panel-2);">
      <div class="muted" style="font-size:12px;">Resultado neto</div>
      <div style="font-size:28px;font-weight:700;margin-top:4px;color:<?php echo ($resultadoNeto >= 0 ? 'var(--ok)' : 'var(--warn)'); ?>;">
        $<?php echo number_format($resultadoNeto, 2); ?>
      </div>
      <div class="muted" style="font-size:11px;margin-top:4px;">Recaudado - staff - artística - venue</div>
    </div>
  </div>
  
  <div style="margin-top:8px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
    <a class="btn pe-action-btn" href="economia_evento.php?evento_id=<?php echo (int)$eventoId; ?>">Desglose</a>
    <span class="muted">(detalle por tipo en página separada)</span>
  </div>
</div>

<!-- Se removió el bloque de artística en esta vista; se gestiona desde el botón "Asignar artística" -->

<!-- === INGRESOS / EGRESOS MANUALES === -->
<div class="card" id="ingresos-egresos">
  <h3>Ingresos/Egresos manuales (Otros/Varios)</h3>
  <p class="muted" style="font-size:13px;margin-bottom:12px;">Registra ventas puerta, consumos, ajustes u otros movimientos. Pueden ser ingresos o egresos.</p>

  <!-- Formulario para agregar ingreso -->
  <form id="formManualIncome" style="margin-bottom:16px;">
    <input type="hidden" name="evento_id" value="<?php echo (int)$eventoId; ?>">
    
    <div class="pe-manual-form-grid" style="display:grid;grid-template-columns:1.2fr 1fr 1fr 1fr auto;gap:8px;align-items:end;margin-bottom:10px;">
      <div>
        <label>Tipo</label>
        <select name="tipo">
          <option value="ingreso">Ingreso</option>
          <option value="egreso">Egreso</option>
        </select>
      </div>
      <div>
        <label>Concepto</label>
        <input type="text" name="concepto" value="Otros / Varios" placeholder="ej: Venta puerta, Consumo bar..." required>
      </div>
      
      <div>
        <label>Monto ($)</label>
        <input type="number" name="monto" placeholder="0.00" step="0.01" min="0" required>
      </div>
      
      <div>
        <label>Descripción (opcional)</label>
        <input type="text" name="descripcion" placeholder="detalles">
      </div>
      
      <div class="pe-submit-wrap">
        <button class="btn" type="submit">Agregar</button>
      </div>
    </div>
  </form>

  <!-- Lista de ingresos -->
  <?php 
    ensure_manual_income_table($pdo);
    $incomes = get_manual_incomes($pdo, $eventoId);
    $manualBreakdown = get_manual_income_breakdown($pdo, $eventoId);
    $totalIncome = $manualBreakdown['neto'];
  ?>
  
  <?php if (!empty($incomes)): ?>
    <div class="pe-table-wrap">
      <table class="table">
        <tr>
          <th>Concepto</th>
          <th>Tipo</th>
          <th>Descripción</th>
          <th>Monto</th>
          <th>Fecha</th>
          <th>Acción</th>
        </tr>
        <?php foreach ($incomes as $inc): ?>
        <?php $isEgreso = (isset($inc['tipo']) && $inc['tipo'] === 'egreso') || (isset($inc['monto']) && (float)$inc['monto'] < 0); ?>
        <tr>
          <td><?php echo e($inc['concepto']); ?></td>
          <td>
            <span class="pill" style="background:<?php echo $isEgreso ? 'var(--panel-3)' : 'var(--panel-2)'; ?>;color:<?php echo $isEgreso ? 'var(--warn)' : 'var(--ok)'; ?>;font-weight:700;font-size:11px;">
              <?php echo $isEgreso ? 'Egreso' : 'Ingreso'; ?>
            </span>
          </td>
          <td><?php echo e($inc['descripcion']); ?></td>
          <td style="text-align:right;font-weight:700;color:<?php echo $isEgreso ? 'var(--warn)' : 'var(--ok)'; ?>;">
            $<?php echo number_format((float)$inc['monto'], 2); ?>
          </td>
          <td style="font-size:12px;" class="muted">
            <?php 
              $ts = strtotime($inc['created_at']);
              echo $ts ? date('d/m/Y H:i', $ts) : $inc['created_at'];
            ?>
          </td>
          <td>
            <button class="btn-delete-income" data-id="<?php echo (int)$inc['id']; ?>" style="font-size:11px;" title="Eliminar">🗑</button>
          </td>
        </tr>
        <?php endforeach; ?>
      </table>
    </div>
    
    <?php 
      $totalIngresos = $manualBreakdown['ingresos']['total'];
      $totalEgresos = $manualBreakdown['egresos']['total'];
      $neto = $manualBreakdown['neto'];
    ?>
    <div style="margin-top:12px;padding:10px;background:var(--panel-2);border-radius:4px;display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:8px;align-items:center;">
      <div><strong>Ingresos manuales:</strong> $<?php echo number_format($totalIngresos, 2); ?></div>
      <div><strong>Egresos manuales:</strong> $<?php echo number_format(abs($totalEgresos), 2); ?></div>
      <div><strong>Balance neto:</strong> <span style="color:<?php echo $neto >= 0 ? 'var(--ok)' : 'var(--warn)'; ?>;">$<?php echo number_format($neto, 2); ?></span></div>
    </div>
  <?php else: ?>
    <div style="padding:10px;background:var(--panel-2);border-radius:4px;color:var(--muted);">
      No hay ingresos/egresos manuales registrados aún.
    </div>
  <?php endif; ?>
</div>
<!-- bloque ingresos movido arriba -->

<div class="card">
  <h3>Entradas del evento</h3>
  <form method="get" style="margin-top:10px;">
    <input type="hidden" name="id" value="<?php echo $eventoId; ?>">

    <div class="pe-filter-grid" style="display:grid;grid-template-columns:2fr 1fr 1fr auto;gap:8px;align-items:end;">
      <div>
        <label>Buscar</label>
        <input type="text" name="q" value="<?php echo e($q); ?>" placeholder="nombre / email / código">
      </div>

      <div>
        <label>Tipo</label>
        <input type="text" name="tipo" value="<?php echo e($fTipo); ?>" placeholder="VIP / General...">
      </div>

      <div>
        <label>Estado</label>
        <select name="estado">
          <option value="">Todos</option>
          <option value="checkin_ok" <?php if($fEstado==='checkin_ok') echo 'selected'; ?>>Checkeados</option>
          <option value="pendiente"  <?php if($fEstado==='pendiente')  echo 'selected'; ?>>Pendientes</option>
        </select>
      </div>

      <div>
        <button class="btn secondary" type="submit">Filtrar</button>
      </div>
    </div>
  </form>

  <div style="margin-top:12px;color:var(--muted);">
    Total: <?php echo $total; ?> — Check-ins: <?php echo $checkins; ?> — Pendientes: <?php echo $faltan; ?>
  </div>

  <?php if (!$rows): ?>
    <div class="card" style="background:var(--panel-2);margin-top:12px;">
      Todavía no hay entradas para este evento.
    </div>
  <?php else: ?>

  <div class="pe-table-wrap">
    <table class="table">
      <tr>
        <th>Origen</th>
        <th>ID</th>
        <th>Nombre</th>
        <th>Email</th>
        <th>Tipo</th>
        <th>Precio</th>
        <th>Código/Ref</th>
        <th>Pago</th>
        <th>Estado</th>
        <th>Acciones</th>
      </tr>

<?php foreach($rows as $r): ?>
      <tr>
        <td style="font-size:11px;font-weight:700;color:<?php echo ($r['source']==='STR') ? 'var(--ok)' : 'var(--info)'; ?>;">
          <?php echo e($r['source']); ?>
        </td>
        <td><?php echo (int)$r['ticket_id']; ?></td>
        <td><?php echo e($r['nombre']); ?></td>
        <td><?php echo e($r['email']); ?></td>
        <td><?php echo e($r['tipo']); ?></td>
        <td>
          <?php $p = isset($r['price']) ? (float)$r['price'] : 0; ?>
          <?php if ($p > 0): ?>
            $<?php echo number_format($p, 0, ',', '.'); ?>
          <?php else: ?>
            <span class="muted">-</span>
          <?php endif; ?>
        </td>
        <td>
          <?php $ref = isset($r['ticket_ref']) ? trim((string)$r['ticket_ref']) : ''; ?>
          <?php if ($ref === ''): ?>
            <span class="muted">-</span>
          <?php else: ?>
            <div class="ref-wrap" style="display:flex;gap:8px;align-items:center;">
              <button type="button" class="btn secondary btn-ref-toggle" style="padding:6px 10px;font-size:12px;line-height:1.2;">👁 Ver</button>
              <span class="ref-value" style="display:none;font-family:monospace;"><?php echo e($ref); ?></span>
            </div>
          <?php endif; ?>
        </td>
        <td>
          <?php if($r['is_paid']): ?>
            <span style="color:var(--ok);font-weight:700;">✓</span>
          <?php else: ?>
            <span style="color:var(--warn);font-weight:700;">✗</span>
          <?php endif; ?>
        </td>
        <td>
          <?php if($r['is_checked_in']): ?>
            <span style="color:var(--ok);font-weight:700;">OK</span>
          <?php else: ?>
            <span style="color:var(--warn);font-weight:700;">Pendiente</span>
          <?php endif; ?>
        </td>
        <td style="display:flex;gap:8px;align-items:center;">
          <?php if($r['source'] === 'STR'): ?>
            <a class="link" href="ticket.php?c=<?php echo urlencode($r['ticket_ref']); ?>">Ver ticket</a>
            <a class="btn secondary btn-delete-entry" style="padding:6px 10px;font-size:12px;line-height:1.2;" data-id="<?php echo (int)$r['ticket_id']; ?>" title="Eliminar">🗑</a>
          <?php else: ?>
            <span class="muted" style="font-size:11px;">Ver en Tickex</span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>

  <?php endif; ?>
</div>

<script>
const formIncome = document.getElementById('formManualIncome');
if (formIncome) {
  formIncome.addEventListener('submit', function(e) {
      e.preventDefault();
      const formData = new FormData(this);
      fetch('add_manual_income.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
            alert('Movimiento guardado');
                location.reload();
            } else {
                alert('Error: ' + (data.error || 'desconocido'));
            }
        })
        .catch(err => alert('Error: ' + err));
  });
}

document.querySelectorAll('.btn-delete-income').forEach(btn => {
    btn.addEventListener('click', function(e) {
        e.preventDefault();
        if (!confirm('¿Eliminar este movimiento?')) return;
        const incomeId = this.dataset.id;
        const formData = new FormData();
        formData.append('id', incomeId);
        fetch('delete_manual_income.php', { method: 'POST', body: formData })
          .then(r => r.json())
          .then(data => {
              if (data.success) {
                  alert('Ingreso eliminado');
                  location.reload();
              } else {
                  alert('Error: ' + (data.error || 'desconocido'));
              }
          })
          .catch(err => alert('Error: ' + err));
    });
});

// editar mapping bridge
const btnEditBridge = document.getElementById('btnEditBridgeMap');
if (btnEditBridge) {
  btnEditBridge.addEventListener('click', function(e) {
      e.preventDefault();
      const current = "<?php echo !empty($currentMap) ? htmlspecialchars(implode(',', $currentMap), ENT_QUOTES) : ''; ?>";
      const suggested = current || "";
      const slug = prompt('Ingrese bridge slug para este evento (ej: savetherave7-3)', suggested);
      if (slug === null) return;
      const s = slug.trim();
      if (s === '') { alert('Slug vacío'); return; }

      const fd = new FormData();
      fd.append('evento_id', '<?php echo (int)$eventoId; ?>');
      fd.append('bridge_slug', s);

      fetch('set_bridge_mapping.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
          if (data && data.success) {
            alert('Mapping guardado');
            location.reload();
          } else {
            alert('Error: ' + (data && data.error ? data.error : 'desconocido'));
          }
        })
        .catch(err => alert('Error: ' + err));
  });
}

// toggle ver código/ref
document.querySelectorAll('.btn-ref-toggle').forEach(btn => {
  btn.addEventListener('click', function() {
    const wrap = this.closest('.ref-wrap');
    const value = wrap ? wrap.querySelector('.ref-value') : null;
    if (!value) return;
    const isShown = value.style.display !== 'none';
    value.style.display = isShown ? 'none' : 'inline';
    this.textContent = isShown ? '👁' : 'Ocultar';
  });
});

// eliminar entrada STR
document.querySelectorAll('.btn-delete-entry').forEach(btn => {
  btn.addEventListener('click', function(e) {
    e.preventDefault();
    const id = this.dataset.id;
    if (!id) return;
    if (!confirm('¿Eliminar esta entrada?')) return;
    const eid = '<?php echo (int)$eventoId; ?>';
    const target = 'delete.php?id=' + encodeURIComponent(id) + '&evento_id=' + encodeURIComponent(eid);
    window.location.href = target;
  });
});

// toggle cargar entrada embebido
const quickBtn = document.getElementById('toggleQuickLoad');
const quickPanel = document.getElementById('quickLoadPanel');
const quickFrame = document.getElementById('quickLoadFrame');
if (quickBtn && quickPanel) {
  quickBtn.addEventListener('click', function(e) {
    e.preventDefault();
    const isOpen = quickPanel.style.display === 'block';
    quickPanel.style.display = isOpen ? 'none' : 'block';
    quickBtn.textContent = isOpen ? 'Cargar entrada' : 'Cerrar carga';
    if (!isOpen && quickFrame && !quickFrame.dataset.loaded) {
      quickFrame.src = 'cargar_entrada.php?evento_id=<?php echo (int)$eventoId; ?>&embed=1';
      quickFrame.dataset.loaded = '1';
    }
  });
}
</script>

</div>

<?php include __DIR__.'/inc/layout_bottom.php'; ?>
