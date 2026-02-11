<?php
require_once __DIR__ . '/inc/bootstrap.php';

if (!function_exists('e')) {
  function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

/* Login: si existe helper, usalo; sino redirigimos suave */
if (function_exists('require_login')) {
  require_login();
} else {
  if (session_status() === PHP_SESSION_NONE) @session_start();
  if (empty($_SESSION)) { header('Location: login.php'); exit; }
}

$pdo = function_exists('db') ? db() : (isset($pdo) ? $pdo : null);
if (!($pdo instanceof PDO)) { http_response_code(500); echo "DB no disponible"; exit; }

/* detectar rol de forma tolerante (sin romper si cambia el schema) */
$role = '';
if (session_status() === PHP_SESSION_NONE) @session_start();

// Usar current_user() si existe, sino fallback
$role = '';
if (function_exists('current_user')) {
  $cu = current_user();
  $role = isset($cu['tipo_global']) ? $cu['tipo_global'] : (isset($cu['rol']) ? $cu['rol'] : '');
} else {
  $u = null;
  if (isset($_SESSION['user'])) $u = $_SESSION['user'];
  elseif (isset($_SESSION['usuario'])) $u = $_SESSION['usuario'];
  elseif (isset($_SESSION['auth'])) $u = $_SESSION['auth'];
  if (is_array($u)) {
    $role = (string)($u['rol'] ?? $u['role'] ?? $u['perfil'] ?? '');
  }
}

$isAdmin = false;
$rl = strtolower($role);
if (strpos($rl, 'super') !== false || strpos($rl, 'admin') !== false) $isAdmin = true;

/* evento_id */
$eventoId = 0;
if (isset($_GET['evento_id'])) $eventoId = (int)$_GET['evento_id'];
if (isset($_POST['evento_id'])) $eventoId = (int)$_POST['evento_id'];
if ($eventoId <= 0) { http_response_code(400); echo "Falta evento_id"; exit; }
$isEmbed = false;
if (isset($_GET['embed'])) {
  $isEmbed = $_GET['embed'] !== '0' && $_GET['embed'] !== 'false';
} elseif (isset($_POST['embed'])) {
  $isEmbed = $_POST['embed'] !== '0' && $_POST['embed'] !== 'false';
}

/* Obtener plantillas de entrada del evento (desde tipos_entrada) */
$plantillas = array(); // array( 'nombre' => array('precio'=>X, 'stock'=>Y, ...) )
try {
  $stmtTipos = $pdo->prepare("SELECT nombre, precio, cantidad_total FROM tipos_entrada WHERE evento_id = ? ORDER BY nombre");
  $stmtTipos->execute(array($eventoId));
  while ($row = $stmtTipos->fetch(PDO::FETCH_ASSOC)) {
    if (isset($row['nombre']) && trim($row['nombre']) !== '') {
      $nombre = trim($row['nombre']);
      $plantillas[$nombre] = array(
        'precio' => isset($row['precio']) ? (float)$row['precio'] : 0,
        'stock' => isset($row['cantidad_total']) ? (int)$row['cantidad_total'] : 0
      );
    }
  }
} catch (Exception $e) {
  // Si no existe tipos_entrada o error, ignorar
}

/* Agregar opción especial "Monto libre" solo para admin */
if ($isAdmin) {
  $plantillas['Monto libre'] = array('precio' => 0, 'stock' => 999999);
}

/* Si no hay plantillas en BD, usar fallback */
if (empty($plantillas)) {
  if ($isAdmin) {
    $plantillas = array(
      'Manual' => array('precio' => 0, 'stock' => 999999),
      'Monto libre' => array('precio' => 0, 'stock' => 999999)
    );
  } else {
    $plantillas = array(
      'Lista' => array('precio' => 0, 'stock' => 999999),
      'Free' => array('precio' => 0, 'stock' => 999999),
      'Invitado' => array('precio' => 0, 'stock' => 999999)
    );
  }
}


/* helper: generar código único */
function gen_codigo($eventoId){
  $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
  $len = 10;
  $out = '';
  $bytes = null;

  if (function_exists('random_bytes')) {
    $bytes = random_bytes($len);
    for ($i=0; $i<$len; $i++) {
      $out .= $alphabet[ord($bytes[$i]) % strlen($alphabet)];
    }
  } elseif (function_exists('openssl_random_pseudo_bytes')) {
    $bytes = openssl_random_pseudo_bytes($len);
    for ($i=0; $i<$len; $i++) {
      $out .= $alphabet[ord($bytes[$i]) % strlen($alphabet)];
    }
  } else {
    for ($i=0; $i<$len; $i++) $out .= $alphabet[mt_rand(0, strlen($alphabet)-1)];
  }

  return 'M'.$eventoId.'-'.$out; // prefijo Manual + evento
}

$errors = array();
$created = array();

/* POST */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $nombre = trim((string)($_POST['nombre'] ?? ''));
  $email  = trim((string)($_POST['email'] ?? ''));
  $plantilla   = trim((string)($_POST['plantilla'] ?? ''));
  $monto  = (float)($_POST['monto_pagado'] ?? 0);
  $cant   = (int)($_POST['cantidad'] ?? 1);

  if ($nombre === '') $errors[] = "Falta el nombre.";
  if ($email === '') $email = '-';
  if ($email !== '-' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Email inválido.";
  if (!isset($plantillas[$plantilla])) $errors[] = "Plantilla no permitida.";
  if ($cant < 1) $cant = 1;

  /* límites por rol */
  $max = $isAdmin ? 200 : 20;
  if ($cant > $max) $errors[] = "Cantidad máxima permitida: $max.";

  /* Monto: si es "Monto libre", admin puede setear; sino, usar precio de plantilla o 0 */
  if ($plantilla === 'Monto libre') {
    if (!$isAdmin) {
      $errors[] = "Solo admin puede usar 'Monto libre'.";
    }
    /* admin con "Monto libre" puede setear monto libremente */
    if ($monto < 0) $monto = 0;
  } else {
    /* Otros tipos: usar precio de plantilla, staff no puede cambiar */
    $monto = $plantillas[$plantilla]['precio'] ?? 0;
  }

  if (empty($errors)) {
    $now = date('Y-m-d H:i:s');

    $isTwoForOne = preg_match('/2\s*x\s*1/i', $plantilla) === 1;
    $unitsPerSale = $isTwoForOne ? 2 : 1;
    $montoUnit = $monto;
    if ($isTwoForOne && $monto > 0) {
      $montoUnit = $monto / 2; // repartir el total entre las dos entradas
    }

    /* statement insert - usar plantilla como tipo */
    $sqlIns = "INSERT INTO entradas (nombre,email,fecha_registro,codigo,checked_in,tipo,monto_pagado,evento_id)
           VALUES (:nombre,:email,:fecha,:codigo,0,:plantilla,:monto,:evento)";
    $stIns = $pdo->prepare($sqlIns);

    /* check uniqueness */
    $stChk = $pdo->prepare("SELECT 1 FROM entradas WHERE codigo = :c LIMIT 1");

    for ($n=0; $n<$cant; $n++) {
      for ($u=0; $u<$unitsPerSale; $u++) {
        $codigo = '';
        for ($try=0; $try<30; $try++) {
          $codigo = gen_codigo($eventoId);
          $stChk->execute(array(':c'=>$codigo));
          $exists = $stChk->fetchColumn();
          if (!$exists) break;
        }
        if ($codigo === '') { $errors[] = "No pude generar código."; break 2; }

        try {
          $stIns->execute(array(
            ':nombre'=>$nombre,
            ':email'=>$email,
            ':fecha'=>$now,
            ':codigo'=>$codigo,
            ':plantilla'=>$plantilla,
            ':monto'=>$montoUnit,
            ':evento'=>$eventoId
          ));
        } catch (PDOException $e) {
          $errors[] = "Error al guardar entrada: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
          break 2;
        }

        $created[] = array(
          'codigo'=>$codigo,
          'nombre'=>$nombre,
          'email'=>$email,
          'plantilla'=>$plantilla,
          'monto'=>$montoUnit
        );
      }
    }
  }
}

/* Layout */
$title = "Cargar entrada (Evento #$eventoId)";
if ($isEmbed) {
  $themeClass = 'theme-dark';
  if (!empty($_SESSION['ui_theme']) && in_array($_SESSION['ui_theme'], array('theme-light','theme-dark'), true)) {
    $themeClass = $_SESSION['ui_theme'];
  }
  ?><!DOCTYPE html>
  <html lang="es">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="stylesheet" href="assets/str.css">
    <link rel="stylesheet" href="assets/str-theme.css">
    <style>.topbar,.nav,.userchip,.hamburger{display:none!important;} body{padding-top:0!important;} .wrap{padding-top:0!important;}</style>
  </head>
  <body class="<?php echo htmlspecialchars($themeClass, ENT_QUOTES, 'UTF-8'); ?>">
  <div class="wrap">
  <?php
} else {
  if (file_exists(__DIR__.'/inc/layout_top.php')) include __DIR__.'/inc/layout_top.php';
}
?>
<div class="card">
  <h2 style="margin-top:0;">Cargar entrada manual</h2>
  <div class="muted">Evento ID: <strong><?php echo (int)$eventoId; ?></strong></div>
  <div class="muted">Rol detectado: <strong><?php echo e($role !== '' ? $role : 'desconocido'); ?></strong></div>
</div>

<?php if (!empty($errors)): ?>
  <div class="card" style="border:1px solid rgba(255,0,0,0.25);">
    <h3 style="margin-top:0;">Errores</h3>
    <ul>
      <?php foreach($errors as $er): ?><li><?php echo e($er); ?></li><?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<div class="card">
  <h3 style="margin-top:0;">Formulario</h3>

  <form method="post" action="cargar_entrada.php">
    <input type="hidden" name="evento_id" value="<?php echo (int)$eventoId; ?>"/>
    <?php if ($isEmbed): ?>
      <input type="hidden" name="embed" value="1"/>
    <?php endif; ?>

    <div style="display:flex;gap:12px;flex-wrap:wrap;">
      <div style="flex:1 1 260px;min-width:240px;">
        <label class="muted">Nombre *</label>
        <input class="input" type="text" name="nombre" required value="<?php echo e($_POST['nombre'] ?? ''); ?>"/>
      </div>

      <div style="flex:1 1 260px;min-width:240px;">
        <label class="muted">Email (opcional)</label>
        <input class="input" type="email" name="email" value="<?php echo e($_POST['email'] ?? ''); ?>"/>
      </div>
    </div>

    <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:10px;">
      <div style="flex:1 1 200px;min-width:200px;">
        <label class="muted">Plantilla de entrada</label>
        <select class="input" name="plantilla" id="plantillaSelect" required>
          <option value="">-- Seleccionar plantilla --</option>
          <?php
            $cur = (string)($_POST['plantilla'] ?? '');
            foreach($plantillas as $nombre => $info):
          ?>
            <option value="<?php echo e($nombre); ?>" <?php echo ($cur===$nombre?'selected':''); ?>>
              <?php echo e($nombre); ?> 
              <?php if($info['precio'] > 0): ?>
                ($ <?php echo number_format($info['precio'], 2); ?>)
              <?php else: ?>
                (Gratis)
              <?php endif; ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div style="flex:1 1 140px;min-width:140px;">
        <label class="muted">Cantidad</label>
        <input class="input" type="number" name="cantidad" min="1" max="<?php echo (int)($isAdmin?200:20); ?>" value="<?php echo e($_POST['cantidad'] ?? '1'); ?>"/>
      </div>

      <div style="flex:1 1 180px;min-width:180px;" id="montoContainer">
        <label class="muted">Monto pagado</label>
        <input class="input" type="number" name="monto_pagado" min="0" step="0.01" value="<?php echo e($_POST['monto_pagado'] ?? '0'); ?>" id="montoInput" disabled/>
        <div class="muted" style="margin-top:6px;font-size:11px;" id="montoNota"></div>
      </div>
    </div>

    <script>
      const plantillaData = <?php echo json_encode($plantillas); ?>;
      const plantillaSelect = document.getElementById('plantillaSelect');
      const montoInput = document.getElementById('montoInput');
      const montoNota = document.getElementById('montoNota');
      
      function updateMonto() {
        const plantilla = plantillaSelect.value;
        if (!plantilla || !plantillaData[plantilla]) {
          montoInput.value = '0';
          montoInput.disabled = true;
          montoNota.textContent = '';
          return;
        }
        
        const isMontoLibre = plantilla === 'Monto libre';
        const precio = plantillaData[plantilla].precio;
        
        if (isMontoLibre) {
          // Monto libre: admin puede escribir el precio
          montoInput.disabled = false;
          montoInput.required = true;
          montoNota.textContent = 'Ingresá el monto que desees';
          if (!montoInput.value || montoInput.value === '0') {
            montoInput.value = '';
          }
        } else {
          // Plantilla con precio fijo
          montoInput.value = precio.toFixed(2);
          montoInput.disabled = true;
          montoInput.required = false;
          if (precio > 0) {
            montoNota.textContent = 'Precio según plantilla';
          } else {
            montoNota.textContent = 'Entrada gratuita';
          }
        }
      }
      
      plantillaSelect.addEventListener('change', updateMonto);
      updateMonto();
    </script>

    <div style="margin-top:12px;display:flex;gap:10px;flex-wrap:wrap;">
      <button class="btn" type="submit">Crear entrada(s)</button>
      <a class="btn secondary" href="panel_evento.php?evento_id=<?php echo (int)$eventoId; ?>">Cancelar</a>
      <?php if ($isEmbed): ?>
        <a class="btn" href="cargar_entrada.php?evento_id=<?php echo (int)$eventoId; ?>&embed=1">Limpiar</a>
      <?php endif; ?>
    </div>
  </form>
</div>

<?php if (!empty($created)): ?>
  <div class="card" style="border:1px solid rgba(0,255,0,0.18);">
    <h3 style="margin-top:0;">Entradas creadas (<?php echo (int)count($created); ?>)</h3>
    <div style="overflow:auto;margin-top:10px;">
      <table class="table">
        <tr>
          <th>#</th><th>Código</th><th>Nombre</th><th>Email</th><th>Plantilla</th><th>Monto</th>
        </tr>
        <?php $i=1; foreach($created as $c): ?>
          <tr>
            <td><?php echo (int)$i++; ?></td>
            <td><strong><?php echo e($c['codigo']); ?></strong></td>
            <td><?php echo e($c['nombre']); ?></td>
            <td><?php echo e($c['email']); ?></td>
            <td><?php echo e($c['plantilla']); ?></td>
            <td style="text-align:right;">$<?php echo number_format((float)$c['monto'], 2); ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
    </div>

    <div style="margin-top:10px;display:flex;gap:10px;flex-wrap:wrap;">
      <a class="btn secondary" href="panel_evento.php?evento_id=<?php echo (int)$eventoId; ?>">← Volver al panel del evento</a>
      <a class="btn" href="cargar_entrada.php?evento_id=<?php echo (int)$eventoId; ?><?php echo $isEmbed ? '&embed=1' : ''; ?>">Cargar más</a>
    </div>
  </div>
<?php endif; ?>

<?php
if ($isEmbed) {
  ?></div></body></html><?php
} else {
  if (file_exists(__DIR__.'/inc/layout_bottom.php')) include __DIR__.'/inc/layout_bottom.php';
}
?>
