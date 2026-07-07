<?php
require_once __DIR__ . '/inc/bootstrap.php';

require_login();
$cu = current_user();
$rol = isset($cu['tipo_global']) ? (string)$cu['tipo_global'] : (isset($cu['rol']) ? (string)$cu['rol'] : '');
if (!in_array($rol, array('super_admin', 'superadmin'), true)) {
    http_response_code(403);
    include __DIR__ . '/inc/layout_top.php';
    echo "<div class='card'><h3>Acceso restringido</h3><p>Solo superadmin.</p></div>";
    include __DIR__ . '/inc/layout_bottom.php';
    exit;
}

$pdo = db();
$csrf = function_exists('tickex_csrf_token') ? (string)tickex_csrf_token() : '';
$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$fRegistered = isset($_GET['f_registered']) ? trim((string)$_GET['f_registered']) : '';
$fBlocked = isset($_GET['f_blocked']) ? trim((string)$_GET['f_blocked']) : '';
$fSource = isset($_GET['f_source']) ? trim((string)$_GET['f_source']) : '';
$export = isset($_GET['export']) ? trim((string)$_GET['export']) : '';
$flashOk = '';
$flashErr = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $provided = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
  if (function_exists('tickex_csrf_verify') && !tickex_csrf_verify($provided)) {
    $flashErr = 'CSRF invalido. Recarga la pagina e intenta nuevamente.';
  } else {
    $action = isset($_POST['action']) ? (string)$_POST['action'] : '';
    $emailAction = isset($_POST['email']) ? trim((string)$_POST['email']) : '';
    $adminId = isset($_SESSION['admin_id']) ? (int)$_SESSION['admin_id'] : (isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null);

    if ($emailAction === '') {
      $flashErr = 'No se encontro el contacto a actualizar.';
    } else {
      try {
        if ($action === 'bloquear') {
          $reason = isset($_POST['reason']) ? trim((string)$_POST['reason']) : '';
          $stOff = $pdo->prepare('UPDATE user_blocks SET active = 0, unblocked_at = datetime(\'now\'), unblocked_by_admin_id = :aid WHERE active = 1 AND lower(email) = lower(:e)');
          $stOff->execute(array(':aid' => $adminId, ':e' => $emailAction));

          $stOn = $pdo->prepare('INSERT INTO user_blocks (email, reason, active, blocked_by_admin_id) VALUES (:e, :r, 1, :aid)');
          $stOn->execute(array(':e' => $emailAction, ':r' => $reason, ':aid' => $adminId));
          $flashOk = 'Contacto bloqueado: ' . e($emailAction);
        } elseif ($action === 'desbloquear') {
          $stOff = $pdo->prepare('UPDATE user_blocks SET active = 0, unblocked_at = datetime(\'now\'), unblocked_by_admin_id = :aid WHERE active = 1 AND lower(email) = lower(:e)');
          $stOff->execute(array(':aid' => $adminId, ':e' => $emailAction));
          $flashOk = 'Contacto desbloqueado: ' . e($emailAction);
        }
      } catch (Exception $e) {
        $flashErr = 'No se pudo actualizar el contacto: ' . $e->getMessage();
      }
    }
  }
}

$emails = array();

function add_email_row(&$emails, $email, $source, $data)
{
    $email = trim((string)$email);
    if ($email === '') return;
    $key = strtolower($email);
    if (!isset($emails[$key])) {
        $emails[$key] = array(
            'email' => $email,
            'nombre' => '',
            'rol' => '',
            'registrado' => 'No',
            'fuentes' => array(),
            'ultimo_envio' => '',
            'ultima_entrada' => '',
            'bloqueado' => 0,
        );
    }

    $emails[$key]['fuentes'][$source] = true;

    if (!empty($data['nombre']) && $emails[$key]['nombre'] === '') {
        $emails[$key]['nombre'] = (string)$data['nombre'];
    }
    if (!empty($data['rol']) && $emails[$key]['rol'] === '') {
        $emails[$key]['rol'] = (string)$data['rol'];
    }
    if (!empty($data['registrado']) && $data['registrado'] === 'Si') {
        $emails[$key]['registrado'] = 'Si';
    }
    if (!empty($data['ultimo_envio'])) {
        $cur = (string)$emails[$key]['ultimo_envio'];
        $new = (string)$data['ultimo_envio'];
        if ($cur === '' || $new > $cur) {
            $emails[$key]['ultimo_envio'] = $new;
        }
    }
    if (!empty($data['ultima_entrada'])) {
        $cur = (string)$emails[$key]['ultima_entrada'];
        $new = (string)$data['ultima_entrada'];
        if ($cur === '' || $new > $cur) {
            $emails[$key]['ultima_entrada'] = $new;
        }
    }
}

