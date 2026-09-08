<?php
// panel_usuario_mi_perfil.php - Página de perfil y seguridad del usuario
require_once __DIR__ . '/inc/security.php';
require_once __DIR__ . '/inc/routes.php';
tickex_send_security_headers();
tickex_session_start();
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/notificaciones.php';
require_once __DIR__ . '/inc/customer_portal.php';

if (!isset($_SESSION['auth_context'], $_SESSION['usuario_id']) || $_SESSION['auth_context'] !== 'user' || (int)$_SESSION['usuario_id'] <= 0) {
  header('Location: ' . tickex_route('login', array()));
  exit;
}

try {
  $pdo = db();
} catch (Exception $e) {
  header('Content-Type: text/plain; charset=utf-8');
  echo "Error al conectar a la base de datos.";
  exit;
}

$usuarioId = (int)$_SESSION['usuario_id'];

$flashOk = '';
$flashErr = '';

// Cargar usuario una sola vez (se usa en handlers POST y en la UI)
try {
  $cols = $pdo->query("PRAGMA table_info(registro_pendientes)")->fetchAll(PDO::FETCH_ASSOC);
  $has = array();
  foreach ($cols as $c) {
    if (isset($c['name'])) {
      $has[(string)$c['name']] = true;
    }
  }

  $sel = array();
  $sel[] = "id";
  $sel[] = isset($has['nombre']) ? "nombre" : "'' AS nombre";
  $sel[] = isset($has['apellido']) ? "apellido" : "'' AS apellido";
  $sel[] = isset($has['email']) ? "email" : "'' AS email";
  $sel[] = isset($has['dni']) ? "dni" : "'' AS dni";
  $sel[] = isset($has['apodo']) ? "apodo" : "'' AS apodo";
  $sel[] = isset($has['genero']) ? "genero" : "'' AS genero";
  $sel[] = isset($has['creado_en']) ? "creado_en" : "'' AS creado_en";
  $sel[] = isset($has['completado_en']) ? "completado_en" : "'' AS completado_en";
  $sel[] = isset($has['password_hash']) ? "password_hash" : "'' AS password_hash";

  $sqlUser = "SELECT " . implode(', ', $sel) . " FROM registro_pendientes WHERE id = :id LIMIT 1";
  $stmt = $pdo->prepare($sqlUser);
  $stmt->execute(array(':id' => $usuarioId));
  $u = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$u) {
    $_SESSION = array();
    session_destroy();
    header('Location: ' . tickex_route('login', array()));
    exit;
  }
} catch (Exception $e) {
  header('Content-Type: text/plain; charset=utf-8');
  echo "Error al cargar datos del usuario.";
  exit;
}

function _tickex_resolve_admin_id($pdo, $raw, &$err)
{
  $err = '';
  $s = trim((string)$raw);
  if ($s === '') { $err = 'Ingresá un Tickex ID de admin (ej: STR o #12).'; return 0; }

  // Caso #123 o 123
  $s2 = $s;
  if ($s2 !== '' && $s2[0] === '#') $s2 = substr($s2, 1);
  if ($s2 !== '' && ctype_digit($s2)) {
    $id = (int)$s2;
    if ($id <= 0) { $err = 'Tickex ID inválido.'; return 0; }
    try {
      $st = $pdo->prepare('SELECT id FROM usuarios_admin WHERE id = :id LIMIT 1');
      $st->execute(array(':id' => $id));
      if ($st->fetchColumn()) return $id;
    } catch (Exception $e) {}
    $err = 'No encontramos un admin con ese Tickex ID.';
    return 0;
  }

  // Caso STR (apodo)
  if (strlen($s) > 64 || !preg_match('/^[a-zA-Z0-9_-]+$/', $s)) {
    $err = 'El Tickex ID solo puede tener letras, números, _ y - (sin espacios).';
    return 0;
  }
  try {
    $st = $pdo->prepare('SELECT id FROM usuarios_admin WHERE lower(apodo) = lower(:ap) LIMIT 1');
    $st->execute(array(':ap' => $s));
    $id = (int)$st->fetchColumn();
    if ($id > 0) return $id;
  } catch (Exception $e) {}
  $err = 'No encontramos un admin con ese Tickex ID.';
  return 0;
}

