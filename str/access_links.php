<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/free_checkout.php';

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
tickex_free_checkout_ensure_schema($pdo);
$title = 'Checkout free';
$adminId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : (isset($cu['id']) ? (int)$cu['id'] : 0);
$csrf = function_exists('tickex_csrf_token') ? (string)tickex_csrf_token() : '';
$eventoId = isset($_GET['evento_id']) ? (int)$_GET['evento_id'] : 0;

$evCols = $pdo->query('PRAGMA table_info(eventos)')->fetchAll(PDO::FETCH_ASSOC);
$hasCreatedBy = false;
foreach ($evCols as $c) {
    if (isset($c['name']) && $c['name'] === 'creado_por_admin_id') { $hasCreatedBy = true; break; }
}

if ($isSuper || !$hasCreatedBy) {
    $stEv = $pdo->query('SELECT id, nombre, slug FROM eventos ORDER BY nombre ASC, id DESC');
    $eventos = $stEv ? $stEv->fetchAll(PDO::FETCH_ASSOC) : array();
} else {
    $stEv = $pdo->prepare('SELECT id, nombre, slug FROM eventos WHERE creado_por_admin_id = :aid ORDER BY nombre ASC, id DESC');
    $stEv->execute(array(':aid' => $adminId));
    $eventos = $stEv->fetchAll(PDO::FETCH_ASSOC);
}

$allowedEventIds = array();
foreach ($eventos as $ev) $allowedEventIds[(int)$ev['id']] = true;
if ($eventoId > 0 && !isset($allowedEventIds[$eventoId])) $eventoId = 0;

$flashOk = '';
$flashErr = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $provided = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
    if (function_exists('tickex_csrf_verify') && !tickex_csrf_verify($provided)) {
        $flashErr = 'CSRF inválido. Recargá e intentá de nuevo.';
    } else {
        $eventoIdPost = isset($_POST['evento_id']) ? (int)$_POST['evento_id'] : 0;
        if ($eventoIdPost <= 0 || !isset($allowedEventIds[$eventoIdPost])) {
            $flashErr = 'Evento inválido.';
        } else {
            $enabled = !empty($_POST['enabled']) ? 1 : 0;
            $ticketTypeId = isset($_POST['ticket_type_id']) ? (int)$_POST['ticket_type_id'] : 0;
            $maxUses = trim((string)(isset($_POST['max_uses']) ? $_POST['max_uses'] : ''));
            $captchaRequired = !empty($_POST['captcha_required']) ? 1 : 0;
            $uniqueEmail = !empty($_POST['unique_email']) ? 1 : 0;

            if ($ticketTypeId <= 0) {
                $flashErr = 'Elegí una entrada del evento.';
            } else {
                $stTp = $pdo->prepare('SELECT COUNT(*) FROM tipos_entrada WHERE id = :id AND evento_id = :eid');
                $stTp->execute(array(':id' => $ticketTypeId, ':eid' => $eventoIdPost));
                if ((int)$stTp->fetchColumn() <= 0) {
                    $flashErr = 'La entrada elegida no pertenece a este evento.';
                }
            }

            if ($flashErr === '') {
                $stCfg = $pdo->prepare('SELECT id FROM event_free_checkout_configs WHERE evento_id = :eid LIMIT 1');
                $stCfg->execute(array(':eid' => $eventoIdPost));
                $cfgId = (int)$stCfg->fetchColumn();
                if ($cfgId > 0) {
                    $stUp = $pdo->prepare('UPDATE event_free_checkout_configs SET enabled = :enabled, ticket_type_id = :ticket_type_id, max_uses = :max_uses, captcha_required = :captcha_required, unique_email = :unique_email, updated_by_admin_id = :uid, updated_at = datetime(\'now\') WHERE evento_id = :eid');
                    $stUp->execute(array(
                        ':enabled' => $enabled,
                        ':ticket_type_id' => $ticketTypeId,
                        ':max_uses' => ($maxUses !== '' ? (int)$maxUses : null),
                        ':captcha_required' => $captchaRequired,
                        ':unique_email' => $uniqueEmail,
                        ':uid' => $adminId,
                        ':eid' => $eventoIdPost,
                    ));
                } else {
                    $stIns = $pdo->prepare('INSERT INTO event_free_checkout_configs (evento_id, enabled, ticket_type_id, max_uses, captcha_required, unique_email, created_by_admin_id, updated_by_admin_id, created_at, updated_at) VALUES (:eid, :enabled, :ticket_type_id, :max_uses, :captcha_required, :unique_email, :uid, :uid, datetime(\'now\'), datetime(\'now\'))');
                    $stIns->execute(array(
                        ':eid' => $eventoIdPost,
                        ':enabled' => $enabled,
                        ':ticket_type_id' => $ticketTypeId,
                        ':max_uses' => ($maxUses !== '' ? (int)$maxUses : null),
                        ':captcha_required' => $captchaRequired,
                        ':unique_email' => $uniqueEmail,
                        ':uid' => $adminId,
                    ));
                }
                header('Location: access_links.php?evento_id=' . $eventoIdPost . '&ok=1');
                exit;
            }
        }
    }
}

if (isset($_GET['ok']) && $_GET['ok'] !== '') {
    $flashOk = 'Checkout free guardado correctamente.';
}

$currentEvent = null;
$currentConfig = null;
$ticketTypes = array();

