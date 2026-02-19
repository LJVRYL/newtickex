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
$editId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$flashOk = '';
$flashErr = '';

function load_template_by_id($pdo, $id)
{
    $st = $pdo->prepare('SELECT * FROM email_templates WHERE id = :id LIMIT 1');
    $st->execute(array(':id' => (int)$id));
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ? $row : null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    if ($action === 'create') {
        $context = isset($_POST['context']) ? trim($_POST['context']) : '';
        $name    = isset($_POST['name']) ? trim($_POST['name']) : '';

        if ($context === '') {
            $flashErr = 'El context es obligatorio.';
        } elseif (!preg_match('/^[a-z0-9_\-\.]{2,64}$/', $context)) {
            $flashErr = 'Context inválido. Usá letras minúsculas, números, guión, underscore o punto.';
        } else {
            try {
                $st = $pdo->prepare('INSERT INTO email_templates (context, name, enabled, is_html, subject, body, updated_at) VALUES (:c,:n,1,0,:s,:b, datetime("now"))');
                $st->execute(array(
                    ':c' => $context,
                    ':n' => $name !== '' ? $name : $context,
                    ':s' => '(Sin asunto)',
                    ':b' => '(Sin cuerpo)',
                ));
                $newId = (int)$pdo->lastInsertId();
                header('Location: superadmin_email_templates.php?id=' . $newId);
                exit;
            } catch (Exception $e) {
                $flashErr = 'No se pudo crear la plantilla (¿context duplicado?).';
            }
        }
    }

    if ($action === 'save') {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $tpl = $id > 0 ? load_template_by_id($pdo, $id) : null;
        if (!$tpl) {
            $flashErr = 'Plantilla no encontrada.';
        } else {
            $enabled = isset($_POST['enabled']) ? 1 : 0;
            $isHtml  = isset($_POST['is_html']) ? 1 : 0;

            $name       = isset($_POST['name']) ? trim($_POST['name']) : '';
            $fromEmail  = isset($_POST['from_email']) ? trim($_POST['from_email']) : '';
            $fromName   = isset($_POST['from_name']) ? trim($_POST['from_name']) : '';
            $replyTo    = isset($_POST['reply_to']) ? trim($_POST['reply_to']) : '';
            $extraParms = isset($_POST['extra_params']) ? trim($_POST['extra_params']) : '';
            $subject    = isset($_POST['subject']) ? trim($_POST['subject']) : '';
            $body       = isset($_POST['body']) ? (string)$_POST['body'] : '';

            if ($subject === '') {
                $flashErr = 'El asunto (subject) es obligatorio.';
            } elseif ($body === '') {
                $flashErr = 'El cuerpo (body) es obligatorio.';
            } else {
                try {
                    $st = $pdo->prepare('UPDATE email_templates SET name=:n, enabled=:en, is_html=:ih, from_email=:fe, from_name=:fn, reply_to=:rt, extra_params=:ep, subject=:s, body=:b, updated_at=datetime("now") WHERE id=:id');
                    $st->execute(array(
                        ':n'  => $name,
                        ':en' => $enabled,
                        ':ih' => $isHtml,
                        ':fe' => $fromEmail,
                        ':fn' => $fromName,
                        ':rt' => $replyTo,
                        ':ep' => $extraParms,
                        ':s'  => $subject,
                        ':b'  => $body,
                        ':id' => $id,
                    ));
                    $flashOk = 'Plantilla guardada.';
                    $editId = $id;
                } catch (Exception $e) {
                    $flashErr = 'No se pudo guardar la plantilla.';
                }
            }
        }
    }

    if ($action === 'send_test') {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $tpl = $id > 0 ? load_template_by_id($pdo, $id) : null;
        if (!$tpl) {
            $flashErr = 'Plantilla no encontrada.';
        } else {
            $to = isset($_POST['to_email']) ? trim($_POST['to_email']) : '';
            $varsJson = isset($_POST['vars_json']) ? trim($_POST['vars_json']) : '';

            if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
                $flashErr = 'Ingresá un email destino válido.';
            } else {
                $vars = array();
                if ($varsJson !== '') {
                    $decoded = json_decode($varsJson, true);
                    if (!is_array($decoded)) {
                        $flashErr = 'vars_json debe ser un JSON válido (objeto).';
                    } else {
                        $vars = $decoded;
                    }
                }

                if ($flashErr === '') {
                    $ok = tickex_send_mail_template($to, $tpl['context'], $vars, array(
                        'context' => $tpl['context'] . '_manual',
                    ), array(
                        'subject' => $tpl['subject'],
                        'body' => $tpl['body'],
                        'from_email' => $tpl['from_email'],
                        'from_name' => $tpl['from_name'],
                        'reply_to' => $tpl['reply_to'],
                        'extra_params' => $tpl['extra_params'],
                        'is_html' => $tpl['is_html'],
                    ));

                    $flashOk = 'Envío ' . ($ok ? 'OK' : 'con fallas') . ' a ' . e($to) . ' usando ' . e($tpl['context']);
                    $editId = $id;
                }
            }
        }
    }
}

