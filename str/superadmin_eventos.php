<?php
// superadmin_eventos.php
// Vista global de eventos para superadmin (cards + buscador + contadores + acciones)

require_once __DIR__ . '/inc/bootstrap.php';

require_login();

$csrf = function_exists('tickex_csrf_token') ? tickex_csrf_token() : '';

$cu = current_user();
$tipoGlobal = isset($cu['tipo_global']) ? $cu['tipo_global'] : (isset($_SESSION['tipo_global']) ? $_SESSION['tipo_global'] : '');
$rol = isset($cu['rol']) ? $cu['rol'] : (isset($_SESSION['rol']) ? $_SESSION['rol'] : '');

if (!in_array($tipoGlobal, array('super_admin', 'superadmin'), true)) {
    http_response_code(403);
    include __DIR__ . '/inc/layout_top.php';
    echo '<div class="card" style="max-width:640px;margin:32px auto;"><h2>Acceso restringido</h2><p>Solo para superadministradores.</p></div>';
    include __DIR__ . '/inc/layout_bottom.php';
    exit;
}

$pdo = db();

// Detectar columnas en eventos (esquemas viejos/nuevos)
$colsEv = array();
try {
    $colsEv = $pdo->query("PRAGMA table_info(eventos)")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $colsEv = array();
}

$hasCreadoPor = false;
$hasPublicadoSite = false;
$hasPublicadoEn = false;
$hasBorradoEn = false;
$hasFechaDesde = false;
$hasFechaHasta = false;
foreach ($colsEv as $c) {
    $n = isset($c['name']) ? $c['name'] : '';
    if ($n === 'creado_por_admin_id') $hasCreadoPor = true;
    if ($n === 'publicado_site') $hasPublicadoSite = true;
    if ($n === 'publicado_en') $hasPublicadoEn = true;
    if ($n === 'borrado_en') $hasBorradoEn = true;
    if ($n === 'fecha_desde') $hasFechaDesde = true;
    if ($n === 'fecha_hasta') $hasFechaHasta = true;
}

// Asegurar publicado_site para poder ocultar/publicar
if (!$hasPublicadoSite) {
    try {
        $pdo->exec("ALTER TABLE eventos ADD COLUMN publicado_site INTEGER DEFAULT 0");
        $hasPublicadoSite = true;
    } catch (Exception $e) {
        // ignore
    }
}

$flashOk = '';
$flashErr = '';

// Acción: ocultar/publicar en el sitio (publicado_site)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_site') {
    $eid = isset($_POST['event_id']) ? (int)$_POST['event_id'] : 0;
    $to  = isset($_POST['to']) ? (int)$_POST['to'] : 0;
    if ($eid > 0 && ($to === 0 || $to === 1) && $hasPublicadoSite) {
        try {
            $st = $pdo->prepare('UPDATE eventos SET publicado_site = :to WHERE id = :id');
            $st->execute(array(':to' => $to, ':id' => $eid));
            $flashOk = 'Evento #' . $eid . ' ' . ($to === 1 ? 'publicado' : 'ocultado') . ' correctamente.';
        } catch (Exception $e) {
            $flashErr = 'No se pudo actualizar el evento.';
        }
    } else {
        $flashErr = 'Acción inválida.';
    }
}

$q = isset($_GET['q']) ? trim($_GET['q']) : '';

// Detectar si podemos unir con usuarios (para mostrar creador)
$hasUsuarios = false;
$hasUsuariosId = false;
$hasUsuariosEmail = false;
try {
    $rowU = $pdo->query("SELECT type FROM sqlite_master WHERE name='usuarios' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($rowU) {
        $hasUsuarios = true;
        $colsU = $pdo->query("PRAGMA table_info(usuarios)")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($colsU as $c) {
            $n = isset($c['name']) ? $c['name'] : '';
            if ($n === 'id') $hasUsuariosId = true;
            if ($n === 'email') $hasUsuariosEmail = true;
        }
    }
} catch (Exception $e) {
    $hasUsuarios = false;
}

$where = array();
$params = array();

if ($hasBorradoEn) {
    $where[] = 'e.borrado_en IS NULL';
}

if ($q !== '') {
    if (ctype_digit($q)) {
        $where[] = 'e.id = :idq';
        $params[':idq'] = (int)$q;
    } else {
        $where[] = '(e.nombre LIKE :q OR e.slug LIKE :q)';
        $params[':q'] = '%' . $q . '%';
    }
}

