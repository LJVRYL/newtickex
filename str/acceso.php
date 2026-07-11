<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/access_links.php';
require_once __DIR__ . '/inc/turnstile.php';

$pdo = db();
$title = 'Acceso al evento';

$publicId = '';
if (isset($_GET['code']) && trim((string)$_GET['code']) !== '') {
    $publicId = trim((string)$_GET['code']);
} elseif (isset($_GET['u']) && trim((string)$_GET['u']) !== '') {
    $publicId = trim((string)$_GET['u']);
}

$link = tickex_access_find_by_public($pdo, $publicId);
if (!$link) {
    http_response_code(404);
    include __DIR__ . '/inc/layout_top.php';
    echo '<div class="card" style="max-width:700px;margin:32px auto;"><h2>Link no válido</h2><p>Este link de acceso no existe.</p></div>';
    include __DIR__ . '/inc/layout_bottom.php';
    exit;
}

$effectiveStatus = tickex_access_effective_status($link);
$errors = array();
$success = false;
$ticketUrl = '';
$traceId = tickex_access_trace_id();

$data = array(
    'first_name' => '',
    'last_name' => '',
    'email' => '',
    'dni' => '',
    'phone' => '',
);

$csrf = function_exists('tickex_csrf_token') ? (string)tickex_csrf_token() : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data['first_name'] = trim((string)(isset($_POST['first_name']) ? $_POST['first_name'] : ''));
    $data['last_name'] = trim((string)(isset($_POST['last_name']) ? $_POST['last_name'] : ''));
    $data['email'] = trim((string)(isset($_POST['email']) ? $_POST['email'] : ''));
    $data['dni'] = trim((string)(isset($_POST['dni']) ? $_POST['dni'] : ''));
    $data['phone'] = trim((string)(isset($_POST['phone']) ? $_POST['phone'] : ''));

    $provided = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
    if (function_exists('tickex_csrf_verify') && !tickex_csrf_verify($provided)) {
        $errors[] = 'Sesión expirada. Recargá e intentá nuevamente.';
    }

    $postCode = isset($_POST['code']) ? trim((string)$_POST['code']) : '';
    if ($postCode === '' || $postCode !== (string)$link['code']) {
        $errors[] = 'Link inválido.';
    }

    $effectiveStatus = tickex_access_effective_status($link);
    if ($effectiveStatus !== 'active') {
        $errors[] = 'Este link no está disponible en este momento.';
    }

    if ($data['first_name'] === '') $errors[] = 'Nombre obligatorio.';
    if ($data['last_name'] === '') $errors[] = 'Apellido obligatorio.';
    if ($data['email'] === '' || !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Email inválido.';
    $dniNorm = tickex_access_normalize_dni($data['dni']);
    if ($dniNorm === '') $errors[] = 'DNI obligatorio.';

    $captchaOk = 1;
    if ((int)$link['captcha_required'] === 1) {
        if (!tickex_turnstile_enabled()) {
            $captchaOk = 0;
            $errors[] = 'Captcha no disponible temporalmente.';
        } else {
            $tok = isset($_POST['cf-turnstile-response']) ? (string)$_POST['cf-turnstile-response'] : '';
            list($okCap, $msgCap) = tickex_turnstile_verify_token($tok, tickex_access_client_ip());
            if (!$okCap) {
                $captchaOk = 0;
                $errors[] = (string)$msgCap;
            }
        }
    }

    if (empty($errors)) {
        $result = tickex_access_issue_entry($pdo, $link, $data, array(
            'trace_id' => $traceId,
            'captcha_ok' => $captchaOk,
            'ip' => tickex_access_client_ip(),
            'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? (string)$_SERVER['HTTP_USER_AGENT'] : '',
            'issued_by' => 'public',
        ));

        if (!empty($result['ok'])) {
            $success = true;
            $ticketUrl = isset($result['ticket_url']) ? (string)$result['ticket_url'] : '';
            $traceId = isset($result['trace_id']) ? (string)$result['trace_id'] : $traceId;
        } else {
            $errors[] = isset($result['error']) ? (string)$result['error'] : 'No se pudo emitir la entrada.';
            $traceId = isset($result['trace_id']) ? (string)$result['trace_id'] : $traceId;
        }
    } else {
        tickex_access_log_attempt($pdo, array(
            'trace_id' => $traceId,
            'access_link_id' => (int)$link['id'],
            'evento_id' => (int)$link['evento_id'],
            'ip_address' => tickex_access_client_ip(),
            'email_normalized' => tickex_access_normalize_email($data['email']),
            'dni_normalized' => tickex_access_normalize_dni($data['dni']),
            'captcha_ok' => ((int)$link['captcha_required'] === 1 ? (tickex_turnstile_enabled() ? 1 : 0) : 1),
            'result' => 'validation_error',
            'detail' => implode(' | ', $errors),
        ));
    }
}

