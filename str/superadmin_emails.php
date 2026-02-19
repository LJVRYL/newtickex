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
$from = isset($_GET['from']) ? trim($_GET['from']) : 'no-reply@tickex.com.ar';
$viewId = isset($_GET['view_id']) ? (int)$_GET['view_id'] : 0;

$flashOk = '';
$flashErr = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'resend') {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    if ($id > 0) {
        $st = $pdo->prepare('SELECT * FROM email_logs WHERE id = :id LIMIT 1');
        $st->execute(array(':id' => $id));
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $fromEmail = !empty($row['from_email']) ? $row['from_email'] : 'no-reply@tickex.com.ar';
            $fromName  = !empty($row['from_name']) ? $row['from_name'] : 'Tickex';
            $replyTo   = !empty($row['reply_to']) ? $row['reply_to'] : $fromEmail;
            $extra     = !empty($row['extra_params']) ? $row['extra_params'] : '';

            $ok = tickex_send_mail($row['to_email'], $row['subject'], $row['body'], array(
                'from_email'    => $fromEmail,
                'from_name'     => $fromName,
                'reply_to'      => $replyTo,
                'extra_params'  => $extra,
                'context'       => ($row['context'] ? ($row['context'] . '_resend') : 'resend'),
                'related_table' => $row['related_table'],
                'related_id'    => $row['related_id'],
                'resend_of_id'  => (int)$row['id'],
            ));

            $flashOk = 'Reenvío ' . ($ok ? 'OK' : 'con fallas') . ' a ' . e($row['to_email']) . ' (log #' . (int)$row['id'] . ')';
        } else {
            $flashErr = 'No se encontró el email log solicitado.';
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
if ($viewId > 0) {
    $stV = $pdo->prepare('SELECT * FROM email_logs WHERE id = :id LIMIT 1');
    $stV->execute(array(':id' => $viewId));
    $viewRow = $stV->fetch(PDO::FETCH_ASSOC);
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
      <option value="no-reply@tickex.com.ar" <?php echo $from === 'no-reply@tickex.com.ar' ? 'selected' : ''; ?>>no-reply@tickex.com.ar</option>
      <option value="info@tickex.com.ar" <?php echo $from === 'info@tickex.com.ar' ? 'selected' : ''; ?>>info@tickex.com.ar</option>
    </select>
    <input name="q" placeholder="Buscar (to / subject / context)" value="<?php echo e($q); ?>" style="min-width:260px;">
    <button class="btn secondary" type="submit">Buscar</button>
    <?php if ($q !== '' || $from !== 'no-reply@tickex.com.ar'): ?>
      <a class="btn secondary" href="superadmin_emails.php">Limpiar</a>
    <?php endif; ?>
  </form>
</div>

<?php if ($viewRow): ?>
  <div class="card" style="overflow:auto;">
    <h3 style="margin-top:0;">Detalle log #<?php echo (int)$viewRow['id']; ?></h3>
    <div class="muted" style="font-size:13px;">Enviado: <?php echo e($viewRow['created_at']); ?> — Resultado: <?php echo ((int)$viewRow['mail_ok'] === 1) ? '<strong>OK</strong>' : '<strong style="color:var(--danger)">FALLÓ</strong>'; ?></div>
    <div style="margin-top:10px;"><strong>To:</strong> <?php echo e($viewRow['to_email']); ?></div>
    <div><strong>From:</strong> <?php echo e($viewRow['from_name'] ? ($viewRow['from_name'] . ' <' . $viewRow['from_email'] . '>') : $viewRow['from_email']); ?></div>
    <div><strong>Subject:</strong> <?php echo e($viewRow['subject']); ?></div>
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
              <a class="btn secondary" href="superadmin_emails.php?<?php echo http_build_query(array('from'=>$from,'q'=>$q,'view_id'=>(int)$r['id'])); ?>">Ver</a>
              <form method="post" style="display:inline;">
                <input type="hidden" name="action" value="resend">
                <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                <button class="btn secondary" type="submit">Reenviar</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<?php include __DIR__ . '/inc/layout_bottom.php'; ?>
