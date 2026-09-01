<?php
require_once __DIR__.'/inc/bootstrap.php';
require_once __DIR__.'/inc/event_trash.php';
$title = "Papelera de eventos – TICKEX";

require_login();

$csrf = function_exists('tickex_csrf_token') ? tickex_csrf_token() : '';

$cu = current_user();
$tipoGlobal = isset($_SESSION['tipo_global']) ? $_SESSION['tipo_global'] : (isset($cu['rol'])?$cu['rol']:'');
if (!in_array($tipoGlobal, array('admin_evento','super_admin','superadmin'), true)) {
    header("Location: login.php");
    exit;
}

try {
    $pdo = db();
} catch (Exception $e) {
    http_response_code(500);
    echo "Error DB: " . e($e->getMessage());
    exit;
}

tickex_event_trash_ensure_schema($pdo);

// Traer eventos borrados en los últimos 30 días
$sqlTrash = "SELECT * FROM eventos WHERE borrado_en IS NOT NULL AND borrado_en >= datetime('now','-30 days')";
$trashParams = array();
if (!tickex_is_super_admin($cu)) {
    $sqlTrash .= ' AND creado_por_admin_id=:admin';
    $trashParams[':admin'] = tickex_admin_id($cu);
}
$sqlTrash .= ' ORDER BY borrado_en DESC';
$stmt = $pdo->prepare($sqlTrash);
$stmt->execute($trashParams);
$eventos = $stmt->fetchAll(PDO::FETCH_ASSOC);

include __DIR__.'/inc/layout_top.php';
?>

<div class="card" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
  <a class="btn" href="panel_admin.php">⬅ Volver al panel</a>
</div>

<div class="card">
  <h2>Papelera de eventos</h2>
  <p style="margin-top:8px;color:var(--muted);font-size:13px;">
    Los eventos eliminados permanecen en la papelera por 30 días. Después de eso pueden limpiarse definitivamente.
  </p>
</div>

<div class="card">
  <?php if (empty($eventos)): ?>
    <div class="card" style="background:var(--panel-2);">
      No hay eventos en la papelera.
    </div>
  <?php else: ?>

  <div style="overflow:auto;margin-top:8px;">
    <table class="table">
      <thead>
        <tr>
          <th>Evento</th>
          <th>Slug</th>
          <th>Borrado en</th>
          <th>Acciones</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($eventos as $ev): ?>
          <?php $eid = (int)$ev['id']; ?>
          <tr>
            <td><?php echo e($ev['nombre']); ?></td>
            <td><?php echo e($ev['slug']); ?></td>
            <td><?php echo e($ev['borrado_en']); ?></td>
            <td>
              <a
                class="btn secondary"
                href="restaurar_evento.php?id=<?php echo $eid; ?>&csrf=<?php echo urlencode($csrf); ?>"
                title="Restaurar evento"
                style="padding:4px 6px;font-size:12px;"
              >↩ Restaurar</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php endif; ?>
</div>

<?php include __DIR__.'/inc/layout_bottom.php'; ?>