// ORDER: próximos primero (por fecha_desde/hasta); si no hay fechas, por ID desc
$dateKeyExpr = "COALESCE(NULLIF(e.fecha_desde,''), NULLIF(e.fecha_hasta,''))";
$orderBy = 'e.id DESC';
if ($hasFechaDesde || $hasFechaHasta) {
    $orderBy = "
      CASE
        WHEN $dateKeyExpr IS NULL THEN 2
        WHEN date($dateKeyExpr) >= date('now') THEN 0
        ELSE 1
      END ASC,
      CASE
        WHEN $dateKeyExpr IS NULL THEN NULL
        WHEN date($dateKeyExpr) >= date('now') THEN date($dateKeyExpr)
        ELSE NULL
      END ASC,
      CASE
        WHEN $dateKeyExpr IS NULL THEN NULL
        WHEN date($dateKeyExpr) < date('now') THEN date($dateKeyExpr)
        ELSE NULL
      END DESC,
      e.id DESC
    ";
}

$selectCreator = 'NULL AS creador_email';
$joinCreator = '';
if ($hasCreadoPor && $hasUsuarios && $hasUsuariosId && $hasUsuariosEmail) {
    $selectCreator = 'u.email AS creador_email';
    $joinCreator = 'LEFT JOIN usuarios u ON u.id = e.creado_por_admin_id';
}

$sql = "SELECT e.*, $selectCreator\nFROM eventos e\n$joinCreator\n";
if (!empty($where)) {
    $sql .= 'WHERE ' . implode(' AND ', $where) . "\n";
}
$sql .= "ORDER BY $orderBy\nLIMIT 500";

$st = $pdo->prepare($sql);
$st->execute($params);
$eventos = $st->fetchAll(PDO::FETCH_ASSOC);

// Contadores
$totalEventos = 0;
$pubSite = 0;
$eventosHoy = 0;
$hoy = date('Y-m-d');
foreach ($eventos as $ev) {
    $totalEventos++;
    if ($hasPublicadoSite && isset($ev['publicado_site']) && (int)$ev['publicado_site'] === 1) $pubSite++;

    $fd = ($hasFechaDesde && !empty($ev['fecha_desde'])) ? date('Y-m-d', strtotime($ev['fecha_desde'])) : '';
    $fh = ($hasFechaHasta && !empty($ev['fecha_hasta'])) ? date('Y-m-d', strtotime($ev['fecha_hasta'])) : '';
    if ($fd !== '' && $fd === $hoy) {
        $eventosHoy++;
    } elseif ($fd !== '' && $fh !== '' && $fd <= $hoy && $hoy <= $fh) {
        $eventosHoy++;
    }
}

// Helpers
function ev_flyer_url($ev)
{
    if (!empty($ev['flyer_filename'])) {
        $path = __DIR__ . '/' . $ev['flyer_filename'];
        if (file_exists($path)) return $ev['flyer_filename'];
    }
    return null;
}

$title = 'Todos los eventos';
include __DIR__ . '/inc/layout_top.php';
?>

<div class="card" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
  <a class="btn secondary" href="panel_admin.php">⬅ Volver</a>
  <h2 style="margin:0;">Todos los eventos</h2>
  <span class="muted">Vista global (solo superadmin)</span>
  <div style="flex:1 1 auto;"></div>
  <a class="btn secondary" href="papelera_eventos.php">🗑 Papelera</a>
</div>

<?php if ($flashOk !== ''): ?>
  <div class="flash ok"><?php echo e($flashOk); ?></div>
<?php endif; ?>
<?php if ($flashErr !== ''): ?>
  <div class="flash err"><?php echo e($flashErr); ?></div>
<?php endif; ?>