function _tickex_cliente_display($pdo, $clienteId)
{
  $cid = (int)$clienteId;
  if ($cid <= 0) return '#0';
  try {
    $st = $pdo->prepare('SELECT id, apodo, email FROM registro_pendientes WHERE id = :id LIMIT 1');
    $st->execute(array(':id' => $cid));
    $c = $st->fetch(PDO::FETCH_ASSOC);
    if ($c) {
      $tid = (isset($c['apodo']) && trim((string)$c['apodo']) !== '') ? (string)$c['apodo'] : ('#' . (int)$c['id']);
      $em = (string)($c['email'] ?? '');
      if ($em !== '') return $tid . ' (' . $em . ')';
      return $tid;
    }
  } catch (Exception $e) {}
  return '#' . $cid;
}

function _tickex_admin_display($pdo, $adminId)
{
  $aid = (int)$adminId;
  if ($aid <= 0) return '#0';
  try {
    $st = $pdo->prepare('SELECT id, apodo, username, email FROM usuarios_admin WHERE id = :id LIMIT 1');
    $st->execute(array(':id' => $aid));
    $a = $st->fetch(PDO::FETCH_ASSOC);
    if ($a) {
      $tid = (isset($a['apodo']) && trim((string)$a['apodo']) !== '') ? (string)$a['apodo'] : ('#' . (int)$a['id']);
      $em = (string)($a['email'] ?? '');
      if ($em !== '') return $tid . ' (' . $em . ')';
      $un = (string)($a['username'] ?? '');
      if ($un !== '') return $tid . ' (' . $un . ')';
      return $tid;
    }
  } catch (Exception $e) {}
  return '#' . $aid;
}

// --- Datos personales y seguridad de la cuenta ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_profile') {
  $provided = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
  $nombre = isset($_POST['nombre']) ? trim((string)$_POST['nombre']) : '';
  $apellido = isset($_POST['apellido']) ? trim((string)$_POST['apellido']) : '';
  $apodo = isset($_POST['apodo']) ? trim((string)$_POST['apodo']) : '';
  $dni = isset($_POST['dni']) ? preg_replace('/\D+/', '', (string)$_POST['dni']) : '';
  $genero = isset($_POST['genero']) ? trim((string)$_POST['genero']) : '';
  if (!tickex_csrf_verify($provided)) $flashErr = 'La sesión venció. Actualizá la página.';
  elseif ($nombre === '' || $apellido === '') $flashErr = 'Nombre y apellido son obligatorios.';
  elseif (strlen($nombre) > 80 || strlen($apellido) > 80) $flashErr = 'Nombre o apellido demasiado largo.';
  elseif ($apodo !== '' && !preg_match('/^[a-zA-Z0-9_-]{3,32}$/', $apodo)) $flashErr = 'El Tickex ID debe tener entre 3 y 32 letras, números, guiones o guiones bajos.';
  elseif ($dni !== '' && (strlen($dni) < 7 || strlen($dni) > 9)) $flashErr = 'Revisá el DNI ingresado.';
  else {
    try {
      if ($apodo !== '') {
        $dup = $pdo->prepare('SELECT 1 FROM registro_pendientes WHERE lower(apodo)=lower(:apodo) AND id<>:id LIMIT 1');
        $dup->execute(array(':apodo' => $apodo, ':id' => $usuarioId));
        if ($dup->fetchColumn()) $flashErr = 'Ese Tickex ID ya está siendo utilizado.';
      }
      if ($flashErr === '') {
        $update = $pdo->prepare('UPDATE registro_pendientes SET nombre=:nombre,apellido=:apellido,apodo=:apodo,dni=:dni,genero=:genero WHERE id=:id');
        $update->execute(array(':nombre'=>$nombre, ':apellido'=>$apellido, ':apodo'=>$apodo, ':dni'=>$dni, ':genero'=>$genero, ':id'=>$usuarioId));
        $u['nombre']=$nombre; $u['apellido']=$apellido; $u['apodo']=$apodo; $u['dni']=$dni; $u['genero']=$genero;
        $_SESSION['usuario_nombre']=trim($nombre.' '.$apellido); $_SESSION['nombre']=$_SESSION['usuario_nombre'];
        $_SESSION['first_name']=$nombre; $_SESSION['last_name']=$apellido; $_SESSION['dni']=$dni;
        $flashOk = 'Datos personales actualizados.';
      }
    } catch (Exception $e) { $flashErr = 'No pudimos actualizar tus datos.'; }
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_password') {
  $provided = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
  if (!tickex_csrf_verify($provided)) {
    $flashErr = 'La sesión venció. Actualizá la página.';
  } else {
    list($passwordOk, $passwordMessage) = tickex_customer_portal_change_password(
      $pdo,
      $usuarioId,
      isset($_POST['pass_actual']) ? (string)$_POST['pass_actual'] : '',
      isset($_POST['pass_nueva']) ? (string)$_POST['pass_nueva'] : '',
      isset($_POST['pass_repite']) ? (string)$_POST['pass_repite'] : ''
    );
    if ($passwordOk) { $flashOk = $passwordMessage; $u = tickex_customer_portal_user($pdo, $usuarioId); }
    else $flashErr = $passwordMessage;
  }
}

