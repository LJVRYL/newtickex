<?php
require_once __DIR__ . '/inc/bootstrap.php';

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

$token = isset($_GET['t']) ? trim((string)$_GET['t']) : '';
$title = 'Mensaje';

$pdo = db();
ensure_email_share_tokens($pdo);

$emailRow = null;
if ($token !== '' && preg_match('/^[a-f0-9]{20,64}$/i', $token)) {
    $st = $pdo->prepare("SELECT l.*
        FROM email_share_tokens t
        JOIN email_logs l ON l.id = t.email_log_id
        WHERE t.token = :t
          AND (t.expires_at IS NULL OR t.expires_at > datetime('now'))
        LIMIT 1");
    $st->execute(array(':t' => $token));
    $emailRow = $st->fetch(PDO::FETCH_ASSOC);
}

include __DIR__ . '/inc/layout_top.php';
?>

<div class="card" style="max-width:760px;margin:16px auto;">
  <?php if (!$emailRow): ?>
    <h2 style="margin-top:0;">Enlace inválido</h2>
    <p class="muted">Este link no existe o ya expiró.</p>
  <?php else: ?>
    <h2 style="margin-top:0;"><?php echo e((string)$emailRow['subject']); ?></h2>
    <div class="muted" style="font-size:13px;">Enviado: <?php echo e((string)$emailRow['created_at']); ?></div>

    <?php
      $body = (string)$emailRow['body'];
      $foundUrl = '';
      if (preg_match('~https?://[^\s<>"]+~', $body, $m)) {
        $foundUrl = (string)$m[0];
      }
    ?>

    <?php if ($foundUrl !== ''): ?>
      <div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
        <a class="btn" href="<?php echo e($foundUrl); ?>" target="_blank" rel="noopener">Abrir link</a>
        <button class="btn secondary" type="button" onclick="(function(u){ if(!u) return; if(navigator.clipboard){navigator.clipboard.writeText(u).then(function(){alert('Link copiado');}).catch(function(){});} else {prompt('Copiar link:', u);} })(this.getAttribute('data-url'));" data-url="<?php echo e($foundUrl); ?>">Copiar link</button>
      </div>
    <?php endif; ?>

    <div style="margin-top:14px;">
      <pre style="white-space:pre-wrap;word-break:break-word;background:rgba(255,255,255,0.04);padding:12px;border-radius:10px;"><?php echo e($body); ?></pre>
    </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/inc/layout_bottom.php'; ?>
