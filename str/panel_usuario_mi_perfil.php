<?php
// panel_usuario_mi_perfil.php - Página de perfil y seguridad del usuario
require_once __DIR__ . '/inc/security.php';
tickex_send_security_headers();
// Mantener sesión del sitio (esta pantalla ya dependía de session_start)
if (session_id() === '') {
  session_start();
}
require_once __DIR__ . '/inc/db.php';

if (!isset($_SESSION['usuario_id']) || (int)$_SESSION['usuario_id'] <= 0) {
  header('Location: login.php');
  exit;
}

try {
  $pdo = db();
} catch (Exception $e) {
  header('Content-Type: text/plain; charset=utf-8');
  echo "Error al conectar a la base de datos.";
  exit;
}

$usuarioId = (int)$_SESSION['usuario_id'];

$flashOk = '';
$flashErr = '';

function _tickex_parse_admin_tickex_id($raw)
{
  $s = trim((string)$raw);
  if ($s === '') return 0;
  if ($s[0] === '#') $s = substr($s, 1);
  if ($s === '') return 0;
  if (!ctype_digit($s)) return 0;
  $id = (int)$s;
  return $id > 0 ? $id : 0;
}

// --- Solicitud de revendedor (cliente -> admin por Tickex ID) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'rev_solicitar') {
  $provided = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
  if (!tickex_csrf_verify($provided)) {
    $flashErr = 'CSRF inválido. Actualizá la página e intentá de nuevo.';
  } else {
    $adminTickexIdRaw = isset($_POST['admin_tickex_id']) ? (string)$_POST['admin_tickex_id'] : '';
    $adminId = _tickex_parse_admin_tickex_id($adminTickexIdRaw);
    $mensaje = isset($_POST['mensaje']) ? trim((string)$_POST['mensaje']) : '';
    if ($adminId <= 0) {
      $flashErr = 'Ingresá un Tickex ID de admin válido (ej: #12).';
    } elseif (strlen($mensaje) > 500) {
      $flashErr = 'El mensaje es demasiado largo (máx 500).';
    } else {
      try {
        $stA = $pdo->prepare('SELECT id FROM usuarios_admin WHERE id = :id LIMIT 1');
        $stA->execute(array(':id' => $adminId));
        if (!$stA->fetchColumn()) {
          $flashErr = 'No encontramos un admin con ese Tickex ID.';
        }
      } catch (Exception $e) {
        $flashErr = 'No se pudo validar el admin. Intentá de nuevo.';
      }

      if ($flashErr === '') {
        try {
          // Evitar duplicados pendientes para el mismo admin (sin evento)
          $stDup = $pdo->prepare("SELECT 1 FROM revendedor_solicitudes WHERE cliente_id = :cid AND owner_admin_id = :oid AND estado = 'pending' AND (evento_id IS NULL OR evento_id = 0) LIMIT 1");
          $stDup->execute(array(':cid' => $usuarioId, ':oid' => $adminId));
          if ($stDup->fetchColumn()) {
            $flashErr = 'Ya tenés una solicitud pendiente para ese admin.';
          } else {
            $stIns = $pdo->prepare("INSERT INTO revendedor_solicitudes (cliente_id, cliente_email, evento_id, owner_admin_id, mensaje, estado) VALUES (:cid,:ce,NULL,:oid,:m,'pending')");
            $stIns->execute(array(
              ':cid' => $usuarioId,
              ':ce'  => isset($_SESSION['usuario_email']) ? (string)$_SESSION['usuario_email'] : null,
              ':oid' => $adminId,
              ':m'   => ($mensaje !== '' ? $mensaje : null),
            ));
            $flashOk = 'Solicitud enviada. El admin la podrá aprobar o rechazar.';
          }
        } catch (Exception $e) {
          $flashErr = 'No se pudo enviar la solicitud.';
        }
      }
    }
  }
}

try {
    $stmt = $pdo->prepare("
      SELECT id, nombre, apellido, email, dni, apodo, genero, creado_en, completado_en, password_hash
      FROM registro_pendientes
      WHERE id = :id
      LIMIT 1
    ");
    $stmt->execute(array(':id' => $usuarioId));
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$u) {
        $_SESSION = array();
        session_destroy();
        header('Location: login.php');
        exit;
    }
} catch (Exception $e) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "Error al cargar datos del usuario: " . $e->getMessage();
    exit;
}

