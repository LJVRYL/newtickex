<?php
require_once __DIR__.'/inc/bootstrap.php';
require_once __DIR__.'/inc/flash.php';
require_once __DIR__.'/inc/mail.php';

function ensure_registro_pendientes($pdo)
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS registro_pendientes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        email TEXT NOT NULL,
        token TEXT NOT NULL,
        nombre TEXT,
        apellido TEXT,
        apodo TEXT,
        dni TEXT,
        genero TEXT,
        foto_path TEXT,
        next_url TEXT,
        creado_en TEXT,
        completado_en TEXT
    )");
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_regpend_token ON registro_pendientes(token)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_regpend_email ON registro_pendientes(email)");
}

function enviar_mail_confirmacion_step1($email, $token, $registroId = null)
{
    $fromEmail = 'no-reply@tickex.com.ar';
    $fromName  = 'Tickex';
    $from      = $fromName . ' <' . $fromEmail . '>';

    $link    = 'https://str.tickex.com.ar/completar_registro.php?token=' . urlencode($token);
    $subject = 'Confirmá tu email en Tickex';

    $body  = "Hola,\n\n";
    $body .= "Para continuar tu registro en Tickex, hacé clic en este enlace:\n";
    $body .= $link . "\n\n";
    $body .= "Si no fuiste vos, podés ignorar este mensaje.\n\n";
    $body .= "Tickex\n";

    $extraParams = '-f ' . $fromEmail;

    return tickex_send_mail_template($email, 'registro_pendiente_step1', array(
      'link' => $link,
    ), array(
      'context'       => 'registro_pendiente_step1',
      'related_table' => 'registro_pendientes',
      'related_id'    => $registroId,
    ), array(
      'subject'      => $subject,
      'body'         => $body,
      'from_email'   => $fromEmail,
      'from_name'    => $fromName,
      'reply_to'     => $fromEmail,
      'extra_params' => $extraParams,
      'is_html'      => 0,
    ));
}

require_login();
$cu = current_user();
$rol = isset($cu['tipo_global']) ? $cu['tipo_global'] : (isset($cu['rol']) ? $cu['rol'] : '');
if (!in_array($rol, array('super_admin','superadmin'), true)) {
    http_response_code(403);
    include __DIR__.'/inc/layout_top.php';
    echo "<div class='card'><h3>Acceso restringido</h3><p>Solo superadmin.</p></div>";
    include __DIR__.'/inc/layout_bottom.php';
    exit;
}

$pdo = db();
ensure_registro_pendientes($pdo);

$q = isset($_GET['q']) ? trim($_GET['q']) : '';
$flashMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'resend') {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    if ($id > 0) {
        $st = $pdo->prepare('SELECT * FROM registro_pendientes WHERE id = :id LIMIT 1');
        $st->execute(array(':id' => $id));
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            // Siempre regeneramos token para seguridad
            if (function_exists('random_bytes')) {
                $token = bin2hex(random_bytes(16));
            } else {
                $token = sha1(uniqid(mt_rand(), true));
            }
            $ahora = date('Y-m-d H:i:s');
            $pdo->prepare('UPDATE registro_pendientes SET token=:t, creado_en=:c, completado_en=NULL WHERE id=:id')
                ->execute(array(':t'=>$token, ':c'=>$ahora, ':id'=>$id));

            $mailOk = enviar_mail_confirmacion_step1($row['email'], $token, (int)$row['id']);
            $flashMsg = 'Token regenerado y reenviado a ' . e($row['email']) . ($mailOk ? '' : ' (mail() devolvió false)');
            $_SESSION['flash_ok'] = $flashMsg;
        }
    }
    header('Location: registro_pendientes_admin.php' . ($q!=='' ? ('?q='.urlencode($q)) : ''));
    exit;
}

$sql = 'SELECT * FROM registro_pendientes';
$params = array();
if ($q !== '') {
    $sql .= ' WHERE email LIKE :q';
    $params[':q'] = '%' . $q . '%';
}
$sql .= ' ORDER BY id DESC LIMIT 200';
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

include __DIR__.'/inc/layout_top.php';
?>
<div class="card" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
  <a class="btn secondary" href="panel_admin.php">⬅ Volver</a>
  <h2 style="margin:0;">Registros pendientes</h2>
  <span class="muted">Visible solo para superadmin</span>
</div>

<div class="card" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
  <form method="get" style="display:flex;gap:8px;align-items:center;">
    <input name="q" placeholder="Buscar email" value="<?php echo e($q); ?>">
    <button class="btn secondary" type="submit">Buscar</button>
    <?php if ($q !== ''): ?><a class="btn secondary" href="registro_pendientes_admin.php">Limpiar</a><?php endif; ?>
  </form>
</div>

<?php if (isset($_SESSION['flash_ok'])): ?>
  <div class="flash ok"><?php echo e($_SESSION['flash_ok']); ?></div>
  <?php unset($_SESSION['flash_ok']); ?>
<?php endif; ?>

<div class="card" style="overflow:auto;">
  <table class="table">
    <thead>
      <tr>
        <th>ID</th>
        <th>Email</th>
        <th>Token</th>
        <th>Creado</th>
        <th>Completado</th>
        <th>Next</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($rows)): ?>
        <tr><td colspan="7" class="muted">Sin registros.</td></tr>
      <?php else: ?>
        <?php foreach ($rows as $r): ?>
          <?php $link = 'https://str.tickex.com.ar/completar_registro.php?token=' . urlencode($r['token']); ?>
          <tr>
            <td><?php echo (int)$r['id']; ?></td>
            <td><?php echo e($r['email']); ?></td>
            <td style="max-width:260px;word-break:break-all;"><a class="link" href="<?php echo e($link); ?>" target="_blank" rel="noopener noreferrer"><?php echo e($r['token']); ?></a></td>
            <td><?php echo e($r['creado_en']); ?></td>
            <td><?php echo $r['completado_en'] ? e($r['completado_en']) : '<span class="muted">pendiente</span>'; ?></td>
            <td style="max-width:180px;word-break:break-all;">&nbsp;<?php echo $r['next_url'] ? e($r['next_url']) : ''; ?></td>
            <td>
              <form method="post" style="display:flex;gap:6px;align-items:center;">
                <input type="hidden" name="action" value="resend">
                <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                <button class="btn secondary" type="submit">Reenviar token</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<?php include __DIR__.'/inc/layout_bottom.php'; ?>