// Listado
$sql = 'SELECT * FROM email_templates';
$params = array();
if ($q !== '') {
    $sql .= ' WHERE context LIKE :q OR name LIKE :q OR subject LIKE :q';
    $params[':q'] = '%' . $q . '%';
}
$sql .= ' ORDER BY context ASC';
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$editRow = null;
if ($editId > 0) {
    $editRow = load_template_by_id($pdo, $editId);
}

$title = 'Plantillas de Emails';
include __DIR__ . '/inc/layout_top.php';
?>

<div class="card" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
  <a class="btn secondary" href="superadmin_emails.php">⬅ Volver</a>
  <h2 style="margin:0;">Plantillas de emails</h2>
  <span class="muted">Editar/crear plantillas (solo superadmin)</span>
</div>

<?php if ($flashOk !== ''): ?>
  <div class="flash ok"><?php echo e($flashOk); ?></div>
<?php endif; ?>
<?php if ($flashErr !== ''): ?>
  <div class="flash err"><?php echo e($flashErr); ?></div>
<?php endif; ?>

<div class="card" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
  <form method="get" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
    <input name="q" placeholder="Buscar (context / nombre / subject)" value="<?php echo e($q); ?>" style="min-width:280px;">
    <button class="btn secondary" type="submit">Buscar</button>
    <?php if ($q !== ''): ?><a class="btn secondary" href="superadmin_email_templates.php">Limpiar</a><?php endif; ?>
  </form>

  <div style="flex:1 1 auto;"></div>

  <form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
    <input type="hidden" name="action" value="create">
    <input name="context" placeholder="nuevo_context" style="min-width:180px;" required>
    <input name="name" placeholder="Nombre" style="min-width:200px;">
    <button class="btn" type="submit">Crear plantilla</button>
  </form>
</div>

