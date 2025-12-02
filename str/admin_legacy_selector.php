<?php
require_once __DIR__ . '/inc/bootstrap.php';
$title = 'Selector de paneles admin – Legacy';

// Compatibilidad: si faltan helpers de auth (deploys legacy), definimos mínimos
if (!function_exists('require_login')) {
    function require_login()
    {
        if (empty($_SESSION['usuario']) && empty($_SESSION['usuario_id'])) {
            header('Location: login.php');
            exit;
        }
    }
}

if (!function_exists('current_user')) {
    function current_user()
    {
        $u = array();
        if (isset($_SESSION['usuario_id'])) {
            $u['id'] = (int) $_SESSION['usuario_id'];
        }
        if (isset($_SESSION['usuario_rol'])) {
            $u['rol'] = $_SESSION['usuario_rol'];
        }
        if (isset($_SESSION['usuario'])) {
            $u['usuario'] = $_SESSION['usuario'];
        }
        if (isset($_SESSION['usuario_email'])) {
            $u['email'] = $_SESSION['usuario_email'];
        }
        return $u;
    }
}

// Solo usuarios autenticados y administrativos
require_login();

$cu = current_user();
$rol = isset($cu['rol']) ? $cu['rol'] : '';
if ($rol === '' && isset($_SESSION['tipo_global'])) {
    $rol = $_SESSION['tipo_global'];
}

if (!in_array($rol, array('admin_evento', 'super_admin', 'superadmin'), true)) {
    http_response_code(403);
    include __DIR__ . '/inc/layout_top.php';
    echo "<div class='card error'><h2>Acceso restringido</h2><p>Esta página es solo para administradores.</p></div>";
    include __DIR__ . '/inc/layout_bottom.php';
    exit;
}

$links = array(
    array(
        'href'  => 'admin.php',
        'label' => 'admin.php',
        'desc'  => 'Wrapper principal actual que redirige al panel del evento STR (id=1).'
    ),
    array(
        'href'  => 'panel_evento.php?id=1',
        'label' => 'panel_evento.php?id=1',
        'desc'  => 'Panel del evento STR principal con filtros y check-in.'
    ),
    array(
        'href'  => 'admin_core.php',
        'label' => 'admin_core.php',
        'desc'  => 'Panel legacy simplificado con contadores y listado plano.'
    ),
    array(
        'href'  => 'admin_original.php',
        'label' => 'admin_original.php',
        'desc'  => 'Panel admin legacy original con filtros avanzados y check-in.'
    ),
    array(
        'href'  => 'panel_str.php',
        'label' => 'panel_str.php',
        'desc'  => 'Atajo que redirige al panel del evento STR (id=1).'
    ),
    array(
        'href'  => 'panel_admin.php',
        'label' => 'panel_admin.php',
        'desc'  => 'Panel admin moderno (actualmente sin contenido visible).'
    ),
    array(
        'href'  => 'superadmin.php',
        'label' => 'superadmin.php',
        'desc'  => 'Panel exclusivo para super_admin con métricas y accesos rápidos.'
    ),
    array(
        'href'  => 'secundarios.php',
        'label' => 'secundarios.php',
        'desc'  => 'Gestión de staff/usuarios secundarios asociados a eventos.'
    ),
);

include __DIR__ . '/inc/layout_top.php';
?>

<div class="card" style="margin-bottom:16px;">
  <h1 style="margin:0;">Selector de paneles administrativos (legacy)</h1>
  <p style="color:var(--muted); margin-top:6px;">
    Desde esta página podés navegar a los paneles viejos y actuales para inspección y soporte.
  </p>
</div>

<div class="card">
  <table style="width:100%; border-collapse: collapse;">
    <thead>
      <tr>
        <th style="text-align:left; padding:8px; border-bottom:1px solid #ececec;">Ruta</th>
        <th style="text-align:left; padding:8px; border-bottom:1px solid #ececec;">Descripción</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($links as $link): ?>
        <tr>
          <td style="padding:8px; border-bottom:1px solid #f1f5f9;">
            <a href="<?php echo e($link['href']); ?>" class="link"><?php echo e($link['label']); ?></a>
          </td>
          <td style="padding:8px; border-bottom:1px solid #f1f5f9; color:#475569;">
            <?php echo e($link['desc']); ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php include __DIR__ . '/inc/layout_bottom.php'; ?>
