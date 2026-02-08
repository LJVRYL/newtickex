<?php
require_once __DIR__.'/inc/bootstrap.php';
$title = "Configurar entradas del evento";

require_login();

$cu = current_user();
$tipoGlobal = isset($_SESSION['tipo_global'])
    ? $_SESSION['tipo_global']
    : (isset($cu['rol']) ? $cu['rol'] : '');

if (!in_array($tipoGlobal, array('admin_evento','super_admin','superadmin'), true)) {
    header("Location: login.php");
    exit;
}

$adminId  = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : (isset($cu['id']) ? (int)$cu['id'] : 0);
$eventoId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($eventoId <= 0) {
    abort_404("ID de evento inválido.");
}

try {
    $pdo = db();
} catch (Exception $e) {
    http_response_code(500);
    echo "Error DB: " . e($e->getMessage());
    exit;
}

// ===== Evento =====
$stEv = $pdo->prepare("SELECT * FROM eventos WHERE id = ?");
$stEv->execute(array($eventoId));
$evento = $stEv->fetch(PDO::FETCH_ASSOC);
if (!$evento) {
    abort_404("Evento no encontrado.");
}

// Detectar columnas opcionales en eventos
$colsEv = $pdo->query("PRAGMA table_info(eventos)")->fetchAll(PDO::FETCH_ASSOC);
$hasCreadoPor = false;
foreach ($colsEv as $c) {
    if (isset($c['name']) && $c['name'] === 'creado_por_admin_id') {
        $hasCreadoPor = true;
        break;
    }
}

// Detectar columnas opcionales en tipos_entrada
$colsTE = $pdo->query("PRAGMA table_info(tipos_entrada)")->fetchAll(PDO::FETCH_ASSOC);
$hasCategoria  = false;
$hasTipoVenta  = false;
$hasHoraLimite = false;
foreach ($colsTE as $c) {
  if (isset($c['name'])) {
    if ($c['name'] === 'categoria') $hasCategoria = true;
    if ($c['name'] === 'tipo_venta') $hasTipoVenta = true;
    if ($c['name'] === 'hora_limite') $hasHoraLimite = true;
  }
}

// Detectar si existe la tabla plantillas_entrada
$hasTablaPlantillas = false;
$stmtTbl = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='plantillas_entrada' LIMIT 1");
if ($stmtTbl && $stmtTbl->fetch(PDO::FETCH_ASSOC)) {
  $hasTablaPlantillas = true;
}

// admin_evento solo puede tocar sus eventos
if ($tipoGlobal === 'admin_evento' && $hasCreadoPor) {
    $creador = isset($evento['creado_por_admin_id']) ? (int)$evento['creado_por_admin_id'] : 0;
    if ($creador !== $adminId) {
        abort_404("No podés modificar este evento.");
    }
}

$error = '';
$okMsg = '';

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
            <td><?php echo (int)$te['cantidad_disponible']; ?></td>
            <td><?php echo e($hl); ?></td>
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
</div>

<?php include __DIR__.'/inc/layout_bottom.php'; ?>
