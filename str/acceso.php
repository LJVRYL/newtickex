<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/free_checkout.php';
require_once __DIR__ . '/inc/turnstile.php';

$pdo = db();
tickex_free_checkout_ensure_schema($pdo);
$title = 'Checkout free';

$slug = isset($_GET['slug']) ? trim((string)$_GET['slug']) : '';
$eventoId = isset($_GET['evento_id']) ? (int)$_GET['evento_id'] : 0;
$evento = tickex_free_checkout_find_event($pdo, $eventoId, $slug);
if (!$evento) {
    http_response_code(404);
    include __DIR__ . '/inc/layout_top.php';
    echo '<div class="card" style="max-width:700px;margin:32px auto;"><h2>Evento no encontrado</h2></div>';
    include __DIR__ . '/inc/layout_bottom.php';
    exit;
}

$config = tickex_free_checkout_load_config($pdo, (int)$evento['id']);
if (!$config || empty($config['enabled'])) {
    include __DIR__ . '/inc/layout_top.php';
    echo '<div class="card" style="max-width:700px;margin:32px auto;"><h2>Checkout free no disponible</h2><p>Este evento no tiene habilitado el checkout gratuito.</p></div>';
    include __DIR__ . '/inc/layout_bottom.php';
    exit;
}

$errors = array();
$success = false;
$ticketUrl = '';
$data = array('nombre' => '', 'apellido' => '', 'email' => '');
$csrf = function_exists('tickex_csrf_token') ? (string)tickex_csrf_token() : '';

$flyerUrl = '';
if (!empty($evento['flyer_filename'])) {
  $ff = (string)$evento['flyer_filename'];
  if (@file_exists(__DIR__ . '/' . $ff)) {
    $flyerUrl = $ff;
  }
}
if ($flyerUrl === '' && !empty($evento['flyer'])) {
  $flyerUrl = (string)$evento['flyer'];
}

$issuedCount = (int)tickex_free_checkout_count_issued($pdo, (int)$evento['id']);
$availableCount = null;
if (isset($config['cantidad_disponible']) && $config['cantidad_disponible'] !== null && $config['cantidad_disponible'] !== '') {
  $availableCount = (int)$config['cantidad_disponible'];
} elseif (isset($config['cantidad_total']) && $config['cantidad_total'] !== null && $config['cantidad_total'] !== '') {
  $availableCount = (int)$config['cantidad_total'];
}
if ($config['max_uses'] !== null && $config['max_uses'] !== '') {
  $remainingByMax = max(0, (int)$config['max_uses'] - $issuedCount);
  if ($availableCount === null) {
    $availableCount = $remainingByMax;
  } else {
    $availableCount = min($availableCount, $remainingByMax);
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $provided = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
    if (function_exists('tickex_csrf_verify') && !tickex_csrf_verify($provided)) {
        $errors[] = 'Sesión expirada. Recargá la página.';
    }

    foreach ($data as $k => $v) {
        $data[$k] = isset($_POST[$k]) ? trim((string)$_POST[$k]) : '';
    }

    if ($data['nombre'] === '') $errors[] = 'Nombre obligatorio.';
    if ($data['email'] === '' || !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Email inválido.';

    if (empty($errors) && !empty($config['captcha_required'])) {
        if (!tickex_turnstile_enabled()) {
            $errors[] = 'Captcha no disponible temporalmente.';
        } else {
            $tok = isset($_POST['cf-turnstile-response']) ? (string)$_POST['cf-turnstile-response'] : '';
            list($okCap, $msgCap) = tickex_turnstile_verify_token($tok, isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '');
            if (!$okCap) $errors[] = (string)$msgCap;
        }
    }

    if (empty($errors)) {
        $result = tickex_free_checkout_issue($pdo, $config, $data);
        if (!empty($result['ok'])) {
            $success = true;
            $ticketUrl = isset($result['ticket_url']) ? (string)$result['ticket_url'] : '';
        } else {
            $errors[] = isset($result['error']) ? (string)$result['error'] : 'No se pudo emitir la entrada.';
        }
    }
}

include __DIR__ . '/inc/layout_top.php';
?>

<div class="card" style="max-width:900px;margin:24px auto;">
  <div style="display:grid;grid-template-columns:320px 1fr;gap:18px;align-items:start;">
    <div>
      <?php if ($flyerUrl !== ''): ?>
        <img src="<?php echo e($flyerUrl); ?>" alt="Flyer" style="width:100%;border-radius:12px;display:block;border:1px solid var(--line);background:#000;object-fit:cover;">
      <?php else: ?>
        <div style="border:1px solid var(--line);border-radius:12px;min-height:320px;display:flex;align-items:center;justify-content:center;background:var(--panel-2);" class="muted">Sin flyer</div>
      <?php endif; ?>
    </div>
    <div>
      <h2 style="margin-top:0;"><?php echo e($evento['nombre']); ?></h2>
      <div class="muted">Checkout free</div>
      <?php if ($availableCount !== null): ?>
        <div class="muted" style="margin-top:6px;">Entradas disponibles: <strong><?php echo (int)$availableCount; ?></strong></div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if ($success): ?>
  <div class="card" style="max-width:720px;margin:0 auto;">
    <h3 style="margin-top:0;">Entrada emitida</h3>
    <p>Ya te enviamos el QR por email.</p>
    <?php if ($ticketUrl !== ''): ?><p><a class="btn" href="<?php echo e($ticketUrl); ?>" target="_blank">Ver ticket</a></p><?php endif; ?>
  </div>
<?php else: ?>
  <?php if (!empty($errors)): ?>
    <div class="card" style="max-width:720px;margin:0 auto 12px auto;border:1px solid rgba(255,0,0,.25);">
      <h3 style="margin-top:0;">No se pudo emitir</h3>
      <ul><?php foreach ($errors as $er): ?><li><?php echo e($er); ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>

  <div class="card" style="max-width:720px;margin:0 auto;">
    <h3 style="margin-top:0;">Completar datos</h3>
    <form method="post" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;">
      <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
      <div>
        <label>Nombre</label>
        <input name="nombre" required value="<?php echo e($data['nombre']); ?>">
      </div>
      <div>
        <label>Apellido</label>
        <input name="apellido" value="<?php echo e($data['apellido']); ?>">
      </div>
      <div>
        <label>Email</label>
        <input type="email" name="email" required value="<?php echo e($data['email']); ?>">
      </div>
      <?php if (!empty($config['captcha_required'])): ?>
        <div style="grid-column:1/-1;"><?php tickex_turnstile_widget(array('theme' => 'auto', 'size' => 'normal')); ?></div>
      <?php endif; ?>
      <div style="grid-column:1/-1;"><button class="btn" type="submit">Emitir entrada gratuita</button></div>
    </form>
  </div>
<?php endif; ?>

<?php include __DIR__ . '/inc/layout_bottom.php'; ?>