<div class="card" style="overflow:auto;">
  <table class="table">
    <thead>
      <tr>
        <th>Context</th>
        <th>Nombre</th>
        <th>Enabled</th>
        <th>HTML</th>
        <th>Updated</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($rows)): ?>
        <tr><td colspan="6" class="muted">Sin plantillas.</td></tr>
      <?php else: ?>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><strong><?php echo e($r['context']); ?></strong></td>
            <td class="muted"><?php echo e($r['name']); ?></td>
            <td><?php echo ((int)$r['enabled'] === 1) ? '<strong>sí</strong>' : '<span style="color:var(--danger)">no</span>'; ?></td>
            <td class="muted"><?php echo ((int)$r['is_html'] === 1) ? 'sí' : 'no'; ?></td>
            <td class="muted" style="white-space:nowrap;"><?php echo e($r['updated_at']); ?></td>
            <td style="white-space:nowrap;">
              <a class="btn secondary" href="superadmin_email_templates.php?<?php echo http_build_query(array('q'=>$q,'id'=>(int)$r['id'])); ?>">Editar</a>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<?php if ($editRow): ?>
  <div class="card" style="max-width:1100px;margin:16px auto;">
    <h3 style="margin-top:0;">Editar plantilla: <?php echo e($editRow['context']); ?></h3>

    <form method="post" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px;">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?php echo (int)$editRow['id']; ?>">

      <div>
        <label>Context (no cambiar)</label>
        <input value="<?php echo e($editRow['context']); ?>" readonly>
        <small class="muted">Los envíos del sistema buscan por este identificador.</small>
      </div>

      <div>
        <label>Nombre</label>
        <input name="name" value="<?php echo e($editRow['name']); ?>">
      </div>

      <div>
        <label>From email</label>
        <input name="from_email" value="<?php echo e($editRow['from_email']); ?>" placeholder="no-reply@tickex.com.ar">
      </div>

      <div>
        <label>From name</label>
        <input name="from_name" value="<?php echo e($editRow['from_name']); ?>" placeholder="Tickex">
      </div>

      <div>
        <label>Reply-to</label>
        <input name="reply_to" value="<?php echo e($editRow['reply_to']); ?>" placeholder="no-reply@tickex.com.ar">
      </div>

      <div>
        <label>Extra params (envelope)</label>
        <input name="extra_params" value="<?php echo e($editRow['extra_params']); ?>" placeholder="-f no-reply@tickex.com.ar">
        <small class="muted">Suele ser importante para SPF/DMARC.</small>
      </div>

      <div style="display:flex;gap:14px;align-items:center;flex-wrap:wrap;">
        <label style="display:flex;gap:8px;align-items:center;margin:0;">
          <input type="checkbox" name="enabled" value="1" <?php echo ((int)$editRow['enabled']===1)?'checked':''; ?>>
          Enabled
        </label>
        <label style="display:flex;gap:8px;align-items:center;margin:0;">
          <input type="checkbox" name="is_html" value="1" <?php echo ((int)$editRow['is_html']===1)?'checked':''; ?>>
          HTML
        </label>
      </div>

      <div style="grid-column:1/-1;">
        <label>Subject</label>
        <input name="subject" value="<?php echo e($editRow['subject']); ?>" style="width:100%;">
      </div>

      <div style="grid-column:1/-1;">
        <label>Body</label>
        <textarea name="body" rows="12" style="width:100%;"><?php echo e($editRow['body']); ?></textarea>
        <small class="muted">Placeholders: usá {{variable}}. Ej: {{link}}, {{nombre}}, {{ticket_url}}.</small>
      </div>

      <div style="grid-column:1/-1;display:flex;gap:8px;flex-wrap:wrap;">
        <button class="btn" type="submit">Guardar</button>
        <a class="btn secondary" href="superadmin_email_templates.php<?php echo ($q!==''?('?q='.urlencode($q)):''); ?>">Cerrar edición</a>
      </div>
    </form>
  </div>

  <div class="card" style="max-width:1100px;margin:16px auto;">
    <h3 style="margin-top:0;">Enviar manual (prueba / emergencia)</h3>
    <form method="post" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px;">
      <input type="hidden" name="action" value="send_test">
      <input type="hidden" name="id" value="<?php echo (int)$editRow['id']; ?>">

      <div>
        <label>To email</label>
        <input name="to_email" type="email" placeholder="destino@correo.com" required>
      </div>

      <div style="grid-column:1/-1;">
        <label>Vars (JSON opcional)</label>
        <textarea name="vars_json" rows="4" placeholder="{\"link\":\"https://...\",\"nombre\":\"Juan\"}"></textarea>
        <small class="muted">Si la plantilla usa placeholders, completalos acá.</small>
      </div>

      <div style="grid-column:1/-1;display:flex;gap:8px;">
        <button class="btn secondary" type="submit">Enviar</button>
      </div>
    </form>
  </div>
<?php endif; ?>

<?php include __DIR__ . '/inc/layout_bottom.php'; ?>
