<?php
// Panel principal de administración STR / Tickex

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/unified_tickets.php';
require_once __DIR__ . '/inc/event_trash.php';

$title = 'Panel de administración';

require_login();

$csrf = function_exists('tickex_csrf_token') ? tickex_csrf_token() : '';

$cu = current_user();

// Rol y permisos
$tipoGlobal = isset($cu['tipo_global'])
    ? $cu['tipo_global']
    : (isset($_SESSION['tipo_global']) ? $_SESSION['tipo_global'] : '');

$rol = isset($cu['rol'])
    ? $cu['rol']
    : (isset($_SESSION['rol']) ? $_SESSION['rol'] : '');

$esAdmin = is_admin();

if (!$esAdmin
    && !in_array($tipoGlobal, array('admin_evento', 'super_admin', 'superadmin'), true)
    && $rol !== 'admin') {

    http_response_code(403);
    include __DIR__ . '/inc/layout_top.php';
    ?>
    <div class="card" style="max-width:640px;margin:32px auto;">
      <h2>Acceso restringido</h2>
      <p>Este panel es solo para administradores.</p>
      <p style="margin-top:8px;">
        <a href="login.php" class="btn">Ir al inicio de sesión</a>
      </p>
    </div>
    <?php
    include __DIR__ . '/inc/layout_bottom.php';
    exit;
}

// Nombre a mostrar

$nombreMostrar = '';
if (isset($cu['display_name']) && trim($cu['display_name']) !== '') {
  $nombreMostrar = $cu['display_name'];
} elseif (isset($cu['nombre']) && trim($cu['nombre']) !== '') {
  $nombreMostrar = $cu['nombre'];
} elseif (isset($cu['username']) && trim($cu['username']) !== '') {
  $nombreMostrar = $cu['username'];
} elseif (isset($cu['email'])) {
  $nombreMostrar = $cu['email'];
} elseif (isset($_SESSION['usuario_email'])) {
  $nombreMostrar = $_SESSION['usuario_email'];
} else {
  $nombreMostrar = 'Admin';
}

// Rol junto al nombre en el saludo
$rolMostrar = '';
if ($tipoGlobal === 'admin_evento') {
  $rolMostrar = 'Admin del evento';
} elseif ($tipoGlobal === 'super_admin') {
  $rolMostrar = 'Super administrador';
} elseif ($tipoGlobal === 'superadmin') {
  $rolMostrar = 'Super administrador';
} else {
  $rolMostrar = $rol;
}

$pdo = db();
tickex_event_trash_ensure_schema($pdo);

// Contadores globales
$totalEntradas = 0;
$checkinsGlobal = 0;
$faltanGlobal = 0;
$legacySummaryEventId = isset($_GET['summary_event_id']) ? (int)$_GET['summary_event_id'] : 0;
$summaryFilter = isset($_GET['summary_filter']) ? trim((string)$_GET['summary_filter']) : 'active';
if ($legacySummaryEventId > 0) $summaryFilter = 'event:'.$legacySummaryEventId;
$listScope = isset($_GET['list_scope']) ? trim((string)$_GET['list_scope']) : 'active';
if (!in_array($listScope, array('active', 'finished', 'all'), true)) $listScope = 'active';
$summaryLabel = 'Eventos activos';

// Búsqueda
$q = isset($_GET['q']) ? trim($_GET['q']) : '';

// Obtener primero el universo autorizado y clasificarlo por su rango real de fechas.
$allEventos = tickex_visible_events($pdo, $cu);
$today = date('Y-m-d');
$eventStatus = function ($ev) use ($today) {
    $from = isset($ev['fecha_desde']) ? trim((string)$ev['fecha_desde']) : '';
    $until = isset($ev['fecha_hasta']) ? trim((string)$ev['fecha_hasta']) : '';
    $from = $from !== '' ? substr($from, 0, 10) : '';
    $until = $until !== '' ? substr($until, 0, 10) : '';
    if ($from === '' && $until === '') return 'undated';
    if ($until !== '' && $until < $today) return 'finished';
    if ($from !== '' && $from > $today) return 'upcoming';
    return 'active';
};
$activeCount = 0;
$finishedCount = 0;
foreach ($allEventos as $evCount) {
    $status = $eventStatus($evCount);
    if ($status === 'active' || $status === 'upcoming') $activeCount++;
    elseif ($status === 'finished') $finishedCount++;
}

$filterEventsByScope = function ($events, $scope) use ($eventStatus) {
    if ($scope === 'all') return array_values($events);
    return array_values(array_filter($events, function ($ev) use ($scope, $eventStatus) {
        $status = $eventStatus($ev);
        if ($scope === 'active') return $status === 'active' || $status === 'upcoming';
        return $status === $scope;
    }));
};

