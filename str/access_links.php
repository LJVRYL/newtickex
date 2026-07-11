<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/access_links.php';

require_login();
$cu = current_user();
$tipoGlobal = isset($_SESSION['tipo_global']) ? (string)$_SESSION['tipo_global'] : (isset($cu['tipo_global']) ? (string)$cu['tipo_global'] : '');
$isSuper = in_array($tipoGlobal, array('super_admin', 'superadmin'), true);
$isAdminEvento = ($tipoGlobal === 'admin_evento');
if (!$isSuper && !$isAdminEvento) {
    http_response_code(403);
    include __DIR__ . '/inc/layout_top.php';
    echo '<div class="card" style="max-width:640px;margin:32px auto;"><h2>Acceso restringido</h2><p>Solo para administradores.</p></div>';
    include __DIR__ . '/inc/layout_bottom.php';
    exit;
}

$pdo = db();
$title = 'Links de acceso';
$adminId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : (isset($cu['id']) ? (int)$cu['id'] : 0);
$csrf = function_exists('tickex_csrf_token') ? (string)tickex_csrf_token() : '';
$eventoFilterId = isset($_GET['evento_id']) ? (int)$_GET['evento_id'] : 0;

$flashOk = '';
$flashErr = '';

$evCols = $pdo->query('PRAGMA table_info(eventos)')->fetchAll(PDO::FETCH_ASSOC);
$hasCreatedBy = false;
foreach ($evCols as $c) {
    if (isset($c['name']) && $c['name'] === 'creado_por_admin_id') {
        $hasCreatedBy = true;
        break;
    }
}

$eventos = array();
if ($isSuper || !$hasCreatedBy) {
    $stEv = $pdo->query('SELECT id, nombre, slug FROM eventos ORDER BY nombre ASC, id DESC');
    $eventos = $stEv ? $stEv->fetchAll(PDO::FETCH_ASSOC) : array();
} else {
    $stEv = $pdo->prepare('SELECT id, nombre, slug FROM eventos WHERE creado_por_admin_id = :aid ORDER BY nombre ASC, id DESC');
    $stEv->execute(array(':aid' => $adminId));
    $eventos = $stEv->fetchAll(PDO::FETCH_ASSOC);
}

$eventIds = array();
foreach ($eventos as $ev) $eventIds[(int)$ev['id']] = true;

$tipos = array();
if (!empty($eventIds)) {
  try {
    $hasTiposTable = false;
    $stTbl = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='tipos_entrada' LIMIT 1");
    if ($stTbl && $stTbl->fetch(PDO::FETCH_ASSOC)) $hasTiposTable = true;

    if ($hasTiposTable) {
      $colsTe = $pdo->query('PRAGMA table_info(tipos_entrada)')->fetchAll(PDO::FETCH_ASSOC);
      $teColMap = array();
      foreach ($colsTe as $c) {
        if (isset($c['name'])) $teColMap[$c['name']] = true;
      }

      $stockExpr = '0 AS cantidad_disponible';
      if (isset($teColMap['cantidad_disponible'])) {
        $stockExpr = 'COALESCE(t.cantidad_disponible,0) AS cantidad_disponible';
      } elseif (isset($teColMap['cantidad_total'])) {
        $stockExpr = 'COALESCE(t.cantidad_total,0) AS cantidad_disponible';
      }

      $ids = array_keys($eventIds);
      $ph = array();
      $params = array();
      foreach ($ids as $i => $eid) {
        $k = ':e' . $i;
        $ph[] = $k;
        $params[$k] = (int)$eid;
      }

      $sqlTipos = 'SELECT t.id, t.evento_id, t.nombre, ' . $stockExpr . ', e.nombre AS evento_nombre FROM tipos_entrada t LEFT JOIN eventos e ON e.id = t.evento_id WHERE t.evento_id IN (' . implode(',', $ph) . ') ORDER BY e.nombre ASC, t.nombre ASC';
      $stTp = $pdo->prepare($sqlTipos);
      $stTp->execute($params);
      $tipos = $stTp->fetchAll(PDO::FETCH_ASSOC);
    }
  } catch (Exception $e) {
    $tipos = array();
  }
}

