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
$prefEventoId = isset($_GET['evento_id']) ? (int)$_GET['evento_id'] : 0;

ensure_produccion_table($pdo);
ensure_produccion_assignment_table($pdo);

// Eventos visibles
$colsEv = $pdo->query("PRAGMA table_info(eventos)")->fetchAll(PDO::FETCH_ASSOC);
$hasCreadoPor = false;
foreach ($colsEv as $c) { if (isset($c['name']) && $c['name']==='creado_por_admin_id') { $hasCreadoPor=true; break; } }

if ($rol === 'super_admin' || $rol === 'superadmin') {
  $stmtEv = $pdo->query("SELECT id, nombre, slug FROM eventos ORDER BY id DESC");
  $eventos = $stmtEv ? $stmtEv->fetchAll(PDO::FETCH_ASSOC) : array();
} else {
  if ($hasCreadoPor) {
    $stmtEv = $pdo->prepare("SELECT id, nombre, slug FROM eventos WHERE creado_por_admin_id = :aid ORDER BY id DESC");
    $stmtEv->execute(array(':aid'=>(int)$cu['id']));
    $eventos = $stmtEv->fetchAll(PDO::FETCH_ASSOC);
  } else {
    $stmtEv = $pdo->query("SELECT id, nombre, slug FROM eventos ORDER BY id DESC");
    $eventos = $stmtEv ? $stmtEv->fetchAll(PDO::FETCH_ASSOC) : array();
  }
}

// Crear / agregar artista
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_artista') {
  $nombre   = trim(isset($_POST['nombre']) ? $_POST['nombre'] : '');
  $tipo     = trim(isset($_POST['tipo']) ? $_POST['tipo'] : '');
  $categoria = trim(isset($_POST['categoria']) ? $_POST['categoria'] : '');
  $precio   = isset($_POST['precio']) ? (float)$_POST['precio'] : 0;
  $origen   = trim(isset($_POST['origen']) ? $_POST['origen'] : '');
  $viaticos = isset($_POST['pide_viaticos']) ? 1 : 0;
  $viaticosMonto = isset($_POST['viaticos_monto']) ? (float)$_POST['viaticos_monto'] : 0;
  $telefono = trim(isset($_POST['telefono']) ? $_POST['telefono'] : '');
  $email    = trim(isset($_POST['email']) ? $_POST['email'] : '');
  $notas    = trim(isset($_POST['notas']) ? $_POST['notas'] : '');

    if ($nombre === '') {
        flash('warn','El nombre es obligatorio.');
    } else {
        try {
            $stmt = $pdo->prepare("INSERT INTO produccion_artistas (nombre, tipo, categoria, precio, origen, pide_viaticos, viaticos_monto, telefono, email, notas) VALUES (:n,:t,:c,:p,:o,:v,:vm,:tel,:em,:no)");
            $stmt->execute(array(
                ':n' => $nombre,
                ':t' => $tipo,
                ':c' => $categoria,
                ':p' => $precio,
                ':o' => $origen,
              ':v' => $viaticos,
              ':vm'=> $viaticosMonto,
              ':tel'=> $telefono,
              ':em'=> $email,
                ':no'=> $notas,
            ));
            flash('ok','Artista agregado.');
        } catch (Exception $e) {
            flash('err','Error al guardar: '.$e->getMessage());
        }
    }
}

