<?php
// mis_entradas.php - Gestion de plantillas de entrada (Crear Entrada)
// PHP 5.6 compatible

require __DIR__ . '/inc/bootstrap.php';

// -------------------------------------------------------------------
// Acceso
// -------------------------------------------------------------------
$tipoGlobal = isset($_SESSION['tipo_global']) ? $_SESSION['tipo_global'] : '';
$userId     = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;

if ($tipoGlobal !== 'admin_evento' && $tipoGlobal !== 'super_admin') {
        header('Location: /login.php?next=' . urlencode($_SERVER['REQUEST_URI']), true, 302);
    exit;
    require __DIR__ . '/inc/layout_top.php';
    echo '<div class="card"><div class="alert alert-danger">Acceso restringido.</div></div>';
    require __DIR__ . '/inc/layout_bottom.php';
    exit;
}

if ($userId <= 0) {
    http_response_code(403);
    $title = 'Sesion invalida';
    require __DIR__ . '/inc/layout_top.php';
    echo '<div class="card"><div class="alert alert-danger">Sesion invalida (falta user_id).</div></div>';
    require __DIR__ . '/inc/layout_bottom.php';
    exit;
}

$adminId = $userId;

// -------------------------------------------------------------------
// DB: usar db() si existe, sino fallback a sqlite directo
// -------------------------------------------------------------------
if (!isset($pdo) || !($pdo instanceof PDO)) {
  if (function_exists('db')) {
    try {
      $pdo = db();
    } catch (Exception $e) {
      http_response_code(500);
      echo 'Error DB: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
      exit;
    }
  } else {
    try {
      $pdo = new PDO('sqlite:save_the_rave.sqlite');
      $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch (Exception $e) {
      http_response_code(500);
      echo 'Error DB (sqlite): ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
      exit;
    }
  }
}

// Detectar columnas opcionales en plantillas_entrada
$hasVis = false; $hasVentaHasta = false;
try {
  $colsPe = $pdo->query("PRAGMA table_info(plantillas_entrada)")->fetchAll(PDO::FETCH_ASSOC);
  foreach ($colsPe as $c) {
    if (isset($c['name']) && $c['name'] === 'visible_publico') { $hasVis = true; }
    if (isset($c['name']) && $c['name'] === 'venta_hasta') { $hasVentaHasta = true; }
  }
} catch (Exception $e) {
  // ignorar
}

// -------------------------------------------------------------------
// Helpers
// -------------------------------------------------------------------

function normalizar_categoria($categoria)
{
    $c = strtoupper(trim($categoria));
    if ($c === 'STAFF')        return 'STAFF';
    if ($c === 'INVITADOS')    return 'INVITADOS';
    if ($c === 'GENERALES' || $c === 'GENERAL') return 'GENERALES';
    if ($c === 'PRENSA')       return 'PRENSA';
    if ($c === 'SPONSORS' || $c === 'SPONSOR')  return 'SPONSORS';
    if ($c === 'REVENDEDORES' || $c === 'REVENTA') return 'REVENDEDORES';
    return 'GENERALES';
}

function normalizar_tipo($tipo)
{
    $t = strtoupper(trim($tipo));
    if ($t === 'FREE' || $t === 'GRATIS')   return 'FREE';
    if ($t === 'PAGA' || $t === 'PAGO')     return 'PAGA';
    if ($t === 'PREPAGA' || $t === 'PREPAGO') return 'PREPAGA';
    if ($t === 'PUERTA')                    return 'PUERTA';
    return 'PAGA';
}

function validar_categoria_tipo_local($categoria, $tipo)
{
    // Si existe una funcion global validar_categoria_tipo, la usamos
    if (function_exists('validar_categoria_tipo')) {
        $res = validar_categoria_tipo($categoria, $tipo);
        if (is_array($res) && isset($res[0]) && isset($res[1])) {
            return array((bool)$res[0], (string)$res[1]);
        } elseif ($res === false) {
            return array(false, 'Combinacion categoria/tipo invalida.');
        } else {
            return array(true, '');
        }
    }

    $cat = strtoupper(trim($categoria));
    $t   = strtoupper(trim($tipo));

    // Reglas basicas
    if ($cat === 'STAFF' && $t !== 'FREE') {
        return array(false, 'STAFF solo permite tipo FREE.');
    }
    if ($cat === 'PRENSA' && $t !== 'FREE') {
        return array(false, 'PRENSA solo permite tipo FREE.');
    }
    if ($cat === 'GENERALES' && $t === 'FREE') {
        return array(false, 'GENERALES no puede ser FREE.');
    }
    if ($cat === 'SPONSORS' && $t === 'PUERTA') {
      return array(false, 'SPONSORS no puede ser PUERTA.');
    }

    return array(true, '');
}

function validar_hora_limite($hora)
{
    $h = trim($hora);
    if ($h === '') {
        return array(true, '');
    }

    if (!preg_match('/^[0-9]{2}:[0-9]{2}$/', $h)) {
        return array(false, 'Hora limite debe ser HH:MM.');
    }

    $parts = explode(':', $h);
    $hh = (int)$parts[0];
    $mm = (int)$parts[1];

    if ($hh < 0 || $hh > 23 || $mm < 0 || $mm > 59) {
        return array(false, 'Hora limite fuera de rango (00:00 - 23:59).');
    }

    return array(true, '');
}

function val_field($row, $key, $default)
{
    if ($row && isset($row[$key])) {
        return $row[$key];
    }
    return $default;
}

// -------------------------------------------------------------------
// POST: create / update / delete
// -------------------------------------------------------------------
$errores   = array();
$mensajeOk = '';
$editRow   = null;

$action = isset($_POST['action']) ? $_POST['action'] : '';
$metodo = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';

if ($metodo === 'POST') {
    $idPlantilla = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $nombre      = isset($_POST['nombre']) ? trim($_POST['nombre']) : '';
    $categoria   = isset($_POST['categoria']) ? $_POST['categoria'] : '';
    $tipo        = isset($_POST['tipo']) ? $_POST['tipo'] : '';
    $precioStr   = isset($_POST['precio_default']) ? trim($_POST['precio_default']) : '';
    $cantStr     = isset($_POST['cantidad_default']) ? trim($_POST['cantidad_default']) : '';
    $horaLimite  = isset($_POST['hora_limite_default']) ? trim($_POST['hora_limite_default']) : '';
    $ventaHasta  = isset($_POST['venta_hasta']) ? trim($_POST['venta_hasta']) : '';
    $descripcion = isset($_POST['descripcion']) ? trim($_POST['descripcion']) : '';
    $reglas      = isset($_POST['reglas_default']) ? trim($_POST['reglas_default']) : '';
    $activoFlag  = isset($_POST['activo']) ? 1 : 0;
    // Por defecto visible si tiene precio; gratis arranca oculto salvo que marquen visible
    if ($hasVis) {
      if (isset($_POST['visible_publico'])) {
        $visibleFlag = ((int)$_POST['visible_publico'] === 1) ? 1 : 0;
      } else {
        $visibleFlag = ($precioStr !== '' && is_numeric($precioStr) && (float)$precioStr > 0) ? 1 : 0;
      }
    } else {
      $visibleFlag = 1;
    }

    $categoriaFinal = normalizar_categoria($categoria);
    $tipoFinal      = normalizar_tipo($tipo);

    if ($action === 'create' || $action === 'update') {
        if ($nombre === '') {
            $errores[] = 'El nombre es obligatorio.';
        }

        list($okCT, $msgCT) = validar_categoria_tipo_local($categoriaFinal, $tipoFinal);
        if (!$okCT && $msgCT !== '') {
            $errores[] = $msgCT;
        }

        list($okHora, $msgHora) = validar_hora_limite($horaLimite);
        if (!$okHora && $msgHora !== '') {
            $errores[] = $msgHora;
        }

        if ($ventaHasta !== '' && !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $ventaHasta)) {
          $errores[] = 'Disponible hasta debe tener formato AAAA-MM-DD.';
        }

        if ($precioStr === '') {
            $precioStr = '0';
        }
        if (!is_numeric($precioStr)) {
            $errores[] = 'Precio debe ser numero.';
        }
        $precio = (float)$precioStr;
        if ($tipoFinal === 'FREE' && $precio > 0) {
            $errores[] = 'Para FREE, precio debe ser 0.';
        }

        if ($cantStr === '') {
            $cantStr = '0';
        }
        if (!ctype_digit(strval($cantStr))) {
            $errores[] = 'Cantidad debe ser entero.';
        }
        $cantidad = (int)$cantStr;
        if ($cantidad < 0) {
            $errores[] = 'Cantidad no puede ser negativa.';
        }

        if ($hasVis && $precio <= 0 && isset($_POST['visible_publico']) === false) {
          // Si es gratis y no marcaron visible, por defecto queda oculto
          $visibleFlag = 0;
        }
    }

    if ($action === 'create' && empty($errores)) {
        try {
          $cols = array('admin_id','nombre','categoria','tipo','precio_default','hora_limite_default','reglas_default','activo','creado_en','creado_por_admin_id','cantidad_default','descripcion');
          $vals = array(':admin_id',':nombre',':categoria',':tipo',':precio',':hora_limite',':reglas',':activo',':creado_en',':creado_por',':cantidad',':descripcion');
          if ($hasVis) { $cols[] = 'visible_publico'; $vals[] = ':visible_publico'; }
          if ($hasVentaHasta) { $cols[] = 'venta_hasta'; $vals[] = ':venta_hasta'; }

          $sql = 'INSERT INTO plantillas_entrada ('.implode(',', $cols).') VALUES ('.implode(',', $vals).')';
          $stmt = $pdo->prepare($sql);
          $stmt->execute(array(
            ':admin_id'    => $adminId,
            ':nombre'      => $nombre,
            ':categoria'   => $categoriaFinal,
            ':tipo'        => $tipoFinal,
            ':precio'      => $precio,
            ':hora_limite' => $horaLimite,
            ':reglas'      => $reglas,
            ':activo'      => $activoFlag,
            ':creado_en'   => date('Y-m-d H:i:s'),
            ':creado_por'  => $adminId,
            ':cantidad'    => $cantidad,
            ':descripcion' => $descripcion,
            ':visible_publico' => $visibleFlag,
            ':venta_hasta' => $ventaHasta
          ));
            $mensajeOk = 'Plantilla creada correctamente.';
        } catch (Exception $e) {
            $errores[] = 'Error al guardar: ' . $e->getMessage();
        }
    } elseif ($action === 'update' && $idPlantilla > 0 && empty($errores)) {
        try {
          $set = array(
            'nombre = :nombre',
            'categoria = :categoria',
            'tipo = :tipo',
            'precio_default = :precio',
            'hora_limite_default = :hora_limite',
            'reglas_default = :reglas',
            'activo = :activo',
            'cantidad_default = :cantidad',
            'descripcion = :descripcion'
          );
          if ($hasVis) $set[] = 'visible_publico = :visible_publico';
          if ($hasVentaHasta) $set[] = 'venta_hasta = :venta_hasta';

          $sql = 'UPDATE plantillas_entrada SET '.implode(', ', $set).' WHERE id = :id AND admin_id = :admin_id';
          $stmt = $pdo->prepare($sql);
          $stmt->execute(array(
            ':nombre'      => $nombre,
            ':categoria'   => $categoriaFinal,
            ':tipo'        => $tipoFinal,
            ':precio'      => $precio,
            ':hora_limite' => $horaLimite,
            ':reglas'      => $reglas,
            ':activo'      => $activoFlag,
            ':cantidad'    => $cantidad,
            ':descripcion' => $descripcion,
            ':visible_publico' => $visibleFlag,
            ':venta_hasta' => $ventaHasta,
            ':id'          => $idPlantilla,
            ':admin_id'    => $adminId
          ));
            if ($stmt->rowCount() > 0) {
                $mensajeOk = 'Plantilla actualizada.';
            } else {
                $errores[] = 'No se encontro plantilla para actualizar.';
            }
        } catch (Exception $e) {
            $errores[] = 'Error al actualizar: ' . $e->getMessage();
        }
    } elseif ($action === 'delete' && $idPlantilla > 0) {
        try {
            $stmt = $pdo->prepare('DELETE FROM plantillas_entrada WHERE id = :id AND admin_id = :admin_id');
            $stmt->execute(array(
                ':id'       => $idPlantilla,
                ':admin_id' => $adminId
            ));
            if ($stmt->rowCount() > 0) {
                $mensajeOk = 'Plantilla eliminada.';
            } else {
                $errores[] = 'No se encontro plantilla para eliminar.';
            }
        } catch (Exception $e) {
            $errores[] = 'Error al eliminar: ' . $e->getMessage();
        }
    }

    if ($action === 'update' && $idPlantilla > 0 && !empty($errores)) {
        try {
            $stmt = $pdo->prepare('SELECT * FROM plantillas_entrada WHERE id = :id AND admin_id = :admin_id');
            $stmt->execute(array(':id' => $idPlantilla, ':admin_id' => $adminId));
            $editRow = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            // ignorar
        }
    }
}

