<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require_once __DIR__.'/inc/bootstrap.php';
require_once __DIR__.'/inc/mail.php';
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
    // Removed incomplete code fragment '$e' that caused syntax error

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
    $nombre   = isset($_POST['nombre']) ? trim($_POST['nombre']) : '';
  $tickexId = isset($_POST['tickex_id']) ? trim((string)$_POST['tickex_id']) : '';

    if ($eventoId <= 0) $errors[] = 'Seleccioná un evento.';
    if ($tipoId === '') $errors[] = 'Seleccioná un tipo de entrada.';

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
            $entradaId = (int)$pdo->lastInsertId();
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

            $mailOk = tickex_send_mail_template($email, 'tickex_cortesia', array(
              'nombre' => $nombreIns,
              'codigo' => $codigo,
              'tipo'   => $tipoId,
              'fecha'  => $fecha,
            ), array(
              'context'       => 'tickex_cortesia',
              'related_table' => 'entradas',
              'related_id'    => $entradaId,
            ), array(
              'subject'      => $asunto,
              'body'         => $mensaje,
              'from_email'   => 'info@tickex.com.ar',
              'from_name'    => 'Tickex',
              'reply_to'     => 'info@tickex.com.ar',
              'extra_params' => '-f info@tickex.com.ar',
              'is_html'      => 0,
            ));

            // Mejor feedback: intentar traer trace_id del log para correlacionar con Exim/Gmail
            $traceId = '';
            $mailOk2 = null;
            $mailErr = '';
            try {
              $stL = $pdo->prepare("SELECT trace_id, mail_ok, error_text FROM email_logs WHERE related_table = 'entradas' AND related_id = :rid ORDER BY id DESC LIMIT 1");
              $stL->execute(array(':rid' => $entradaId));
              $rowL = $stL->fetch(PDO::FETCH_ASSOC);
              if ($rowL) {
                if (isset($rowL['trace_id'])) $traceId = (string)$rowL['trace_id'];
                if (isset($rowL['mail_ok'])) $mailOk2 = ((int)$rowL['mail_ok'] === 1);
                if (!empty($rowL['error_text'])) $mailErr = (string)$rowL['error_text'];
              }
            } catch (Exception $e) {
              // ignore
            }

            $finalOk = ($mailOk2 !== null) ? (bool)$mailOk2 : (bool)$mailOk;
            $success .= $finalOk ? ' — Email: OK' : ' — Email: con fallas';
            if ($traceId !== '') {
              $success .= ' — Trace: ' . htmlspecialchars($traceId, ENT_QUOTES, 'UTF-8');
              $success .= ' — Gmail: rfc822msgid:tickex-' . htmlspecialchars($traceId, ENT_QUOTES, 'UTF-8') . '@tickex.com.ar';
            }
            if (!$finalOk && $mailErr !== '') {
              $errors[] = 'Error de envío: ' . $mailErr;
            }
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
      <input name="tickex_id" placeholder="Ej: Senchi">
      <small class="muted">Es el apodo Tickex. Si lo completás, buscamos el email automáticamente.</small>
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

    // Buscar últimos tickex enviados por este formulario (código CORT-...)
    $where = "WHERE codigo LIKE 'CORT-%'";
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
