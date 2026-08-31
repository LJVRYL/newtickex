<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require_once __DIR__.'/inc/bootstrap.php';
require_once __DIR__.'/inc/manual_ticket_issuance.php';
require_login();
$cu = current_user();
$rol = isset($cu['tipo_global']) ? $cu['tipo_global'] : (isset($cu['rol']) ? $cu['rol'] : '');
if (!in_array($rol, array('admin_evento','super_admin','superadmin'), true)) {
    http_response_code(403);
    include __DIR__.'/inc/layout_top.php';
    echo "<div class='card'><h3>Acceso restringido</h3><p>Solo organizadores.</p></div>";
    include __DIR__.'/inc/layout_bottom.php';
    exit;
}

$pdo = db();

// Eventos visibles
$evSql = ($rol === 'admin_evento') ? "SELECT id, nombre FROM eventos WHERE creado_por_admin_id = :aid ORDER BY id DESC" : "SELECT id, nombre FROM eventos ORDER BY id DESC";
$stEv = $pdo->prepare($evSql);
if ($rol === 'admin_evento') {
    $stEv->execute(array(':aid'=>(int)$cu['id']));
} else {
    $stEv->execute();
}
$eventos = $stEv->fetchAll(PDO::FETCH_ASSOC);

$tiposPorEvento = array();
foreach ($eventos as $ev) {
  $eid = (int)$ev['id'];
  $tiposPorEvento[$eid] = array();

  // Tipos de entrada (todas, incluso ocultas/inactivas)
  try {
    $stT = $pdo->prepare("SELECT id, nombre, tipo, precio, cantidad_disponible, qr_quantity FROM tipos_entrada WHERE evento_id = :id ORDER BY id ASC");
    $stT->execute(array(':id'=>$eid));
    while ($row = $stT->fetch(PDO::FETCH_ASSOC)) {
      $tiposPorEvento[$eid][] = array(
        'id' => $row['id'],
        'nombre' => $row['nombre'],
        'tipo' => $row['tipo'],
        'precio' => $row['precio'],
        'cantidad_disponible' => $row['cantidad_disponible'],
        'qr_quantity' => tickex_ticket_qr_quantity(isset($row['qr_quantity']) ? $row['qr_quantity'] : 1),
        'origen' => 'tipo',
      );
    }
  } catch (Exception $e) {}
}

$errors = array();
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $eventoId = isset($_POST['evento_id']) ? (int)$_POST['evento_id'] : 0;
    $tipoId   = isset($_POST['tipo_id']) ? (int)$_POST['tipo_id'] : 0;
    $email    = isset($_POST['email']) ? trim($_POST['email']) : '';
    $nombre   = isset($_POST['nombre']) ? trim($_POST['nombre']) : '';
    $tickexId = isset($_POST['tickex_id']) ? trim((string)$_POST['tickex_id']) : '';
    $mode     = isset($_POST['modo']) ? (string)$_POST['modo'] : 'courtesy';
    $quantity = isset($_POST['cantidad']) ? (int)$_POST['cantidad'] : 1;
    $hidden   = (isset($_POST['oculto']) && in_array($rol, array('super_admin','superadmin'), true)) ? 1 : 0;

    if (!tickex_csrf_verify(isset($_POST['_csrf']) ? $_POST['_csrf'] : '')) $errors[] = 'La sesión venció. Actualizá la página e intentá nuevamente.';
    if ($eventoId <= 0) $errors[] = 'Seleccioná un evento.';
    if ($tipoId <= 0) $errors[] = 'Seleccioná un tipo de entrada.';
    if (!in_array($mode, array('courtesy','manual_transfer'), true)) $errors[] = 'Seleccioná una modalidad válida.';
    if ($quantity < 1 || $quantity > 20) $errors[] = 'La cantidad de promociones debe estar entre 1 y 20.';

    // Resolver email por Tickex ID (apodo) si no viene email
    if ($email === '' && $tickexId !== '') {
        try {
          $raw = trim($tickexId);
          $idLookup = 0;
          if ($raw !== '' && $raw[0] === '#') {
            $idLookup = (int)substr($raw, 1);
          } elseif (ctype_digit($raw)) {
            // compat: si alguien pegó un ID numérico antiguo
            $idLookup = (int)$raw;
          }

          if ($idLookup > 0) {
            $stU = $pdo->prepare('SELECT email, nombre, apellido, apodo, id FROM registro_pendientes WHERE id = :id LIMIT 1');
            $stU->execute(array(':id' => $idLookup));
          } else {
            $stU = $pdo->prepare('SELECT email, nombre, apellido, apodo, id FROM registro_pendientes WHERE lower(apodo) = lower(:ap) LIMIT 1');
            $stU->execute(array(':ap' => $raw));
          }
            $rowU = $stU->fetch(PDO::FETCH_ASSOC);
            if ($rowU && !empty($rowU['email'])) {
                $email = $rowU['email'];
                if ($nombre === '') {
                    $full = trim((isset($rowU['nombre']) ? $rowU['nombre'] : '') . ' ' . (isset($rowU['apellido']) ? $rowU['apellido'] : ''));
                    $nombre = $full !== '' ? $full : (isset($rowU['apodo']) ? $rowU['apodo'] : '');
                }
            } else {
          $errors[] = 'No se encontró el usuario Tickex con ese Tickex ID.';
            }
        } catch (Exception $e) {
            $errors[] = 'No se pudo buscar el usuario Tickex.';
        }
    }

    if ($email === '') $errors[] = 'Ingresá el email del destinatario o su Tickex ID.';

    if (empty($errors)) {
        $nombreIns = $nombre !== '' ? $nombre : $email;
        try {
            $result = tickex_manual_issue_package($pdo, array(
                'evento_id' => $eventoId,
                'tipo_id' => $tipoId,
                'cantidad' => $quantity,
                'modo' => $mode,
                'email' => $email,
                'nombre' => $nombreIns,
                'admin_id' => (int)$cu['id'],
                'restrict_to_admin' => $rol === 'admin_evento',
                'oculto' => $hidden,
            ));
            $success = 'Se emitieron ' . (int)$result['issued_quantity'] . ' QR independientes para ' . e($email) . '.';
            if ($mode === 'manual_transfer') {
                $success .= ' Pago registrado: $' . number_format((float)$result['total'], 2, ',', '.') . '.';
            } else {
                $success .= ' Emisión de cortesía, sin ingreso registrado.';
            }
            $success .= $result['email_status'] === 'sent' ? ' Email: OK.' : ' Email pendiente o con fallas.';
            if ($result['email_status'] !== 'sent' && $result['email_error'] !== '') $errors[] = 'Error de envío: ' . $result['email_error'];
        } catch (Exception $e) {
            $errors[] = 'No se pudieron emitir las entradas: ' . $e->getMessage();
        }
    }
}

