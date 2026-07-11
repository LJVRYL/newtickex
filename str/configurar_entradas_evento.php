<?php
require_once __DIR__.'/inc/bootstrap.php';
$pdo = db();
$eventoId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Actualizar cantidad_disponible
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_disponible') {
  $teId = isset($_POST['id']) ? (int)$_POST['id'] : 0;
  $cant = isset($_POST['cantidad_disponible']) ? (int)$_POST['cantidad_disponible'] : 0;
  if ($teId > 0 && $cant >= 0 && $eventoId > 0) {
    $upd = $pdo->prepare("UPDATE tipos_entrada SET cantidad_disponible = :cant WHERE id = :id AND evento_id = :eid");
    $upd->execute(array(':cant' => $cant, ':id' => $teId, ':eid' => $eventoId));
    $okMsg = "Cantidad disponible actualizada.";
  } else {
    $error = "Datos inválidos.";
  }


}
$title = "Configurar entradas del evento";
require_login();
$cu = current_user();
$tipoGlobal = isset($_SESSION['tipo_global'])
    ? $_SESSION['tipo_global'] : (isset($cu['tipo_global']) ? $cu['tipo_global'] : (isset($cu['rol']) ? $cu['rol'] : ''));
if (!in_array($tipoGlobal, array('admin_evento','super_admin','superadmin'), true)) {
  abort_404("No podés modificar este evento.");
}
$error = '';
$okMsg = '';

$adminId = isset($cu['id']) ? (int)$cu['id'] : 0;

function ensure_plantillas_entrada_schema($pdo) {
  try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS plantillas_entrada (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      admin_id INTEGER,
      creado_por_admin_id INTEGER,
      categoria TEXT DEFAULT 'GENERALES',
      nombre TEXT NOT NULL,
      tipo TEXT NOT NULL DEFAULT 'PAGA',
      precio_default REAL NOT NULL DEFAULT 0,
      cantidad_default INTEGER NOT NULL DEFAULT 0,
      hora_limite_default TEXT,
      descripcion TEXT,
      reglas_default TEXT,
      activo INTEGER NOT NULL DEFAULT 1,
      visible_publico INTEGER NOT NULL DEFAULT 1,
      venta_hasta TEXT,
      creado_en DATETIME DEFAULT (datetime('now'))
    )");

    $cols = $pdo->query("PRAGMA table_info(plantillas_entrada)")->fetchAll(PDO::FETCH_ASSOC);
    $has = array();
    foreach ($cols as $c) {
      $n = isset($c['name']) ? (string)$c['name'] : '';
      if ($n !== '') $has[$n] = true;
    }

    $required = array(
      'admin_id' => 'INTEGER',
      'creado_por_admin_id' => 'INTEGER',
      'categoria' => "TEXT DEFAULT 'GENERALES'",
      'nombre' => "TEXT DEFAULT ''",
      'tipo' => "TEXT DEFAULT 'PAGA'",
      'precio_default' => 'REAL NOT NULL DEFAULT 0',
      'cantidad_default' => 'INTEGER NOT NULL DEFAULT 0',
      'hora_limite_default' => 'TEXT',
      'descripcion' => 'TEXT',
      'reglas_default' => 'TEXT',
      'activo' => 'INTEGER NOT NULL DEFAULT 1',
      'visible_publico' => 'INTEGER NOT NULL DEFAULT 1',
      'venta_hasta' => 'TEXT',
      'creado_en' => "DATETIME DEFAULT (datetime('now'))",
    );
    foreach ($required as $col => $def) {
      if (!isset($has[$col])) {
        $pdo->exec("ALTER TABLE plantillas_entrada ADD COLUMN $col $def");
      }
    }
  } catch (Exception $e) {
    // ignore: se manejará con mensajes existentes si luego falla una query
  }
}

ensure_plantillas_entrada_schema($pdo);

$colsTE = $pdo->query("PRAGMA table_info(tipos_entrada)")->fetchAll(PDO::FETCH_ASSOC);
$hasCategoria = false;
$hasTipoVenta = false;
$hasHoraLimite = false;
$visCol = '';
if (is_array($colsTE)) {
  foreach ($colsTE as $c) {
    $n = isset($c['name']) ? strtolower((string)$c['name']) : '';
    if ($n === 'categoria') $hasCategoria = true;
    if ($n === 'tipo_venta') $hasTipoVenta = true;
    if ($n === 'hora_limite') $hasHoraLimite = true;
    if ($visCol === '' && ($n === 'visible_publico' || $n === 'publico' || $n === 'venta_publico' || $n === 'visible')) {
      $visCol = (string)$c['name'];
    }
  }
}

