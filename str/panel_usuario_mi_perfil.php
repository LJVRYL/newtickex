<?php
// panel_usuario_mi_perfil.php - Página de perfil y seguridad del usuario
session_start();

if (!isset($_SESSION['usuario_id']) || (int)$_SESSION['usuario_id'] <= 0) {
  header('Location: login.php');
  exit;
}

$dbFile = __DIR__ . '/save_the_rave.sqlite';

try {
  $pdo = new PDO('sqlite:' . $dbFile);
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
  header('Content-Type: text/plain; charset=utf-8');
  echo "Error al conectar a la base de datos: " . $e->getMessage();
  exit;
}

$usuarioId = (int)$_SESSION['usuario_id'];

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

<?php include __DIR__ . '/inc/layout_bottom.php'; ?>