if ($eventoId > 0) {
    $currentEvent = tickex_free_checkout_find_event($pdo, $eventoId, '');
    $currentConfig = tickex_free_checkout_load_config($pdo, $eventoId);

    $colsTe = $pdo->query('PRAGMA table_info(tipos_entrada)')->fetchAll(PDO::FETCH_ASSOC);
    $hasCantDisp = false;
    $hasCantTotal = false;
    foreach ($colsTe as $c) {
        if (isset($c['name']) && $c['name'] === 'cantidad_disponible') $hasCantDisp = true;
        if (isset($c['name']) && $c['name'] === 'cantidad_total') $hasCantTotal = true;
    }
    $stockExpr = '0 AS cantidad_disponible';
    if ($hasCantDisp) $stockExpr = 'COALESCE(cantidad_disponible,0) AS cantidad_disponible';
    elseif ($hasCantTotal) $stockExpr = 'COALESCE(cantidad_total,0) AS cantidad_disponible';

    $stTp = $pdo->prepare('SELECT id, nombre, ' . $stockExpr . ' FROM tipos_entrada WHERE evento_id = :eid ORDER BY nombre ASC');
    $stTp->execute(array(':eid' => $eventoId));
    $ticketTypes = $stTp->fetchAll(PDO::FETCH_ASSOC);
}

$f = array(
    'enabled' => ($currentConfig && !empty($currentConfig['enabled'])) ? 1 : 0,
    'ticket_type_id' => $currentConfig ? (int)$currentConfig['ticket_type_id'] : 0,
    'max_uses' => ($currentConfig && $currentConfig['max_uses'] !== null) ? (string)$currentConfig['max_uses'] : '',
    'captcha_required' => ($currentConfig && isset($currentConfig['captcha_required'])) ? (int)$currentConfig['captcha_required'] : 1,
    'unique_email' => ($currentConfig && isset($currentConfig['unique_email'])) ? (int)$currentConfig['unique_email'] : 1,
);

include __DIR__ . '/inc/layout_top.php';
?>

<div class="card" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
  <a class="btn secondary" href="panel_admin.php">Volver</a>
  <h2 style="margin:0;">Checkout free</h2>
  <span class="muted">Configuración mínima por evento.</span>
</div>

<?php if ($flashOk !== ''): ?><div class="flash ok"><?php echo e($flashOk); ?></div><?php endif; ?>
<?php if ($flashErr !== ''): ?><div class="flash err"><?php echo e($flashErr); ?></div><?php endif; ?>

<?php if ($eventoId <= 0): ?>
  <div class="card">
    <h3 style="margin-top:0;">Elegí un evento</h3>
    <?php if (empty($eventos)): ?>
      <div class="muted">No tenés eventos disponibles.</div>
    <?php else: ?>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:10px;">
        <?php foreach ($eventos as $ev): ?>
          <?php $cfg = tickex_free_checkout_load_config($pdo, (int)$ev['id']); ?>
          <div class="card" style="margin:0;">
            <div style="font-weight:700;"><?php echo e($ev['nombre']); ?></div>
            <div class="muted">Slug: <?php echo e($ev['slug']); ?></div>
            <div class="muted" style="margin-top:6px;">Estado free: <strong><?php echo ($cfg && !empty($cfg['enabled'])) ? 'habilitado' : 'deshabilitado'; ?></strong></div>
            <div style="margin-top:10px;"><a class="btn" href="access_links.php?evento_id=<?php echo (int)$ev['id']; ?>">Configurar checkout free</a></div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
<?php else: ?>
  <div class="card">
    <h3 style="margin-top:0;">Evento: <?php echo e(isset($currentEvent['nombre']) ? $currentEvent['nombre'] : ''); ?></h3>
    <div class="muted">Slug: <?php echo e(isset($currentEvent['slug']) ? $currentEvent['slug'] : ''); ?></div>
    <div class="muted" style="margin-top:6px;">Entradas gratuitas emitidas: <strong><?php echo (int)tickex_free_checkout_count_issued($pdo, $eventoId); ?></strong></div>
  </div>

  <div class="card">
    <h3 style="margin-top:0;">Configuración</h3>
    <form method="post" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:10px;align-items:end;">
      <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
      <input type="hidden" name="evento_id" value="<?php echo (int)$eventoId; ?>">

      <div style="grid-column:1/-1;display:flex;gap:16px;flex-wrap:wrap;">
        <label><input type="checkbox" name="enabled" value="1" <?php echo !empty($f['enabled']) ? 'checked' : ''; ?>> habilitado</label>
        <label><input type="checkbox" name="captcha_required" value="1" <?php echo !empty($f['captcha_required']) ? 'checked' : ''; ?>> captcha obligatorio</label>
        <label><input type="checkbox" name="unique_email" value="1" <?php echo !empty($f['unique_email']) ? 'checked' : ''; ?>> email único</label>
      </div>

      <div>
        <label>Entrada del evento</label>
        <select name="ticket_type_id" required>
          <option value="">Seleccionar...</option>
          <?php foreach ($ticketTypes as $tp): ?>
            <option value="<?php echo (int)$tp['id']; ?>" <?php echo (int)$f['ticket_type_id'] === (int)$tp['id'] ? 'selected' : ''; ?>><?php echo e($tp['nombre']); ?> (disp: <?php echo (int)$tp['cantidad_disponible']; ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label>Cupo máximo</label>
        <input type="number" min="1" name="max_uses" value="<?php echo e($f['max_uses']); ?>">
      </div>

      <div style="grid-column:1/-1;display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
        <button class="btn" type="submit">Guardar checkout free</button>
        <?php if (!empty($currentEvent['slug'])): ?>
          <span class="muted">URL pública: <a href="acceso.php?slug=<?php echo urlencode($currentEvent['slug']); ?>" target="_blank">acceso.php?slug=<?php echo e($currentEvent['slug']); ?></a></span>
        <?php endif; ?>
      </div>
    </form>
  </div>
<?php endif; ?>

<?php include __DIR__ . '/inc/layout_bottom.php'; ?>
