<?php
// descuentos.php - Gestión de códigos de descuento (PHP 5.6)

require __DIR__ . '/inc/bootstrap.php';

// -------------------------------------------------
// Acceso
// -------------------------------------------------
$tipoGlobal = isset($_SESSION['tipo_global']) ? $_SESSION['tipo_global'] : '';
$userId     = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;

if ($tipoGlobal !== 'admin_evento' && $tipoGlobal !== 'super_admin') {
    http_response_code(403);
    $title = 'Acceso restringido';
    require __DIR__ . '/inc/layout_top.php';
    echo '<div class="card"><div class="alert alert-danger">Acceso restringido.</div></div>';
    require __DIR__ . '/inc/layout_bottom.php';
    exit;
}

if ($userId <= 0) {
    http_response_code(403);
    $title = 'Sesión inválida';
    require __DIR__ . '/inc/layout_top.php';
    echo '<div class="card"><div class="alert alert-danger">Falta user_id.</div></div>';
    require __DIR__ . '/inc/layout_bottom.php';
    exit;
}

$adminId  = $userId;
$eventoId = isset($_SESSION['evento_id']) ? (int)$_SESSION['evento_id'] : 0;

// -------------------------------------------------
// DB
// -------------------------------------------------
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
            echo 'Error DB sqlite: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
            exit;
        }
    }
}

// -------------------------------------------------
// Helpers
// -------------------------------------------------
function valf($row, $key, $default)
{
    if ($row && isset($row[$key])) {
        return $row[$key];
    }
    return $default;
}

// -------------------------------------------------
// Estado
// -------------------------------------------------
$errores   = array();
$mensajeOk = '';
$editRow   = null;

$action = isset($_POST['action']) ? $_POST['action'] : '';
$metodo = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';