$hasTablaPlantillas = false;
try {
  $chkTpl = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='plantillas_entrada' LIMIT 1");
  if ($chkTpl && $chkTpl->fetch(PDO::FETCH_ASSOC)) $hasTablaPlantillas = true;
} catch (Exception $e) {
  $hasTablaPlantillas = false;
}

// =======================
// ELIMINAR TIPO DEL EVENTO (tachito)
// =======================
if (isset($_GET['del_te'])) {
    $teId = (int)$_GET['del_te'];

    $st = $pdo->prepare("SELECT id FROM tipos_entrada WHERE id = ? AND evento_id = ?");
    $st->execute(array($teId, $eventoId));
    if ($st->fetch(PDO::FETCH_ASSOC)) {
        $pdo->prepare("DELETE FROM tipos_entrada WHERE id = ?")->execute(array($teId));
        header("Location: configurar_entradas_evento.php?id=" . $eventoId);
        exit;
    } else {
        $error = "Tipo de entrada inexistente para este evento.";
    }
}

// =======================
// TOGGLE DE VISIBILIDAD DESDE EL SWITCH
// =======================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_vis' && $visCol) {
    $teId = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $val  = isset($_POST['val']) ? (int)$_POST['val'] : 0;
    if ($teId > 0) {
        $upd = $pdo->prepare("UPDATE tipos_entrada SET $visCol = :v WHERE id = :id AND evento_id = :eid");
        $upd->execute(array(':v' => $val, ':id' => $teId, ':eid' => $eventoId));
    }
    header("Location: configurar_entradas_evento.php?id=" . $eventoId);
    exit;
}

// =======================
// AGREGAR TIPO DESDE PLANTILLA (Mis Entradas)
// =======================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_from_template'])) {

  if (!$hasTablaPlantillas) {
    $error = "Esta base no tiene 'plantillas_entrada'. Crealas o agregá tipos manualmente.";
  }

    $tplId = isset($_POST['tpl_id']) ? (int)$_POST['tpl_id'] : 0;
    if ($tplId <= 0) {
        $error = "Plantilla inválida.";
    }

    if ($error === '' && $hasTablaPlantillas) {
      if ($tipoGlobal === 'super_admin' || $tipoGlobal === 'superadmin') {
        $stTpl = $pdo->prepare("SELECT * FROM plantillas_entrada WHERE id = ? AND activo = 1");
        $stTpl->execute(array($tplId));
      } else {
        $stTpl = $pdo->prepare("SELECT * FROM plantillas_entrada WHERE id = ? AND activo = 1 AND creado_por_admin_id = ?");
        $stTpl->execute(array($tplId, $adminId));
      }

      $tpl = $stTpl->fetch(PDO::FETCH_ASSOC);
      if (!$tpl) {
        $error = "No se encontró la plantilla o no tenés permiso para usarla.";
      }
    }

    if ($error === '') {
        // En tu schema, tipo y tipo_venta existen;
        // tipo es NOT NULL, así que lo seteamos igual que tipo_venta
        $tipoVenta = strtoupper($tpl['tipo']); // FREE / PAGA / ...
        $precio    = (int)$tpl['precio_default'];
        $cant      = (int)$tpl['cantidad_default'];
        $hora      = isset($tpl['hora_limite_default']) ? $tpl['hora_limite_default'] : null;
        $desc      = isset($tpl['descripcion']) ? $tpl['descripcion'] : null;
        $catVal    = $hasCategoria ? (isset($tpl['categoria']) ? $tpl['categoria'] : null) : null;

        if ($tipoVenta === 'FREE') {
          $precio = 0;
        }

        // Build INSERT compatible con esquemas sin categoria/tipo_venta/hora_limite
        $cols = array('evento_id','nombre','tipo','precio','cantidad_total','cantidad_disponible');
        $vals = array(':eid',':nom',':tipo',':pre',':ct',':cd');
        $params = array(
          ':eid'  => $eventoId,
          ':nom'  => $tpl['nombre'],
          ':tipo' => $tipoVenta,
          ':pre'  => $precio,
          ':ct'   => $cant,
          ':cd'   => $cant,
        );

        if ($hasCategoria) {
          $cols[] = 'categoria';
          $vals[] = ':cat';
          $params[':cat'] = $catVal;
        }
        if ($hasTipoVenta) {
          $cols[] = 'tipo_venta';
          $vals[] = ':tv';
          $params[':tv'] = $tipoVenta;
        }
        if ($hasHoraLimite) {
          $cols[] = 'hora_limite';
          $vals[] = ':hl';
          $params[':hl'] = $hora;
        }
        if ($visCol !== '') {
          $cols[] = $visCol;
          $vals[] = ':visible_publico';
          $params[':visible_publico'] = !empty($tpl['visible_publico']) ? 1 : 0;
        }

        // descripcion si existe la columna
        $hasDesc = false;
        foreach ($colsTE as $c) {
          if (isset($c['name']) && $c['name'] === 'descripcion') {
            $hasDesc = true; break;
          }
        }
        if ($hasDesc) {
          $cols[] = 'descripcion';
          $vals[] = ':desc';
          $params[':desc'] = $desc;
        }

        $sqlIns = "INSERT INTO tipos_entrada (".implode(',', $cols).") VALUES (".implode(',', $vals).")";
        $stIns = $pdo->prepare($sqlIns);
        $stIns->execute($params);

        $okMsg = "Tipo de entrada agregado al evento desde Mis Entradas.";
    }
}

    // ===== Tipos ya asociados al evento =====
    $orderBy = $hasCategoria ? 'categoria ASC, nombre ASC, id ASC' : 'nombre ASC, id ASC';
    $stTE = $pdo->prepare("SELECT * FROM tipos_entrada WHERE evento_id = ? ORDER BY $orderBy");
    $stTE->execute(array($eventoId));

