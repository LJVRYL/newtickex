<?php
// Panel principal de administración STR / Tickex

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/unified_tickets.php';

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

// Contadores globales
$totalEntradas = 0;
$checkinsGlobal = 0;
$faltanGlobal = 0;
$summaryEventId = isset($_GET['summary_event_id']) ? (int)$_GET['summary_event_id'] : 0;
$summaryLabel = 'Todos los eventos';

// Búsqueda
$q = isset($_GET['q']) ? trim($_GET['q']) : '';

// IDs por búsqueda en eventos
$eventIds = array();
if ($q !== '') {
    $stmtEv = $pdo->prepare("SELECT id FROM eventos WHERE nombre LIKE :q OR slug LIKE :q");
    $stmtEv->execute(array(':q' => '%'.$q.'%'));
    $eventIds = $stmtEv->fetchAll(PDO::FETCH_COLUMN);

    // IDs por búsqueda en entradas (nombre/email)
    $stmtEn = $pdo->prepare("SELECT DISTINCT evento_id FROM entradas WHERE nombre LIKE :q OR email LIKE :q");
    $stmtEn->execute(array(':q' => '%'.$q.'%'));
    $idsEn = $stmtEn->fetchAll(PDO::FETCH_COLUMN);
    if ($idsEn) {
        $eventIds = array_merge($eventIds, $idsEn);
    }
    $eventIds = array_values(array_unique(array_map('intval', $eventIds)));
}

// Obtener eventos
if ($q !== '' && !$eventIds) {
    $eventos = array();
} else {
    if ($q === '') {
        $stmtList = $pdo->query("SELECT * FROM eventos ORDER BY id DESC");
        $eventos = $stmtList->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $in = implode(',', array_fill(0, count($eventIds), '?'));
        $stmtList = $pdo->prepare("SELECT * FROM eventos WHERE id IN ($in) ORDER BY id DESC");
        $stmtList->execute($eventIds);
        $eventos = $stmtList->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Recalcular contadores globales con stats unificados (STR + Tickex)
if (!empty($eventos)) {
  if ($summaryEventId > 0) {
    foreach ($eventos as $evStats) {
      if ((int)$evStats['id'] === $summaryEventId) {
        try {
          $stg = get_unified_stats($pdo, $summaryEventId);
          $totalEntradas = (int)$stg['total'];
          $checkinsGlobal = (int)$stg['checkins'];
          $faltanGlobal = max(0, $totalEntradas - $checkinsGlobal);
          $summaryLabel = isset($evStats['nombre']) ? $evStats['nombre'] : ('Evento #'.$summaryEventId);
        } catch (Exception $e) {
          // si falla, queda en 0 y label por defecto
        }
        break;
      }
    }
  } else {
    foreach ($eventos as $evStats) {
      try {
        $stg = get_unified_stats($pdo, (int)$evStats['id']);
        $totalEntradas += (int)$stg['total'];
        $checkinsGlobal += (int)$stg['checkins'];
      } catch (Exception $e) {
        // si falla, seguir sin cortar panel
      }
    }
    $faltanGlobal = max(0, $totalEntradas - $checkinsGlobal);
  }
}

include __DIR__ . '/inc/layout_top.php';
?>

<div class="card" style="max-width:1100px;margin:16px auto;">
  <h1 style="margin-top:0;">Panel de administración</h1>
  <p style="margin:8px 0;">Hola, <strong><?php echo e($nombreMostrar); ?></strong> <span class="muted" style="font-size:13px;">(<?php echo e($rolMostrar); ?>)</span>.</p>
  <form method="get" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:8px;">
    <label class="muted" for="summary_event_id" style="margin:0;">Resumen:</label>
    <select name="summary_event_id" id="summary_event_id" style="min-width:220px;">
      <option value="0" <?php echo $summaryEventId === 0 ? 'selected' : ''; ?>>Todos los eventos</option>
      <?php foreach ($eventos as $evOpt): ?>
        <option value="<?php echo (int)$evOpt['id']; ?>" <?php echo $summaryEventId === (int)$evOpt['id'] ? 'selected' : ''; ?>>
          <?php echo e($evOpt['nombre']); ?> (ID <?php echo (int)$evOpt['id']; ?>)
        </option>
      <?php endforeach; ?>
    </select>
    <?php if ($q !== ''): ?><input type="hidden" name="q" value="<?php echo e($q); ?>"><?php endif; ?>
    <button class="btn" type="submit">Ver</button>
  </form>
  <div class="muted" style="font-size:12px;margin-top:4px;">Mostrando: <?php echo e($summaryLabel); ?></div>
  <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:12px;">
    <div class="card" style="flex:1 1 200px;min-width:200px;">
      <div class="muted">Entradas vendidas</div>
      <div style="font-size:24px;font-weight:700;"><?php echo (int)$totalEntradas; ?></div>
    </div>
    <div class="card" style="flex:1 1 200px;min-width:200px;">
      <div class="muted">Check-ins</div>
      <div style="font-size:24px;font-weight:700;"><?php echo (int)$checkinsGlobal; ?></div>
    </div>
    <div class="card" style="flex:1 1 200px;min-width:200px;">
      <div class="muted">Faltan chequear</div>
      <div style="font-size:24px;font-weight:700;"><?php echo (int)$faltanGlobal; ?></div>
    </div>
  </div>
</div>

<div class="card" style="max-width:1100px;margin:16px auto;">
  <form method="get" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
    <label for="q" class="muted" style="margin:0;">Buscar eventos / compradores</label>
    <input type="text" id="q" name="q" value="<?php echo e($q); ?>" placeholder="nombre de evento, slug o comprador" style="flex:1 1 240px;">
    <button class="btn" type="submit">Buscar</button>
    <?php if ($q !== ''): ?>
      <a class="btn secondary" href="panel_admin.php">Limpiar</a>
    <?php endif; ?>
  </form>
</div>

<div class="card" style="max-width:1100px;margin:16px auto;">
  <h2 style="margin-top:0;">Eventos</h2>
  <?php if (empty($eventos)): ?>
    <div class="muted">No se encontraron eventos.</div>
  <?php else: ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px;">
      <?php foreach ($eventos as $ev): ?>
        <div class="card clickable-event-card" style="margin:0;cursor:pointer;" onclick="window.location.href='panel_evento.php?evento_id=<?php echo (int)$ev['id']; ?>';" role="button" tabindex="0">
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
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<div class="card" style="max-width:1100px;margin:16px auto;">
  <h2 style="margin-top:0;">Cuenta</h2>
  <ul style="margin:0 0 8px 20px;padding:0;line-height:1.6;">
    <li><a href="mi_perfil.php">Mi perfil</a></li>
    <li><a href="logout_usuario.php">Cerrar sesión</a></li>
  </ul>
</div>

<?php include __DIR__ . '/inc/layout_bottom.php'; ?>