$usedCount = tickex_access_used_count($pdo, (int)$link['id']);
$maxUses = (isset($link['max_uses']) && $link['max_uses'] !== null && $link['max_uses'] !== '') ? (int)$link['max_uses'] : 0;

include __DIR__ . '/inc/layout_top.php';
?>

<div class="card" style="max-width:720px;margin:24px auto;">
  <h2 style="margin-top:0;"><?php echo e($link['evento_nombre'] !== '' ? $link['evento_nombre'] : 'Evento'); ?></h2>
  <div class="muted">Acceso: <strong><?php echo e($link['label']); ?></strong> · Tipo: <strong><?php echo e($link['access_type']); ?></strong></div>
  <div class="muted" style="margin-top:6px;">Estado: <strong><?php echo e($effectiveStatus); ?></strong> · Usados: <strong><?php echo (int)$usedCount; ?></strong><?php if ($maxUses > 0): ?> / <?php echo (int)$maxUses; ?><?php endif; ?></div>
</div>

<?php if ($success): ?>
  <div class="card" style="max-width:720px;margin:0 auto;">
    <h3 style="margin-top:0;">Acceso confirmado</h3>
    <p>Ya emitimos tu entrada. Te enviamos el email con el QR.</p>
    <?php if ($ticketUrl !== ''): ?>
      <p><a class="btn" href="<?php echo e($ticketUrl); ?>" target="_blank">Ver mi ticket</a></p>
    <?php endif; ?>
    <div class="muted">trace_id: <?php echo e($traceId); ?></div>
  </div>
<?php else: ?>
  <?php if (!empty($errors)): ?>
    <div class="card" style="max-width:720px;margin:0 auto 12px auto;border:1px solid rgba(255,0,0,.25);">
      <h3 style="margin-top:0;">No se pudo confirmar</h3>
      <ul>
        <?php foreach ($errors as $er): ?><li><?php echo e($er); ?></li><?php endforeach; ?>
      </ul>
      <div class="muted">trace_id: <?php echo e($traceId); ?></div>
    </div>
  <?php endif; ?>

  <div class="card" style="max-width:720px;margin:0 auto;">
    <h3 style="margin-top:0;">Completar datos</h3>
    <form method="post" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;">
      <input type="hidden" name="csrf" value="<?php echo e($csrf); ?>">
      <input type="hidden" name="code" value="<?php echo e($link['code']); ?>">

      <div>
        <label>Nombre</label>
        <input name="first_name" required value="<?php echo e($data['first_name']); ?>">
      </div>
      <div>
        <label>Apellido</label>
        <input name="last_name" required value="<?php echo e($data['last_name']); ?>">
      </div>
      <div>
        <label>Email</label>
        <input type="email" name="email" required value="<?php echo e($data['email']); ?>">
      </div>
      <div>
        <label>DNI</label>
        <input name="dni" required value="<?php echo e($data['dni']); ?>">
      </div>
      <div>
        <label>Teléfono (opcional)</label>
        <input name="phone" value="<?php echo e($data['phone']); ?>">
      </div>

      <?php if ((int)$link['captcha_required'] === 1): ?>
        <div style="grid-column:1/-1;">
          <?php tickex_turnstile_widget(array('theme' => 'auto', 'size' => 'normal')); ?>
        </div>
      <?php endif; ?>

      <div style="grid-column:1/-1;display:flex;gap:8px;align-items:center;">
        <button class="btn" type="submit">Confirmar acceso</button>
      </div>
    </form>
  </div>
<?php endif; ?>

<?php include __DIR__ . '/inc/layout_bottom.php'; ?>
