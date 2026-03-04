<?php
// facturacion_admin.php
// Página de facturación para admin y superadmin

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/arca.php';

$title = 'Facturación';

require_login();
$cu = current_user();

$tg = isset($cu['tipo_global']) ? $cu['tipo_global'] : (isset($_SESSION['tipo_global']) ? $_SESSION['tipo_global'] : '');
$rol = isset($cu['rol']) ? $cu['rol'] : (isset($_SESSION['rol']) ? $_SESSION['rol'] : '');

$isSuper = in_array($tg, array('super_admin','superadmin'), true);
$isAdmin = in_array($tg, array('admin_evento'), true) || $rol === 'admin' || is_admin();

if (!$isSuper && !$isAdmin) {
  header('Location: panel_admin.php');
  exit;
}

// Intentar login con ARCA/AFIP
$arcaStatus = '';
$config = arca_get_config();
if ($config && !empty($config['cuit']) && !empty($config['cert']) && !empty($config['key'])) {
  try {
    require_once __DIR__ . '/../vendor/autoload.php';
    $afip = new \Afip\Afip([
      'CUIT' => $config['cuit'],
      'cert' => $config['cert'],
      'key'  => $config['key'],
      'production' => ($config['modo'] ?? 'homologacion') === 'produccion',
    ]);
    $ta = $afip->ElectronicBilling->GetLastVoucher(1, 1, 1);
    $arcaStatus = '<span style="color:green;">Conexión exitosa con ARCA/AFIP ✔️</span>';
  } catch (Exception $e) {
    $arcaStatus = '<span style="color:red;">Error de autenticación con ARCA/AFIP: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</span>';
  }
} else {
  $arcaStatus = '<span style="color:orange;">Faltan datos de configuración de ARCA/AFIP.</span>';
}

include __DIR__ . '/inc/layout_top.php';
?>
<div class="card">
  <h2>Facturación</h2>
  <div style="margin-bottom:10px;">Estado de conexión ARCA/AFIP: <?php echo $arcaStatus; ?></div>
  <ul>
    <li><a href="config_arca.php">Configurar API ARCA/AFIP</a></li>
    <li><a href="#">Ver facturas emitidas (próximamente)</a></li>
    <li><a href="#">Emitir factura manualmente (próximamente)</a></li>
  </ul>
</div>
<?php
include __DIR__ . '/inc/layout_bottom.php';
