<?php
require_once __DIR__.'/inc/bootstrap.php';
require_once __DIR__.'/inc/venues.php';

require_login();
$cu = current_user();
$rol = isset($cu['tipo_global']) && $cu['tipo_global'] !== ''
  ? $cu['tipo_global']
  : (isset($cu['rol']) ? $cu['rol'] : (isset($_SESSION['tipo_global']) ? $_SESSION['tipo_global'] : ''));

if (!in_array($rol, array('admin_evento','super_admin','superadmin'), true)) {
    http_response_code(403);
    include __DIR__.'/inc/layout_top.php';
    echo "<div class='card error'><h2>Acceso denegado</h2><p>No tenés permiso para gestionar venues.</p></div>";
    include __DIR__.'/inc/layout_bottom.php';
    exit;
}

$isSuper = in_array($rol, array('super_admin','superadmin'), true);
$adminId = 0;
if (isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] > 0) {
  $adminId = (int)$_SESSION['user_id'];
} elseif (isset($_SESSION['admin_id']) && (int)$_SESSION['admin_id'] > 0) {
  $adminId = (int)$_SESSION['admin_id'];
} elseif (isset($cu['id']) && (int)$cu['id'] > 0) {
  $adminId = (int)$cu['id'];
}
$pdo = db();

ensure_venues_table($pdo);
ensure_evento_venue_table($pdo);

$eventoId = isset($_GET['evento_id']) ? (int)$_GET['evento_id'] : 0;
$error = '';
$okMsg = '';

$colsEv = $pdo->query("PRAGMA table_info(eventos)")->fetchAll(PDO::FETCH_ASSOC);
$hasCreadoPorEv = false;
foreach ($colsEv as $c) {
    if (isset($c['name']) && $c['name'] === 'creado_por_admin_id') { $hasCreadoPorEv = true; break; }
}

$eventos = array();
if ($isSuper) {
    $stE = $pdo->query("SELECT id, nombre, slug FROM eventos ORDER BY id DESC");
    $eventos = $stE ? $stE->fetchAll(PDO::FETCH_ASSOC) : array();
} else {
    if ($hasCreadoPorEv) {
        $stE = $pdo->prepare("SELECT id, nombre, slug FROM eventos WHERE creado_por_admin_id = :aid ORDER BY id DESC");
        $stE->execute(array(':aid' => $adminId));
        $eventos = $stE->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stE = $pdo->query("SELECT id, nombre, slug FROM eventos ORDER BY id DESC");
        $eventos = $stE ? $stE->fetchAll(PDO::FETCH_ASSOC) : array();
    }
}

