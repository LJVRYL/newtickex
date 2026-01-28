<?php
// Panel principal de administración STR / Tickex

require_once __DIR__ . '/inc/bootstrap.php';

$title = 'Panel de administración';

require_login();

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

$pdo = db();

// Detectar columnas de entradas para check-in
$colsEn = $pdo->query("PRAGMA table_info(entradas)")->fetchAll(PDO::FETCH_ASSOC);
$colCheck = 'checked_in';
foreach ($colsEn as $c) {
    if (isset($c['name']) && $c['name'] === 'checkin') {
        $colCheck = 'checkin';
        break;
    }
}

// Contadores globales
$totalEntradas = (int)$pdo->query("SELECT COUNT(*) FROM entradas")->fetchColumn();
$sqlCheck = "SELECT COUNT(*) FROM entradas WHERE $colCheck = 1";
$checkinsGlobal = (int)$pdo->query($sqlCheck)->fetchColumn();
$faltanGlobal = max(0, $totalEntradas - $checkinsGlobal);

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

// Funciones de stats por evento
function stats_evento($pdo, $eventoId, $colCheck, $hasTipos, $hasCantDisp, $hasCantTotal) {
    $out = array('total'=>0,'checkins'=>0,'faltan'=>0,'disponibles'=>null,'stock_total'=>null);

    // Entradas
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

include __DIR__ . '/inc/layout_top.php';
?>

<div class="card" style="max-width:1100px;margin:16px auto;">
  <h1 style="margin-top:0;">Panel de administración</h1>
  <p style="margin:8px 0;">Hola, <strong><?php echo e($nombreMostrar); ?></strong>.</p>
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
            <a class="btn btn-panel" style="display:inline-flex;align-items:center;gap:6px;" href="panel_evento.php?evento_id=<?php echo (int)$ev['id']; ?>">Ver panel</a>
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
