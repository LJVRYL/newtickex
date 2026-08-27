<?php
require_once __DIR__.'/inc/bootstrap.php';
require_once __DIR__.'/inc/event_trash.php';
$title = "Evento eliminado – TICKEX";

require_login();

$cu = current_user();
$tipoGlobal = isset($_SESSION['tipo_global']) ? $_SESSION['tipo_global'] : (isset($cu['rol'])?$cu['rol']:'');
if (!in_array($tipoGlobal, array('admin_evento','super_admin','superadmin'), true)) {
    header("Location: login.php");
    exit;
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    echo "ID de evento inválido.";
    exit;
}

$csrf = isset($_GET['csrf']) ? (string)$_GET['csrf'] : '';
if (!tickex_csrf_verify($csrf)) {
        http_response_code(403);
        include __DIR__.'/inc/layout_top.php';
        ?>
        <div class="card">
            <h2>Acción bloqueada</h2>
            <p style="margin-top:8px;">Token de seguridad inválido. Volvé a intentar desde el panel.</p>
            <a class="btn" href="panel_admin.php">⬅ Volver al panel</a>
        </div>
        <?php
        include __DIR__.'/inc/layout_bottom.php';
        exit;
}

try {
    $pdo = db();
} catch (Exception $e) {
    http_response_code(500);
    echo "Error DB: " . e($e->getMessage());
    exit;
}

$mensaje = '';
$ok = false;

try {
    $ok = tickex_event_soft_delete($pdo, $id);
    if ($ok) {
        $mensaje = "El evento fue enviado a la papelera correctamente. Sus entradas, pagos y registros se conservaron.";
    } else {
        $mensaje = "No se encontró el evento indicado.";
    }
} catch (Exception $e) {
    $mensaje = "Error al eliminar el evento: " . $e->getMessage();
}

include __DIR__.'/inc/layout_top.php';
?>

<div class="card">
  <h2><?php echo $ok ? "Evento eliminado" : "No se pudo eliminar el evento"; ?></h2>
  <p style="margin-top:8px;">
    <?php echo e($mensaje); ?>
  </p>

  <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:16px;">
    <a class="btn" href="panel_admin.php">⬅ Volver al panel</a>
    <a class="btn secondary" href="papelera_eventos.php">🗑 Ir a la papelera</a>
  </div>
</div>

<?php include __DIR__.'/inc/layout_bottom.php'; ?>
