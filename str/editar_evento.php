<?php
require_once __DIR__ . '/inc/bootstrap.php';

require_login();
$pdo = db();
$cu = current_user();
$tipoGlobal = isset($cu['tipo_global']) ? (string)$cu['tipo_global'] : '';
$adminId = isset($cu['id']) ? (int)$cu['id'] : 0;
if (!in_array($tipoGlobal, array('admin_evento', 'super_admin', 'superadmin'), true)) {
    abort_404('No tenés permiso.');
}

$eventoId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($eventoId <= 0) abort_404('ID de evento inválido.');
tickex_require_event_access($pdo, $eventoId, $cu);

$stEv = $pdo->prepare('SELECT * FROM eventos WHERE id=:id LIMIT 1');
$stEv->execute(array(':id' => $eventoId));
$evento = $stEv->fetch(PDO::FETCH_ASSOC);
if (!$evento) abort_404('Evento no encontrado.');

$error = '';
$okMsg = '';
$csrf = tickex_csrf_token();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !tickex_csrf_verify(isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '')) {
    http_response_code(403);
    exit('Solicitud vencida o inválida.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_evento'])) {
    $nombre = trim(isset($_POST['nombre']) ? (string)$_POST['nombre'] : '');
    $fechaDesde = trim(isset($_POST['fecha_desde']) ? (string)$_POST['fecha_desde'] : '');
    $fechaHasta = trim(isset($_POST['fecha_hasta']) ? (string)$_POST['fecha_hasta'] : '');
    $descripcion = trim(isset($_POST['descripcion']) ? (string)$_POST['descripcion'] : '');
    if ($nombre === '') $error = 'El nombre es obligatorio.';

    $flyerFilename = isset($evento['flyer_filename']) ? $evento['flyer_filename'] : null;
    if ($error === '' && isset($_FILES['flyer']) && $_FILES['flyer']['error'] !== UPLOAD_ERR_NO_FILE) {
        if ($_FILES['flyer']['error'] !== UPLOAD_ERR_OK) {
            $error = 'Error al subir el flyer.';
        } elseif ((int)$_FILES['flyer']['size'] > 2 * 1024 * 1024) {
            $error = 'El flyer no puede pesar más de 2 MB.';
        } else {
            $imageInfo = @getimagesize($_FILES['flyer']['tmp_name']);
            $allowedMime = array('image/png' => 'png', 'image/jpeg' => 'jpg');
            $mime = is_array($imageInfo) && isset($imageInfo['mime']) ? $imageInfo['mime'] : '';
            if (!isset($allowedMime[$mime])) {
                $error = 'El archivo debe ser una imagen PNG o JPG válida.';
            } else {
                $directory = __DIR__ . '/event_flyers';
                if (!is_dir($directory)) @mkdir($directory, 0755, true);
                $new = bin2hex(random_bytes(16)) . '.' . $allowedMime[$mime];
                if (!move_uploaded_file($_FILES['flyer']['tmp_name'], $directory . '/' . $new)) {
                    $error = 'No se pudo guardar el flyer.';
                } else {
                    $flyerFilename = 'event_flyers/' . $new;
                }
            }
        }
    }

    if ($error === '') {
        $st = $pdo->prepare('UPDATE eventos SET nombre=:nombre, descripcion=:descripcion, flyer_filename=:flyer, fecha_desde=:desde, fecha_hasta=:hasta WHERE id=:id');
        $st->execute(array(
            ':nombre' => $nombre,
            ':descripcion' => $descripcion !== '' ? $descripcion : null,
            ':flyer' => $flyerFilename,
            ':desde' => $fechaDesde !== '' ? $fechaDesde : null,
            ':hasta' => $fechaHasta !== '' ? $fechaHasta : null,
            ':id' => $eventoId,
        ));
        $okMsg = 'Evento actualizado.';
        $stEv->execute(array(':id' => $eventoId));
        $evento = $stEv->fetch(PDO::FETCH_ASSOC);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_from_template'])) {
    $tplId = isset($_POST['tpl_id']) ? (int)$_POST['tpl_id'] : 0;
    if ($tplId <= 0) $error = 'Plantilla inválida.';
    if ($error === '') {
        $sql = 'SELECT * FROM plantillas_entrada WHERE id=:id AND activo=1';
        $params = array(':id' => $tplId);
        if (!tickex_is_super_admin($cu)) {
            $sql .= ' AND creado_por_admin_id=:admin';
            $params[':admin'] = $adminId;
        }
        $stTpl = $pdo->prepare($sql . ' LIMIT 1');
        $stTpl->execute($params);
        $tpl = $stTpl->fetch(PDO::FETCH_ASSOC);
        if (!$tpl) $error = 'No se encontró la plantilla.';
    }
    if ($error === '') {
        $precio = (int)(isset($tpl['precio_default']) ? $tpl['precio_default'] : (isset($tpl['precio']) ? $tpl['precio'] : 0));
        $cantidad = (int)(isset($tpl['cantidad_default']) ? $tpl['cantidad_default'] : (isset($tpl['cantidad_total']) ? $tpl['cantidad_total'] : 0));
        $tipo = isset($tpl['tipo']) ? (string)$tpl['tipo'] : '';
        if (!in_array($tipo, array('free', 'paga'), true)) $tipo = $precio > 0 ? 'paga' : 'free';
        if ($tipo === 'free') $precio = 0;
        $stIns = $pdo->prepare('INSERT INTO tipos_entrada (evento_id,nombre,tipo,precio,cantidad_total,cantidad_disponible,hora_limite,reglas_precio,qr_quantity) VALUES (:evento,:nombre,:tipo,:precio,:total,:disponible,:hora,:reglas,:qr)');
        $stIns->execute(array(
            ':evento' => $eventoId,
            ':nombre' => isset($tpl['nombre']) ? (string)$tpl['nombre'] : '',
            ':tipo' => $tipo,
            ':precio' => $precio,
            ':total' => $cantidad,
            ':disponible' => $cantidad,
            ':hora' => isset($tpl['hora_limite_default']) ? $tpl['hora_limite_default'] : (isset($tpl['hora_limite']) ? $tpl['hora_limite'] : null),
            ':reglas' => isset($tpl['reglas_default']) ? $tpl['reglas_default'] : (isset($tpl['reglas_precio']) ? $tpl['reglas_precio'] : null),
            ':qr' => isset($tpl['qr_quantity']) ? max(1, min(10, (int)$tpl['qr_quantity'])) : 1,
        ));
        $okMsg = 'Tipo agregado desde Mis entradas.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_type'])) {
    $typeId = isset($_POST['type_id']) ? (int)$_POST['type_id'] : 0;
    $st = $pdo->prepare('DELETE FROM tipos_entrada WHERE id=:id AND evento_id=:evento');
    $st->execute(array(':id' => $typeId, ':evento' => $eventoId));
    if ($st->rowCount() !== 1) abort_404('Tipo inexistente.');
    header('Location: editar_evento.php?id=' . $eventoId, true, 303);
    exit;
}

