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

// --- Solicitud de revendedor ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'rev_solicitar') {
  $provided = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
  if (!tickex_csrf_verify($provided)) {
    $flashErr = 'CSRF inválido. Actualizá la página e intentá de nuevo.';
  } else {
    $eventoId = isset($_POST['evento_id']) ? (int)$_POST['evento_id'] : 0;
    $mensaje = isset($_POST['mensaje']) ? trim((string)$_POST['mensaje']) : '';
    if ($eventoId <= 0) {
      $flashErr = 'Seleccioná un evento.';
    } elseif (strlen($mensaje) > 500) {
      $flashErr = 'El mensaje es demasiado largo (máx 500).';
    } else {
      // Determinar owner_admin_id desde el evento si existe columna de creador
      $ownerAdminId = null;
      try {
        $colsInfo = $pdo->query("PRAGMA table_info(eventos)")->fetchAll(PDO::FETCH_ASSOC);
        $colMap = array();
        foreach ($colsInfo as $ci) {
          if (isset($ci['name'])) $colMap[$ci['name']] = true;
        }
        $creatorCol = null;
        foreach (array('creado_por_admin_id','creador_id','admin_id','usuario_admin_id') as $cname) {
          if (isset($colMap[$cname])) { $creatorCol = $cname; break; }
        }
        $sql = 'SELECT * FROM eventos WHERE id = :id LIMIT 1';
        $stEv = $pdo->prepare($sql);
        $stEv->execute(array(':id' => $eventoId));
        $ev = $stEv->fetch(PDO::FETCH_ASSOC);
        if ($ev && $creatorCol && isset($ev[$creatorCol]) && (int)$ev[$creatorCol] > 0) {
          $ownerAdminId = (int)$ev[$creatorCol];
        }
      } catch (Exception $e) {
        $ownerAdminId = null;
      }

      try {
        // Evitar duplicados pendientes por evento
        $stDup = $pdo->prepare("SELECT 1 FROM revendedor_solicitudes WHERE cliente_id = :cid AND evento_id = :eid AND estado = 'pending' LIMIT 1");
        $stDup->execute(array(':cid' => $usuarioId, ':eid' => $eventoId));
        if ($stDup->fetchColumn()) {
          $flashErr = 'Ya tenés una solicitud pendiente para este evento.';
        } else {
          $stIns = $pdo->prepare("INSERT INTO revendedor_solicitudes (cliente_id, cliente_email, evento_id, owner_admin_id, mensaje, estado) VALUES (:cid,:ce,:eid,:oid,:m,'pending')");
          $stIns->execute(array(
            ':cid' => $usuarioId,
            ':ce'  => isset($_SESSION['usuario_email']) ? (string)$_SESSION['usuario_email'] : null,
            ':eid' => $eventoId,
            ':oid' => $ownerAdminId,
            ':m'   => ($mensaje !== '' ? $mensaje : null),
          ));
          $flashOk = 'Solicitud enviada. Un admin la revisará.';
        }
      } catch (Exception $e) {
        $flashErr = 'No se pudo enviar la solicitud.';
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
    <div style="color:var(--muted);">Apodo</div>
    <div><?php echo htmlspecialchars($u['apodo'], ENT_QUOTES, 'UTF-8'); ?></div>
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
    Si querés vender entradas con comisión, podés enviar una solicitud para ser revendedor de un evento.
  </div>

  <?php
    $eventOptions = array();
    try {
      $stE = $pdo->query("SELECT id, nombre, fecha_desde FROM eventos ORDER BY id DESC LIMIT 200");
      while ($r = $stE->fetch(PDO::FETCH_ASSOC)) {
        $label = isset($r['nombre']) ? (string)$r['nombre'] : ('Evento #' . (int)$r['id']);
        if (!empty($r['fecha_desde'])) $label .= ' (' . substr((string)$r['fecha_desde'], 0, 10) . ')';
        $eventOptions[] = array('id' => (int)$r['id'], 'label' => $label);
      }
    } catch (Exception $e) {
      $eventOptions = array();
    }
  ?>

  <form method="post" style="display:grid;grid-template-columns:1fr;gap:10px;">
    <input type="hidden" name="action" value="rev_solicitar">
    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(tickex_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">

    <div>
      <label for="evento_id">Evento</label>
      <select id="evento_id" name="evento_id" required>
        <option value="">Seleccioná…</option>
        <?php foreach ($eventOptions as $op): ?>
          <option value="<?php echo (int)$op['id']; ?>"><?php echo htmlspecialchars($op['label'], ENT_QUOTES, 'UTF-8'); ?></option>
        <?php endforeach; ?>
      </select>
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
