<?php
require_once __DIR__.'/inc/bootstrap.php';
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
    $stT = $pdo->prepare("SELECT id, nombre, tipo, precio FROM tipos_entrada WHERE evento_id = :id ORDER BY id ASC");
    $stT->execute(array(':id'=>$eid));
    while ($row = $stT->fetch(PDO::FETCH_ASSOC)) {
      $tiposPorEvento[$eid][] = array(
        'id' => $row['id'],
        'nombre' => $row['nombre'],
        'tipo' => $row['tipo'],
        'precio' => $row['precio'],
        'origen' => 'tipo',
      );
    }
  } catch (Exception $e) {}

  // Plantillas de entrada (por si no existen tipos cargados)
  try {
    $colsPl = $pdo->query("PRAGMA table_info(plantillas_entrada)")->fetchAll(PDO::FETCH_ASSOC);
    $hasEvCol = false;
    foreach ($colsPl as $c) { if (!empty($c['name']) && $c['name']==='evento_id') { $hasEvCol = true; break; } }
    $sqlPl = "SELECT id, nombre, tipo, precio";
    if ($hasEvCol) {
      $sqlPl .= " FROM plantillas_entrada WHERE evento_id = :id ORDER BY id ASC";
      $stP = $pdo->prepare($sqlPl);
      $stP->execute(array(':id'=>$eid));
    } else {
      $sqlPl .= " FROM plantillas_entrada ORDER BY id ASC";
      $stP = $pdo->prepare($sqlPl);
      $stP->execute();
    }
    while ($row = $stP->fetch(PDO::FETCH_ASSOC)) {
      $tiposPorEvento[$eid][] = array(
        'id' => $row['id'],
        'nombre' => $row['nombre'],
        'tipo' => $row['tipo'],
        'precio' => $row['precio'],
        'origen' => 'plantilla',
      );
    }
  } catch (Exception $e) {}
}