// -------------------------------------------------------------------
// GET: editar
// -------------------------------------------------------------------
if ($metodo === 'GET') {
    $getAction = isset($_GET['action']) ? $_GET['action'] : '';
    $getId     = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    if ($getAction === 'edit' && $getId > 0) {
        try {
            $stmt = $pdo->prepare('SELECT * FROM plantillas_entrada WHERE id = :id AND admin_id = :admin_id');
            $stmt->execute(array(':id' => $getId, ':admin_id' => $adminId));
            $editRow = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$editRow) {
                $errores[] = 'No se encontro plantilla para edicion.';
            }
        } catch (Exception $e) {
            $errores[] = 'Error al cargar plantilla: ' . $e->getMessage();
        }
    }
}

// -------------------------------------------------------------------
// Listado
// -------------------------------------------------------------------
$plantillas = array();
try {
    $stmt = $pdo->prepare('SELECT * FROM plantillas_entrada WHERE admin_id = :admin_id ORDER BY categoria, nombre');
    $stmt->execute(array(':admin_id' => $adminId));
    $plantillas = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $errores[] = 'Error al listar plantillas: ' . $e->getMessage();
}

// -------------------------------------------------------------------
// Layout + HTML (similar estilo a crear_evento.php)
// -------------------------------------------------------------------
$title = 'Crear Entrada';
require __DIR__ . '/inc/layout_top.php';
?>
<div class="card" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
  <a class="btn secondary" href="panel_admin.php">⬅ Volver al panel</a>
