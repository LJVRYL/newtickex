<?php
require_once __DIR__ . '/inc/security.php';
tickex_send_security_headers();
tickex_session_start();
require_once __DIR__ . '/inc/db.php';

if (!isset($_SESSION['usuario_id']) || (int)$_SESSION['usuario_id'] <= 0) {
  header('Location: login.php');
  exit;
}

$usuarioId = (int)$_SESSION['usuario_id'];
$pdo = db();
$title = 'Panel staff';

// Traer asignaciones staff activas para este cliente
$asignaciones = array();
try {
  $st = $pdo->prepare("SELECT sa.id, sa.owner_admin_id, sa.rol_staff, sa.created_at,
      ua.apodo AS admin_apodo, ua.username AS admin_username, ua.email AS admin_email
    FROM staff_admins sa
    LEFT JOIN usuarios_admin ua ON ua.id = sa.owner_admin_id
    WHERE sa.cliente_id = :cid AND sa.activo = 1
    ORDER BY sa.id DESC
    LIMIT 50");
  $st->execute(array(':cid' => $usuarioId));
  $asignaciones = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
  $asignaciones = array();
}

include __DIR__ . '/inc/layout_top.php';
?>

<div class="card" style="max-width:760px;margin:16px auto;">
  <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:center;">
    <div>
      <h1 style="margin:0;">Panel staff</h1>
      <div class="muted" style="margin-top:4px;">Tu cuenta sigue siendo cliente; staff es un rol adicional por admin.</div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
      <a class="btn secondary" href="panel_usuario.php">⬅ Volver</a>
      <a class="btn secondary" href="panel_usuario_mi_perfil.php">Mi perfil</a>
    </div>
  </div>

  <?php if (empty($asignaciones)): ?>
    <div class="flash err" style="margin-top:12px;">No tenés asignaciones de staff activas.</div>
  <?php else: ?>
    <div style="margin-top:12px;overflow:auto;">
      <table class="table" style="width:100%;min-width:640px;">
        <thead>
          <tr>
            <th style="width:220px;">Admin</th>
            <th style="width:160px;">Rol</th>
            <th style="width:180px;">Desde</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($asignaciones as $a): ?>
            <?php
              $adminLabel = '';
              if (isset($a['admin_apodo']) && trim((string)$a['admin_apodo']) !== '') {
                $adminLabel = (string)$a['admin_apodo'];
              } elseif (isset($a['admin_username']) && trim((string)$a['admin_username']) !== '') {
                $adminLabel = (string)$a['admin_username'];
              } else {
                $adminLabel = '#' . (int)$a['owner_admin_id'];
              }
              if (isset($a['admin_email']) && trim((string)$a['admin_email']) !== '') {
                $adminLabel .= ' (' . (string)$a['admin_email'] . ')';
              }
            ?>
            <tr>
              <td><?php echo htmlspecialchars($adminLabel, ENT_QUOTES, 'UTF-8'); ?></td>
              <td><?php echo htmlspecialchars((string)($a['rol_staff'] ?? 'staff'), ENT_QUOTES, 'UTF-8'); ?></td>
              <td><?php echo htmlspecialchars((string)($a['created_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/inc/layout_bottom.php'; ?>