include __DIR__ . '/inc/layout_top.php';
?>
<div class="card" style="max-width:600px;margin:0 auto 16px auto;">
  <h2>Mi perfil</h2>
  <div style="display:grid;grid-template-columns:120px 1fr;row-gap:6px;column-gap:8px;font-size:14px;margin-top:8px;">
    <div style="color:var(--muted);">Nombre</div>
    <div><?php echo htmlspecialchars($u['nombre'], ENT_QUOTES, 'UTF-8'); ?></div>
    <div style="color:var(--muted);">Apellido</div>
    <div><?php echo htmlspecialchars($u['apellido'], ENT_QUOTES, 'UTF-8'); ?></div>
    <div style="color:var(--muted);">Mi Tickex ID</div>
    <div><?php echo htmlspecialchars(($u['apodo'] && $u['apodo'] !== '') ? (string)$u['apodo'] : ('#' . (int)$u['id']), ENT_QUOTES, 'UTF-8'); ?></div>
    <div style="color:var(--muted);">Género</div>
    <div><?php echo htmlspecialchars($u['genero'], ENT_QUOTES, 'UTF-8'); ?></div>
    <div style="color:var(--muted);">Email</div>
    <div><?php echo htmlspecialchars($u['email'], ENT_QUOTES, 'UTF-8'); ?></div>
    <div style="color:var(--muted);">DNI</div>
    <div><?php echo htmlspecialchars($u['dni'], ENT_QUOTES, 'UTF-8'); ?></div>
    <div style="color:var(--muted);">Creado</div>
    <div><?php echo htmlspecialchars($u['creado_en'], ENT_QUOTES, 'UTF-8'); ?></div>
  </div>
</div>

<div class="card" style="max-width:600px;margin:0 auto 16px auto;">
  <h3>Seguridad</h3>
  <form method="post" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;">
    <input type="hidden" name="action" value="change_password">
    <div>
      <label for="pass_nueva">Nueva contraseña (mín 6)</label>
      <input type="password" id="pass_nueva" name="pass_nueva" autocomplete="new-password" required>
    </div>
    <div>
      <label for="pass_repite">Repetir nueva</label>
      <input type="password" id="pass_repite" name="pass_repite" autocomplete="new-password" required>
    </div>
    <div style="grid-column:1 / -1;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
      <button type="submit" class="btn">Guardar contraseña</button>
      <span style="font-size:12px;color:var(--muted);">Se guarda cifrada; no podemos leerla.</span>
    </div>
  </form>
</div>

<div class="card" style="max-width:600px;margin:0 auto 16px auto;">
  <h3>Revendedores</h3>
  <?php if ($flashErr !== ''): ?>
    <div class="flash err" style="margin:8px 0;"><?php echo htmlspecialchars($flashErr, ENT_QUOTES, 'UTF-8'); ?></div>
  <?php endif; ?>
  <?php if ($flashOk !== ''): ?>
    <div class="flash ok" style="margin:8px 0;"><?php echo htmlspecialchars($flashOk, ENT_QUOTES, 'UTF-8'); ?></div>
  <?php endif; ?>

  <div style="font-size:13px;color:var(--muted);margin:6px 0 10px 0;">
    Para solicitar ser revendedor, ingresá el <strong>Tickex ID</strong> del administrador (ej: <strong>#12</strong>). El admin recibirá tu solicitud y podrá aprobarla o rechazarla.
  </div>

  <form method="post" style="display:grid;grid-template-columns:1fr;gap:10px;">
    <input type="hidden" name="action" value="rev_solicitar">
    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(tickex_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">

    <div>
      <label for="admin_tickex_id">Tickex ID del admin</label>
      <input id="admin_tickex_id" name="admin_tickex_id" placeholder="#12" required>
    </div>

    <div>
      <label for="mensaje">Mensaje (opcional)</label>
      <textarea id="mensaje" name="mensaje" rows="3" maxlength="500" placeholder="Contanos por qué querés ser revendedor."></textarea>
    </div>

    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
      <button type="submit" class="btn">Enviar solicitud</button>
      <span style="font-size:12px;color:var(--muted);">Un admin la podrá aprobar o rechazar.</span>
    </div>
  </form>
</div>

<?php include __DIR__ . '/inc/layout_bottom.php'; ?>
