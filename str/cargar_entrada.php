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

$u = null;
if (isset($_SESSION['user'])) $u = $_SESSION['user'];
elseif (isset($_SESSION['usuario'])) $u = $_SESSION['usuario'];
elseif (isset($_SESSION['auth'])) $u = $_SESSION['auth'];

if (is_array($u)) {
  $role = (string)($u['rol'] ?? $u['role'] ?? $u['perfil'] ?? '');
}

$isAdmin = false;
$rl = strtolower($role);
if (strpos($rl, 'super') !== false || strpos($rl, 'admin') !== false) $isAdmin = true;

/* evento_id */
$eventoId = 0;
if (isset($_GET['evento_id'])) $eventoId = (int)$_GET['evento_id'];
if (isset($_POST['evento_id'])) $eventoId = (int)$_POST['evento_id'];
if ($eventoId <= 0) { http_response_code(400); echo "Falta evento_id"; exit; }

/* Tipos por rol (simple por ahora) */
$tipos_admin = array('Manual', 'Lista', 'Free', 'Staff', 'Cortesía', 'Prensa', 'Promo');
$tipos_staff = array('Lista', 'Free', 'Invitado');
$tipos = $isAdmin ? $tipos_admin : $tipos_staff;

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
  $tipo   = trim((string)($_POST['tipo'] ?? ''));
  $monto  = (int)($_POST['monto_pagado'] ?? 0);
  $cant   = (int)($_POST['cantidad'] ?? 1);

  if ($nombre === '') $errors[] = "Falta el nombre.";
  if ($email === '') $email = '-';
  if ($email !== '-' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Email inválido.";
  if (!in_array($tipo, $tipos, true)) $errors[] = "Tipo no permitido.";
  if ($cant < 1) $cant = 1;

  /* límites por rol */
  $max = $isAdmin ? 200 : 20;
  if ($cant > $max) $errors[] = "Cantidad máxima permitida: $max.";

  /* staff no puede setear monto */
  if (!$isAdmin) $monto = 0;
  if ($monto < 0) $monto = 0;

  if (empty($errors)) {
    $now = date('Y-m-d H:i:s');

    /* statement insert */
    $sqlIns = "INSERT INTO entradas (nombre,email,fecha_registro,codigo,checked_in,tipo,monto_pagado,evento_id)
               VALUES (:nombre,:email,:fecha,:codigo,0,:tipo,:monto,:evento)";
    $stIns = $pdo->prepare($sqlIns);

    /* check uniqueness */
    $stChk = $pdo->prepare("SELECT 1 FROM entradas WHERE codigo = :c LIMIT 1");

    for ($n=0; $n<$cant; $n++) {
      $codigo = '';
      for ($try=0; $try<30; $try++) {
        $codigo = gen_codigo($eventoId);
        $stChk->execute(array(':c'=>$codigo));
        $exists = $stChk->fetchColumn();
        if (!$exists) break;
      }
      if ($codigo === '') { $errors[] = "No pude generar código."; break; }

      $stIns->execute(array(
        ':nombre'=>$nombre,
        ':email'=>$email,
        ':fecha'=>$now,
        ':codigo'=>$codigo,
        ':tipo'=>$tipo,
        ':monto'=>$monto,
        ':evento'=>$eventoId
      ));

      $created[] = array(
        'codigo'=>$codigo,
        'nombre'=>$nombre,
        'email'=>$email,
        'tipo'=>$tipo,
        'monto'=>$monto
      );
    }
  }
}

/* Layout */
$title = "Cargar entrada (Evento #$eventoId)";
if (file_exists(__DIR__.'/inc/layout_top.php')) include __DIR__.'/inc/layout_top.php';
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

<?php if (!empty($created)): ?>
  <div class="card" style="border:1px solid rgba(0,255,0,0.18);">
    <h3 style="margin-top:0;">Entradas creadas (<?php echo (int)count($created); ?>)</h3>
    <div style="overflow:auto;margin-top:10px;">
      <table class="table">
        <tr>
          <th>#</th><th>Código</th><th>Nombre</th><th>Email</th><th>Tipo</th><th>Monto</th>
        </tr>
        <?php $i=1; foreach($created as $c): ?>
          <tr>
            <td><?php echo (int)$i++; ?></td>
            <td><strong><?php echo e($c['codigo']); ?></strong></td>
            <td><?php echo e($c['nombre']); ?></td>
            <td><?php echo e($c['email']); ?></td>
            <td><?php echo e($c['tipo']); ?></td>
            <td><?php echo e((string)$c['monto']); ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
    </div>

    <div style="margin-top:10px;display:flex;gap:10px;flex-wrap:wrap;">
      <a class="btn secondary" href="panel_evento.php?evento_id=<?php echo (int)$eventoId; ?>">← Volver al panel del evento</a>
      <a class="btn" href="cargar_entrada.php?evento_id=<?php echo (int)$eventoId; ?>">Cargar más</a>
    </div>
  </div>
<?php endif; ?>

<div class="card">
  <h3 style="margin-top:0;">Formulario</h3>

  <form method="post" action="cargar_entrada.php">
    <input type="hidden" name="evento_id" value="<?php echo (int)$eventoId; ?>"/>

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
        <label class="muted">Tipo</label>
        <select class="input" name="tipo" required>
          <?php
            $cur = (string)($_POST['tipo'] ?? ($tipos[0] ?? 'Manual'));
            foreach($tipos as $t):
          ?>
            <option value="<?php echo e($t); ?>" <?php echo ($cur===$t?'selected':''); ?>><?php echo e($t); ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div style="flex:1 1 140px;min-width:140px;">
        <label class="muted">Cantidad</label>
        <input class="input" type="number" name="cantidad" min="1" max="<?php echo (int)($isAdmin?200:20); ?>" value="<?php echo e($_POST['cantidad'] ?? '1'); ?>"/>
      </div>

      <div style="flex:1 1 180px;min-width:180px;">
        <label class="muted">Monto pagado</label>
        <input class="input" type="number" name="monto_pagado" min="0" value="<?php echo e($_POST['monto_pagado'] ?? '0'); ?>" <?php echo $isAdmin ? '' : 'disabled'; ?>/>
        <?php if(!$isAdmin): ?><div class="muted" style="margin-top:6px;">(solo admin puede setear monto)</div><?php endif; ?>
      </div>
    </div>

    <div style="margin-top:12px;display:flex;gap:10px;flex-wrap:wrap;">
      <button class="btn" type="submit">Crear entrada(s)</button>
      <a class="btn secondary" href="panel_evento.php?evento_id=<?php echo (int)$eventoId; ?>">Cancelar</a>
    </div>
  </form>
</div>

<?php
if (file_exists(__DIR__.'/inc/layout_bottom.php')) include __DIR__.'/inc/layout_bottom.php';
?>