$stUsers = $pdo->query('SELECT email, COALESCE(nombre,\'\') AS nombre, COALESCE(apellido,\'\') AS apellido, COALESCE(rol,\'\') AS rol FROM usuarios');
while ($r = $stUsers->fetch(PDO::FETCH_ASSOC)) {
    $nom = trim((string)$r['nombre'] . ' ' . (string)$r['apellido']);
    add_email_row($emails, $r['email'], 'usuarios', array(
        'nombre' => $nom,
        'rol' => $r['rol'],
        'registrado' => 'Si',
    ));
}

$stReg = $pdo->query('SELECT email, COALESCE(nombre,\'\') AS nombre, COALESCE(apellido,\'\') AS apellido FROM registro_pendientes');
while ($r = $stReg->fetch(PDO::FETCH_ASSOC)) {
    $nom = trim((string)$r['nombre'] . ' ' . (string)$r['apellido']);
    add_email_row($emails, $r['email'], 'registro_pendientes', array(
        'nombre' => $nom,
        'registrado' => 'Si',
    ));
}

$stEntradas = $pdo->query("SELECT email, MAX(fecha_registro) AS ultima_entrada, MAX(COALESCE(nombre,'')) AS nombre FROM entradas WHERE email IS NOT NULL AND email <> '' GROUP BY lower(email)");
while ($r = $stEntradas->fetch(PDO::FETCH_ASSOC)) {
    add_email_row($emails, $r['email'], 'entradas', array(
        'nombre' => $r['nombre'],
        'ultima_entrada' => $r['ultima_entrada'],
    ));
}

$stLogs = $pdo->query("SELECT to_email AS email, MAX(created_at) AS ultimo_envio FROM email_logs WHERE to_email IS NOT NULL AND to_email <> '' GROUP BY lower(to_email)");
while ($r = $stLogs->fetch(PDO::FETCH_ASSOC)) {
    add_email_row($emails, $r['email'], 'email_logs', array(
        'ultimo_envio' => $r['ultimo_envio'],
    ));
}

$stBlocked = $pdo->query('SELECT lower(email) AS email_key FROM user_blocks WHERE active = 1');
while ($r = $stBlocked->fetch(PDO::FETCH_ASSOC)) {
    $k = (string)$r['email_key'];
    if (isset($emails[$k])) {
        $emails[$k]['bloqueado'] = 1;
    }
}

$registeredCount = 0;
$blockedCount = 0;
foreach ($emails as $rowEmail) {
  if (isset($rowEmail['registrado']) && $rowEmail['registrado'] === 'Si') {
    $registeredCount++;
  }
  if (!empty($rowEmail['bloqueado'])) {
    $blockedCount++;
  }
}

$rows = array_values($emails);
usort($rows, function ($a, $b) {
    return strcmp(strtolower((string)$a['email']), strtolower((string)$b['email']));
});

if ($q !== '') {
    $needle = strtolower($q);
    $rows = array_values(array_filter($rows, function ($r) use ($needle) {
        $f = strtolower((string)$r['email'] . ' ' . (string)$r['nombre'] . ' ' . (string)$r['rol']);
        return strpos($f, $needle) !== false;
    }));
}

if ($fRegistered === 'si' || $fRegistered === 'no') {
  $rows = array_values(array_filter($rows, function ($r) use ($fRegistered) {
    return strtolower((string)$r['registrado']) === $fRegistered;
  }));
}

if ($fBlocked === '1' || $fBlocked === '0') {
  $rows = array_values(array_filter($rows, function ($r) use ($fBlocked) {
    return (int)$r['bloqueado'] === (int)$fBlocked;
  }));
}