$title = 'Enviar Tickex';
include __DIR__.'/inc/layout_top.php';
?>
<div class="card" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
  <a class="btn secondary" href="panel_admin.php">⬅ Volver</a>
  <h2 style="margin:0;">Enviar Tickex</h2>
  <span class="muted">Registrá una transferencia o emití cortesías respetando paquetes, QR y stock.</span>
</div>

<?php if (!empty($errors)): ?>
  <div class="flash err">
    <ul style="margin:0 0 0 16px;">
      <?php foreach ($errors as $er): ?>
        <li><?php echo e($er); ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<?php if ($success !== ''): ?>
  <div class="flash ok"><?php echo $success; ?></div>
<?php endif; ?>

<div class="card" style="max-width:900px;">
  <form method="post" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px;">
    <input type="hidden" name="_csrf" value="<?php echo e(tickex_csrf_token()); ?>">
    <div>
      <label>Evento</label>
      <select name="evento_id" required>
        <option value="">Elegí evento</option>
        <?php foreach ($eventos as $ev): ?>
          <option value="<?php echo (int)$ev['id']; ?>"><?php echo e('#'.$ev['id'].' — '.$ev['nombre']); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label>Tipo de entrada</label>
      <select name="tipo_id" required>
        <option value="">Elegí tipo</option>
        <?php foreach ($tiposPorEvento as $eid => $tipos): ?>
          <?php foreach ($tipos as $t): ?>
            <option value="<?php echo (int)$t['id']; ?>" data-evento="<?php echo (int)$eid; ?>">
              #<?php echo (int)$t['id']; ?> — <?php echo e($t['nombre']); ?> — $<?php echo number_format((float)$t['precio'], 2, ',', '.'); ?> — entrega <?php echo (int)$t['qr_quantity']; ?> QR — stock <?php echo (int)$t['cantidad_disponible']; ?>
            </option>
          <?php endforeach; ?>
        <?php endforeach; ?>
      </select>
      <small class="muted">Se filtra al elegir evento.</small>
    </div>
    <div>
      <label>Modalidad</label>
      <select name="modo" required>
        <option value="manual_transfer">Venta manual / transferencia</option>
        <option value="courtesy">Cortesía</option>
      </select>
      <small class="muted">La transferencia registra el precio configurado. La cortesía registra $0.</small>
    </div>
    <div>
      <label>Cantidad de promociones</label>
      <input type="number" name="cantidad" min="1" max="20" value="1" required>
      <small class="muted">Ejemplo: 1 Promo 3x4 emite 4 QR y descuenta 4 lugares.</small>
    </div>
    <div>
      <label>Email destino</label>
      <input type="email" name="email" placeholder="usuario@correo.com">
    </div>
    <div>
      <label>Tickex ID (opcional)</label>
      <input name="tickex_id" placeholder="Ej: Senchi">
      <small class="muted">Es el apodo Tickex. Si lo completás, buscamos el email automáticamente.</small>
    </div>
    <div>
      <label>Nombre visible en entrada (opcional)</label>
      <input name="nombre" placeholder="Nombre / apodo para el ticket">
    </div>
    <?php if (in_array($rol, array('super_admin','superadmin'), true)): ?>
    <div style="display:flex;align-items:center;gap:10px;">
      <label style="display:flex;align-items:center;gap:8px;">
        <input type="checkbox" name="oculto" value="1"> Crear como ticket oculto
      </label>
      <div class="muted" style="font-size:12px;">
        El ticket no aparecerá en el listado del evento hasta que sea escaneado.
      </div>
    </div>
    <?php endif; ?>
    <div style="grid-column:1 / -1;">
      <button class="btn" type="submit">Emitir y enviar todos los QR</button>
    </div>
  </form>
