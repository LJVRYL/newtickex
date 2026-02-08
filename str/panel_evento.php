<?php
require_once __DIR__.'/inc/bootstrap.php';

require_login();

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

    $stmtT = $pdo->prepare("SELECT COUNT(*) FROM entradas WHERE evento_id = ?");
    $stmtT->execute(array($eventoId));
    $out['total'] = (int)$stmtT->fetchColumn();

    $stmtC = $pdo->prepare("SELECT COUNT(*) FROM entradas WHERE evento_id = ? AND $colCheck = 1");
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
          <div style="font-size:24px;font-weight:700;"><?php echo (int)$eventosCreados; ?></div>
        </div>
        <div class="card" style="flex:1 1 200px;min-width:200px;">
          <div class="muted">Eventos activos</div>
          <div style="font-size:24px;font-weight:700;"><?php echo (int)$eventosActivos; ?></div>
        </div>
        <div class="card" style="flex:1 1 200px;min-width:200px;">
          <div class="muted">Total eventos</div>
          <div style="font-size:24px;font-weight:700;"><?php echo (int)$eventosTotal; ?></div>
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

// obtener datos del evento (modo detalle)
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

$where  = array("evento_id = :eid");
$params = array(':eid'=>$eventoId);

if ($q !== '') {
    $where[] = "(nombre LIKE :q OR email LIKE :q OR codigo LIKE :q)";
    $params[':q'] = '%'.$q.'%';
}
if ($fTipo !== '') {
    $where[] = "tipo = :tipo";
    $params[':tipo'] = $fTipo;
}
if ($fEstado === 'checkin_ok') {
    $where[] = "$colCheck = 1";
}
if ($fEstado === 'pendiente') {
    $where[] = "$colCheck = 0";
}

// ejecutar
$sql = "
SELECT *
FROM entradas
WHERE ".implode(" AND ",$where)."
ORDER BY id DESC
";
$stmtRows = $pdo->prepare($sql);
$stmtRows->execute($params);
$rows = $stmtRows->fetchAll(PDO::FETCH_ASSOC);

// estadísticas
$total     = count($rows);
$checkins  = 0;
foreach ($rows as $r) {
  if (isset($r[$colCheck]) && (int)$r[$colCheck] === 1) $checkins++;
}
$faltan = $total - $checkins;

// métricas globales del evento (no filtradas)
$stEvento = stats_evento($pdo, $eventoId, $colCheck, $hasTipos, $hasCantDisp, $hasCantTotal);

$title = "Panel del Evento – " . (isset($evento['nombre']) ? $evento['nombre'] : 'Evento');
include __DIR__.'/inc/layout_top.php';
?>

<div class="card">
  <h2><?php echo e($evento['nombre']); ?></h2>
  <div>Slug: <strong><?php echo e($evento['slug']); ?></strong></div>

  <?php include __DIR__."/inc/tickex_bridge_panel_block.php"; ?>
<!-- STR BUY BTN START -->
  <div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
    <a class="btn" href="comprar.php?id=<?php echo (int)$eventoId; ?>" target="_blank" rel="noopener">🎟 Adquirir entrada</a>
    <a class="btn secondary" href="comprar_iframe.php?id=<?php echo (int)$eventoId; ?>" target="_blank" rel="noopener">Ver embebido</a>
  </div>
  <!-- STR BUY BTN END -->
  <div style="margin-top:10px;">
    <a class="btn secondary" href="cargar_entrada.php?evento_id=<?php echo (int)$eventoId; ?>">➕ Cargar entrada</a>
  </div>
  <div style="margin-top:10px;">
    <a class="link" href="panel_evento.php">← Volver a mis eventos</a>
  </div>
</div>

<div class="card" style="display:flex;gap:12px;flex-wrap:wrap;">
  <div class="card" style="flex:1 1 180px;min-width:180px;">
    <div class="muted">Vendidas</div>
    <div style="font-size:22px;font-weight:700;">
      <?php echo (int)$stEvento['total']; ?>
    </div>
  </div>
  <div class="card" style="flex:1 1 180px;min-width:180px;">
    <div class="muted">Check-ins</div>
    <div style="font-size:22px;font-weight:700;">
      <?php echo (int)$stEvento['checkins']; ?>
    </div>
  </div>
  <div class="card" style="flex:1 1 180px;min-width:180px;">
    <div class="muted">Faltan</div>
    <div style="font-size:22px;font-weight:700;">
      <?php echo (int)$stEvento['faltan']; ?>
    </div>
  </div>
  <div class="card" style="flex:1 1 180px;min-width:180px;">
    <div class="muted">Disponibles</div>
    <div style="font-size:22px;font-weight:700;">
      <?php echo ($stEvento['disponibles'] !== null ? (int)$stEvento['disponibles'] : '-'); ?>
    </div>
  </div>
  <div class="card" style="flex:1 1 180px;min-width:180px;">
    <div class="muted">Stock total</div>
    <div style="font-size:22px;font-weight:700;">
      <?php echo ($stEvento['stock_total'] !== null ? (int)$stEvento['stock_total'] : '-'); ?>
    </div>
  </div>
</div>

<div class="card">
  <h3>Entradas del evento</h3>

  <form method="get" style="margin-top:10px;">
    <input type="hidden" name="id" value="<?php echo $eventoId; ?>">

    <div style="display:grid;grid-template-columns:2fr 1fr 1fr auto;gap:8px;align-items:end;">
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

  <div style="overflow:auto;margin-top:10px;">
    <table class="table">
      <tr>
        <th>ID</th>
        <th>Nombre</th>
        <th>Email</th>
        <th>Tipo</th>
        <th>Código</th>
        <th>Estado</th>
        <th>Acciones</th>
      </tr>

<?php foreach($rows as $r): ?>
      <tr>
        <td><?php echo (int)$r['id']; ?></td>
        <td><?php echo e($r['nombre']); ?></td>
        <td><?php echo e($r['email']); ?></td>
        <td><?php echo e($r['tipo']); ?></td>
        <td><?php echo e($r['codigo']); ?></td>
        <td>
          <?php if(isset($r[$colCheck]) && (int)$r[$colCheck]===1): ?>
            <span style="color:var(--ok);font-weight:700;">OK</span>
          <?php else: ?>
            <span style="color:var(--warn);font-weight:700;">Pendiente</span>
          <?php endif; ?>
        </td>
        <td>
          <a class="link" href="ticket.php?c=<?php echo urlencode($r['codigo']); ?>">Ver ticket</a>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>

  <?php endif; ?>
</div>

<?php include __DIR__.'/inc/layout_bottom.php'; ?>