if ($fSource !== '') {
  $rows = array_values(array_filter($rows, function ($r) use ($fSource) {
    return isset($r['fuentes'][$fSource]);
  }));
}

if ($export === 'csv') {
  header('Content-Type: text/csv; charset=UTF-8');
  header('Content-Disposition: attachment; filename="contactos_tickex.csv"');
  $out = fopen('php://output', 'w');
  if ($out !== false) {
    fputs($out, "\xEF\xBB\xBF");
    fputcsv($out, array('email', 'nombre', 'rol', 'registrado', 'fuentes', 'ultimo_envio', 'ultima_entrada', 'acceso'));
    foreach ($rows as $r) {
      $src = array_keys($r['fuentes']);
      fputcsv($out, array(
        (string)$r['email'],
        (string)($r['nombre'] !== '' ? $r['nombre'] : '-'),
        (string)($r['rol'] !== '' ? $r['rol'] : '-'),
        (string)$r['registrado'],
        implode(', ', $src),
        (string)($r['ultimo_envio'] !== '' ? $r['ultimo_envio'] : ''),
        (string)($r['ultima_entrada'] !== '' ? $r['ultima_entrada'] : ''),
        ((int)$r['bloqueado'] === 1 ? 'PROHIBIDO' : 'ACTIVO'),
      ));
    }
    fclose($out);
  }
  exit;
}

$title = 'Comunicacion - Contactos';
include __DIR__ . '/inc/layout_top.php';
?>

<div class="card" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
  <a class="btn secondary" href="panel_admin.php">Volver</a>
  <div>
    <div class="muted" style="font-size:12px;letter-spacing:.08em;text-transform:uppercase;">📣 Comunicacion</div>
    <h2 style="margin:0;">👥 Contactos</h2>
  </div>
  <span class="muted">Vista unificada de personas conocidas por Tickex sin duplicar datos.</span>
</div>

<div class="card" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
  <a class="btn" href="superadmin_emails_db.php">👥 Contactos</a>
  <span class="btn secondary" style="opacity:.6;cursor:not-allowed;">Audiencias · Proximamente</span>
  <span class="btn secondary" style="opacity:.6;cursor:not-allowed;">Plantillas · Proximamente</span>
  <span class="btn secondary" style="opacity:.6;cursor:not-allowed;">Campanas · Proximamente</span>
  <span class="btn secondary" style="opacity:.6;cursor:not-allowed;">Historial · Proximamente</span>
</div>

<?php if ($flashOk !== ''): ?>
  <div class="flash ok"><?php echo $flashOk; ?></div>
<?php endif; ?>
<?php if ($flashErr !== ''): ?>
  <div class="flash err"><?php echo e($flashErr); ?></div>
<?php endif; ?>

<div class="card" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;">
  <div>
    <div class="muted" style="font-size:12px;text-transform:uppercase;letter-spacing:.08em;">Contactos</div>
    <div style="font-size:28px;font-weight:800;"><?php echo (int)count($emails); ?></div>
  </div>
  <div>
    <div class="muted" style="font-size:12px;text-transform:uppercase;letter-spacing:.08em;">Registrados</div>
    <div style="font-size:28px;font-weight:800;"><?php echo (int)$registeredCount; ?></div>
  </div>
  <div>
    <div class="muted" style="font-size:12px;text-transform:uppercase;letter-spacing:.08em;">Bloqueados</div>
    <div style="font-size:28px;font-weight:800;"><?php echo (int)$blockedCount; ?></div>
  </div>
  <div>
    <div class="muted" style="font-size:12px;text-transform:uppercase;letter-spacing:.08em;">Fuentes</div>
    <div style="font-size:15px;font-weight:700;">usuarios, registro_pendientes, entradas, email_logs, user_blocks</div>
  </div>
</div>