$tiposEvento = $stTE->fetchAll(PDO::FETCH_ASSOC);

// Cargar datos del evento
$evento = array();
if ($eventoId > 0) {
  $stEv = $pdo->prepare("SELECT * FROM eventos WHERE id = ? LIMIT 1");
  $stEv->execute(array($eventoId));
  $evento = $stEv->fetch(PDO::FETCH_ASSOC);
}

// ===== Plantillas (Mis Entradas) del admin =====
if ($hasTablaPlantillas) {
  if ($tipoGlobal === 'super_admin' || $tipoGlobal === 'superadmin') {
    $sqlTplList = "SELECT * FROM plantillas_entrada WHERE activo = 1 ORDER BY categoria ASC, nombre ASC";
    $stTplList = $pdo->prepare($sqlTplList);
    $stTplList->execute();
  } else {
    $sqlTplList = "SELECT * FROM plantillas_entrada WHERE activo = 1 AND creado_por_admin_id = ? ORDER BY categoria ASC, nombre ASC";
    $stTplList = $pdo->prepare($sqlTplList);
    $stTplList->execute(array($adminId));
  }
  $plantillas = $stTplList->fetchAll(PDO::FETCH_ASSOC);
} else {
  $plantillas = array();
}

// ===== Texto de fechas para el HTML =====
$fd = isset($evento['fecha_desde']) ? $evento['fecha_desde'] : '';
$fh = isset($evento['fecha_hasta']) ? $evento['fecha_hasta'] : '';
if ($fd === '' && $fh === '') {
    $fechaTexto = '<span class="muted">Sin fecha definida</span>';
} else {
    $fechaTexto = e($fd);
    if ($fh !== '') {
        $fechaTexto .= ' → ' . e($fh);
    }
}

include __DIR__.'/inc/layout_top.php';
?>
<div class="card" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
  <a class="btn secondary" href="panel_admin.php">⬅ Volver al panel</a>
  <a class="btn secondary" href="editar_evento.php?id=<?php echo (int)$eventoId; ?>">✏ Editar datos del evento</a>
  <a class="btn secondary" href="plantillas_entrada.php">⚙ Mis entradas (plantillas)</a>
  <span style="flex:1 1 auto;"></span>
</div>

<?php if ($error): ?>
  <div class="flash err"><?php echo e($error); ?></div>
<?php endif; ?>
<?php if ($okMsg): ?>
  <div class="flash ok"><?php echo e($okMsg); ?></div>
<?php endif; ?>