// La selección del resumen es independiente de la búsqueda de tarjetas.
$summaryEvents = array();
$summaryEventId = 0;
if (strpos($summaryFilter, 'event:') === 0) {
    $summaryEventId = (int)substr($summaryFilter, 6);
    foreach ($allEventos as $evSummary) {
        if ((int)$evSummary['id'] === $summaryEventId) {
            $summaryEvents[] = $evSummary;
            $summaryLabel = isset($evSummary['nombre']) ? $evSummary['nombre'] : ('Evento #'.$summaryEventId);
            break;
        }
    }
    if (empty($summaryEvents)) {
        $summaryFilter = 'active';
        $summaryEventId = 0;
    }
}
if ($summaryEventId === 0) {
    $summaryScope = in_array($summaryFilter, array('active', 'finished', 'all'), true) ? $summaryFilter : 'active';
    $summaryFilter = $summaryScope;
    $summaryEvents = $filterEventsByScope($allEventos, $summaryScope);
    $summaryLabel = $summaryScope === 'all' ? 'Todos los eventos' : ($summaryScope === 'finished' ? 'Eventos finalizados' : 'Eventos activos');
}

// La gestión muestra activos por defecto para no mezclar campañas viejas.
$eventos = $filterEventsByScope($allEventos, $listScope);
if ($q !== '') {
    $needle = function_exists('mb_strtolower') ? mb_strtolower($q, 'UTF-8') : strtolower($q);
    $eventos = array_values(array_filter($eventos, function ($ev) use ($pdo, $needle) {
        $haystack = (isset($ev['nombre']) ? $ev['nombre'] : '') . ' ' . (isset($ev['slug']) ? $ev['slug'] : '');
        $haystack = function_exists('mb_strtolower') ? mb_strtolower($haystack, 'UTF-8') : strtolower($haystack);
        if (strpos($haystack, $needle) !== false) return true;
        $st = $pdo->prepare('SELECT 1 FROM entradas WHERE evento_id=:event AND (nombre LIKE :q OR email LIKE :q) LIMIT 1');
        $st->execute(array(':event'=>(int)$ev['id'], ':q'=>'%'.$needle.'%'));
        return (bool)$st->fetchColumn();
    }));
}

// Recalcular contadores con stats unificados (STR + Tickex).
foreach ($summaryEvents as $evStats) {
  try {
    $stg = get_unified_stats($pdo, (int)$evStats['id']);
    $totalEntradas += (int)$stg['total'];
    $checkinsGlobal += (int)$stg['checkins'];
  } catch (Exception $e) {
    // Si falla un evento, continuar con el resto del panel.
  }
}
$faltanGlobal = max(0, $totalEntradas - $checkinsGlobal);

include __DIR__ . '/inc/layout_top.php';
?>

<main class="tx-dashboard">
<div class="card tx-dashboard-hero">
  <div class="tx-kicker"><i></i> Centro de operaciones</div>
  <h1 style="margin-top:0;">Panel de administración</h1>
  <p style="margin:8px 0;">Hola, <strong><?php echo e($nombreMostrar); ?></strong> <span class="muted" style="font-size:13px;">(<?php echo e($rolMostrar); ?>)</span>.</p>
  <form method="get" class="tx-summary-filter">
    <label for="summary_filter">Resumen de actividad</label>
    <select name="summary_filter" id="summary_filter">
      <optgroup label="Vista general">
        <option value="active" <?php echo $summaryFilter === 'active' ? 'selected' : ''; ?>>Eventos activos (<?php echo $activeCount; ?>)</option>
        <option value="all" <?php echo $summaryFilter === 'all' ? 'selected' : ''; ?>>Todos los eventos (<?php echo count($allEventos); ?>)</option>
        <option value="finished" <?php echo $summaryFilter === 'finished' ? 'selected' : ''; ?>>Eventos finalizados (<?php echo $finishedCount; ?>)</option>
      </optgroup>
      <optgroup label="Seleccionar evento">
        <?php foreach ($allEventos as $evOpt): ?>
          <option value="event:<?php echo (int)$evOpt['id']; ?>" <?php echo $summaryEventId === (int)$evOpt['id'] ? 'selected' : ''; ?>>
            <?php echo e($evOpt['nombre']); ?>
          </option>
        <?php endforeach; ?>
      </optgroup>
    </select>
    <input type="hidden" name="list_scope" value="<?php echo e($listScope); ?>">
    <?php if ($q !== ''): ?><input type="hidden" name="q" value="<?php echo e($q); ?>"><?php endif; ?>
    <button class="btn" type="submit">Ver</button>
  </form>
  <div class="tx-summary-caption"><span></span> Mostrando: <strong><?php echo e($summaryLabel); ?></strong></div>
  <div class="tx-stat-grid">
    <div class="card tx-stat-card">
      <div class="muted">Entradas vendidas</div>
      <div style="font-size:24px;font-weight:700;"><?php echo (int)$totalEntradas; ?></div>
    </div>
    <div class="card tx-stat-card">
      <div class="muted">Check-ins</div>
      <div style="font-size:24px;font-weight:700;"><?php echo (int)$checkinsGlobal; ?></div>
    </div>
    <div class="card tx-stat-card">
      <div class="muted">Faltan chequear</div>
      <div style="font-size:24px;font-weight:700;"><?php echo (int)$faltanGlobal; ?></div>
    </div>
  </div>