<div class="card" style="max-width:1100px;margin:16px auto;">
  <form method="get" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
    <label for="q" class="muted" style="margin:0;">Buscar evento</label>
    <input type="text" id="q" name="q" value="<?php echo e($q); ?>" placeholder="nombre, slug o ID" style="flex:1 1 260px;">
    <button class="btn secondary" type="submit">Buscar</button>
    <?php if ($q !== ''): ?><a class="btn secondary" href="superadmin_eventos.php">Limpiar</a><?php endif; ?>
  </form>

  <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:12px;">
    <div class="card" style="flex:1 1 200px;min-width:200px;margin:0;">
      <div class="muted">Eventos</div>
      <div style="font-size:24px;font-weight:700;"><?php echo (int)$totalEventos; ?></div>
    </div>
    <div class="card" style="flex:1 1 200px;min-width:200px;margin:0;">
      <div class="muted">Publicados (sitio)</div>
      <div style="font-size:24px;font-weight:700;"><?php echo (int)$pubSite; ?></div>
    </div>
    <div class="card" style="flex:1 1 200px;min-width:200px;margin:0;">
      <div class="muted">Eventos hoy</div>
      <div style="font-size:24px;font-weight:700;"><?php echo (int)$eventosHoy; ?></div>
    </div>
  </div>
  <div class="muted" style="font-size:12px;margin-top:8px;">Orden: próximos eventos primero.</div>
</div>

<div class="card" style="max-width:1100px;margin:16px auto;">
  <h3 style="margin-top:0;">Cards</h3>

  <?php if (empty($eventos)): ?>
    <div class="muted">No hay eventos para mostrar.</div>
  <?php else: ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px;">
      <?php foreach ($eventos as $ev): ?>
        <?php
          $eid = (int)$ev['id'];
          $fly = ev_flyer_url($ev);
          $fd = ($hasFechaDesde && !empty($ev['fecha_desde'])) ? $ev['fecha_desde'] : '';
          $fh = ($hasFechaHasta && !empty($ev['fecha_hasta'])) ? $ev['fecha_hasta'] : '';
          $pub = ($hasPublicadoSite && isset($ev['publicado_site'])) ? ((int)$ev['publicado_site'] === 1) : false;
          $badge = $pub ? '<span style="font-size:12px;"><strong>Publicado</strong></span>' : '<span class="muted" style="font-size:12px;">Oculto</span>';
        ?>
        <div class="card" style="margin:0;">
          <div style="display:flex;gap:12px;">
            <div style="width:80px;height:80px;border:1px solid var(--line);border-radius:8px;overflow:hidden;background:#000;flex-shrink:0;display:flex;align-items:center;justify-content:center;">
              <?php if ($fly): ?>
                <img src="<?php echo e($fly); ?>" alt="Flyer" style="width:100%;height:100%;object-fit:cover;">
              <?php else: ?>
                <span class="muted" style="font-size:12px;">Sin flyer</span>
              <?php endif; ?>
            </div>
            <div style="flex:1 1 auto;min-width:0;">
              <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                <div style="font-weight:700;">#<?php echo $eid; ?> — <?php echo e(isset($ev['nombre']) ? $ev['nombre'] : ''); ?></div>
                <?php echo $badge; ?>
              </div>
              <div class="muted" style="font-size:12px;">Slug: <?php echo e(isset($ev['slug']) ? $ev['slug'] : ''); ?></div>
              <div class="muted" style="font-size:12px;margin-top:4px;">
                Fecha: <?php
                  if ($fd === '' && $fh === '') {
                    echo 'Sin fecha';
                  } else {
                    echo e($fd);
                    if ($fh !== '') echo ' → ' . e($fh);
                  }
                ?>
              </div>
              <?php if (!empty($ev['creador_email'])): ?>
                <div class="muted" style="font-size:12px;margin-top:4px;">Creador: <?php echo e($ev['creador_email']); ?></div>
              <?php endif; ?>
            </div>
          </div>

          <div style="display:flex;gap:8px;margin-top:12px;flex-wrap:wrap;">
            <a class="btn btn-panel" href="panel_evento.php?evento_id=<?php echo $eid; ?>">Ver panel</a>
            <a class="btn secondary" href="editar_evento.php?id=<?php echo $eid; ?>">✏ Editar</a>

            <?php if ($hasPublicadoSite): ?>
              <form method="post" style="display:inline;">
                <input type="hidden" name="action" value="toggle_site">
                <input type="hidden" name="event_id" value="<?php echo $eid; ?>">
                <input type="hidden" name="to" value="<?php echo $pub ? 0 : 1; ?>">
                <button class="btn secondary" type="submit"><?php echo $pub ? 'Ocultar' : 'Publicar'; ?></button>
              </form>
            <?php endif; ?>

            <a class="btn danger" href="eliminar_evento.php?id=<?php echo $eid; ?>&csrf=<?php echo urlencode($csrf); ?>" onclick="return confirm('¿Seguro que querés eliminar este evento?');">🗑 Eliminar</a>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/inc/layout_bottom.php'; ?>