// -------------------------------------------------
// POST: create / update / delete
// -------------------------------------------------
if ($metodo === 'POST') {
    $idCodigo  = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $codigo    = isset($_POST['codigo']) ? strtoupper(trim($_POST['codigo'])) : '';
    $porcStr   = isset($_POST['porcentaje']) ? trim($_POST['porcentaje']) : '';
    $cantStr   = isset($_POST['cantidad_maxima']) ? trim($_POST['cantidad_maxima']) : '';
    $activo    = isset($_POST['activo']) ? 1 : 0;

    $porcentaje  = 0;
    $cantidadMax = 0;

    if ($action === 'create' || $action === 'update') {
        // Código
        if ($codigo === '') {
            $errores[] = 'Código vacío.';
        } elseif (strlen($codigo) > 10) {
            $errores[] = 'Código muy largo.';
        } elseif (!preg_match('/^[A-Z0-9]+$/', $codigo)) {
            $errores[] = 'Código inválido.';
        }

        // Porcentaje
        if ($porcStr === '') {
            $errores[] = 'Porcentaje requerido.';
        } elseif (!ctype_digit($porcStr)) {
            $errores[] = 'Porcentaje no numérico.';
        } else {
            $porcentaje = (int)$porcStr;
            if ($porcentaje <= 0 || $porcentaje > 100) {
                $errores[] = 'Porcentaje fuera de rango.';
            }
        }

        // Cantidad máxima
        if ($cantStr === '') {
            $cantStr = '0';
        }
        if (!ctype_digit(strval($cantStr))) {
            $errores[] = 'Cantidad in
        }
        $cantidadMax = (int)$cantStr;
        if ($canti
            $errores[] = 'Cantidad inválida.';
        }
    }

    // CREATE
    if ($action === 'create' && empty($errores)) {
        // Chequear duplicado
        try {
            $stmt = $pdo->prepare(
                'SELECT id FROM codigos_descuento WHERE admin_id = :admin_id AND codigo = :codigo'
            );
            $stmt->execute(array(':admin_id' => $adminId, ':codigo' => $codigo));
            $existe = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($existe) {
                $errores[] = 'Código ya existe.';
            }
        } catch (Exception $e) {
            $errores[] = 'Error buscando duplicado.';
        }

        if (empty($errores)) {
            try {
                $stmt = $pdo->prepare(
                    'INSERT INTO codigos_descuento
                        (admin_id, evento_id, codigo, porcentaje,
                         cantidad_maxima, cantidad_usada, activo, creado_en)
                     VALUES
                        (:admin_id, :evento_id, :codigo, :porcentaje,
                         :cant_max, 0, :activo, :creado_en)'
                );
                $stmt->execute(array(
                    ':admin_id'   => $adminId,
                    ':evento_id'  => $eventoId,
                    ':codigo'     => $codigo,
                    ':porcentaje' => $porcentaje,
                    ':cant_max'   => $cantidadMax,
                    ':activo'     => $activo,
                    ':creado_en'  => date('Y-m-d H:i:s')
                ));
                $mensajeOk = 'Código creado.';
            } catch (Exception $e) {
                $errores[] = 'Error guardando código.';
            }
        }
    }
    // UPDATE
    elseif ($action === 'update' && $idCodigo > 0 && empty($errores)) {
        try {
            $stmt = $pdo->prepare(
                'SELECT id FROM codigos_descuento
                 WHERE admin_id = :admin_id AND codigo = :codigo AND id <> :id'
            );
            $stmt->execute(array(
                ':admin_id' => $adminId,
                ':codigo'   => $codigo,
                ':id'       => $idCodigo
            ));
            $existe = $stmt->fet
            if ($existe) {
                $errores[] = 'Código ya existe en otro registro.';
            }
        } catch (Exception $e) {
            $errores[] = 'Error buscando duplicado.';
        }

        if (empty($errores)) {
            try {
                $stmt = $pdo->prepare(
                    'UPDATE codigos_descuento
                     SET codigo = :codigo,
                         porcentaje = :porcentaje,
                         cantidad_maxima = :cant_max,
                         activo = :activo
                     WHERE id = :id AND admin_id = :admin_id'
                );
                $stmt->execute(array(
                    ':codigo'    => $codigo,
                    ':porcentaje'=> $porcentaje,
                    ':cant_max'  => $cantidadMax,
                    ':activo'    => $activo,
                    ':id'        => $idCodigo,
                    ':admin_id'  => $adminId
                ));
                if ($stmt->rowCount() > 0) {
                    $mensajeOk = 'Código actualizado.';
                } else {
                    $errores[] = 'No se encontró para actualizar.';
                }
            } catch (Exception $e) {
                $errores[] = 'Error actualizando código.';
            }
        }

        if (!empty($errores)) {
            try {
                $stmt = $pdo->prepare(
                    'SELECT * FROM codigos_descuento
                     WHERE id = :id AND admin_id = :admin_id'
                );
                $stmt->execute(array(':id' => $idCodigo, ':admin_id' => $adminId));
                $editRow = $stmt->fetch(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                // Ignorar
            }
        }
    }
    // DELETE
    elseif ($action === 'delete' && $idCodigo > 0) {
        try {
            $stmt = $pdo->prepare(
                'DELETE FROM codigos_descuento
                 WHERE id = :id AND admin_id = :admin_id'
            );
            $stmt->execute(array(':id' => $idCodigo, ':admin_id' => $adminId));
            if ($stmt->rowCount() > 0) {
                $mensajeOk = 'Código eliminado.';
            } else {
                $errores[] = 'No se encontró para eliminar.';
            }
        } catch (Exception $e) {
            $errores[] = 'Error eliminando código.';
        }
    }
}

// -------------------------------------------------
// GET: editar
// -------------------------------------------------
if ($metodo === 'GET') {
    $getAction = isset($_GET['action']) ? $_GET['action'] : '';
    $getId     = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    if ($getAction === 'edit' && $getId > 0) {
        try {
            $stmt = $pdo->prepare(
                'SELECT * FROM codigos_descuento
                 WHERE id = :id AND admin_id = :admin_id'
            );
            $stmt->execute(array(':id' => $getId, ':admin_id' => $adminId));
            $editRow = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$editRow) {
                $errores[] = 'No se encontró para edición.';
            }
        } catch (Exception $e) {
            $errores[] = 'Error cargando código.';
        }
    }
}

// -------------------------------------------------
// Listado
// -------------------------------------------------
$codigos = array();
try {
    $stmt = $pdo->prepare(
        'SELECT * FROM codigos_descuento
         WHERE admin_id = :admin_id
         ORDER BY creado_en DESC, codigo'
    );
    $stmt->execute(array(':admin_id' => $adminId));
    $codigos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $errores[] = 'Error listando códigos.';
}