$stTE = $pdo->prepare('SELECT * FROM tipos_entrada WHERE evento_id=:evento ORDER BY id DESC');
$stTE->execute(array(':evento' => $eventoId));
$tiposEvento = $stTE->fetchAll(PDO::FETCH_ASSOC);

$params = array();
$sql = 'SELECT * FROM plantillas_entrada WHERE activo=1';
if (!tickex_is_super_admin($cu)) {
    $sql .= ' AND creado_por_admin_id=:admin';
    $params[':admin'] = $adminId;
}
$stTpl = $pdo->prepare($sql . ' ORDER BY categoria ASC, nombre ASC');
$stTpl->execute($params);
$plantillas = $stTpl->fetchAll(PDO::FETCH_ASSOC);

$title = 'Editar evento';
include __DIR__ . '/inc/layout_top.php';
?>
<div class="card" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
  <a class="btn secondary" href="panel_evento.php">Mis eventos</a>
  <a class="btn secondary" href="panel_evento.php?evento_id=<?php echo (int)$eventoId; ?>">Panel del evento</a>
</div>
<?php if ($error !== ''): ?><div class="flash err"><?php echo e($error); ?></div><?php endif; ?>
<?php if ($okMsg !== ''): ?><div class="flash ok"><?php echo e($okMsg); ?></div><?php endif; ?>

