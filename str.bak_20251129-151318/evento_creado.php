<?php
require_once __DIR__.'/inc/bootstrap.php';
$title = "Evento creado – TICKEX";

require_login();

$cu = current_user();
$tipoGlobal = isset($_SESSION['tipo_global']) ? $_SESSION['tipo_global'] : (isset($cu['rol'])?$cu['rol']:'');
if (!in_array($tipoGlobal, array('super_admin','admin_evento','superadmin'), true)) {
    header('Location: panel_admin.php');
    exit;
}

$eventoId = isset($_GET['evento_id']) ? (int)$_GET['evento_id'] : 0;
$eventoNombre = '';

try {
    $pdo = db();
    if ($eventoId > 0) {
        $stmt = $pdo->prepare("SELECT nombre FROM eventos WHERE id = :id LIMIT 1");
        $stmt->execute(array(':id' => $eventoId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $eventoNombre = $row['nombre'];
        }
    }
} catch (Exception $e) {
    // Si falla, no rompemos la pantalla de éxito, solo no mostramos el nombre
}

include __DIR__.'/inc/layout_top.php';
?>
<div class="card">
  <h2>✅ Evento creado con éxito</h2>
  <?php if ($eventoNombre !== ''): ?>
    <p>El evento <strong><?php echo e($eventoNombre); ?></strong> se creó correctamente.</p>
  <?php elseif ($eventoId > 0): ?>
    <p>El evento con ID <strong>#<?php echo (int)$eventoId; ?></strong> se creó correctamente.</p>
  <?php else: ?>
    <p>El evento se creó correctamente.</p>
  <?php endif; ?>

  <p>Desde el panel vas a poder ver y editar tus eventos, y configurar las entradas desde <strong>Mis entradas</strong>.</p>

  <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:16px;">
    <a class="btn primary" href="panel_admin.php">Volver al panel</a>
    <a class="btn secondary" href="crear_evento.php">Crear otro evento</a>
  </div>
</div>
<?php include __DIR__.'/inc/layout_bottom.php'; ?>
