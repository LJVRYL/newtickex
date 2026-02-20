<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/mail.php';

require_login();
$cu = current_user();
$rol = isset($cu['tipo_global']) ? $cu['tipo_global'] : (isset($cu['rol']) ? $cu['rol'] : '');
if (!in_array($rol, array('super_admin','superadmin'), true)) {
    http_response_code(403);
    include __DIR__ . '/inc/layout_top.php';
    echo "<div class='card'><h3>Acceso restringido</h3><p>Solo superadmin.</p></div>";
    include __DIR__ . '/inc/layout_bottom.php';
    exit;
}

$pdo = db();

$q = isset($_GET['q']) ? trim($_GET['q']) : '';
$from = isset($_GET['from']) ? trim($_GET['from']) : '';
$viewId = isset($_GET['view_id']) ? (int)$_GET['view_id'] : 0;

$flashOk = '';
$flashErr = '';

function ensure_email_share_tokens($pdo)
{
  $pdo->exec("CREATE TABLE IF NOT EXISTS email_share_tokens (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email_log_id INTEGER NOT NULL,
    token TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    expires_at TEXT,
    created_by_admin_id INTEGER,
    UNIQUE(token)
  )");
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_email_share_tokens_log ON email_share_tokens(email_log_id)");
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_email_share_tokens_exp ON email_share_tokens(expires_at)");
}

function tickex_make_share_token()
{
  if (function_exists('random_bytes')) {
    return bin2hex(random_bytes(16));
  }
  return sha1(uniqid(mt_rand(), true));
}

function tickex_guess_base_url_here()
{
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
  if ($host === '') {
    return '';
  }
  $scriptName = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '/';
  $basePath = rtrim(dirname($scriptName), '/\\');
  if ($basePath === '' || $basePath === '.') {
    $basePath = '';
  }
  return $scheme . '://' . $host . $basePath;
}

function tickex_whatsapp_link($phone, $text)
{
  $digits = preg_replace('/[^0-9]/', '', (string)$phone);
  if ($digits === '') return '';
  // wa.me requiere número en formato internacional sin +
  return 'https://wa.me/' . $digits . '?text=' . rawurlencode((string)$text);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
  $action = (string)$_POST['action'];

  if ($action === 'resend' || $action === 'resend_noreply') {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    if ($id > 0) {
      $st = $pdo->prepare('SELECT * FROM email_logs WHERE id = :id LIMIT 1');
      $st->execute(array(':id' => $id));
      $row = $st->fetch(PDO::FETCH_ASSOC);

      if ($row) {
        if ($action === 'resend_noreply') {
          $fromEmail = 'servicio@tickex.com.ar';
          $fromName  = 'Tickex';
          $replyTo   = $fromEmail;
          $extra     = '-f' . $fromEmail;
          $ctxSuf    = '_resend_noreply';
        } else {
          $fromEmail = !empty($row['from_email']) ? $row['from_email'] : 'servicio@tickex.com.ar';
          $fromName  = !empty($row['from_name']) ? $row['from_name'] : 'Tickex';
          $replyTo   = !empty($row['reply_to']) ? $row['reply_to'] : $fromEmail;
          $extra     = !empty($row['extra_params']) ? $row['extra_params'] : '';
          $ctxSuf    = '_resend';
        }

        $ok = tickex_send_mail($row['to_email'], $row['subject'], $row['body'], array(
          'from_email'    => $fromEmail,
          'from_name'     => $fromName,
          'reply_to'      => $replyTo,
          'extra_params'  => $extra,
          'context'       => ($row['context'] ? ($row['context'] . $ctxSuf) : ltrim($ctxSuf, '_')),
          'related_table' => $row['related_table'],
          'related_id'    => $row['related_id'],
          'resend_of_id'  => (int)$row['id'],
        ));

        $label = ($action === 'resend_noreply') ? 'Reenvío (servicio)' : 'Reenvío';
        $flashOk = $label . ' ' . ($ok ? 'OK' : 'con fallas') . ' a ' . e($row['to_email']) . ' (log #' . (int)$row['id'] . ')';
      } else {
        $flashErr = 'No se encontró el email log solicitado.';
      }
    }
  }
}

$where = array();
$params = array();

if ($from !== '') {
    $where[] = 'l.from_email = :from';
    $params[':from'] = $from;
}

if ($q !== '') {
    $where[] = '(l.to_email LIKE :q OR l.subject LIKE :q OR l.context LIKE :q)';
    $params[':q'] = '%' . $q . '%';
}