</div>

<div class="card">
  <h2>Crear Entrada</h2>
  <div style="color:var(--muted);font-size:14px;">
    Definí tipos de entrada que despues vas a poder reutilizar en tus eventos.
  </div>
</div>

<?php if (!empty($mensajeOk)): ?>
  <div class="card">
    <div class="alert alert-success">
      <?php echo e($mensajeOk); ?>
    </div>
  </div>
<?php endif; ?>

<?php if (!empty($errores)): ?>
  <div class="card">
    <div class="alert alert-danger">
      <ul>
        <?php foreach ($errores as $err): ?>
          <li><?php echo e($err); ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
<?php endif; ?>

<form method="post">
  <div class="card">
    <h3><?php echo $editRow ? 'Editar plantilla' : 'Nueva plantilla'; ?></h3>

    <input type="hidden" name="action" value="<?php echo $editRow ? 'update' : 'create'; ?>">
    <?php if ($editRow): ?>
      <input type="hidden" name="id" value="<?php echo (int)$editRow['id']; ?>">
    <?php endif; ?>

    <label for="nombre">Nombre</label>
    <input type="text" id="nombre" name="nombre" required
           value="<?php echo e(val_field($editRow, 'nombre', '')); ?>">

    <label for="categoria">Categoria</label>
    <?php
      $catActual = strtoupper(trim(val_field($editRow, 'categoria', 'GENERALES')));
    ?>
    <select id="categoria" name="categoria">
      <option value="STAFF"        <?php echo ($catActual === 'STAFF') ? 'selected' : ''; ?>>Staff</option>
      <option value="INVITADOS"    <?php echo ($catActual === 'INVITADOS') ? 'selected' : ''; ?>>Invitados</option>
      <option value="GENERALES"    <?php echo ($catActual === 'GENERALES') ? 'selected' : ''; ?>>Generales</option>
      <option value="PRENSA"       <?php echo ($catActual === 'PRENSA') ? 'selected' : ''; ?>>Prensa</option>
      <option value="SPONSORS"     <?php echo ($catActual === 'SPONSORS') ? 'selected' : ''; ?>>Sponsors</option>
      <option value="REVENDEDORES" <?php echo ($catActual === 'REVENDEDORES') ? 'selected' : ''; ?>>Revendedores</option>
    </select>

    <label for="tipo">Tipo de venta</label>
    <?php
      $tipoActual = strtoupper(trim(val_field($editRow, 'tipo', 'PAGA')));
    ?>
    <select id="tipo" name="tipo">
      <option value="FREE"    <?php echo ($tipoActual === 'FREE') ? 'selected' : ''; ?>>FREE</option>
      <option value="PAGA"    <?php echo ($tipoActual === 'PAGA') ? 'selected' : ''; ?>>PAGA</option>
      <option value="PREPAGA" <?php echo ($tipoActual === 'PREPAGA') ? 'selected' : ''; ?>>PREPAGA</option>
      <option value="PUERTA"  <?php echo ($tipoActual === 'PUERTA') ? 'selected' : ''; ?>>PUERTA</option>
    </select>

        <label for="precio_default">Precio por defecto</label>
        <input type="text" id="precio_default" name="precio_default"
          value="<?php echo e(val_field($editRow, 'precio_default', '0')); ?>">

        <?php if ($hasVis): ?>
          <?php 
            $priceFormVal = val_field($editRow, 'precio_default', '');
            $visDefaultFromPrice = ($priceFormVal !== '' && is_numeric($priceFormVal) && (float)$priceFormVal > 0) ? 1 : 0;
            $visActual = (int)val_field($editRow, 'visible_publico', $visDefaultFromPrice);
          ?>
          <label class="switch" style="margin:6px 0 4px;">
            <input type="hidden" name="visible_publico" value="0">
            <input type="checkbox" name="visible_publico" value="1" <?php echo ($visActual ? 'checked' : ''); ?> >
            <span class="switch-track"><span class="switch-thumb"></span></span>
            <span style="font-size:12px;color:var(--muted);">Visible al público (gratis queda oculto por defecto hasta que lo marques visible)</span>
          </label>
        <?php endif; ?>

    <label for="cantidad_default">Cantidad por defecto</label>
    <input type="text" id="cantidad_default" name="cantidad_default"
           value="<?php echo e(val_field($editRow, 'cantidad_default', '0')); ?>">

    <label for="hora_limite_default">Hora limite (opcional)</label>
    <input type="text" id="hora_limite_default" name="hora_limite_default"
           placeholder="HH:MM"
           value="<?php echo e(val_field($editRow, 'hora_limite_default', '')); ?>">

        <?php if ($hasVentaHasta): ?>
          <label for="venta_hasta">Disponible hasta (AAAA-MM-DD, corte 23:59)</label>
          <input type="text" id="venta_hasta" name="venta_hasta" placeholder="2026-12-31"
            value="<?php echo e(val_field($editRow, 'venta_hasta', '')); ?>">
        <?php endif; ?>

    <label for="descripcion">Descripcion (opcional)</label>
    <textarea id="descripcion" name="descripcion"><?php
      echo e(val_field($editRow, 'descripcion', ''));
    ?></textarea>

    <label for="reglas_default">Notas / reglas internas (opcional)</label>
    <textarea id="reglas_default" name="reglas_default"><?php
      echo e(val_field($editRow, 'reglas_default', ''));
    ?></textarea>

    <?php
      $activoActual = (int)val_field($editRow, 'activo', 1);
    ?>
    <label style="display:inline-flex;align-items:center;gap:6px;margin-top:8px;">
      <input type="checkbox" name="activo" value="1" <?php echo $activoActual ? 'checked' : ''; ?>>
      Plantilla activa
    </label>

    <button type="submit" class="btn">
      <?php echo $editRow ? 'Guardar cambios' : 'Crear plantilla'; ?>
    </button>
  </div>