<div class="card">
  <h2>Configurar entradas para: <?php echo e($evento['nombre']); ?></h2>
  <div style="display:flex;gap:16px;flex-wrap:wrap;margin-top:8px;align-items:flex-start;">
    <div style="flex:1 1 220px;">
      <div class="muted">Slug / URL</div>
      <div><code><?php echo e($evento['slug']); ?></code></div>

      <div class="muted" style="margin-top:8px;">Fechas</div>
      <div><?php echo $fechaTexto; ?></div>

      <div class="muted" style="margin-top:8px;">Descripción</div>
      <div><?php echo nl2br(e(isset($evento['descripcion']) ? $evento['descripcion'] : '')); ?></div>
    </div>

    <div>
      <div class="muted">Flyer</div>
      <?php
        $flyerOk = (!empty($evento['flyer_filename']) && file_exists(__DIR__ . '/' . $evento['flyer_filename']));
      ?>
      <?php if ($flyerOk): ?>
        <img src="<?php echo e($evento['flyer_filename']); ?>" alt="Flyer"
             style="width:160px;height:160px;object-fit:cover;border-radius:10px;border:1px solid var(--line);background:#000;">
      <?php else: ?>
        <div class="muted">Sin flyer.</div>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="card">
  <h3>Agregar tipos desde Mis Entradas</h3>
  <?php if (empty($plantillas)): ?>
    <div class="muted">No tenés plantillas activas. Crealas en <a href="plantillas_entrada.php">Mis Entradas</a>.</div>
  <?php else: ?>
    <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
      <label for="tpl_id" class="muted">Plantilla:</label>
      <select name="tpl_id" id="tpl_id">
        <?php foreach ($plantillas as $tpl): ?>
          <?php $catTpl = isset($tpl['categoria']) ? $tpl['categoria'] : 'General'; ?>
          <option value="<?php echo (int)$tpl['id']; ?>">
            <?php echo e($catTpl); ?> – <?php echo e($tpl['nombre']); ?>
            (<?php echo e($tpl['tipo']); ?>,
             $<?php echo (int)$tpl['precio_default']; ?>,
             <?php echo (int)$tpl['cantidad_default']; ?>)
          </option>
        <?php endforeach; ?>
      </select>
      <button class="btn" type="submit" name="add_from_template" value="1">Agregar al evento</button>
    </form>
  <?php endif; ?>
</div>

<div class="card">
  <h3>Tipos de entrada del evento</h3>

  <?php if (empty($tiposEvento)): ?>
    <div class="muted">Este evento todavía no tiene tipos de entrada configurados.</div>
  <?php else: ?>
    <table class="table" style="margin-top:8px;">
      <thead>
        <tr>
          <th>ID</th>
          <th>Categoría</th>
          <th>Nombre</th>
          <th>Tipo venta</th>
          <th>Precio</th>
          <th>Total</th>
          <th>Disponible</th>
          <th>Hora límite</th>
          <?php if ($visCol): ?><th>Visible</th><?php endif; ?>
          <th>Acciones</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($tiposEvento as $te): ?>
          <tr>
            <td><?php echo (int)$te['id']; ?></td>
            <?php $cat = isset($te['categoria']) ? $te['categoria'] : ''; ?>
            <?php $tv  = isset($te['tipo_venta']) ? $te['tipo_venta'] : (isset($te['tipo'])?$te['tipo']:''); ?>
            <?php $hl  = isset($te['hora_limite']) ? $te['hora_limite'] : ''; ?>
            <td><?php echo e($cat); ?></td>
            <td><?php echo e($te['nombre']); ?></td>
            <td><?php echo e($tv); ?></td>
            <td>$<?php echo (int)$te['precio']; ?></td>
            <td><?php echo (int)$te['cantidad_total']; ?></td>
            <td>
              <form method="post" style="margin:0;display:inline;">
                <input type="hidden" name="action" value="update_disponible">
                <input type="hidden" name="id" value="<?php echo (int)$te['id']; ?>">
                <input type="number" name="cantidad_disponible" value="<?php echo (int)$te['cantidad_disponible']; ?>" min="0" style="width:60px;">
                <button class="btn" type="submit" style="padding:2px 8px;font-size:13px;">Guardar</button>
              </form>
            </td>
            <td><?php echo e($hl); ?></td>
            <?php if ($visCol): ?>
            <td>
              <?php $visOn = isset($te[$visCol]) ? (int)$te[$visCol] === 1 : true; ?>
              <form method="post" style="margin:0;display:inline;">
                <input type="hidden" name="action" value="toggle_vis">
                <input type="hidden" name="id" value="<?php echo (int)$te['id']; ?>">
                <input type="hidden" name="val" value="<?php echo $visOn ? 0 : 1; ?>">
                <label class="switch" style="font-size:12px;">
                  <input type="checkbox" <?php echo $visOn ? 'checked' : ''; ?> onchange="this.form.submit();">
                  <span class="switch-track"><span class="switch-thumb"></span></span>
                  <span><?php echo $visOn ? 'Visible' : 'Oculta'; ?></span>
                </label>
              </form>
            </td>
            <?php endif; ?>
            <td>
              <a class="btn secondary"
                 href="configurar_entradas_evento.php?id=<?php echo (int)$eventoId; ?>&del_te=<?php echo (int)$te['id']; ?>"
                 onclick="return confirm('¿Eliminar este tipo de entrada del evento?');">
                🗑
              </a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<div class="card" style="display:flex;gap:8px;flex-wrap:wrap;">
  <a class="btn" href="publicar_evento.php?id=<?php echo (int)$eventoId; ?>">✅ Publicar evento</a>
  <a class="btn secondary" href="comprar.php?id=<?php echo (int)$eventoId; ?>" target="_blank" rel="noopener">Ver CheckOut</a>
</div>

<?php include __DIR__.'/inc/layout_bottom.php'; ?>