</div>

<div class="card tx-dashboard-toolbar">
  <form method="get" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
    <label for="q" class="muted" style="margin:0;">Buscar eventos / compradores</label>
    <input type="text" id="q" name="q" value="<?php echo e($q); ?>" placeholder="nombre de evento, slug o comprador" style="flex:1 1 240px;">
    <button class="btn" type="submit">Buscar</button>
    <input type="hidden" name="summary_filter" value="<?php echo e($summaryFilter); ?>">
    <input type="hidden" name="list_scope" value="<?php echo e($listScope); ?>">
    <?php if ($q !== ''): ?>
      <a class="btn secondary" href="panel_admin.php">Limpiar</a>
    <?php endif; ?>
  </form>
</div>

<div class="card tx-dashboard-section">
  <div class="tx-event-section-head">
    <div>
      <div class="tx-kicker"><i></i> Gestión de eventos</div>
      <h2>Eventos</h2>
      <p>Priorizá lo que está en marcha y consultá el historial cuando lo necesites.</p>
    </div>
    <a class="btn tx-new-event" href="crear_evento.php">Crear evento</a>
  </div>
  <nav class="tx-event-scopes" aria-label="Filtrar eventos">
    <?php
      $scopeLabels = array(
        'active' => array('Activos', $activeCount),
        'finished' => array('Finalizados', $finishedCount),
        'all' => array('Todos', count($allEventos)),
      );
      foreach ($scopeLabels as $scopeKey => $scopeData):
        $scopeQuery = array('list_scope'=>$scopeKey, 'summary_filter'=>$summaryFilter);
        if ($q !== '') $scopeQuery['q'] = $q;
    ?>
      <a class="<?php echo $listScope === $scopeKey ? 'active' : ''; ?>" href="panel_admin.php?<?php echo e(http_build_query($scopeQuery)); ?>">
        <?php echo e($scopeData[0]); ?> <strong><?php echo (int)$scopeData[1]; ?></strong>
      </a>
    <?php endforeach; ?>
  </nav>
  <?php if (empty($eventos)): ?>
    <div class="tx-events-empty">
      <strong>No hay eventos en esta vista.</strong>
      <span><?php echo $listScope === 'active' ? 'Los eventos nuevos y los que todavía no finalizaron van a aparecer acá.' : 'Probá con otra categoría o limpiá la búsqueda.'; ?></span>
    </div>
  <?php else: ?>
    <div class="tx-event-grid">
      <?php foreach ($eventos as $ev): ?>
        <?php
          $cardStatus = $eventStatus($ev);
          $cardStatusLabel = $cardStatus === 'finished' ? 'Finalizado' : ($cardStatus === 'upcoming' ? 'Próximo' : ($cardStatus === 'undated' ? 'Sin programar' : 'Activo'));
        ?>
        <div class="card clickable-event-card tx-event-card" onclick="window.location.href='panel_evento.php?evento_id=<?php echo (int)$ev['id']; ?>';" role="button" tabindex="0">
          <div class="tx-event-image">
            <span class="tx-event-status is-<?php echo e($cardStatus); ?>"><?php echo e($cardStatusLabel); ?></span>
            <?php $fly = isset($ev['flyer_filename']) ? $ev['flyer_filename'] : ''; ?>
            <?php if ($fly && file_exists(__DIR__ . '/' . $fly)): ?>
              <img src="<?php echo e($fly); ?>" alt="Flyer" style="width:100%;height:100%;object-fit:cover;">
            <?php else: ?>
              <span class="muted" style="font-size:12px;">Sin flyer</span>
            <?php endif; ?>
          </div>
          <div style="padding:12px 10px 14px;">
            <div style="font-weight:700;font-size:16px;line-height:1.25;margin-bottom:6px;min-height:44px;overflow:hidden;text-overflow:ellipsis;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;">
              <?php echo e($ev['nombre']); ?>
            </div>
            <?php
              $fd = isset($ev['fecha_desde']) ? $ev['fecha_desde'] : '';
              $fh = isset($ev['fecha_hasta']) ? $ev['fecha_hasta'] : '';
            ?>
            <?php if ($fd !== '' || $fh !== ''): ?>
              <div class="muted" style="font-size:13px;line-height:1.4;">
                <?php echo e($fd); ?><?php if ($fh !== ''): ?> → <?php echo e($fh); ?><?php endif; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<div class="card tx-dashboard-section tx-account-card">
  <h2 style="margin-top:0;">Cuenta</h2>
  <ul style="margin:0 0 8px 20px;padding:0;line-height:1.6;">
    <li><a href="mi_perfil.php">Mi perfil</a></li>
    <li><a href="logout_usuario.php">Cerrar sesión</a></li>
  </ul>
</div>
</main>

<?php include __DIR__ . '/inc/layout_bottom.php'; ?>