$editId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$current = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $provided = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
    if (function_exists('tickex_csrf_verify') && !tickex_csrf_verify($provided)) {
        $flashErr = 'CSRF inválido. Recargá la página e intentá nuevamente.';
    } else {
        $action = isset($_POST['action']) ? (string)$_POST['action'] : '';

        if ($action === 'duplicate') {
            $srcId = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            $st = $pdo->prepare('SELECT * FROM access_links WHERE id = :id LIMIT 1');
            $st->execute(array(':id' => $srcId));
            $src = $st->fetch(PDO::FETCH_ASSOC);
            if (!$src) {
                $flashErr = 'Link no encontrado para duplicar.';
            } elseif (!$isSuper && (!isset($eventIds[(int)$src['evento_id']]))) {
                $flashErr = 'No tenés permiso para duplicar este link.';
            } else {
                $newCode = tickex_access_make_code($pdo, (string)$src['code'] . '-copy', 0);
                $stIns = $pdo->prepare('INSERT INTO access_links
                    (uuid, code, label, evento_id, access_type, status, starts_at, expires_at, max_uses, captcha_required, unique_email, unique_dni,
                     ip_limit_window_seconds, ip_limit_max_uses, rate_limit_window_seconds, rate_limit_max_requests, ticket_type_id, notes,
                     created_by_admin_id, updated_by_admin_id, created_at, updated_at)
                    VALUES
                    (:uuid, :code, :label, :evento_id, :access_type, :status, :starts_at, :expires_at, :max_uses, :captcha_required, :unique_email, :unique_dni,
                     :ip_window, :ip_max, :rate_window, :rate_max, :ticket_type_id, :notes,
                     :created_by, :updated_by, datetime(\'now\'), datetime(\'now\'))');
                $stIns->execute(array(
                    ':uuid' => tickex_access_uuid(),
                    ':code' => $newCode,
                    ':label' => (string)$src['label'] . ' (copia)',
                    ':evento_id' => (int)$src['evento_id'],
                    ':access_type' => (string)$src['access_type'],
                    ':status' => 'draft',
                    ':starts_at' => isset($src['starts_at']) && $src['starts_at'] !== '' ? $src['starts_at'] : null,
                    ':expires_at' => isset($src['expires_at']) && $src['expires_at'] !== '' ? $src['expires_at'] : null,
                    ':max_uses' => isset($src['max_uses']) && $src['max_uses'] !== '' ? (int)$src['max_uses'] : null,
                    ':captcha_required' => !empty($src['captcha_required']) ? 1 : 0,
                    ':unique_email' => !empty($src['unique_email']) ? 1 : 0,
                    ':unique_dni' => !empty($src['unique_dni']) ? 1 : 0,
                    ':ip_window' => isset($src['ip_limit_window_seconds']) && $src['ip_limit_window_seconds'] !== '' ? (int)$src['ip_limit_window_seconds'] : null,
                    ':ip_max' => isset($src['ip_limit_max_uses']) && $src['ip_limit_max_uses'] !== '' ? (int)$src['ip_limit_max_uses'] : null,
                    ':rate_window' => isset($src['rate_limit_window_seconds']) && $src['rate_limit_window_seconds'] !== '' ? (int)$src['rate_limit_window_seconds'] : null,
                    ':rate_max' => isset($src['rate_limit_max_requests']) && $src['rate_limit_max_requests'] !== '' ? (int)$src['rate_limit_max_requests'] : null,
                    ':ticket_type_id' => (int)$src['ticket_type_id'],
                    ':notes' => isset($src['notes']) ? (string)$src['notes'] : '',
                    ':created_by' => $adminId,
                    ':updated_by' => $adminId,
                ));
                $newId = (int)$pdo->lastInsertId();
                $loc = 'access_links.php?id=' . $newId . '&ok=duplicado';
                if ($eventoFilterId > 0) $loc .= '&evento_id=' . $eventoFilterId;
                header('Location: ' . $loc);
                exit;
            }
        } elseif ($action === 'set_status') {
            $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            $status = isset($_POST['status']) ? strtolower(trim((string)$_POST['status'])) : 'draft';
            $allowed = array('draft', 'active', 'paused', 'expired', 'disabled');
            if (!in_array($status, $allowed, true)) $status = 'draft';
            $stChk = $pdo->prepare('SELECT evento_id FROM access_links WHERE id = :id LIMIT 1');
            $stChk->execute(array(':id' => $id));
            $evId = (int)$stChk->fetchColumn();
            if ($evId <= 0) {
                $flashErr = 'Link no encontrado.';
            } elseif (!$isSuper && !isset($eventIds[$evId])) {
                $flashErr = 'No tenés permiso para cambiar ese link.';
            } else {
                $stUp = $pdo->prepare('UPDATE access_links SET status = :s, updated_by_admin_id = :uid, updated_at = datetime(\'now\') WHERE id = :id');
                $stUp->execute(array(':s' => $status, ':uid' => $adminId, ':id' => $id));
                $flashOk = 'Estado actualizado.';
            }
        } elseif ($action === 'save') {
            $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            $label = trim((string)(isset($_POST['label']) ? $_POST['label'] : ''));
            $code = tickex_access_slugify(isset($_POST['code']) ? $_POST['code'] : '');
            $eventoId = isset($_POST['evento_id']) ? (int)$_POST['evento_id'] : 0;
            $accessType = trim((string)(isset($_POST['access_type']) ? $_POST['access_type'] : 'free'));
            $status = strtolower(trim((string)(isset($_POST['status']) ? $_POST['status'] : 'draft')));
            $startsAt = trim((string)(isset($_POST['starts_at']) ? $_POST['starts_at'] : ''));
            $expiresAt = trim((string)(isset($_POST['expires_at']) ? $_POST['expires_at'] : ''));
            $maxUses = trim((string)(isset($_POST['max_uses']) ? $_POST['max_uses'] : ''));
            $ticketTypeId = isset($_POST['ticket_type_id']) ? (int)$_POST['ticket_type_id'] : 0;
            $captchaRequired = !empty($_POST['captcha_required']) ? 1 : 0;
            $uniqueEmail = !empty($_POST['unique_email']) ? 1 : 0;
            $uniqueDni = !empty($_POST['unique_dni']) ? 1 : 0;
            $ipWindow = trim((string)(isset($_POST['ip_limit_window_seconds']) ? $_POST['ip_limit_window_seconds'] : ''));
            $ipMax = trim((string)(isset($_POST['ip_limit_max_uses']) ? $_POST['ip_limit_max_uses'] : ''));
            $rateWindow = trim((string)(isset($_POST['rate_limit_window_seconds']) ? $_POST['rate_limit_window_seconds'] : ''));
            $rateMax = trim((string)(isset($_POST['rate_limit_max_requests']) ? $_POST['rate_limit_max_requests'] : ''));
            $notes = trim((string)(isset($_POST['notes']) ? $_POST['notes'] : ''));

            $allowed = array('draft', 'active', 'paused', 'expired', 'disabled');
            if (!in_array($status, $allowed, true)) $status = 'draft';

            if ($label === '') {
                $flashErr = 'El nombre del link es obligatorio.';
            } elseif ($eventoId <= 0 || !isset($eventIds[$eventoId])) {
                $flashErr = 'Evento inválido o sin permiso.';
            } elseif ($ticketTypeId <= 0) {
                $flashErr = 'ticket_type_id es obligatorio.';
            } else {
                $stTypeChk = $pdo->prepare('SELECT COUNT(*) FROM tipos_entrada WHERE id = :tid AND evento_id = :eid');
                $stTypeChk->execute(array(':tid' => $ticketTypeId, ':eid' => $eventoId));
                if ((int)$stTypeChk->fetchColumn() <= 0) {
                    $flashErr = 'El tipo de entrada no pertenece al evento seleccionado.';
                }
            }

            if ($flashErr === '') {
                if ($code === '') {
                    $code = tickex_access_make_code($pdo, $label, $id);
                } elseif (!tickex_access_code_available($pdo, $code, $id)) {
                    $flashErr = 'El código público ya está en uso.';
                }
            }

            if ($flashErr === '') {
                if ($id > 0) {
                    $stOwn = $pdo->prepare('SELECT evento_id FROM access_links WHERE id = :id LIMIT 1');
                    $stOwn->execute(array(':id' => $id));
                    $ownerEv = (int)$stOwn->fetchColumn();
                    if ($ownerEv <= 0) {
                        $flashErr = 'Link inexistente.';
                    } elseif (!$isSuper && !isset($eventIds[$ownerEv])) {
                        $flashErr = 'No tenés permiso para editar este link.';
                    }
                }
            }

            if ($flashErr === '') {
                if ($id > 0) {
                    $stUp = $pdo->prepare('UPDATE access_links SET
                        code = :code,
                        label = :label,
                        evento_id = :evento_id,
                        access_type = :access_type,
                        status = :status,
                        starts_at = :starts_at,
                        expires_at = :expires_at,
                        max_uses = :max_uses,
                        captcha_required = :captcha_required,
                        unique_email = :unique_email,
                        unique_dni = :unique_dni,
                        ip_limit_window_seconds = :ip_window,
                        ip_limit_max_uses = :ip_max,
                        rate_limit_window_seconds = :rate_window,
                        rate_limit_max_requests = :rate_max,
                        ticket_type_id = :ticket_type_id,
                        notes = :notes,
                        updated_by_admin_id = :uid,
                        updated_at = datetime(\'now\')
                        WHERE id = :id');
                    $stUp->execute(array(
                        ':code' => $code,
                        ':label' => $label,
                        ':evento_id' => $eventoId,
                        ':access_type' => $accessType,
                        ':status' => $status,
                        ':starts_at' => ($startsAt !== '' ? $startsAt : null),
                        ':expires_at' => ($expiresAt !== '' ? $expiresAt : null),
                        ':max_uses' => ($maxUses !== '' ? (int)$maxUses : null),
                        ':captcha_required' => $captchaRequired,
                        ':unique_email' => $uniqueEmail,
                        ':unique_dni' => $uniqueDni,
                        ':ip_window' => ($ipWindow !== '' ? (int)$ipWindow : null),
                        ':ip_max' => ($ipMax !== '' ? (int)$ipMax : null),
                        ':rate_window' => ($rateWindow !== '' ? (int)$rateWindow : null),
                        ':rate_max' => ($rateMax !== '' ? (int)$rateMax : null),
                        ':ticket_type_id' => $ticketTypeId,
                        ':notes' => $notes,
                        ':uid' => $adminId,
                        ':id' => $id,
                    ));
                    $loc = 'access_links.php?id=' . $id . '&ok=guardado';
                    if ($eventoFilterId > 0) $loc .= '&evento_id=' . $eventoFilterId;
                    header('Location: ' . $loc);
                    exit;
                } else {
                    $stIns = $pdo->prepare('INSERT INTO access_links
                        (uuid, code, label, evento_id, access_type, status, starts_at, expires_at, max_uses,
                         captcha_required, unique_email, unique_dni, ip_limit_window_seconds, ip_limit_max_uses,
                         rate_limit_window_seconds, rate_limit_max_requests, ticket_type_id, notes,
                         created_by_admin_id, updated_by_admin_id, created_at, updated_at)
                        VALUES
                        (:uuid, :code, :label, :evento_id, :access_type, :status, :starts_at, :expires_at, :max_uses,
                         :captcha_required, :unique_email, :unique_dni, :ip_window, :ip_max,
                         :rate_window, :rate_max, :ticket_type_id, :notes,
                         :uid, :uid, datetime(\'now\'), datetime(\'now\'))');
                    $stIns->execute(array(
                        ':uuid' => tickex_access_uuid(),
                        ':code' => $code,
                        ':label' => $label,
                        ':evento_id' => $eventoId,
                        ':access_type' => $accessType,
                        ':status' => $status,
                        ':starts_at' => ($startsAt !== '' ? $startsAt : null),
                        ':expires_at' => ($expiresAt !== '' ? $expiresAt : null),
                        ':max_uses' => ($maxUses !== '' ? (int)$maxUses : null),
                        ':captcha_required' => $captchaRequired,
                        ':unique_email' => $uniqueEmail,
                        ':unique_dni' => $uniqueDni,
                        ':ip_window' => ($ipWindow !== '' ? (int)$ipWindow : null),
                        ':ip_max' => ($ipMax !== '' ? (int)$ipMax : null),
                        ':rate_window' => ($rateWindow !== '' ? (int)$rateWindow : null),
                        ':rate_max' => ($rateMax !== '' ? (int)$rateMax : null),
                        ':ticket_type_id' => $ticketTypeId,
                        ':notes' => $notes,
                        ':uid' => $adminId,
                    ));
                    $newId = (int)$pdo->lastInsertId();
                    $loc = 'access_links.php?id=' . $newId . '&ok=creado';
                    if ($eventoFilterId > 0) $loc .= '&evento_id=' . $eventoFilterId;
                    header('Location: ' . $loc);
                    exit;
                }
            }
        }
    }
}

if ($editId > 0) {
    $stCur = $pdo->prepare('SELECT * FROM access_links WHERE id = :id LIMIT 1');
    $stCur->execute(array(':id' => $editId));
    $tmp = $stCur->fetch(PDO::FETCH_ASSOC);
    if ($tmp && ($isSuper || isset($eventIds[(int)$tmp['evento_id']]))) {
        $current = $tmp;
    }
}

$where = '';
$paramsList = array();
if (!$isSuper && !empty($eventIds)) {
    $ph = array();
    $i = 0;
    foreach (array_keys($eventIds) as $eid) {
        $k = ':w' . $i++;
        $ph[] = $k;
        $paramsList[$k] = (int)$eid;
    }
    $where = 'WHERE l.evento_id IN (' . implode(',', $ph) . ')';
} elseif (!$isSuper) {
    $where = 'WHERE 1=0';
}

  if ($eventoFilterId > 0) {
    if ($where === '') {
      $where = 'WHERE l.evento_id = :evento_filter_id';
    } else {
      $where .= ' AND l.evento_id = :evento_filter_id';
    }
    $paramsList[':evento_filter_id'] = $eventoFilterId;
  }

$sqlList = 'SELECT l.*, e.nombre AS evento_nombre, e.slug AS evento_slug, t.nombre AS ticket_type_nombre,
    (SELECT COUNT(*) FROM access_link_issues i WHERE i.access_link_id = l.id) AS used_count
    FROM access_links l
    LEFT JOIN eventos e ON e.id = l.evento_id
    LEFT JOIN tipos_entrada t ON t.id = l.ticket_type_id
    ' . $where . '
    ORDER BY l.id DESC';
$rows = array();
try {
  $stList = $pdo->prepare($sqlList);
  $stList->execute($paramsList);
  $rows = $stList->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
  $flashErr = 'No se pudo cargar Links de acceso. Verificá que la migración de base esté aplicada.';
}

$attempts = array();
$issues = array();
if ($current) {
    $stA = $pdo->prepare('SELECT * FROM access_link_attempts WHERE access_link_id = :id ORDER BY id DESC LIMIT 50');
    $stA->execute(array(':id' => (int)$current['id']));
    $attempts = $stA->fetchAll(PDO::FETCH_ASSOC);

    $stI = $pdo->prepare('SELECT ai.*, en.codigo FROM access_link_issues ai LEFT JOIN entradas en ON en.id = ai.entrada_id WHERE ai.access_link_id = :id ORDER BY ai.id DESC LIMIT 50');
    $stI->execute(array(':id' => (int)$current['id']));
    $issues = $stI->fetchAll(PDO::FETCH_ASSOC);
}

$isEdit = $current ? true : false;
$f = array(
    'id' => $isEdit ? (int)$current['id'] : 0,
    'label' => $isEdit ? (string)$current['label'] : '',
    'code' => $isEdit ? (string)$current['code'] : '',
    'evento_id' => $isEdit ? (int)$current['evento_id'] : ($eventoFilterId > 0 ? $eventoFilterId : 0),
    'access_type' => $isEdit ? (string)$current['access_type'] : 'free',
    'status' => $isEdit ? (string)$current['status'] : 'draft',
    'starts_at' => $isEdit && !empty($current['starts_at']) ? (string)$current['starts_at'] : '',
    'expires_at' => $isEdit && !empty($current['expires_at']) ? (string)$current['expires_at'] : '',
    'max_uses' => $isEdit && $current['max_uses'] !== null ? (string)$current['max_uses'] : '',
    'captcha_required' => $isEdit ? (int)$current['captcha_required'] : 1,
    'unique_email' => $isEdit ? (int)$current['unique_email'] : 1,
    'unique_dni' => $isEdit ? (int)$current['unique_dni'] : 1,
    'ip_limit_window_seconds' => $isEdit && $current['ip_limit_window_seconds'] !== null ? (string)$current['ip_limit_window_seconds'] : '',
    'ip_limit_max_uses' => $isEdit && $current['ip_limit_max_uses'] !== null ? (string)$current['ip_limit_max_uses'] : '',
    'rate_limit_window_seconds' => $isEdit && $current['rate_limit_window_seconds'] !== null ? (string)$current['rate_limit_window_seconds'] : '',
    'rate_limit_max_requests' => $isEdit && $current['rate_limit_max_requests'] !== null ? (string)$current['rate_limit_max_requests'] : '',
    'ticket_type_id' => $isEdit ? (int)$current['ticket_type_id'] : 0,
    'notes' => $isEdit ? (string)$current['notes'] : '',
    'uuid' => $isEdit ? (string)$current['uuid'] : '',
);

if (isset($_GET['ok']) && $_GET['ok'] !== '') {
    $flashOk = 'Operación realizada correctamente.';
}

include __DIR__ . '/inc/layout_top.php';
?>

<div class="card" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
  <a class="btn secondary" href="panel_admin.php">Volver</a>
  <h2 style="margin:0;">Links de acceso</h2>
  <span class="muted">Motor reusable de emisión por configuración.</span>
  <?php if ($eventoFilterId > 0): ?>
    <a class="btn secondary" href="access_links.php">Quitar filtro de evento</a>
  <?php endif; ?>
</div>

<?php if ($flashOk !== ''): ?><div class="flash ok"><?php echo e($flashOk); ?></div><?php endif; ?>
<?php if ($flashErr !== ''): ?><div class="flash err"><?php echo e($flashErr); ?></div><?php endif; ?>

<div class="card">
  <h3 style="margin-top:0;"><?php echo $isEdit ? 'Editar Link' : 'Nuevo Link'; ?></h3>
  <form method="post" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:10px;align-items:end;">
    <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?php echo (int)$f['id']; ?>">

    <div>
      <label>Nombre interno</label>
      <input name="label" required value="<?php echo e($f['label']); ?>">
    </div>
    <div>
      <label>Code público</label>
      <input name="code" placeholder="auto-si-vacío" value="<?php echo e($f['code']); ?>">
    </div>
    <div>
      <label>Evento</label>
      <select name="evento_id" required>
        <option value="">Seleccionar...</option>
        <?php foreach ($eventos as $ev): ?>
          <option value="<?php echo (int)$ev['id']; ?>" <?php echo (int)$f['evento_id'] === (int)$ev['id'] ? 'selected' : ''; ?>><?php echo e($ev['nombre']); ?> (#<?php echo (int)$ev['id']; ?>)</option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label>Access type</label>
      <input name="access_type" required value="<?php echo e($f['access_type']); ?>" placeholder="free|invite|press|sponsor">
    </div>
    <div>
      <label>Estado</label>
      <select name="status" required>
        <?php foreach (array('draft','active','paused','expired','disabled') as $st): ?>
          <option value="<?php echo e($st); ?>" <?php echo $f['status'] === $st ? 'selected' : ''; ?>><?php echo e($st); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label>Tipo de entrada (obligatorio)</label>
      <select name="ticket_type_id" required>
        <option value="">Seleccionar...</option>
        <?php foreach ($tipos as $tp): ?>
          <option value="<?php echo (int)$tp['id']; ?>" <?php echo (int)$f['ticket_type_id'] === (int)$tp['id'] ? 'selected' : ''; ?>><?php echo e($tp['evento_nombre']); ?> - <?php echo e($tp['nombre']); ?> (disp: <?php echo (int)$tp['cantidad_disponible']; ?>)</option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label>Inicio (YYYY-mm-dd HH:ii:ss)</label>
      <input name="starts_at" value="<?php echo e($f['starts_at']); ?>">
    </div>
    <div>
      <label>Expira (YYYY-mm-dd HH:ii:ss)</label>
      <input name="expires_at" value="<?php echo e($f['expires_at']); ?>">
    </div>
    <div>
      <label>Cupo máximo</label>
      <input type="number" min="1" name="max_uses" value="<?php echo e($f['max_uses']); ?>">
    </div>
    <div>
      <label>IP window (seg)</label>
      <input type="number" min="1" name="ip_limit_window_seconds" value="<?php echo e($f['ip_limit_window_seconds']); ?>">
    </div>
    <div>
      <label>IP max uses</label>
      <input type="number" min="1" name="ip_limit_max_uses" value="<?php echo e($f['ip_limit_max_uses']); ?>">
    </div>
    <div>
      <label>Rate window (seg)</label>
      <input type="number" min="1" name="rate_limit_window_seconds" value="<?php echo e($f['rate_limit_window_seconds']); ?>">
    </div>
    <div>
      <label>Rate max requests</label>
      <input type="number" min="1" name="rate_limit_max_requests" value="<?php echo e($f['rate_limit_max_requests']); ?>">
    </div>

    <div style="grid-column:1/-1;display:flex;gap:16px;flex-wrap:wrap;">
      <label><input type="checkbox" name="captcha_required" value="1" <?php echo !empty($f['captcha_required']) ? 'checked' : ''; ?>> captcha obligatorio</label>
      <label><input type="checkbox" name="unique_email" value="1" <?php echo !empty($f['unique_email']) ? 'checked' : ''; ?>> email único</label>
      <label><input type="checkbox" name="unique_dni" value="1" <?php echo !empty($f['unique_dni']) ? 'checked' : ''; ?>> DNI único</label>
    </div>

    <div style="grid-column:1/-1;">
      <label>Observaciones</label>
      <textarea name="notes" rows="3"><?php echo e($f['notes']); ?></textarea>
    </div>

    <div style="grid-column:1/-1;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
      <button class="btn" type="submit"><?php echo $isEdit ? 'Guardar cambios' : 'Crear link'; ?></button>
      <?php if ($isEdit): ?>
        <a class="btn secondary" href="access_links.php">Nuevo</a>
        <span class="muted">UUID interno: <code><?php echo e($f['uuid']); ?></code></span>
        <span class="muted">URL: <a href="acceso.php?code=<?php echo urlencode($f['code']); ?>" target="_blank">acceso.php?code=<?php echo e($f['code']); ?></a></span>
      <?php endif; ?>
    </div>
  </form>
</div>

<div class="card" style="overflow:auto;">
  <h3 style="margin-top:0;">Listado</h3>
  <table class="table">
    <thead>
      <tr>
        <th>ID</th>
        <th>Label</th>
        <th>Code</th>
        <th>Evento</th>
        <th>Tipo</th>
        <th>Status</th>
        <th>Usado</th>
        <th>Cupo</th>
        <th>Acciones</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
      <tr>
        <td><?php echo (int)$r['id']; ?></td>
        <td><?php echo e($r['label']); ?></td>
        <td><?php echo e($r['code']); ?></td>
        <td><?php echo e($r['evento_nombre']); ?></td>
        <td><?php echo e($r['access_type']); ?></td>
        <td><?php echo e(tickex_access_effective_status($r)); ?></td>
        <td><?php echo (int)$r['used_count']; ?></td>
        <td><?php echo ($r['max_uses'] !== null && $r['max_uses'] !== '') ? (int)$r['max_uses'] : '∞'; ?></td>
        <td style="display:flex;gap:6px;flex-wrap:wrap;">
          <a class="btn secondary" href="access_links.php?id=<?php echo (int)$r['id']; ?>">Editar</a>
          <form method="post" style="display:inline;">
            <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
            <input type="hidden" name="action" value="duplicate">
            <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
            <button class="btn secondary" type="submit">Duplicar</button>
          </form>
          <form method="post" style="display:inline;">
            <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
            <input type="hidden" name="action" value="set_status">
            <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
            <input type="hidden" name="status" value="disabled">
            <button class="btn secondary" type="submit">Deshabilitar</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php if ($isEdit): ?>
<div class="card" style="overflow:auto;">
  <h3 style="margin-top:0;">Últimos intentos</h3>
  <table class="table">
    <thead><tr><th>Fecha</th><th>trace_id</th><th>IP</th><th>Resultado</th><th>Detalle</th></tr></thead>
    <tbody>
      <?php foreach ($attempts as $a): ?>
      <tr>
        <td><?php echo e($a['created_at']); ?></td>
        <td><?php echo e($a['trace_id']); ?></td>
        <td><?php echo e($a['ip_address']); ?></td>
        <td><?php echo e($a['result']); ?></td>
        <td><?php echo e($a['detail']); ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="card" style="overflow:auto;">
  <h3 style="margin-top:0;">Emisiones</h3>
  <table class="table">
    <thead><tr><th>Fecha</th><th>Entrada</th><th>Código</th><th>Email</th><th>DNI</th><th>IP</th><th>issued_by</th></tr></thead>
    <tbody>
      <?php foreach ($issues as $i): ?>
      <tr>
        <td><?php echo e($i['created_at']); ?></td>
        <td>#<?php echo (int)$i['entrada_id']; ?></td>
        <td><?php echo e($i['codigo']); ?></td>
        <td><?php echo e($i['email_normalized']); ?></td>
        <td><?php echo e($i['dni_normalized']); ?></td>
        <td><?php echo e($i['ip_address']); ?></td>
        <td><?php echo e($i['issued_by']); ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php include __DIR__ . '/inc/layout_bottom.php'; ?>