// Editar artista existente
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_artista') {
  $id        = isset($_POST['id']) ? (int)$_POST['id'] : 0;
  $nombre    = trim(isset($_POST['nombre']) ? $_POST['nombre'] : '');
  $tipo      = trim(isset($_POST['tipo']) ? $_POST['tipo'] : '');
  $categoria = trim(isset($_POST['categoria']) ? $_POST['categoria'] : '');
  $precio    = isset($_POST['precio']) ? (float)$_POST['precio'] : 0;
  $origen    = trim(isset($_POST['origen']) ? $_POST['origen'] : '');
  $viaticos  = isset($_POST['pide_viaticos']) ? 1 : 0;
  $viaticosMonto = isset($_POST['viaticos_monto']) ? (float)$_POST['viaticos_monto'] : 0;
  $telefono  = trim(isset($_POST['telefono']) ? $_POST['telefono'] : '');
  $email     = trim(isset($_POST['email']) ? $_POST['email'] : '');
  $notas     = trim(isset($_POST['notas']) ? $_POST['notas'] : '');

  if ($id <= 0 || $nombre === '') {
    flash('warn','Elegí un artista y completá el nombre.');
  } else {
    try {
      $stmt = $pdo->prepare("UPDATE produccion_artistas
        SET nombre=:n, tipo=:t, categoria=:c, precio=:p, origen=:o,
          pide_viaticos=:v, viaticos_monto=:vm, telefono=:tel, email=:em, notas=:no,
          updated_at=CURRENT_TIMESTAMP
        WHERE id=:id");
      $stmt->execute(array(
        ':n'=>$nombre,
        ':t'=>$tipo,
        ':c'=>$categoria,
        ':p'=>$precio,
        ':o'=>$origen,
        ':v'=>$viaticos,
        ':vm'=>$viaticosMonto,
        ':tel'=>$telefono,
        ':em'=>$email,
        ':no'=>$notas,
        ':id'=>$id,
      ));
      flash('ok','Artista actualizado.');
      if ($prefEventoId > 0) {
        header('Location: produccion.php?evento_id='.(int)$prefEventoId);
        exit;
      }
    } catch (Exception $e) {
      flash('err','No se pudo actualizar: '.$e->getMessage());
    }
  }
}