// -------------------------------------------------
// Layout + HTML
// -------------------------------------------------
$title = 'Códigos de descuento';
require __DIR__ . '/inc/layout_top.php';
?>
<div class="card" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
  <a class="btn secondary" href="panel_admin.php">⬅ Volver al panel</a>
</div>

<div class="card">
  <h2>Códigos de descuento</h2>
  <div style="color:var(--muted);font-size:14px;">
    Generá códigos que aplican % sobre el precio de la entrada.
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
    <
      <ul>
        <?php foreach ($errores as $err): ?>
          <li><?php echo e($err); ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
<?php endif; ?>

<form method="post" action="descuentos.php">
  <div class="card">
    <h3><?php echo $editRow ? 'Editar código' : 'Nuevo código'; ?></h3>

    <input type="hidden" name="action" value="<?php echo $editRow ? 'update' : 'create'; ?>">
    <?php if ($editRow): ?>
      <input type="hidden" name="id" value="<?php echo (int)$editRow['id']; ?>">
    <?php endif; ?>

    <label for="codigo">Código (max. 10)</label>
    <input type="text" id="codigo" name="codigo" maxlength="10" required
           value="<?php echo e(valf($editRow, 'codigo', '')); ?>"
           placeholder="Ej: STR10, VIP20">

    <label for="porcentaje">% de descuento</label>
    <input type="number" id="porcentaje" name="porcentaje"
           min="1" max="100" step="1" required
           value="<?php echo e(valf($editRow, 'porcentaje', '')); ?>"
           placeholder="Ej: 10, 20, 50">

    <label for="cantidad_maxima">Cantidad máxima (0 = ilimitado)</label>
    <input type="number" id="cantidad_maxima" name="cantidad_maxima"
           min="0" step="1"
           value="<?php echo e(valf($editRow, 'cantidad_maxima', '0')); ?>">

    <?php $activoActual = (int)valf($editRow, 'activo', 1); ?>
    <label style="display:inline-flex;align-items:center;gap:6px;margin-top:8px;">
      <input type="checkbox" name="activo" value="1" <?php echo $activoActual ? 'checked' : ''; ?>>
      Código activo
    </label>

    <button type="submit" class="btn" style="margin-top:12px;">
      <?php echo $editRow ? 'Guardar cambios' : 'Crear código'; ?>
    </button>
  </div>
</form>

<div class="card">
  <h3>Códigos existentes</h3>

  <?php if (empty($codigos)): ?>
    <p>No tenés códigos cargados.</p>
  <?php else: ?>
    <table style="width:100%;border-collapse:collapse;font-size:0.9em;">
      <thead>
        <tr>
          <th>Código</th>
          <th>% desc.</th>
          <th>Max usos</th>
          <th>Usados</th>
          <th>Activo</th>
          <th>Acciones</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($codigos as $c): ?>
          <tr>
            <td><?php echo e($c['codigo']); ?></td>
            <td><?php echo (int)$c['porcentaje']; ?>%</td>
            <td><?php echo (int)$c['cantidad_maxima']; ?></td>
            <td><?php echo (int)$c['cantidad_usada']; ?></td>
            <td><?php echo ((int)$c['activo'] ? '✔' : '✖'); ?></td>
            <td style="white-space:nowrap;">
              <a href="descuentos.php?action=edit&amp;id=<?php echo (int)$c['id']; ?>"
                 title="Editar"
                 style="display:inline-block;padding:2px 6px;border-radius:4px;background:#22c55e;color:#fff;font-size:0.8em;text-decoration:none;margin-right:4px;">
                &#9998;
              </a>

              <form method="post" action="descuentos.php"
                    style="display:inline;"
                    onsubmit="return confirm('Seguro que querés eliminar este código?');">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?php echo (int)$c['id']; ?>">
                <button type="submit" title="Borrar"
                        style="display:inline-block;padding:2px 6px;border-radius:4px;background:#ef4444;color:#fff;border:0;font-size:0.8em;cursor:pointer;">
                  &#128465;
                </button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<?php
require __DIR__ . '/inc/layout_bottom.php';