$allowedEventIds = array();
foreach ($eventos as $ev) $allowedEventIds[(int)$ev['id']] = true;
if ($eventoId > 0 && !isset($allowedEventIds[$eventoId])) {
    $eventoId = 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $provided = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
    if (function_exists('tickex_csrf_verify') && !tickex_csrf_verify($provided)) {
        $error = 'CSRF inválido.';
    } else {
        $action = isset($_POST['action']) ? (string)$_POST['action'] : '';

        if ($action === 'save_venue') {
            $venueId = isset($_POST['venue_id']) ? (int)$_POST['venue_id'] : 0;
            $nombre = trim((string)($_POST['nombre'] ?? ''));
            $direccion = trim((string)($_POST['direccion'] ?? ''));
            $costoBase = (float)($_POST['costo_base'] ?? 0);
            $detalles = trim((string)($_POST['detalles'] ?? ''));
            if ($nombre === '') {
                $error = 'El nombre del venue es obligatorio.';
            } else {
                try {
                    if ($venueId > 0) {
                        if ($isSuper) {
                            $stUp = $pdo->prepare("UPDATE venues SET nombre=:n, direccion=:d, costo_base=:c, detalles=:m, updated_at=datetime('now') WHERE id=:id");
                            $stUp->execute(array(':n'=>$nombre, ':d'=>$direccion, ':c'=>$costoBase, ':m'=>$detalles, ':id'=>$venueId));
                        } else {
                            $stUp = $pdo->prepare("UPDATE venues SET nombre=:n, direccion=:d, costo_base=:c, detalles=:m, updated_at=datetime('now') WHERE id=:id AND created_by_admin_id=:aid");
                            $stUp->execute(array(':n'=>$nombre, ':d'=>$direccion, ':c'=>$costoBase, ':m'=>$detalles, ':id'=>$venueId, ':aid'=>$adminId));
                        }
                        $okMsg = 'Venue actualizado.';
                    } else {
                        $stIns = $pdo->prepare("INSERT INTO venues (nombre, direccion, costo_base, detalles, created_by_admin_id, activo, created_at, updated_at)
                            VALUES (:n, :d, :c, :m, :aid, 1, datetime('now'), datetime('now'))");
                        $stIns->execute(array(':n'=>$nombre, ':d'=>$direccion, ':c'=>$costoBase, ':m'=>$detalles, ':aid'=>$adminId));
                        $okMsg = 'Venue creado.';
                    }
                } catch (Exception $e) {
                    $error = 'No se pudo guardar el venue.';
                }
            }
        }

        if ($action === 'delete_venue') {
            $venueId = isset($_POST['venue_id']) ? (int)$_POST['venue_id'] : 0;
            if ($venueId > 0) {
                try {
                    $stUse = $pdo->prepare("SELECT COUNT(*) FROM evento_venue WHERE venue_id = :id");
                    $stUse->execute(array(':id' => $venueId));
                    $inUse = (int)$stUse->fetchColumn();
                    if ($inUse > 0) {
                        $error = 'No se puede eliminar: el venue está asignado a uno o más eventos.';
                    } else {
                        if ($isSuper) {
                            $stDel = $pdo->prepare("DELETE FROM venues WHERE id = :id");
                            $stDel->execute(array(':id'=>$venueId));
                        } else {
                            $stDel = $pdo->prepare("DELETE FROM venues WHERE id = :id AND created_by_admin_id = :aid");
                            $stDel->execute(array(':id'=>$venueId, ':aid'=>$adminId));
                        }
                        $okMsg = 'Venue eliminado.';
                    }
                } catch (Exception $e) {
                    $error = 'No se pudo eliminar el venue.';
                }
            }
        }

        if ($action === 'assign_venue') {
            $eventoPost = isset($_POST['evento_id']) ? (int)$_POST['evento_id'] : 0;
            $venuePost = isset($_POST['venue_id']) ? (int)$_POST['venue_id'] : 0;
            $costoVenue = (float)($_POST['costo_venue'] ?? 0);
            $comentarios = trim((string)($_POST['comentarios'] ?? ''));

            if ($eventoPost <= 0 || !isset($allowedEventIds[$eventoPost])) {
                $error = 'Evento inválido.';
            } elseif ($venuePost <= 0) {
                $error = 'Seleccioná un venue.';
            } else {
                if (assign_venue_to_event($pdo, $eventoPost, $venuePost, $costoVenue, $comentarios)) {
                    $okMsg = 'Venue asignado al evento.';
                    $eventoId = $eventoPost;
                } else {
                    $error = 'No se pudo asignar el venue.';
                }
            }
        }
    }
}

$venues = get_venues($pdo, $adminId, $isSuper);
$assignment = $eventoId > 0 ? get_venue_assignment($pdo, $eventoId) : null;

$editVenue = null;
$editId = isset($_GET['edit_id']) ? (int)$_GET['edit_id'] : 0;
if ($editId > 0) {
    foreach ($venues as $v) {
        if ((int)$v['id'] === $editId) { $editVenue = $v; break; }
    }
}

$title = 'Venues';
include __DIR__.'/inc/layout_top.php';
?>

<div class="card" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
  <a class="btn secondary" href="panel_admin.php">⬅ Volver al panel</a>
  <?php if ($eventoId > 0): ?>
    <a class="btn secondary" href="panel_evento.php?evento_id=<?php echo (int)$eventoId; ?>">Volver al evento</a>
  <?php endif; ?>
</div>

<?php if ($error !== ''): ?><div class="flash err"><?php echo e($error); ?></div><?php endif; ?>
<?php if ($okMsg !== ''): ?><div class="flash ok"><?php echo e($okMsg); ?></div><?php endif; ?>

<div class="card" style="max-width:980px;">
  <h2>Venue</h2>
  <div class="muted">Gestión de lugares (nombre, dirección, costo y notas) y asignación por evento.</div>
</div>

<div class="card" style="max-width:980px;">
  <h3><?php echo $editVenue ? 'Editar venue' : 'Nuevo venue'; ?></h3>
  <form method="post" style="display:grid;grid-template-columns:1fr 1fr;gap:10px;align-items:end;">
    <input type="hidden" name="csrf" value="<?php echo e(tickex_csrf_token()); ?>">
    <input type="hidden" name="action" value="save_venue">
    <input type="hidden" name="venue_id" value="<?php echo $editVenue ? (int)$editVenue['id'] : 0; ?>">

    <div>
      <label>Nombre</label>
      <input type="text" name="nombre" required value="<?php echo e($editVenue ? $editVenue['nombre'] : ''); ?>">
    </div>
    <div>
      <label>Dirección</label>
      <input type="text" name="direccion" value="<?php echo e($editVenue ? $editVenue['direccion'] : ''); ?>">
    </div>
    <div>
      <label>Costo base ($)</label>
      <input type="number" min="0" step="0.01" name="costo_base" value="<?php echo e($editVenue ? (string)$editVenue['costo_base'] : '0'); ?>">
    </div>
    <div>
      <label>Detalles / comentarios</label>
      <input type="text" name="detalles" value="<?php echo e($editVenue ? $editVenue['detalles'] : ''); ?>">
    </div>
    <div style="grid-column:1 / -1;display:flex;gap:8px;">
      <button class="btn" type="submit"><?php echo $editVenue ? 'Guardar cambios' : 'Crear venue'; ?></button>
      <?php if ($editVenue): ?><a class="btn secondary" href="venues.php<?php echo $eventoId>0 ? ('?evento_id='.(int)$eventoId) : ''; ?>">Cancelar</a><?php endif; ?>
    </div>
  </form>
</div>

<div class="card" style="max-width:980px;">
  <h3>Asignar venue a evento</h3>
  <form method="post" style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;align-items:end;">
    <input type="hidden" name="csrf" value="<?php echo e(tickex_csrf_token()); ?>">
    <input type="hidden" name="action" value="assign_venue">

    <div>
      <label>Evento</label>
      <select name="evento_id" required>
        <option value="">Seleccioná evento...</option>
        <?php foreach ($eventos as $ev): ?>
          <option value="<?php echo (int)$ev['id']; ?>" <?php echo ($eventoId === (int)$ev['id']) ? 'selected' : ''; ?>>
            #<?php echo (int)$ev['id']; ?> — <?php echo e($ev['nombre']); ?> (<?php echo e($ev['slug']); ?>)
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div>
      <label>Venue</label>
      <select name="venue_id" required>
        <option value="">Seleccioná venue...</option>
        <?php foreach ($venues as $v): ?>
          <?php $sel = ($assignment && (int)$assignment['venue_id'] === (int)$v['id']) ? 'selected' : ''; ?>
          <option value="<?php echo (int)$v['id']; ?>" <?php echo $sel; ?>>
            <?php echo e($v['nombre']); ?><?php if (!empty($v['direccion'])): ?> — <?php echo e($v['direccion']); ?><?php endif; ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div>
      <label>Costo venue ($)</label>
      <input type="number" min="0" step="0.01" name="costo_venue" value="<?php echo e($assignment ? (string)$assignment['costo_venue'] : '0'); ?>">
    </div>

    <div style="grid-column:1 / -1;">
      <label>Comentarios asignación</label>
      <input type="text" name="comentarios" value="<?php echo e($assignment ? (string)$assignment['comentarios'] : ''); ?>" placeholder="Ej: incluye seguridad, barra, limpieza...">
    </div>

    <div style="grid-column:1 / -1;display:flex;gap:8px;">
      <button class="btn" type="submit">Guardar asignación</button>
    </div>
  </form>

  <?php if ($assignment): ?>
    <div style="margin-top:12px;padding:10px;border:1px solid var(--line);border-radius:8px;background:var(--panel-2);">
      <strong>Asignación actual:</strong>
      <?php echo e((string)$assignment['venue_nombre']); ?>
      <?php if (!empty($assignment['venue_direccion'])): ?> — <?php echo e((string)$assignment['venue_direccion']); ?><?php endif; ?>
      <br>
      <span class="muted">Costo: $<?php echo number_format((float)$assignment['costo_venue'], 2); ?></span>
      <?php if (!empty($assignment['comentarios'])): ?><br><span class="muted"><?php echo e((string)$assignment['comentarios']); ?></span><?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<div class="card" style="max-width:980px;">
  <h3>Mis venues</h3>
  <?php if (empty($venues)): ?>
    <div class="muted">No hay venues cargados todavía.</div>
  <?php else: ?>
    <div style="overflow:auto;">
      <table class="table" style="width:100%;min-width:760px;">
        <thead>
          <tr>
            <th>Nombre</th>
            <th>Dirección</th>
            <th>Costo base</th>
            <th>Detalles</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($venues as $v): ?>
            <tr>
              <td><?php echo e((string)$v['nombre']); ?></td>
              <td><?php echo e((string)($v['direccion'] ?? '')); ?></td>
              <td>$<?php echo number_format((float)($v['costo_base'] ?? 0), 2); ?></td>
              <td><?php echo e((string)($v['detalles'] ?? '')); ?></td>
              <td style="text-align:right;display:flex;gap:6px;justify-content:flex-end;">
                <a class="btn secondary" href="venues.php?edit_id=<?php echo (int)$v['id']; ?><?php echo $eventoId > 0 ? ('&evento_id=' . (int)$eventoId) : ''; ?>">✏</a>
                <form method="post" style="margin:0;" onsubmit="return confirm('¿Eliminar venue?');">
                  <input type="hidden" name="csrf" value="<?php echo e(tickex_csrf_token()); ?>">
                  <input type="hidden" name="action" value="delete_venue">
                  <input type="hidden" name="venue_id" value="<?php echo (int)$v['id']; ?>">
                  <button class="btn danger" type="submit">🗑</button>
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
