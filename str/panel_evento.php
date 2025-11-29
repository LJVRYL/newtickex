<?php
require_once __DIR__.'/inc/bootstrap.php';

require_login();

// roles permitidos (compat con tu DB)
$cu = current_user();
$rol = isset($cu['rol']) ? $cu['rol'] : (isset($_SESSION['tipo_global']) ? $_SESSION['tipo_global'] : '');
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

if ($eventoId <= 0) {
    abort_404("ID de evento inválido.");
}

$pdo = db();

// obtener datos del evento
$stmtEv = $pdo->prepare("SELECT * FROM eventos WHERE id=:id");
$stmtEv->execute(array(':id'=>$eventoId));
$evento = $stmtEv->fetch(PDO::FETCH_ASSOC);

if (!$evento) {
    abort_404("Evento no encontrado.");
}

// admin_evento: solo su evento creado por él
if ($rol === 'admin_evento') {
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
    $where[] = "checked_in = 1";
}
if ($fEstado === 'pendiente') {
    $where[] = "checked_in = 0";
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
    if ((int)$r['checked_in'] === 1) $checkins++;
}
$faltan = $total - $checkins;

$title = "Panel del Evento – ".$evento['nombre'];
include __DIR__.'/inc/layout_top.php';
?>

<div class="card">
  <h2><?php echo e($evento['nombre']); ?></h2>
  <div>Slug: <strong><?php echo e($evento['slug']); ?></strong></div>

  <div style="margin-top:10px;">
    <a class="link" href="panel_admin.php">← Volver al panel general</a>
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
          <?php if((int)$r['checked_in']===1): ?>
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