// Asignar artista a uno o varios eventos
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'assign_artist') {
  $artistaId = isset($_POST['artista_id']) ? (int)$_POST['artista_id'] : 0;
  $allEvents = isset($_POST['all_events']) && (int)$_POST['all_events'] === 1;
  $precio    = isset($_POST['precio_asig']) && $_POST['precio_asig'] !== '' ? (float)$_POST['precio_asig'] : null;
  $notasA    = trim(isset($_POST['notas_asig']) ? $_POST['notas_asig'] : '');

  $eventoSel = array();
  if (isset($_POST['evento_id'])) {
    if (is_array($_POST['evento_id'])) {
      foreach ($_POST['evento_id'] as $e) { $eid = (int)$e; if ($eid>0) $eventoSel[$eid]=true; }
    } else {
      $eid = (int)$_POST['evento_id']; if ($eid>0) $eventoSel[$eid]=true;
    }
  }

  if ($artistaId <= 0 || (!$allEvents && empty($eventoSel))) {
    flash('warn','Elegí artista y al menos un evento (o Todos).');
  } else {
    // construir lista final de eventos
    $selected = array();
    if ($allEvents) {
      foreach ($eventos as $ev) { $selected[(int)$ev['id']] = true; }
    }
    foreach (array_keys($eventoSel) as $eid) { $selected[$eid] = true; }

    $ok = add_produccion_assignment_multi($pdo, $artistaId, array_keys($selected), $precio, $notasA);
    flash($ok ? 'ok' : 'err', $ok ? 'Artista asignado a '.count($selected).' evento(s).' : 'No se pudo asignar.');
    if ($prefEventoId > 0) {
      header('Location: produccion.php?evento_id='.(int)$prefEventoId);
      exit;
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
  <?php if ($prefEventoId > 0): ?>
    <span class="pill" style="background:var(--panel-2);border:1px solid var(--line);">Evento #<?php echo (int)$prefEventoId; ?></span>
  <?php endif; ?>
  <h2 style="margin:0;">Producción</h2>
  <span class="muted">Registro rápido de DJs / productores con costos y notas.</span>
</div>

<?php if ($prefEventoId <= 0): ?>
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
    <div>
      <label>Monto viáticos ($)</label>
      <input type="number" step="0.01" min="0" name="viaticos_monto" placeholder="0">
    </div>
    <div>
      <label>Teléfono</label>
      <input name="telefono" placeholder="+54...">
    </div>
    <div>
      <label>Email</label>
      <input type="email" name="email" placeholder="contacto@ejemplo.com">
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
<?php else: ?>
<div class="card" style="max-width:900px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
  <div class="muted">Para crear un nuevo artista, abrí la vista general.</div>
  <a class="btn" href="produccion.php" style="background:var(--ok);color:#04150a;">+ Agregar artista</a>
</div>
<?php endif; ?>

<div class="card" style="max-width:900px;">
  <h3>Asignar artista existente a eventos</h3>
  <form method="post" style="display:grid;grid-template-columns:1.2fr 1fr 1fr;gap:10px;align-items:end;">
    <input type="hidden" name="action" value="assign_artist">
    <div>
      <label>Artista</label>
      <div style="display:flex;gap:6px;flex-direction:column;">
        <input type="text" id="filterArtist" placeholder="Filtrar artista" oninput="filterArtistOptions()">
        <select name="artista_id" id="artistSelect" required>
          <option value="">Elegí artista...</option>
          <?php foreach ($artistas as $a): ?>
            <option value="<?php echo (int)$a['id']; ?>" data-name="<?php echo e(strtolower($a['nombre'])); ?>"><?php echo e($a['nombre']); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div>
      <label>Eventos</label>
      <div style="display:flex;gap:6px;flex-direction:column;">
        <input type="text" id="filterEvento" placeholder="Filtrar evento" oninput="filterEventOptions()">
        <select name="evento_id[]" id="eventSelect" multiple size="6" style="min-height:140px;">
          <?php foreach ($eventos as $ev): ?>
            <option value="<?php echo (int)$ev['id']; ?>" data-name="<?php echo e(strtolower($ev['nombre'].' '.$ev['slug'])); ?>" <?php echo ($prefEventoId === (int)$ev['id']) ? 'selected' : ''; ?>>
              #<?php echo (int)$ev['id']; ?> — <?php echo e($ev['nombre']); ?>
            </option>
          <?php endforeach; ?>
        </select>
        <label style="font-size:12px;display:flex;gap:6px;align-items:center;margin-top:2px;">
          <input type="checkbox" name="all_events" value="1"> Todos los eventos
        </label>
      </div>
    </div>
    <div>
      <label>Precio (opcional)</label>
      <input type="number" step="0.01" min="0" name="precio_asig" placeholder="Usar precio base">
      <label style="margin-top:6px;display:block;">Notas (opcional)</label>
      <textarea name="notas_asig" rows="3" placeholder="Horario, stage, rider..."></textarea>
    </div>
    <div style="grid-column:1 / -1;">
      <button class="btn" type="submit">Asignar</button>
    </div>
  </form>
  <script>
    function filterArtistOptions(){
      var q = document.getElementById('filterArtist').value.toLowerCase();
      var sel = document.getElementById('artistSelect');
      for (var i=0;i<sel.options.length;i++) {
        var opt = sel.options[i];
        var name = opt.getAttribute('data-name') || '';
        opt.hidden = (q !== '' && name.indexOf(q) === -1 && opt.value !== '');
      }
      if (sel.selectedIndex > 0 && sel.options[sel.selectedIndex].hidden) sel.selectedIndex = 0;
    }
    function filterEventOptions(){
      var q = document.getElementById('filterEvento').value.toLowerCase();
      var sel = document.getElementById('eventSelect');
      for (var i=0;i<sel.options.length;i++) {
        var opt = sel.options[i];
        var name = opt.getAttribute('data-name') || '';
        opt.hidden = (q !== '' && name.indexOf(q) === -1);
      }
    }
  </script>
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
            <th>Viáticos</th>
            <th>Total</th>
            <th>Origen</th>
            <th>Teléfono</th>
            <th>Email</th>
            <th>Notas</th>
            <th>Asignado a</th>
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
              <td><?php echo ((int)$a['pide_viaticos'] === 1) ? 'Sí ($'.number_format((float)$a['viaticos_monto'], 0, ',', '.').')' : 'No'; ?></td>
              <td>$<?php echo number_format((float)$a['precio'] + (float)$a['viaticos_monto'], 0, ',', '.'); ?></td>
              <td><?php echo e($a['origen']); ?></td>
              <td><?php echo $a['telefono'] !== '' ? e($a['telefono']) : '<span class="muted">-</span>'; ?></td>
              <td><?php echo $a['email'] !== '' ? e($a['email']) : '<span class="muted">-</span>'; ?></td>
              <td><?php echo nl2br(e($a['notas'])); ?></td>
              <td style="min-width:180px;">
                <?php $artEv = get_artist_event_ids($pdo, (int)$a['id']); ?>
                <?php if (empty($artEv)): ?>
                  <span class="muted">Sin asignar</span>
                <?php else: ?>
                  <div style="display:flex;flex-wrap:wrap;gap:6px;">
                    <?php foreach ($eventos as $ev): ?>
                      <?php if (in_array((int)$ev['id'], $artEv, true)): ?>
                        <span class="pill" style="background:var(--panel-2);border:1px solid var(--line);">#<?php echo (int)$ev['id']; ?> <?php echo e($ev['slug']); ?></span>
                      <?php endif; ?>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </td>
              <td>
                <div style="display:flex;flex-direction:column;gap:6px;">
                  <button class="btn secondary" type="button" onclick="toggleEdit(<?php echo (int)$a['id']; ?>)">Editar</button>
                  <form method="post" onsubmit="return confirm('¿Eliminar este artista?');">
                    <input type="hidden" name="action" value="del_artista">
                    <input type="hidden" name="id" value="<?php echo (int)$a['id']; ?>">
                    <button class="btn danger" type="submit">Borrar</button>
                  </form>
                </div>
              </td>
            </tr>
            <tr id="edit-row-<?php echo (int)$a['id']; ?>" style="display:none;background:var(--panel-2);">
              <td colspan="13">
                <form method="post" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px;align-items:end;">
                  <input type="hidden" name="action" value="edit_artista">
                  <input type="hidden" name="id" value="<?php echo (int)$a['id']; ?>">
                  <div>
                    <label>Nombre</label>
                    <input name="nombre" value="<?php echo e($a['nombre']); ?>" required>
                  </div>
                  <div>
                    <label>Tipo</label>
                    <select name="tipo">
                      <option value="DJ" <?php echo ($a['tipo']==='DJ')?'selected':''; ?>>DJ</option>
                      <option value="PRODUCTOR" <?php echo ($a['tipo']==='PRODUCTOR')?'selected':''; ?>>Productor</option>
                      <option value="DJ + PRODUCTOR" <?php echo ($a['tipo']==='DJ + PRODUCTOR')?'selected':''; ?>>DJ + Productor</option>
                    </select>
                  </div>
                  <div>
                    <label>Categoría</label>
                    <select name="categoria">
                      <option value="PRINCIPAL" <?php echo ($a['categoria']==='PRINCIPAL')?'selected':''; ?>>Principal</option>
                      <option value="SECUNDARIO" <?php echo ($a['categoria']==='SECUNDARIO')?'selected':''; ?>>Secundario</option>
                    </select>
                  </div>
                  <div>
                    <label>Precio ($)</label>
                    <input type="number" step="0.01" min="0" name="precio" value="<?php echo e($a['precio']); ?>">
                  </div>
                  <div>
                    <label>Monto viáticos ($)</label>
                    <input type="number" step="0.01" min="0" name="viaticos_monto" value="<?php echo e($a['viaticos_monto']); ?>">
                  </div>
                  <div style="display:flex;align-items:center;gap:6px;">
                    <input type="checkbox" id="pide_viaticos_<?php echo (int)$a['id']; ?>" name="pide_viaticos" value="1" <?php echo ((int)$a['pide_viaticos']===1)?'checked':''; ?>>
                    <label for="pide_viaticos_<?php echo (int)$a['id']; ?>" style="margin:0;">Pide viáticos</label>
                  </div>
                  <div>
                    <label>Origen</label>
                    <select name="origen">
                      <option value="LOCAL" <?php echo ($a['origen']==='LOCAL')?'selected':''; ?>>Local</option>
                      <option value="INTERIOR" <?php echo ($a['origen']==='INTERIOR')?'selected':''; ?>>Interior</option>
                      <option value="EXTRANJERO" <?php echo ($a['origen']==='EXTRANJERO')?'selected':''; ?>>Extranjeros</option>
                    </select>
                  </div>
                  <div>
                    <label>Teléfono</label>
                    <input name="telefono" value="<?php echo e($a['telefono']); ?>" placeholder="+54...">
                  </div>
                  <div>
                    <label>Email</label>
                    <input type="email" name="email" value="<?php echo e($a['email']); ?>" placeholder="contacto@ejemplo.com">
                  </div>
                  <div style="grid-column:1 / -1;">
                    <label>Notas</label>
                    <textarea name="notas" rows="2" placeholder="Rider, backline, horarios..."><?php echo e($a['notas']); ?></textarea>
                  </div>
                  <div style="grid-column:1 / -1;display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
                    <button class="btn" type="submit">Guardar cambios</button>
                    <button class="btn secondary" type="button" onclick="toggleEdit(<?php echo (int)$a['id']; ?>)">Cancelar</button>
                  </div>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<script>
  function toggleEdit(id){
    var row = document.getElementById('edit-row-'+id);
    if (!row) return;
    row.style.display = row.style.display === 'table-row' ? 'none' : 'table-row';
  }
</script>

<?php include __DIR__.'/inc/layout_bottom.php'; ?>