</form>

<div class="card">
  <h3>Plantillas existentes</h3>

  <?php if (empty($plantillas)): ?>
    <p>No tenes plantillas cargadas todavia.</p>
  <?php else: ?>
    <div style="overflow:auto;margin-top:8px;">
      <table class="table">
        <thead>
          <tr>
            <th>Categoria</th>
            <th>Nombre</th>
            <th>Tipo</th>
            <th>Precio</th>
            <th>Cant.</th>
            <?php if ($hasVentaHasta): ?><th>Hasta</th><?php endif; ?>
            <?php if ($hasVis): ?><th>Visible</th><?php endif; ?>
            <th>Activo</th>
            <th style="text-align:right;">Acciones</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($plantillas as $p): ?>
            <tr>
              <td><?php echo e($p['categoria']); ?></td>
              <td><?php echo e($p['nombre']); ?></td>
              <td><?php echo e($p['tipo']); ?></td>
              <td><?php echo number_format((float)$p['precio_default'], 0, ',', '.'); ?></td>
              <td><?php echo (int)$p['cantidad_default']; ?></td>
              <?php if ($hasVentaHasta): ?><td><?php echo e(isset($p['venta_hasta']) ? $p['venta_hasta'] : ''); ?></td><?php endif; ?>
              <?php if ($hasVis): ?>
                <td>
                  <form method="post" class="vis-toggle" style="margin:0;display:inline;">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="id" value="<?php echo (int)$p['id']; ?>">
                    <input type="hidden" name="nombre" value="<?php echo e($p['nombre']); ?>">
                    <input type="hidden" name="categoria" value="<?php echo e($p['categoria']); ?>">
                    <input type="hidden" name="tipo" value="<?php echo e($p['tipo']); ?>">
                    <input type="hidden" name="precio_default" value="<?php echo e($p['precio_default']); ?>">
                    <input type="hidden" name="cantidad_default" value="<?php echo e($p['cantidad_default']); ?>">
                    <input type="hidden" name="hora_limite_default" value="<?php echo e(isset($p['hora_limite_default']) ? $p['hora_limite_default'] : ''); ?>">
                    <input type="hidden" name="descripcion" value="<?php echo e(isset($p['descripcion']) ? $p['descripcion'] : ''); ?>">
                    <input type="hidden" name="reglas_default" value="<?php echo e(isset($p['reglas_default']) ? $p['reglas_default'] : ''); ?>">
                    <?php if ($hasVentaHasta): ?><input type="hidden" name="venta_hasta" value="<?php echo e(isset($p['venta_hasta']) ? $p['venta_hasta'] : ''); ?>"><?php endif; ?>
                    <?php if (!empty($p['activo'])): ?><input type="hidden" name="activo" value="1"><?php endif; ?>
                    <input type="hidden" name="visible_publico" value="0">
                    <?php $visOn = !empty($p['visible_publico']); ?>
                    <label class="switch" style="font-size:12px;">
                      <input type="checkbox" name="visible_publico" value="1" <?php echo $visOn ? 'checked' : ''; ?> onchange="this.form.submit();">
                      <span class="switch-track"><span class="switch-thumb"></span></span>
                      <span><?php echo $visOn ? 'Visible' : 'Oculto'; ?></span>
                    </label>
                  </form>
                </td>
              <?php endif; ?>
              <td>
                <?php if ((int)$p['activo'] === 1): ?>
                  <span style="color:var(--ok);font-weight:700;">Activo</span>
                <?php else: ?>
                  <span style="color:var(--warn);font-weight:700;">Inactivo</span>
                <?php endif; ?>
              </td>
              <td style="text-align:right;white-space:nowrap;">
                <a class="btn secondary" style="padding:6px 10px;font-size:14px;" href="mis_entradas.php?action=edit&amp;id=<?php echo (int)$p['id']; ?>" title="Editar">
                  ✏️
                </a>
                <form method="post" action="mis_entradas.php" style="display:inline;" onsubmit="return confirm('Seguro que queres eliminar esta plantilla?');">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?php echo (int)$p['id']; ?>">
                  <button type="submit" class="btn danger" title="Borrar" style="padding:6px 10px;font-size:14px;">
                    🗑️
                  </button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php
require __DIR__ . '/inc/layout_bottom.php';