$errors = array();
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $eventoId = isset($_POST['evento_id']) ? (int)$_POST['evento_id'] : 0;
    $tipoId   = isset($_POST['tipo_id']) ? trim($_POST['tipo_id']) : '';
    $email    = isset($_POST['email']) ? trim($_POST['email']) : '';
    $tickexId = isset($_POST['tickex_id']) ? (int)$_POST['tickex_id'] : 0;
    $nombre   = isset($_POST['nombre']) ? trim($_POST['nombre']) : '';
    $apodo    = isset($_POST['apodo']) ? trim($_POST['apodo']) : '';

    if ($eventoId <= 0) $errors[] = 'Seleccioná un evento.';
    if ($tipoId === '') $errors[] = 'Seleccioná un tipo de entrada.';

    // Resolver email por tickex_id o apodo si no viene email
    if ($email === '' && ($tickexId > 0 || $apodo !== '')) {
        try {
        if ($tickexId > 0) {
          $stU = $pdo->prepare('SELECT email, nombre, apellido, apodo, id FROM registro_pendientes WHERE id = :id LIMIT 1');
          $stU->execute(array(':id'=>$tickexId));
        } else {
          $stU = $pdo->prepare('SELECT email, nombre, apellido, apodo, id FROM registro_pendientes WHERE apodo = :ap LIMIT 1');
          $stU->execute(array(':ap'=>$apodo));
        }
            $rowU = $stU->fetch(PDO::FETCH_ASSOC);
            if ($rowU && !empty($rowU['email'])) {
                $email = $rowU['email'];
          if ($tickexId <= 0) { $tickexId = (int)$rowU['id']; }
                if ($nombre === '') {
                    $full = trim(($rowU['nombre'] ?? '') . ' ' . ($rowU['apellido'] ?? ''));
                    $nombre = $full !== '' ? $full : ($rowU['apodo'] ?? '');
                }
            } else {
          $errors[] = 'No se encontró el usuario Tickex con ese ID/apodo.';
            }
        } catch (Exception $e) {
            $errors[] = 'No se pudo buscar el usuario Tickex.';
        }
    }

    if ($email === '') $errors[] = 'Ingresá el email del destinatario o su Tickex ID.';

    if (empty($errors)) {
        // Generar código único
        $codigo = 'CORT-' . strtoupper(substr(md5($email . microtime(true)), 0, 10));
        $fecha  = date('Y-m-d H:i:s');
        $nombreIns = $nombre !== '' ? $nombre : $email;
        try {
          $stmt = $pdo->prepare("INSERT INTO entradas (nombre, email, fecha_registro, codigo, checked_in, tipo, monto_pagado, evento_id) VALUES (:n,:e,:f,:c,0,:t,0,:ev)");
          $stmt->execute(array(
            ':n'  => $nombreIns,
            ':e'  => $email,
            ':f'  => $fecha,
            ':c'  => $codigo,
            ':t'  => $tipoId,
            ':ev' => $eventoId,
          ));
          $success = 'Entrada creada y asignada a ' . htmlspecialchars($email, ENT_QUOTES, 'UTF-8');

          // Envío automático de email
          $asunto = "¡Recibiste una entrada de cortesía!";
          $mensaje = "Hola $nombreIns,\n\nTe han asignado una entrada de cortesía para el evento.\n\n";
          $mensaje .= "Código de entrada: $codigo\n";
          $mensaje .= "Tipo: $tipoId\n";
          $mensaje .= "Fecha de registro: $fecha\n";
          $mensaje .= "\nMostrá este código en la puerta del evento para ingresar.\n\n";
          $mensaje .= "Si tenés dudas, respondé este email.\n\n";
          $mensaje .= "¡Nos vemos!\nEquipo Tickex";
          $headers = "From: info@tickex.com.ar\r\nReply-To: info@tickex.com.ar\r\n";
          // mail() puede fallar silenciosamente, así que no interrumpe el flujo
          @mail($email, $asunto, $mensaje, $headers);
        } catch (Exception $e) {
          $errors[] = 'No se pudo crear la entrada: ' . $e->getMessage();
        }
      }
}

$title = 'Enviar Tickex';
include __DIR__.'/inc/layout_top.php';
?>
<div class="card" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
  <a class="btn secondary" href="panel_admin.php">⬅ Volver</a>
  <h2 style="margin:0;">Enviar Tickex</h2>
  <span class="muted">Emití entradas de cortesía y asignalas a un usuario Tickex.</span>
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
            <?php $labelOrigen = ($t['origen'] === 'plantilla') ? '[Plantilla]' : '[Tipo]'; ?>
            <option value="<?php echo e($t['nombre']); ?>" data-evento="<?php echo (int)$eid; ?>">
              <?php echo $labelOrigen; ?> #<?php echo (int)$t['id']; ?> — <?php echo e($t['nombre']); ?>
            </option>
          <?php endforeach; ?>
        <?php endforeach; ?>
      </select>
      <small class="muted">Se filtra al elegir evento.</small>
    </div>
    <div>
      <label>Email destino</label>
      <input type="email" name="email" placeholder="usuario@correo.com">
    </div>
    <div>
      <label>Tickex ID (opcional)</label>
      <input type="number" name="tickex_id" placeholder="ID de usuario Tickex">
      <small class="muted">Si lo completás, buscamos el email automáticamente.</small>
    </div>
    <div>
      <label>Apodo Tickex (opcional)</label>
      <input name="apodo" placeholder="Si no sabés el email, probá con apodo">
    </div>
    <div>
      <label>Nombre visible en entrada (opcional)</label>
      <input name="nombre" placeholder="Nombre / apodo para el ticket">
    </div>
    <div style="grid-column:1 / -1;">
      <button class="btn" type="submit">Crear y enviar</button>
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

<?php include __DIR__.'/inc/layout_bottom.php'; ?>
