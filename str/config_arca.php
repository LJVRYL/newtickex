<?php
// config_arca.php
// Configuración de la API de ARCA (AFIP) para facturación electrónica

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/arca.php';

require_login();

$cu = current_user();
$tipoGlobal = isset($cu['tipo_global']) ? $cu['tipo_global'] : '';
if (!in_array($tipoGlobal, array('super_admin', 'superadmin'), true)) {
    http_response_code(403);
    include __DIR__ . '/inc/layout_top.php';
    echo '<div class="card" style="max-width:640px;margin:32px auto;"><h2>Acceso restringido</h2><p>Solo para administradores.</p></div>';
    include __DIR__ . '/inc/layout_bottom.php';
    exit;
}

$config = arca_get_config();
if (!is_array($config)) $config = array();

// Guardar configuración (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!tickex_csrf_verify(isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '')) {
        http_response_code(403);
        exit('La sesión venció. Actualizá la página.');
    }
    $cuit = trim(isset($_POST['cuit']) ? (string)$_POST['cuit'] : '');
    $cert = trim(isset($_POST['cert']) ? (string)$_POST['cert'] : '');
    $key = trim(isset($_POST['key']) ? (string)$_POST['key'] : '');
    if ($cert === '' && isset($config['cert'])) $cert = (string)$config['cert'];
    if ($key === '' && isset($config['key'])) $key = (string)$config['key'];
    $modo = trim(isset($_POST['modo']) ? (string)$_POST['modo'] : 'homologacion');
    $endpoint = $modo === 'produccion' ? 'https://wsaa.afip.gov.ar/ws/services/LoginCms' : 'https://wsaahomo.afip.gov.ar/ws/services/LoginCms';
    // Guardar en archivo seguro (ejemplo simple, mejorar seguridad en producción)
    $data = [
        'cuit' => $cuit,
        'cert' => $cert,
        'key' => $key,
        'modo' => $modo,
        'endpoint' => $endpoint
    ];
    if (arca_save_config($data)) {
        $msg = 'Configuración guardada correctamente.';
        $config = $data;
    } else {
        http_response_code(500);
        $msg = 'No se pudo guardar la configuración privada.';
    }
}
include __DIR__ . '/inc/layout_top.php';
?>
<div class="card" style="max-width:600px;margin:32px auto;">
  <h2>Configuración de Facturación ARCA (AFIP)</h2>
  <?php if (!empty($msg)) echo '<div class="muted" style="color:green;">' . htmlspecialchars($msg) . '</div>'; ?>
  <form method="post" autocomplete="off">
    <input type="hidden" name="_csrf" value="<?php echo e(tickex_csrf_token()); ?>">
    <label>CUIT<br><input type="text" name="cuit" value="<?php echo e(isset($config['cuit']) ? $config['cuit'] : ''); ?>" required></label><br><br>
    <label>Certificado (PEM)<br><textarea name="cert" rows="4" style="width:100%;" placeholder="Dejar vacío para conservar el actual"></textarea></label><br><br>
    <label>Clave privada (PEM)<br><textarea name="key" rows="4" style="width:100%;" placeholder="Dejar vacío para conservar la actual"></textarea></label><br><br>
    <label>Modo:<br>
      <select name="modo">
        <option value="homologacion" <?php if((isset($config['modo']) ? $config['modo'] : '')==='homologacion')echo 'selected';?>>Homologación (pruebas)</option>
        <option value="produccion" <?php if((isset($config['modo']) ? $config['modo'] : '')==='produccion')echo 'selected';?>>Producción</option>
      </select>
    </label><br><br>
    <button class="btn" type="submit">Guardar configuración</button>
  </form>
</div>
<?php include __DIR__ . '/inc/layout_bottom.php'; ?>
