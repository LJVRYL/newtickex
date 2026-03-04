<?php
// config_arca.php
// Configuración de la API de ARCA (AFIP) para facturación electrónica

require_once __DIR__ . '/inc/bootstrap.php';

require_login();

$cu = current_user();
$esAdmin = is_admin();
$tipoGlobal = isset($cu['tipo_global']) ? $cu['tipo_global'] : '';
if (!$esAdmin && !in_array($tipoGlobal, array('admin_evento', 'super_admin', 'superadmin'), true)) {
    http_response_code(403);
    include __DIR__ . '/inc/layout_top.php';
    echo '<div class="card" style="max-width:640px;margin:32px auto;"><h2>Acceso restringido</h2><p>Solo para administradores.</p></div>';
    include __DIR__ . '/inc/layout_bottom.php';
    exit;
}

// Guardar configuración (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cuit = trim($_POST['cuit'] ?? '');
    $cert = trim($_POST['cert'] ?? '');
    $key = trim($_POST['key'] ?? '');
    $modo = trim($_POST['modo'] ?? 'homologacion');
    $endpoint = $modo === 'produccion' ? 'https://wsaa.afip.gov.ar/ws/services/LoginCms' : 'https://wsaahomo.afip.gov.ar/ws/services/LoginCms';
    // Guardar en archivo seguro (ejemplo simple, mejorar seguridad en producción)
    $data = [
        'cuit' => $cuit,
        'cert' => $cert,
        'key' => $key,
        'modo' => $modo,
        'endpoint' => $endpoint
    ];
    file_put_contents(__DIR__ . '/arca_config.json', json_encode($data));
    $msg = 'Configuración guardada correctamente.';
}
// Leer configuración actual
$config = [];
if (file_exists(__DIR__ . '/arca_config.json')) {
    $config = json_decode(file_get_contents(__DIR__ . '/arca_config.json'), true);
}
include __DIR__ . '/inc/layout_top.php';
?>
<div class="card" style="max-width:600px;margin:32px auto;">
  <h2>Configuración de Facturación ARCA (AFIP)</h2>
  <?php if (!empty($msg)) echo '<div class="muted" style="color:green;">' . htmlspecialchars($msg) . '</div>'; ?>
  <form method="post" autocomplete="off">
    <label>CUIT<br><input type="text" name="cuit" value="<?php echo htmlspecialchars($config['cuit'] ?? ''); ?>" required></label><br><br>
    <label>Certificado (PEM)<br><textarea name="cert" rows="4" style="width:100%;" required><?php echo htmlspecialchars($config['cert'] ?? ''); ?></textarea></label><br><br>
    <label>Clave privada (PEM)<br><textarea name="key" rows="4" style="width:100%;" required><?php echo htmlspecialchars($config['key'] ?? ''); ?></textarea></label><br><br>
    <label>Modo:<br>
      <select name="modo">
        <option value="homologacion" <?php if(($config['modo'] ?? '')==='homologacion')echo 'selected';?>>Homologación (pruebas)</option>
        <option value="produccion" <?php if(($config['modo'] ?? '')==='produccion')echo 'selected';?>>Producción</option>
      </select>
    </label><br><br>
    <button class="btn" type="submit">Guardar configuración</button>
  </form>
</div>
<?php include __DIR__ . '/inc/layout_bottom.php'; ?>