// Join a "usuario" dataset if possible to infer validation status.
// In some DBs, usuarios_admin does NOT have email_confirmado; the usuarios VIEW usually does.
$hasUsuariosEmail = false;
$hasUsuariosValid = false;
$hasUsuariosRol   = false;
$hasUsuariosId    = false;
try {
  $colsU = $pdo->query("PRAGMA table_info(usuarios)")->fetchAll(PDO::FETCH_ASSOC);
  foreach ($colsU as $c) {
    $n = isset($c['name']) ? $c['name'] : '';
    if ($n === 'email') { $hasUsuariosEmail = true; }
    if ($n === 'email_confirmado') { $hasUsuariosValid = true; }
    if ($n === 'rol') { $hasUsuariosRol = true; }
    if ($n === 'id') { $hasUsuariosId = true; }
  }
} catch (Exception $e) {
  // ignore
}

$selectUserId    = $hasUsuariosId ? 'u.id' : 'NULL';
$selectUserValid = $hasUsuariosValid ? 'u.email_confirmado' : 'NULL';
$selectUserRol   = $hasUsuariosRol ? 'u.rol' : 'NULL';

$sql = "SELECT l.*, $selectUserId AS usuario_id, $selectUserValid AS usuario_email_confirmado, $selectUserRol AS usuario_rol\n";
$sql .= "FROM email_logs l\n";
if ($hasUsuariosEmail) {
  $sql .= "LEFT JOIN usuarios u ON u.email = l.to_email\n";
}
if (!empty($where)) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY l.id DESC LIMIT 200';

$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$viewRow = null;
$shareUrl = '';
$shareToken = '';
$waPhone = isset($_GET['wa']) ? trim((string)$_GET['wa']) : '';
$shareText = '';
if ($viewId > 0) {
    $stV = $pdo->prepare('SELECT * FROM email_logs WHERE id = :id LIMIT 1');
    $stV->execute(array(':id' => $viewId));
    $viewRow = $stV->fetch(PDO::FETCH_ASSOC);

  // token existente (si hay y no expiró)
  try {
    ensure_email_share_tokens($pdo);
    $stTok = $pdo->prepare("SELECT token FROM email_share_tokens WHERE email_log_id = :id AND (expires_at IS NULL OR expires_at > datetime('now')) ORDER BY id DESC LIMIT 1");
    $stTok->execute(array(':id' => $viewId));
    $tok = (string)$stTok->fetchColumn();
    if ($tok !== '') {
      $shareToken = $tok;
    }
  } catch (Exception $e) {
    // ignore
  }
}

// Generar link compartible para el viewRow
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'share_link') {
  $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
  if ($id > 0) {
    try {
      ensure_email_share_tokens($pdo);

      // Reusar token vigente si existe
      $stTok = $pdo->prepare("SELECT token FROM email_share_tokens WHERE email_log_id = :id AND (expires_at IS NULL OR expires_at > datetime('now')) ORDER BY id DESC LIMIT 1");
      $stTok->execute(array(':id' => $id));
      $tok = (string)$stTok->fetchColumn();
      if ($tok === '') {
        $tok = tickex_make_share_token();
        $adminId = 0;
        if (isset($_SESSION['admin_id'])) $adminId = (int)$_SESSION['admin_id'];
        elseif (isset($_SESSION['user_id'])) $adminId = (int)$_SESSION['user_id'];

        $ins = $pdo->prepare("INSERT INTO email_share_tokens (email_log_id, token, expires_at, created_by_admin_id) VALUES (:id,:t, datetime('now','+7 days'), :aid)");
        $ins->execute(array(':id' => $id, ':t' => $tok, ':aid' => $adminId > 0 ? $adminId : null));
      }

      // PRG
      $qs = array('from' => $from, 'q' => $q, 'view_id' => $id);
      if ($waPhone !== '') $qs['wa'] = $waPhone;
      header('Location: superadmin_emails.php?' . http_build_query($qs));
      exit;
    } catch (Exception $e) {
      $flashErr = 'No se pudo generar el link para compartir.';
    }
  }
}

if ($shareToken !== '') {
  $base = tickex_guess_base_url_here();
  if ($base !== '') {
    $shareUrl = $base . '/email_share.php?t=' . urlencode($shareToken);
  }
}

if ($viewRow) {
  $subj = isset($viewRow['subject']) ? (string)$viewRow['subject'] : '';
  $body = isset($viewRow['body']) ? (string)$viewRow['body'] : '';
  $shareText = trim(($subj !== '' ? ($subj . "\n\n") : '') . $body);
}

$title = 'Emails';
include __DIR__ . '/inc/layout_top.php';
?>

<div class="card" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
  <a class="btn secondary" href="panel_admin.php">⬅ Volver</a>
  <h2 style="margin:0;">Emails salientes</h2>
  <span class="muted">Log + reenvío (solo superadmin)</span>
  <div style="flex:1 1 auto;"></div>
  <a class="btn secondary" href="superadmin_email_templates.php">Plantillas</a>
