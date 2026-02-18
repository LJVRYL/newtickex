<?php
require_once __DIR__.'/inc/bootstrap.php';
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

try {
    $pdo = db();
} catch (Exception $e) {
    http_response_code(500);
    echo "Error DB: " . e($e->getMessage());
    exit;
}

// Detectar si eventos tiene columna borrado_en
$colsEv = $pdo->query("PRAGMA table_info(eventos)")->fetchAll(PDO::FETCH_ASSOC);
$hasBorradoEn = false;
foreach ($colsEv as $c) {
    if (isset($c['name']) && $c['name'] === 'borrado_en') {
        $hasBorradoEn = true;
        break;
    }
}

$mensaje = '';
$ok = false;

try {
    if ($hasBorradoEn) {
        // Soft delete: marcamos como borrado
        $stmt = $pdo->prepare("UPDATE eventos SET borrado_en = datetime('now') WHERE id = :id");
        $stmt->execute(array(':id' => $id));
        if ($stmt->rowCount() > 0) {
            $ok = true;
            $mensaje = "El evento fue enviado a la papelera correctamente.";
        } else {
            $mensaje = "No se encontró el evento indicado.";
        }
    } else {
        // Hard delete si no existe la columna
        $stmt = $pdo->prepare("DELETE FROM eventos WHERE id = :id");
        $stmt->execute(array(':id' => $id));
        if ($stmt->rowCount() > 0) {
            $ok = true;
            $mensaje = "El evento fue eliminado correctamente.";
        } else {
            $mensaje = "No se encontró el evento indicado.";
        }
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
