<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/arca.php';

require_login();
$cu = current_user();
$tipoGlobal = isset($cu['tipo_global']) ? (string)$cu['tipo_global'] : '';
if (!in_array($tipoGlobal, array('super_admin', 'superadmin'), true)) {
    abort_404('Configuración no disponible.');
}

$config = arca_get_config();
if (!is_array($config)) $config = array();
$msg = '';
$error = '';
$csrf = tickex_csrf_token();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!tickex_csrf_verify(isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '')) {
        http_response_code(403);
        exit('Solicitud vencida o inválida.');
    }

    $cuit = preg_replace('/\D+/', '', isset($_POST['cuit']) ? (string)$_POST['cuit'] : '');
    $cert = trim(isset($_POST['cert']) ? (string)$_POST['cert'] : '');
    $key = trim(isset($_POST['key']) ? (string)$_POST['key'] : '');
    $modo = isset($_POST['modo']) && $_POST['modo'] === 'produccion' ? 'produccion' : 'homologacion';

    if (strlen($cuit) !== 11) $error = 'Ingresá un CUIT válido de 11 dígitos.';
    if ($cert === '') $cert = isset($config['cert']) ? (string)$config['cert'] : '';
    if ($key === '') $key = isset($config['key']) ? (string)$config['key'] : '';
    if ($cert === '' || $key === '') $error = 'El certificado y la clave privada son obligatorios la primera vez.';

    if ($error === '') {
        $endpoint = $modo === 'produccion'
            ? 'https://wsaa.afip.gov.ar/ws/services/LoginCms'
            : 'https://wsaahomo.afip.gov.ar/ws/services/LoginCms';
        try {
            arca_save_config(array(
                'cuit' => $cuit,
                'cert' => $cert,
                'key' => $key,
                'modo' => $modo,
                'endpoint' => $endpoint,
            ));
            $config = arca_get_config();
            $msg = 'Configuración guardada en el almacenamiento privado.';
        } catch (Exception $e) {
            $error = 'No se pudo guardar la configuración.';
        }
    }
}

$hasCert = !empty($config['cert']);
$hasKey = !empty($config['key']);
$title = 'Configuración ARCA';
include __DIR__ . '/inc/layout_top.php';
?>
<div class="card" style="max-width:680px;margin:32px auto;">
  <div class="muted" style="font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.08em;">Sólo superadministración</div>
  <h2>Configuración de Facturación ARCA</h2>
  <p class="muted">Las credenciales son globales para Tickex y se guardan fuera de la carpeta pública. Nunca se vuelven a mostrar en pantalla.</p>
  <?php if ($msg !== ''): ?><div class="flash ok"><?php echo e($msg); ?></div><?php endif; ?>
  <?php if ($error !== ''): ?><div class="flash err"><?php echo e($error); ?></div><?php endif; ?>
  <form method="post" autocomplete="off">
    <input type="hidden" name="_csrf" value="<?php echo e($csrf); ?>">
    <label>CUIT<br><input type="text" name="cuit" inputmode="numeric" maxlength="11" value="<?php echo e(isset($config['cuit']) ? $config['cuit'] : ''); ?>" required></label><br><br>
    <label>Certificado (PEM)<br><textarea name="cert" rows="4" style="width:100%;" placeholder="<?php echo $hasCert ? 'Configurado. Dejá vacío para conservarlo.' : 'Pegá el certificado PEM'; ?>"></textarea></label><br><br>
    <label>Clave privada (PEM)<br><textarea name="key" rows="4" style="width:100%;" placeholder="<?php echo $hasKey ? 'Configurada. Dejá vacío para conservarla.' : 'Pegá la clave privada PEM'; ?>"></textarea></label><br><br>
    <label>Entorno<br>
      <select name="modo">
        <option value="homologacion"<?php echo (isset($config['modo']) ? $config['modo'] : '') === 'homologacion' ? ' selected' : ''; ?>>Homologación (pruebas)</option>
        <option value="produccion"<?php echo (isset($config['modo']) ? $config['modo'] : '') === 'produccion' ? ' selected' : ''; ?>>Producción</option>
      </select>
    </label><br><br>
    <button class="btn" type="submit">Guardar configuración</button>
    <a class="btn secondary" href="facturacion_admin.php">Volver a Facturación</a>
  </form>
</div>
<?php include __DIR__ . '/inc/layout_bottom.php'; ?>