</div>

<?php if ($flashOk !== ''): ?>
  <div class="flash ok"><?php echo e($flashOk); ?></div>
<?php endif; ?>
<?php if ($flashErr !== ''): ?>
  <div class="flash err"><?php echo e($flashErr); ?></div>
<?php endif; ?>

<div class="card" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
  <form method="get" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
    <label class="muted" style="margin:0;">From</label>
    <select name="from">
      <option value="" <?php echo $from === '' ? 'selected' : ''; ?>>Todos</option>
      <option value="servicio@tickex.com.ar" <?php echo $from === 'servicio@tickex.com.ar' ? 'selected' : ''; ?>>servicio@tickex.com.ar</option>
      <option value="no-reply@tickex.com.ar" <?php echo $from === 'no-reply@tickex.com.ar' ? 'selected' : ''; ?>>no-reply@tickex.com.ar</option>
      <option value="info@tickex.com.ar" <?php echo $from === 'info@tickex.com.ar' ? 'selected' : ''; ?>>info@tickex.com.ar</option>
    </select>
    <input name="q" placeholder="Buscar (to / subject / context)" value="<?php echo e($q); ?>" style="min-width:260px;">
    <button class="btn secondary" type="submit">Buscar</button>
    <?php if ($q !== '' || $from !== ''): ?>
      <a class="btn secondary" href="superadmin_emails.php">Limpiar</a>
    <?php endif; ?>
  </form>
</div>

<?php if ($viewRow): ?>
  <div class="card" style="overflow:auto;">
    <h3 style="margin-top:0;">Detalle log #<?php echo (int)$viewRow['id']; ?></h3>
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:10px 0 6px 0;">
      <form method="post" style="margin:0;">
        <input type="hidden" name="action" value="share_link">
        <input type="hidden" name="id" value="<?php echo (int)$viewRow['id']; ?>">
        <button class="btn secondary" type="submit">Generar link</button>
      </form>
      <?php if ($shareText !== ''): ?>
        <button class="btn secondary" type="button" onclick="(function(){var t=document.getElementById('shareText').value||''; if(!t)return; if(navigator.share){navigator.share({title:'Tickex',text:t}).catch(function(){});} else if(navigator.clipboard){navigator.clipboard.writeText(t).then(function(){alert('Mensaje copiado');}).catch(function(){});} else {prompt('Copiar mensaje:', t);} })();">Compartir mensaje</button>
        <button class="btn secondary" type="button" onclick="(function(){var t=document.getElementById('shareText').value||''; if(!t)return; if(navigator.clipboard){navigator.clipboard.writeText(t).then(function(){alert('Mensaje copiado');}).catch(function(){prompt('Copiar mensaje:', t);});} else {prompt('Copiar mensaje:', t);} })();">Copiar mensaje</button>
      <?php endif; ?>

      <form method="post" style="margin:0;">
        <input type="hidden" name="action" value="resend">
        <input type="hidden" name="id" value="<?php echo (int)$viewRow['id']; ?>">
        <button class="btn secondary" type="submit">Reenviar</button>
      </form>
      <form method="post" style="margin:0;">
        <input type="hidden" name="action" value="resend_noreply">
        <input type="hidden" name="id" value="<?php echo (int)$viewRow['id']; ?>">
        <button class="btn secondary" type="submit">Reenviar (servicio)</button>
      </form>
    </div>

    <?php if ($shareText !== ''): ?>
      <div class="card" style="margin:10px 0 0 0;">
        <div class="muted" style="font-size:13px;margin-bottom:6px;">Mensaje (body) para enviar manualmente</div>
        <textarea id="shareText" style="width:100%;min-height:120px;" readonly><?php echo e($shareText); ?></textarea>
      </div>
    <?php endif; ?>

    <?php if ($shareText !== ''): ?>
      <div class="card" style="margin:10px 0 0 0;">
        <div class="muted" style="font-size:13px;margin-bottom:6px;">WhatsApp (abre WhatsApp con el link listo para enviar)</div>
        <form method="get" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:0;">
          <input type="hidden" name="from" value="<?php echo e($from); ?>">
          <input type="hidden" name="q" value="<?php echo e($q); ?>">
          <input type="hidden" name="view_id" value="<?php echo (int)$viewRow['id']; ?>">
          <input name="wa" placeholder="Teléfono (ej: 54911...)" value="<?php echo e($waPhone); ?>" style="min-width:220px;">
          <button class="btn secondary" type="submit">Usar número</button>
          <?php
            $waLink = '';
            if ($waPhone !== '') {
              $waLink = tickex_whatsapp_link($waPhone, $shareText);
            }
          ?>
          <?php if ($waLink !== ''): ?>
            <a class="btn" href="<?php echo e($waLink); ?>" target="_blank" rel="noopener">Abrir WhatsApp</a>
          <?php endif; ?>
        </form>
      </div>
    <?php endif; ?>

    <?php if ($shareUrl !== ''): ?>
      <div class="muted" style="font-size:12px;margin-top:10px;">Link opcional (para compartir solo el detalle): <a class="link" href="<?php echo e($shareUrl); ?>" target="_blank" rel="noopener"><?php echo e($shareUrl); ?></a></div>
    <?php endif; ?>

    <div class="muted" style="font-size:13px;">Enviado: <?php echo e($viewRow['created_at']); ?> — Resultado: <?php echo ((int)$viewRow['mail_ok'] === 1) ? '<strong>OK</strong>' : '<strong style="color:var(--danger)">FALLÓ</strong>'; ?></div>
    <div style="margin-top:10px;"><strong>To:</strong> <?php echo e($viewRow['to_email']); ?></div>
    <div><strong>From:</strong> <?php echo e($viewRow['from_name'] ? ($viewRow['from_name'] . ' <' . $viewRow['from_email'] . '>') : $viewRow['from_email']); ?></div>
    <div><strong>Subject:</strong> <?php echo e($viewRow['subject']); ?></div>
    <?php if (isset($viewRow['trace_id']) && (string)$viewRow['trace_id'] !== ''): ?>
      <div><strong>Trace:</strong> <?php echo e($viewRow['trace_id']); ?></div>
    <?php endif; ?>
    <div><strong>Context:</strong> <?php echo e($viewRow['context']); ?></div>
    <?php if (!empty($viewRow['error_text'])): ?>
      <div class="flash err" style="margin-top:10px;">Error: <?php echo e($viewRow['error_text']); ?></div>
    <?php endif; ?>
    <div style="margin-top:10px;">
      <strong>Body</strong>
      <pre style="white-space:pre-wrap;word-break:break-word;background:rgba(255,255,255,0.04);padding:10px;border-radius:10px;max-height:320px;overflow:auto;"><?php echo e($viewRow['body']); ?></pre>
    </div>
    <div style="margin-top:10px;">
      <strong>Headers</strong>
      <pre style="white-space:pre-wrap;word-break:break-word;background:rgba(255,255,255,0.04);padding:10px;border-radius:10px;max-height:220px;overflow:auto;"><?php echo e($viewRow['headers']); ?></pre>
    </div>
  </div>