// --- Invitación de staff (admin -> cliente) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'staff_invite_decision') {
  $provided = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
  if (!tickex_csrf_verify($provided)) {
    $flashErr = 'CSRF inválido. Actualizá la página e intentá de nuevo.';
  } else {
    $invId = isset($_POST['inv_id']) ? (int)$_POST['inv_id'] : 0;
    $decision = isset($_POST['decision']) ? (string)$_POST['decision'] : '';
    if ($invId <= 0 || ($decision !== 'accept' && $decision !== 'reject')) {
      $flashErr = 'Acción inválida.';
    } else {
      try {
        $stI = $pdo->prepare("SELECT * FROM staff_admin_invitaciones WHERE id = :id AND estado = 'pending' LIMIT 1");
        $stI->execute(array(':id' => $invId));
        $inv = $stI->fetch(PDO::FETCH_ASSOC);
        if (!$inv) {
          $flashErr = 'Invitación inexistente.';
        } else {
          $invEmail = strtolower(trim((string)($inv['email'] ?? '')));
          $userEmail = strtolower(trim((string)($u['email'] ?? '')));
          $invClienteId = isset($inv['cliente_id']) ? (int)$inv['cliente_id'] : 0;

          if ($invClienteId > 0 && $invClienteId !== $usuarioId) {
            $flashErr = 'No autorizado.';
          } elseif ($invClienteId <= 0 && $invEmail !== '' && $userEmail !== '' && $invEmail !== $userEmail) {
            $flashErr = 'No autorizado.';
          } else {
            if ($decision === 'reject') {
              $stU = $pdo->prepare("UPDATE staff_admin_invitaciones SET estado='rejected', cliente_id=:cid, updated_at=datetime('now') WHERE id=:id");
              $stU->execute(array(':cid' => $usuarioId, ':id' => $invId));
              $flashOk = 'Invitación de staff rechazada.';
            } else {
              $ownerAdminId = isset($inv['owner_admin_id']) ? (int)$inv['owner_admin_id'] : 0;
              $rolStaff = isset($inv['rol_staff']) ? (string)$inv['rol_staff'] : '';

              if ($ownerAdminId <= 0) {
                $flashErr = 'Invitación inválida.';
              } else {
                // Crear/activar relación staff (sin perder rol cliente)
                try {
                  $stIns = $pdo->prepare('INSERT OR IGNORE INTO staff_admins (owner_admin_id, cliente_id, rol_staff, activo) VALUES (:oid,:cid,:r,1)');
                  $stIns->execute(array(
                    ':oid' => $ownerAdminId,
                    ':cid' => $usuarioId,
                    ':r' => ($rolStaff !== '' ? $rolStaff : null),
                  ));
                } catch (Exception $e) {
                  // ignore
                }

                try {
                  $stRe = $pdo->prepare('UPDATE staff_admins SET activo = 1, rol_staff = COALESCE(NULLIF(:r,\'\'), rol_staff) WHERE owner_admin_id = :oid AND cliente_id = :cid');
                  $stRe->execute(array(
                    ':r' => $rolStaff,
                    ':oid' => $ownerAdminId,
                    ':cid' => $usuarioId,
                  ));
                } catch (Exception $e) {
                  // ignore
                }

                $stU = $pdo->prepare("UPDATE staff_admin_invitaciones SET estado='accepted', cliente_id=:cid, updated_at=datetime('now') WHERE id=:id");
                $stU->execute(array(':cid' => $usuarioId, ':id' => $invId));

                if ($ownerAdminId > 0) {
                  $nombreNotif = trim((string)(isset($u['nombre']) ? $u['nombre'] : ''));
                  if ($nombreNotif === '') {
                    $nombreNotif = trim((string)(isset($u['email']) ? $u['email'] : ('Usuario #' . $usuarioId)));
                  }
                  add_notification(
                    $ownerAdminId,
                    $nombreNotif . ' aceptó tu invitación para sumarse al staff.',
                    'staff_accept',
                    array('cliente_id' => $usuarioId, 'inv_id' => $invId)
                  );
                }

                $flashOk = 'Invitación de staff aceptada. Ya sos staff.';
              }
            }
          }
        }
      } catch (Exception $e) {
        $flashErr = 'No se pudo procesar la invitación.';
      }
    }
  }
}