<div class="card" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
  <form method="get" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
    <input name="q" placeholder="Buscar email, nombre o rol" value="<?php echo e($q); ?>" style="min-width:260px;">
    <select name="f_registered">
      <option value="" <?php echo $fRegistered === '' ? 'selected' : ''; ?>>Registrados: Todos</option>
      <option value="si" <?php echo $fRegistered === 'si' ? 'selected' : ''; ?>>Registrados: Si</option>
      <option value="no" <?php echo $fRegistered === 'no' ? 'selected' : ''; ?>>Registrados: No</option>
    </select>
    <select name="f_blocked">
      <option value="" <?php echo $fBlocked === '' ? 'selected' : ''; ?>>Acceso: Todos</option>
      <option value="0" <?php echo $fBlocked === '0' ? 'selected' : ''; ?>>Acceso: Activos</option>
      <option value="1" <?php echo $fBlocked === '1' ? 'selected' : ''; ?>>Acceso: Prohibidos</option>
    </select>
    <select name="f_source">
      <option value="" <?php echo $fSource === '' ? 'selected' : ''; ?>>Fuente: Todas</option>
      <option value="usuarios" <?php echo $fSource === 'usuarios' ? 'selected' : ''; ?>>usuarios</option>
      <option value="registro_pendientes" <?php echo $fSource === 'registro_pendientes' ? 'selected' : ''; ?>>registro_pendientes</option>
      <option value="entradas" <?php echo $fSource === 'entradas' ? 'selected' : ''; ?>>entradas</option>
      <option value="email_logs" <?php echo $fSource === 'email_logs' ? 'selected' : ''; ?>>email_logs</option>
    </select>
    <button class="btn secondary" type="submit">Buscar</button>
    <?php if ($q !== '' || $fRegistered !== '' || $fBlocked !== '' || $fSource !== ''): ?>
      <a class="btn secondary" href="superadmin_emails_db.php">Limpiar</a>
    <?php endif; ?>
    <button class="btn secondary" type="submit" name="export" value="csv">Exportar CSV</button>
  </form>
  <span class="muted">Resultado actual: <?php echo (int)count($rows); ?> contactos</span>
</div>

<div class="card" style="overflow:auto;">
  <table class="table" style="width:100%;font-size:14px;">
    <thead>
      <tr>
        <th>Email</th>
        <th>Nombre</th>
        <th>Rol</th>
        <th>Registrado</th>
        <th>Fuentes</th>
        <th>Ultimo envio</th>
        <th>Ultima entrada</th>
        <th>Acceso</th>
        <th>Accion</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?php echo e($r['email']); ?></td>
          <td><?php echo e($r['nombre'] !== '' ? $r['nombre'] : '-'); ?></td>
          <td><?php echo e($r['rol'] !== '' ? $r['rol'] : '-'); ?></td>
          <td><?php echo e($r['registrado']); ?></td>
          <td>
            <?php
              $src = array_keys($r['fuentes']);
              echo e(implode(', ', $src));
            ?>
          </td>
          <td><?php echo e($r['ultimo_envio'] !== '' ? $r['ultimo_envio'] : '-'); ?></td>
          <td><?php echo e($r['ultima_entrada'] !== '' ? $r['ultima_entrada'] : '-'); ?></td>
          <td>
            <?php if ((int)$r['bloqueado'] === 1): ?>
              <span style="display:inline-block;padding:4px 8px;border-radius:999px;background:#5a1a1a;color:#fff;font-weight:700;font-size:12px;">PROHIBIDO</span>
            <?php else: ?>
              <span style="display:inline-block;padding:4px 8px;border-radius:999px;background:#184d2a;color:#fff;font-weight:700;font-size:12px;">ACTIVO</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if ((int)$r['bloqueado'] === 1): ?>
              <form method="post" action="superadmin_emails_db.php" style="display:inline;">
                <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
                <input type="hidden" name="email" value="<?php echo e($r['email']); ?>">
                <button type="submit" name="action" value="desbloquear" style="font-size:12px;padding:4px 8px;">Quitar bloqueo</button>
              </form>
            <?php else: ?>
              <form method="post" action="superadmin_emails_db.php" style="display:flex;gap:4px;align-items:center;">
                <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
                <input type="hidden" name="email" value="<?php echo e($r['email']); ?>">
                <input type="text" name="reason" placeholder="Motivo (opcional)" style="font-size:12px;padding:4px 6px;max-width:170px;">
                <button type="submit" name="action" value="bloquear" style="font-size:12px;padding:4px 8px;background:#8e2b2b;color:#fff;border:1px solid #8e2b2b;border-radius:6px;">PROHIBIDO</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php include __DIR__ . '/inc/layout_bottom.php'; ?>
