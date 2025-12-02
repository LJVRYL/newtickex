<?php
require_once __DIR__ . '/inc/bootstrap.php';
$title = 'Selector de paneles admin – Legacy';

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
    echo "<div class='card error'><h2>Acceso denegado</h2><p>No tenés permiso para ver este selector.</p></div>";
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
  <h2>Selector de paneles administrativos (legacy)</h2>
  <p style="color:var(--muted); margin-top:6px;">
    Accesos rápidos a los distintos paneles de administración y sus variantes legacy.
  </p>
  <p style="color:#475569; margin-top:4px;">
    Solo visible para usuarios con roles administrativos válidos.
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