// --- Solicitud de revendedor (cliente -> admin por Tickex ID) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'rev_solicitar') {
  $provided = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
  if (!tickex_csrf_verify($provided)) {
    $flashErr = 'CSRF inválido. Actualizá la página e intentá de nuevo.';
  } else {
    $adminTickexIdRaw = isset($_POST['admin_tickex_id']) ? (string)$_POST['admin_tickex_id'] : '';
    $tmpErr = '';
    $adminId = _tickex_resolve_admin_id($pdo, $adminTickexIdRaw, $tmpErr);
    $mensaje = isset($_POST['mensaje']) ? trim((string)$_POST['mensaje']) : '';
    if ($adminId <= 0) {
      $flashErr = ($tmpErr !== '' ? $tmpErr : 'Ingresá un Tickex ID de admin válido (ej: STR o #12).');
    } elseif (strlen($mensaje) > 500) {
      $flashErr = 'El mensaje es demasiado largo (máx 500).';
    } else {
      try {
        $stA = $pdo->prepare('SELECT id FROM usuarios_admin WHERE id = :id LIMIT 1');
        $stA->execute(array(':id' => $adminId));
        if (!$stA->fetchColumn()) {
          $flashErr = 'No encontramos un admin con ese Tickex ID.';
        }
      } catch (Exception $e) {
        $flashErr = 'No se pudo validar el admin. Intentá de nuevo.';
      }

      if ($flashErr === '') {
        try {
          // Evitar duplicados pendientes para el mismo admin (sin evento)
          $stDup = $pdo->prepare("SELECT 1 FROM revendedor_solicitudes WHERE cliente_id = :cid AND owner_admin_id = :oid AND estado = 'pending' AND (evento_id IS NULL OR evento_id = 0) LIMIT 1");
          $stDup->execute(array(':cid' => $usuarioId, ':oid' => $adminId));
          if ($stDup->fetchColumn()) {
            $flashErr = 'Ya tenés una solicitud pendiente para ese admin.';
          } else {
            $stIns = $pdo->prepare("INSERT INTO revendedor_solicitudes (cliente_id, cliente_email, evento_id, owner_admin_id, mensaje, estado, direction) VALUES (:cid,:ce,NULL,:oid,:m,'pending','client_to_admin')");
            $stIns->execute(array(
              ':cid' => $usuarioId,
              ':ce'  => isset($_SESSION['usuario_email']) ? (string)$_SESSION['usuario_email'] : null,
              ':oid' => $adminId,
              ':m'   => ($mensaje !== '' ? $mensaje : null),
            ));
            $flashOk = 'Solicitud enviada. El admin la podrá aprobar o rechazar.';
          }
        } catch (Exception $e) {
          $flashErr = 'No se pudo enviar la solicitud.';
        }
      }
    }
  }
}

