<?php
require_once __DIR__.'/inc/bootstrap.php';
require_once __DIR__.'/inc/produccion.php';

require_login();
$cu = current_user();
$rol = isset($cu['tipo_global']) && $cu['tipo_global'] !== '' ? $cu['tipo_global'] : (isset($cu['rol']) ? $cu['rol'] : (isset($_SESSION['tipo_global']) ? $_SESSION['tipo_global'] : ''));
if (!in_array($rol, array('admin_evento','super_admin','superadmin'), true)) {
    header('Location: /login.php?next=' . urlencode($_SERVER['REQUEST_URI']), true, 302);
    exit;
}

$pdo = db();

ensure_produccion_table($pdo);

// Crear / agregar artista
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_artista') {
    $nombre   = trim(isset($_POST['nombre']) ? $_POST['nombre'] : '');
    $tipo     = trim(isset($_POST['tipo']) ? $_POST['tipo'] : '');
    $categoria = trim(isset($_POST['categoria']) ? $_POST['categoria'] : '');
    $precio   = isset($_POST['precio']) ? (float)$_POST['precio'] : 0;
    $origen   = trim(isset($_POST['origen']) ? $_POST['origen'] : '');
    $viaticos = isset($_POST['pide_viaticos']) ? 1 : 0;
    $notas    = trim(isset($_POST['notas']) ? $_POST['notas'] : '');

    if ($nombre === '') {
        flash('warn','El nombre es obligatorio.');
    } else {
        try {
            $stmt = $pdo->prepare("INSERT INTO produccion_artistas (nombre, tipo, categoria, precio, origen, pide_viaticos, notas) VALUES (:n,:t,:c,:p,:o,:v,:no)");
            $stmt->execute(array(
                ':n' => $nombre,
                ':t' => $tipo,
                ':c' => $categoria,
                ':p' => $precio,
                ':o' => $origen,
                ':v' => $viaticos,
                ':no'=> $notas,
            ));
            flash('ok','Artista agregado.');
        } catch (Exception $e) {
            flash('err','Error al guardar: '.$e->getMessage());
        }
    }
}

// Eliminar
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'del_artista') {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    if ($id > 0) {
        try {
            $pdo->prepare('DELETE FROM produccion_artistas WHERE id = :id')->execute(array(':id'=>$id));
            flash('ok','Artista eliminado.');
        } catch (Exception $e) {
            flash('err','No se pudo eliminar: '.$e->getMessage());
        }
    }
}

// Listado
$artistas = get_produccion_artistas($pdo);

$title = 'Producción';
include __DIR__.'/inc/layout_top.php';
?>

<div class="card" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
  <a class="btn secondary" href="panel_admin.php">⬅ Volver</a>
  <h2 style="margin:0;">Producción</h2>
  <span class="muted">Registro rápido de DJs / productores con costos y notas.</span>
</div>

<div class="card" style="max-width:900px;">
  <h3>Agregar artista</h3>
  <form method="post" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;">
    <input type="hidden" name="action" value="add_artista">
    <div>
      <label>Nombre</label>
      <input name="nombre" required>
    </div>
    <div>
      <label>Tipo</label>
      <select name="tipo">
        <option value="DJ">DJ</option>
        <option value="PRODUCTOR">Productor</option>
        <option value="DJ + PRODUCTOR">DJ + Productor</option>
      </select>
    </div>
    <div>
      <label>Categoría</label>
      <select name="categoria">
        <option value="PRINCIPAL">Principal</option>
        <option value="SECUNDARIO">Secundario</option>
      </select>
    </div>
    <div>
      <label>Precio ($)</label>
      <input type="number" step="0.01" min="0" name="precio" placeholder="0">
    </div>
    <div>
      <label>Origen</label>
      <select name="origen">
        <option value="LOCAL">Local</option>
        <option value="INTERIOR">Interior</option>
        <option value="EXTRANJERO">Extranjeros</option>
      </select>
    </div>
    <div style="display:flex;align-items:center;gap:6px;">
      <input type="checkbox" id="pide_viaticos" name="pide_viaticos" value="1">
      <label for="pide_viaticos" style="margin:0;">Pide viáticos</label>
    </div>
    <div style="grid-column:1 / -1;">
      <label>Notas / requerimientos</label>
      <textarea name="notas" rows="3" placeholder="Rider, backline, horarios, etc."></textarea>
    </div>
    <div style="grid-column:1 / -1;">
      <button class="btn" type="submit">Guardar</button>
    </div>
  </form>
</div>

<div class="card">
  <h3>Listado de artistas</h3>
  <?php if (empty($artistas)): ?>
    <div class="muted">Todavía no cargaste artistas.</div>
  <?php else: ?>
    <div style="overflow:auto;">
      <table class="table">
        <thead>
          <tr>
            <th>ID</th>
            <th>Nombre</th>
            <th>Tipo</th>
            <th>Categoría</th>
            <th>Precio</th>
            <th>Origen</th>
            <th>Viáticos</th>
            <th>Notas</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($artistas as $a): ?>
            <tr>
              <td><?php echo (int)$a['id']; ?></td>
              <td><?php echo e($a['nombre']); ?></td>
              <td><?php echo e($a['tipo']); ?></td>
              <td><?php echo e($a['categoria']); ?></td>
              <td>$<?php echo number_format((float)$a['precio'], 0, ',', '.'); ?></td>
              <td><?php echo e($a['origen']); ?></td>
              <td><?php echo ((int)$a['pide_viaticos'] === 1) ? 'Sí' : 'No'; ?></td>
              <td><?php echo nl2br(e($a['notas'])); ?></td>
              <td>
                <form method="post" onsubmit="return confirm('¿Eliminar este artista?');">
                  <input type="hidden" name="action" value="del_artista">
                  <input type="hidden" name="id" value="<?php echo (int)$a['id']; ?>">
                  <button class="btn danger" type="submit">Borrar</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php include __DIR__.'/inc/layout_bottom.php'; ?>