</div>

<script>
  (function(){
    const evSelect = document.querySelector('select[name="evento_id"]');
    const tipoSelect = document.querySelector('select[name="tipo_id"]');
    function filterTipos(){
      const ev = evSelect.value;
      Array.from(tipoSelect.options).forEach(opt => {
        if (!opt.value) return;
        const evAttr = opt.getAttribute('data-evento');
        opt.hidden = (evAttr && ev && evAttr !== ev);
      });
      // reset selection if hidden
      if (tipoSelect.selectedOptions.length && tipoSelect.selectedOptions[0].hidden) {
        tipoSelect.value = '';
      }
    }
    if (evSelect && tipoSelect) {
      evSelect.addEventListener('change', filterTipos);
      filterTipos();
    }
  })();
</script>


<div class="card" style="margin-top:32px;max-width:900px;overflow:auto;">
  <h3>Últimos Tickex enviados</h3>
  <form method="get" style="margin-bottom:12px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
    <input type="text" name="buscar" placeholder="Buscar por email, nombre, código..." value="<?php echo isset($_GET['buscar']) ? e($_GET['buscar']) : ''; ?>" style="flex:1 1 200px;">
    <button class="btn" type="submit">Buscar</button>
    <?php if (isset($_GET['buscar']) && $_GET['buscar'] !== ''): ?>
      <a class="btn secondary" href="enviar_tickex.php">Limpiar</a>
    <?php endif; ?>
  </form>

  <?php
    // Eliminar tickex si se pide
    if (isset($_GET['del_tickex']) && (int)$_GET['del_tickex'] > 0) {
      $delId = (int)$_GET['del_tickex'];
      $pdo->prepare("DELETE FROM entradas WHERE id = ?")->execute(array($delId));
      echo '<div class="flash ok">Tickex eliminado.</div>';
    }

    // Buscar últimos Tickex emitidos manualmente mediante una orden auditable.
    $where = "WHERE tc_order_request_id LIKE 'manual-%'";
    $params = array();
    if (isset($_GET['buscar']) && trim($_GET['buscar']) !== '') {
      $q = '%'.trim($_GET['buscar']).'%';
      $where .= " AND (email LIKE :q OR nombre LIKE :q OR codigo LIKE :q)";
      $params[':q'] = $q;
    }
    $sql = "SELECT * FROM entradas $where ORDER BY id DESC LIMIT 20";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $ultimos = $st->fetchAll(PDO::FETCH_ASSOC);
  ?>
  <?php if (empty($ultimos)): ?>
    <div class="muted">No hay tickex enviados recientemente.</div>
  <?php else: ?>
    <table class="table" style="font-size:14px;min-width:860px;table-layout:fixed;width:100%;">
      <thead>
        <tr>
          <th>ID</th>
          <th>Nombre</th>
          <th>Email</th>
          <th>Código</th>
          <th>Tipo</th>
          <th>Evento</th>
          <th>Fecha</th>
          <th>Acciones</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($ultimos as $u): ?>
          <tr>
            <td><?php echo (int)$u['id']; ?></td>
            <td style="word-break:break-word;overflow-wrap:anywhere;"><?php echo e($u['nombre']); ?></td>
            <td style="word-break:break-word;overflow-wrap:anywhere;"><?php echo e($u['email']); ?></td>
            <td><?php echo e($u['codigo']); ?></td>
            <td><?php echo e($u['tipo']); ?></td>
            <td><?php echo (int)$u['evento_id']; ?></td>
            <td><?php echo e($u['fecha_registro']); ?></td>
            <td style="white-space:nowrap;">
              <a class="btn secondary" style="padding:6px 10px;" href="ticket.php?c=<?php echo urlencode((string)$u['codigo']); ?>" target="_blank" rel="noopener" title="Ver ticket" aria-label="Ver ticket">👁</a>
              <a class="btn danger" style="padding:6px 10px;" href="enviar_tickex.php?del_tickex=<?php echo (int)$u['id']; ?>" onclick="return confirm('¿Eliminar este tickex?');" title="Eliminar" aria-label="Eliminar">🗑</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<?php include __DIR__.'/inc/layout_bottom.php'; ?>