<div class="card">
  <h2>Editar evento</h2>
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="_csrf" value="<?php echo e($csrf); ?>">
    <input type="hidden" name="save_evento" value="1">
    <label>Nombre</label><input name="nombre" value="<?php echo e($evento['nombre']); ?>" required>
    <label>Descripción</label><textarea name="descripcion" rows="3"><?php echo e(isset($evento['descripcion']) ? $evento['descripcion'] : ''); ?></textarea>
    <label>Fechas (desde / hasta)</label>
    <div style="display:flex;gap:8px;flex-wrap:wrap;"><input type="date" name="fecha_desde" value="<?php echo e(isset($evento['fecha_desde']) ? $evento['fecha_desde'] : ''); ?>"><input type="date" name="fecha_hasta" value="<?php echo e(isset($evento['fecha_hasta']) ? $evento['fecha_hasta'] : ''); ?>"></div>
    <label>Flyer (PNG/JPG, máximo 2 MB)</label><input type="file" name="flyer" accept="image/png,image/jpeg">
    <div style="margin-top:10px;"><button class="btn" type="submit">Guardar cambios</button></div>
  </form>
</div>

<div class="card">
  <h2>Tipos de entrada del evento</h2>
  <?php if (!$tiposEvento): ?><div class="muted">Todavía no agregaste tipos.</div><?php else: ?>
  <div style="overflow:auto;"><table class="table"><thead><tr><th>Nombre</th><th>Tipo</th><th>Precio</th><th>Cantidad</th><th>Acciones</th></tr></thead><tbody>
  <?php foreach ($tiposEvento as $te): ?><tr>
    <td><?php echo e(isset($te['nombre']) ? $te['nombre'] : ''); ?></td>
    <td><?php echo e(isset($te['tipo']) ? $te['tipo'] : (isset($te['tipo_venta']) ? $te['tipo_venta'] : '')); ?></td>
    <td>$<?php echo number_format((float)(isset($te['precio']) ? $te['precio'] : 0), 0, ',', '.'); ?></td>
    <td><?php echo (int)(isset($te['cantidad_total']) ? $te['cantidad_total'] : 0); ?></td>
    <td><form method="post" style="display:inline" onsubmit="return confirm('¿Eliminar este tipo de entrada?');"><input type="hidden" name="_csrf" value="<?php echo e($csrf); ?>"><input type="hidden" name="delete_type" value="1"><input type="hidden" name="type_id" value="<?php echo (int)$te['id']; ?>"><button class="btn danger" type="submit">Eliminar</button></form></td>
  </tr><?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Agregar desde Mis entradas</h2>
  <?php if (!$plantillas): ?><div class="muted">No tenés plantillas activas todavía.</div><?php else: ?>
  <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
    <input type="hidden" name="_csrf" value="<?php echo e($csrf); ?>"><input type="hidden" name="add_from_template" value="1">
    <select name="tpl_id" required><option value="">Elegí una plantilla...</option><?php foreach ($plantillas as $p): ?><option value="<?php echo (int)$p['id']; ?>"><?php echo e((isset($p['categoria']) ? $p['categoria'] . ' — ' : '') . $p['nombre']); ?></option><?php endforeach; ?></select>
    <button class="btn secondary" type="submit">Agregar al evento</button>
    <a class="btn secondary" href="mis_entradas.php">Gestionar Mis entradas</a>
  </form>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/inc/layout_bottom.php'; ?>
