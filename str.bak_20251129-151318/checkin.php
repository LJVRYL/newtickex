<?php
require_once __DIR__.'/inc/bootstrap.php';
$title = "Check-in – TICKEX";

date_default_timezone_set('America/Argentina/Buenos_Aires');

$pdo = db();

$codigo = isset($_GET['c']) ? trim($_GET['c']) : '';
$eventoIdGet = isset($_GET['evento_id']) ? (int)$_GET['evento_id'] : 0;

// Si está logueado, saco rol
$isLogged = !empty($_SESSION['usuario']);
$tipoGlobal = $isLogged ? (isset($_SESSION['tipo_global']) ? $_SESSION['tipo_global'] : '') : '';
$rolEvento  = $isLogged ? (isset($_SESSION['rol_evento']) ? $_SESSION['rol_evento'] : '') : '';
$eventoIdSes = $isLogged ? (isset($_SESSION['evento_id']) ? (int)$_SESSION['evento_id'] : 0) : 0;

// Evento efectivo para validar
$eventoId = $eventoIdGet > 0 ? $eventoIdGet : $eventoIdSes;

$entrada = null;
$eventoNombre = '';
$eventoSlug = '';

if ($codigo !== '') {
    $stmt = $pdo->prepare("
        SELECT id, nombre, email, codigo, tipo, evento_id,
               checked_in, checked_in_at
        FROM entradas
        WHERE codigo = :codigo
        LIMIT 1
    ");
    $stmt->execute(array(':codigo' => $codigo));
    $entrada = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Si existe entrada, traemos info del evento
if ($entrada) {
    $eidEntrada = isset($entrada['evento_id']) ? (int)$entrada['evento_id'] : 0;

    if ($eidEntrada > 0) {
        $stmtEv = $pdo->prepare("SELECT nombre, slug FROM eventos WHERE id=? LIMIT 1");
        $stmtEv->execute(array($eidEntrada));
        $ev = $stmtEv->fetch(PDO::FETCH_ASSOC);
        if ($ev) {
            $eventoNombre = $ev['nombre'];
            $eventoSlug = $ev['slug'];
        }
    }
}

// ¿Puede checkinear?
$puedeCheckin = false;
if ($isLogged) {
    if ($tipoGlobal === 'staff_evento' && $rolEvento === 'puerta') $puedeCheckin = true;
    if ($tipoGlobal === 'admin_evento' || $tipoGlobal === 'super_admin' || $tipoGlobal === 'superadmin') $puedeCheckin = true;
}

// Validación de evento
$eventoOk = false;
if ($entrada) {
    $eidEntrada = (int)$entrada['evento_id'];
    if ($eventoId > 0 && $eidEntrada === $eventoId) $eventoOk = true;
}

// Ejecutar check-in SOLO si corresponde
$hizoCheckinAhora = false;
$mensaje = '';

if ($entrada && $puedeCheckin && $eventoOk) {
    if ((int)$entrada['checked_in'] === 0) {
        $ahora = date('Y-m-d H:i:s');
        $upd = $pdo->prepare("UPDATE entradas SET checked_in=1, checked_in_at=:f WHERE id=:id");
        $upd->execute(array(':f'=>$ahora, ':id'=>(int)$entrada['id']));
        $entrada['checked_in'] = 1;
        $entrada['checked_in_at'] = $ahora;
        $hizoCheckinAhora = true;
        $mensaje = "Check-in realizado correctamente.";
    } else {
        $mensaje = "Esta entrada ya estaba checkeada.";
    }
} elseif ($entrada && $puedeCheckin && !$eventoOk) {
    $mensaje = "Este ticket no pertenece a tu evento.";
} elseif ($entrada && !$puedeCheckin) {
    $mensaje = "Entrada válida. Para hacer check-in, iniciá sesión en Puerta.";
}

$baseUrl    = 'https://str.tickex.com.ar';
$checkinUrl = $baseUrl . '/checkin.php?c=' . urlencode($codigo);
$qrUrl      = 'https://api.qrserver.com/v1/create-qr-code/?size=260x260&data=' . urlencode($checkinUrl);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <title><?php echo e($title); ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link rel="stylesheet" href="assets/str.css">
  <style>
    body{display:flex;align-items:center;justify-content:center;min-height:100vh;}
    .card{max-width:520px;width:100%;}
    .status{
      display:inline-block;padding:4px 10px;border-radius:999px;
      font-size:12px;letter-spacing:.06em;text-transform:uppercase;font-weight:800;
      margin:6px 0 10px;
    }
    .status.ok{background:#123b20;color:#a6f3b7;border:1px solid var(--ok);}
    .status.err{background:#3b1616;color:#ffb3b3;border:1px solid var(--err);}
    .qr img{max-width:260px;border-radius:10px;background:#fff;padding:6px;}
    .muted{color:var(--muted);font-size:13px;}
  </style>
</head>
<body>
  <div class="wrap">
    <div class="card">
      <h2>Check-in – Entrada</h2>

      <?php if(!$entrada): ?>
        <div class="status err">Código no válido</div>
        <p>No encontramos una entrada para este código.</p>

      <?php else: ?>
        <?php if($eventoNombre || $eventoSlug): ?>
          <div class="muted">
            Evento: <strong><?php echo e($eventoNombre); ?></strong>
            <?php if($eventoSlug): ?> (<?php echo e($eventoSlug); ?>)<?php endif; ?>
          </div>
        <?php endif; ?>

        <?php if((int)$entrada['checked_in']===1): ?>
          <div class="status ok">Check-in OK</div>
        <?php else: ?>
          <div class="status err">Pendiente</div>
        <?php endif; ?>

        <?php if($mensaje): ?>
          <div class="flash <?php echo ($eventoOk && $puedeCheckin) ? 'ok' : 'warn'; ?>">
            <?php echo e($mensaje); ?>
          </div>
        <?php endif; ?>

        <p>
          #<?php echo (int)$entrada['id']; ?> —
          <strong><?php echo e($entrada['nombre']); ?></strong>
          <div class="muted"><?php echo e($entrada['tipo']); ?></div>
        </p>

        <?php if(!empty($entrada['email'])): ?>
          <div class="muted"><?php echo e($entrada['email']); ?></div>
        <?php endif; ?>

        <div class="qr" style="margin-top:14px;">
          <img src="<?php echo e($qrUrl); ?>" alt="QR de entrada">
        </div>

        <div class="muted" style="margin-top:8px;word-break:break-all;">
          <?php echo e($checkinUrl); ?>
        </div>

        <?php if(!empty($entrada['checked_in_at'])): ?>
          <div class="muted" style="margin-top:8px;">
            Checkeada el: <?php echo e($entrada['checked_in_at']); ?>
          </div>
        <?php endif; ?>

        <?php if($isLogged && $eventoId>0): ?>
          <div style="margin-top:14px;">
            <a class="btn secondary" href="puerta.php?evento_id=<?php echo (int)$eventoId; ?>">Volver a Puerta</a>
          </div>
        <?php endif; ?>
      <?php endif; ?>

    </div>
  </div>
</body>
</html>