// --- Invitación de revendedor (admin -> cliente) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'rev_invite_decision') {
  $provided = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
  if (!tickex_csrf_verify($provided)) {
    $flashErr = 'CSRF inválido. Actualizá la página e intentá de nuevo.';
  } else {
    $reqId = isset($_POST['req_id']) ? (int)$_POST['req_id'] : 0;
    $decision = isset($_POST['decision']) ? (string)$_POST['decision'] : '';
    if ($reqId <= 0 || ($decision !== 'accept' && $decision !== 'reject')) {
      $flashErr = 'Acción inválida.';
    } else {
      try {
        $stR = $pdo->prepare("SELECT * FROM revendedor_solicitudes WHERE id = :id AND cliente_id = :cid AND estado = 'pending' AND direction = 'admin_to_client' LIMIT 1");
        $stR->execute(array(':id' => $reqId, ':cid' => $usuarioId));
        $req = $stR->fetch(PDO::FETCH_ASSOC);
        if (!$req) {
          $flashErr = 'Invitación inexistente.';
        } else {
          if ($decision === 'reject') {
            $stU = $pdo->prepare("UPDATE revendedor_solicitudes SET estado='rejected', updated_at=datetime('now') WHERE id=:id");
            $stU->execute(array(':id' => $reqId));
            $flashOk = 'Invitación rechazada.';
          } else {
            // aceptar: crear revendedor y aprobar
            $ownerAdminId = isset($req['owner_admin_id']) ? (int)$req['owner_admin_id'] : 0;
            $nombre = trim((string)($u['apodo'] ?? ''));
            if ($nombre === '') {
              $nombre = trim((string)($u['nombre'] ?? '') . ' ' . (string)($u['apellido'] ?? ''));
            }
            if ($nombre === '') $nombre = (string)($u['email'] ?? 'Revendedor');

            $stIns = $pdo->prepare('INSERT INTO revendedores (owner_admin_id, cliente_id, nombre, comision_percent, activo) VALUES (:oid,:cid,:n,0,1)');
            $stIns->execute(array(
              ':oid' => ($ownerAdminId > 0 ? $ownerAdminId : null),
              ':cid' => $usuarioId,
              ':n' => $nombre,
            ));
            $newRid = (int)$pdo->lastInsertId();

            $stUp = $pdo->prepare("UPDATE revendedor_solicitudes SET estado='approved', revendedor_id=:rid, updated_at=datetime('now') WHERE id=:id");
            $stUp->execute(array(':rid' => $newRid, ':id' => $reqId));
            $flashOk = 'Invitación aceptada. Ya sos revendedor.';
          }
        }
      } catch (Exception $e) {
        $flashErr = 'No se pudo procesar la invitación.';
      }
    }
  }
}