<?php endif; ?>

<div class="card" style="overflow:auto;">
  <table class="table">
    <thead>
      <tr>
        <th>ID</th>
        <th>Fecha</th>
        <th>To</th>
        <th>From</th>
        <th>Subject</th>
        <th>Context</th>
        <th>OK</th>
        <th>Validado</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($rows)): ?>
        <tr><td colspan="9" class="muted">Sin emails para mostrar.</td></tr>
      <?php else: ?>
        <?php foreach ($rows as $r): ?>
          <?php
            $ok = ((int)$r['mail_ok'] === 1);
            $val = '';
            if (!isset($r['usuario_email_confirmado']) || $r['usuario_email_confirmado'] === null) {
              $val = '<span class="muted">—</span>';
            } else {
              $val = ((int)$r['usuario_email_confirmado'] === 1) ? '<strong>sí</strong>' : '<span style="color:var(--danger)">no</span>';
            }
          ?>
          <tr>
            <td>#<?php echo (int)$r['id']; ?></td>
            <td style="white-space:nowrap;" class="muted"><?php echo e($r['created_at']); ?></td>
            <td><?php echo e($r['to_email']); ?></td>
            <td class="muted"><?php echo e($r['from_email']); ?></td>
            <td style="max-width:360px;word-break:break-word;"><?php echo e($r['subject']); ?></td>
            <td class="muted"><?php echo e($r['context']); ?></td>
            <td><?php echo $ok ? '<strong>OK</strong>' : '<span style="color:var(--danger)">FALLÓ</span>'; ?></td>
            <td><?php echo $val; ?></td>
            <td style="white-space:nowrap;">
              <div style="display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end;">
                <a class="btn secondary" href="superadmin_emails.php?<?php echo http_build_query(array('from'=>$from,'q'=>$q,'view_id'=>(int)$r['id'])); ?>">Ver</a>
                <form method="post" style="margin:0;">
                  <input type="hidden" name="action" value="resend">
                  <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                  <button class="btn secondary" type="submit">Reenviar</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<?php include __DIR__ . '/inc/layout_bottom.php'; ?>