$title = 'Mi perfil – Tickex';
include __DIR__ . '/inc/layout_top.php';
?>
<style>
.customer-profile{max-width:900px;margin:0 auto;display:grid;gap:16px}.customer-profile>.card{max-width:none!important;margin:0!important}.customer-profile-hero{padding:28px;background:radial-gradient(circle at 90% 0,rgba(54,216,245,.13),transparent 34%),linear-gradient(135deg,rgba(25,29,57,.98),rgba(54,31,105,.93));border-color:rgba(139,92,246,.3)}.customer-profile-logo{display:block;width:142px;height:40px;object-fit:contain;object-position:left center;margin-bottom:14px}.customer-profile h3{font-family:Manrope,Inter,sans-serif}.customer-profile form label{display:grid;gap:6px;font-weight:750}.customer-profile .table{border-collapse:separate;border-spacing:0 6px}@media(max-width:620px){.customer-profile{gap:12px}.customer-profile-hero{padding:22px}}
.customer-profile-logo{width:52px;height:52px;object-position:center;margin-bottom:14px}
</style>
<main class="customer-profile">
<div class="card customer-profile-hero">
  <img class="customer-profile-logo" src="/tickex-isotipo.svg" alt="Tickex">
  <div style="display:flex;justify-content:space-between;gap:12px;align-items:start;flex-wrap:wrap;"><div><div class="muted" style="font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.1em;">Cuenta de comprador</div><h2 style="margin:5px 0;">Mi perfil</h2><p class="muted" style="margin:0;">Actualizá tus datos y administrá la seguridad de tu cuenta.</p></div><a class="btn secondary" href="<?php echo htmlspecialchars(tickex_route('customer_home', array()), ENT_QUOTES, 'UTF-8'); ?>">Mis entradas</a></div>
</div>

<?php if ($flashErr !== '' || $flashOk !== ''): ?>
  <div class="card" style="max-width:760px;margin:0 auto 16px auto;">
    <?php if ($flashErr !== ''): ?>
      <div class="flash err" style="margin:0;"><?php echo htmlspecialchars($flashErr, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>
    <?php if ($flashOk !== ''): ?>
      <div class="flash ok" style="margin:0;"><?php echo htmlspecialchars($flashOk, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php
// Invitaciones staff pendientes para este usuario (por cliente_id o email)
$staffInvToken = isset($_GET['staff_invite']) ? trim((string)$_GET['staff_invite']) : '';
$staffInvites = array();
try {
  $sql = "SELECT id, owner_admin_id, email, mensaje, rol_staff, estado, created_at
          FROM staff_admin_invitaciones
          WHERE estado = 'pending' AND (cliente_id = :cid OR lower(email) = lower(:e))";
  $params = array(':cid' => $usuarioId, ':e' => (string)($u['email'] ?? ''));
  if ($staffInvToken !== '') {
    $sql .= " AND token = :t";
    $params[':t'] = $staffInvToken;
  }
  $sql .= " ORDER BY id DESC LIMIT 50";

  $stSI = $pdo->prepare($sql);
  $stSI->execute($params);
  $staffInvites = $stSI->fetchAll(PDO::FETCH_ASSOC);

  if (!empty($staffInvites) && function_exists('add_notification_once_by_key')) {
    foreach ($staffInvites as $ivn) {
      $invIdN = isset($ivn['id']) ? (int)$ivn['id'] : 0;
      if ($invIdN <= 0) continue;
      $adminDisp = _tickex_admin_display($pdo, (int)$ivn['owner_admin_id']);
      add_notification_once_by_key(
        $usuarioId,
        'staff_invite_' . $invIdN,
        'Te invitaron a sumarte al staff de ' . $adminDisp . '. Entrá a Mi Perfil para aceptar o rechazar.',
        'staff_invite',
        array('inv_id' => $invIdN, 'admin_id' => (int)$ivn['owner_admin_id']),
        $pdo
      );
    }
  }
} catch (Exception $e) {
  $staffInvites = array();
}
?>

<div class="card" style="max-width:760px;margin:0 auto 16px auto;">
  <h3 style="margin-top:0;">Datos personales</h3>
  <form method="post" style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;">
    <input type="hidden" name="action" value="update_profile"><input type="hidden" name="csrf" value="<?php echo htmlspecialchars(tickex_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
    <label>Nombre<input name="nombre" required maxlength="80" value="<?php echo htmlspecialchars($u['nombre'], ENT_QUOTES, 'UTF-8'); ?>"></label>
    <label>Apellido<input name="apellido" required maxlength="80" value="<?php echo htmlspecialchars($u['apellido'], ENT_QUOTES, 'UTF-8'); ?>"></label>
    <label>Tickex ID<input name="apodo" minlength="3" maxlength="32" value="<?php echo htmlspecialchars($u['apodo'], ENT_QUOTES, 'UTF-8'); ?>"><small class="muted">Letras, números, guiones y guiones bajos.</small></label>
    <label>DNI<input name="dni" inputmode="numeric" maxlength="9" value="<?php echo htmlspecialchars($u['dni'], ENT_QUOTES, 'UTF-8'); ?>"></label>
    <label>Género<input name="genero" maxlength="40" value="<?php echo htmlspecialchars($u['genero'], ENT_QUOTES, 'UTF-8'); ?>"></label>
    <label>Email<input value="<?php echo htmlspecialchars($u['email'], ENT_QUOTES, 'UTF-8'); ?>" disabled><small class="muted">El email identifica tus entradas. Su cambio requiere soporte.</small></label>
    <div style="grid-column:1/-1;"><button class="btn" type="submit">Guardar datos</button></div>
  </form>
</div>

<?php if (!empty($staffInvites)): ?>
  <div class="card" id="invitaciones" style="max-width:760px;margin:0 auto 16px auto;">
    <h3>Invitaciones de staff</h3>
    <div class="muted" style="font-size:13px;margin:6px 0 10px 0;">Un admin te invitó a ser staff. Podés aceptar o rechazar.</div>
    <div style="overflow:auto;">
      <table class="table" style="width:100%;min-width:520px;">
        <thead>
          <tr>
            <th style="width:200px;">Admin</th>
            <th>Mensaje</th>
            <th style="width:190px;">Acciones</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($staffInvites as $iv): ?>
            <tr>
              <td><?php echo htmlspecialchars(_tickex_admin_display($pdo, (int)$iv['owner_admin_id']), ENT_QUOTES, 'UTF-8'); ?></td>
              <td><?php echo htmlspecialchars((string)($iv['mensaje'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
              <td>
                <form method="post" style="display:inline;">
                  <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(tickex_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                  <input type="hidden" name="action" value="staff_invite_decision">
                  <input type="hidden" name="inv_id" value="<?php echo (int)$iv['id']; ?>">
                  <input type="hidden" name="decision" value="accept">
                  <button type="submit" class="btn">Aceptar</button>
                </form>
                <form method="post" style="display:inline;">
                  <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(tickex_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                  <input type="hidden" name="action" value="staff_invite_decision">
                  <input type="hidden" name="inv_id" value="<?php echo (int)$iv['id']; ?>">
                  <input type="hidden" name="decision" value="reject">
                  <button type="submit" class="btn danger">Rechazar</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<div class="card" style="max-width:760px;margin:0 auto 16px auto;">
  <h3>Seguridad</h3>
  <form method="post" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;">
    <input type="hidden" name="action" value="change_password">
    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(tickex_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
    <div>
      <label for="pass_actual">Contraseña actual</label>
      <input type="password" id="pass_actual" name="pass_actual" autocomplete="current-password" required>
    </div>
    <div>
      <label for="pass_nueva">Nueva contraseña (mínimo 10)</label>
      <input type="password" id="pass_nueva" name="pass_nueva" autocomplete="new-password" required>
    </div>
    <div>
      <label for="pass_repite">Repetir nueva</label>
      <input type="password" id="pass_repite" name="pass_repite" autocomplete="new-password" required>
    </div>
    <div style="grid-column:1 / -1;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
      <button type="submit" class="btn">Guardar contraseña</button>
      <span style="font-size:12px;color:var(--muted);">Se guarda cifrada; no podemos leerla.</span>
    </div>
  </form>
</div>

<div class="card" style="max-width:760px;margin:0 auto 16px auto;">
  <h3>Revendedores</h3>
  <div style="font-size:13px;color:var(--muted);margin:6px 0 10px 0;">
    Para solicitar ser revendedor, ingresá el <strong>Tickex ID</strong> del administrador (ej: <strong>STR</strong> o <strong>#12</strong>). El admin recibirá tu solicitud y podrá aprobarla o rechazarla.
  </div>

  <form method="post" style="display:grid;grid-template-columns:1fr;gap:10px;">
    <input type="hidden" name="action" value="rev_solicitar">
    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(tickex_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">

    <div>
      <label for="admin_tickex_id">Tickex ID del admin</label>
      <input id="admin_tickex_id" name="admin_tickex_id" placeholder="STR o #12" required>
    </div>

    <div>
      <label for="mensaje">Mensaje (opcional)</label>
      <textarea id="mensaje" name="mensaje" rows="3" maxlength="500" placeholder="Contanos por qué querés ser revendedor."></textarea>
    </div>

    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
      <button type="submit" class="btn">Enviar solicitud</button>
      <span style="font-size:12px;color:var(--muted);">Un admin la podrá aprobar o rechazar.</span>
    </div>
  </form>
</div>

<?php
// Invitaciones pendientes (admin -> cliente)
$invites = array();
try {
  $stI = $pdo->prepare("SELECT id, owner_admin_id, mensaje, created_at FROM revendedor_solicitudes WHERE cliente_id = :cid AND estado = 'pending' AND direction = 'admin_to_client' ORDER BY id DESC LIMIT 50");
  $stI->execute(array(':cid' => $usuarioId));
  $invites = $stI->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
  $invites = array();
}
?>

<?php if (!empty($invites)): ?>
  <div class="card" style="max-width:600px;margin:0 auto 16px auto;">
    <h3>Invitaciones para ser revendedor</h3>
    <div class="muted" style="font-size:13px;margin:6px 0 10px 0;">Un admin te invitó a ser revendedor. Podés aceptar o rechazar.</div>
    <div style="overflow:auto;">
      <table class="table" style="width:100%;min-width:520px;">
        <thead>
          <tr>
            <th style="width:200px;">Admin</th>
            <th>Mensaje</th>
            <th style="width:190px;">Acciones</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($invites as $iv): ?>
            <tr>
              <td><?php echo htmlspecialchars(_tickex_admin_display($pdo, (int)$iv['owner_admin_id']), ENT_QUOTES, 'UTF-8'); ?></td>
              <td><?php echo htmlspecialchars((string)($iv['mensaje'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
              <td>
                <form method="post" style="display:inline;">
                  <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(tickex_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                  <input type="hidden" name="action" value="rev_invite_decision">
                  <input type="hidden" name="req_id" value="<?php echo (int)$iv['id']; ?>">
                  <input type="hidden" name="decision" value="accept">
                  <button type="submit" class="btn">Aceptar</button>
                </form>
                <form method="post" style="display:inline;">
                  <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(tickex_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                  <input type="hidden" name="action" value="rev_invite_decision">
                  <input type="hidden" name="req_id" value="<?php echo (int)$iv['id']; ?>">
                  <input type="hidden" name="decision" value="reject">
                  <button type="submit" class="btn danger">Rechazar</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

</main>
<?php include __DIR__ . '/inc/layout_bottom.php'; ?>
