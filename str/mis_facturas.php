<?php
// mis_facturas.php
// Pestaña para que el cliente final vea sus facturas emitidas

require_once __DIR__ . '/inc/bootstrap.php';
require_login();

$cu = current_user();
$email = isset($cu['email']) ? $cu['email'] : (isset($_SESSION['usuario_email']) ? $_SESSION['usuario_email'] : '');

if (!$email) {
    http_response_code(403);
    include __DIR__ . '/inc/layout_top.php';
    echo '<div class="card" style="max-width:640px;margin:32px auto;"><h2>Acceso restringido</h2><p>No se encontró el email del usuario.</p></div>';
    include __DIR__ . '/inc/layout_bottom.php';
    exit;
}

// Buscar facturas emitidas para este email (ejemplo: tabla facturas_cliente)
$facturas = array();
if (file_exists(__DIR__ . '/facturas_cliente.json')) {
    $all = json_decode(file_get_contents(__DIR__ . '/facturas_cliente.json'), true);
    foreach ($all as $f) {
        if (isset($f['email']) && strtolower($f['email']) === strtolower($email)) {
            $facturas[] = $f;
        }
    }
}

include __DIR__ . '/inc/layout_top.php';
?>
<div class="card" style="max-width:800px;margin:32px auto;">
  <h2>Mis Facturas</h2>
  <?php if (empty($facturas)): ?>
    <div class="muted">No hay facturas emitidas a tu nombre.</div>
  <?php else: ?>
    <table class="table" style="width:100%;">
      <thead>
        <tr><th>Fecha</th><th>Evento</th><th>Monto</th><th>CAE</th><th>PDF</th></tr>
      </thead>
      <tbody>
        <?php foreach ($facturas as $f): ?>
        <tr>
          <td><?php echo htmlspecialchars($f['fecha'] ?? ''); ?></td>
          <td><?php echo htmlspecialchars($f['evento'] ?? ''); ?></td>
          <td>$<?php echo number_format((float)($f['monto'] ?? 0), 2, ',', '.'); ?></td>
          <td><?php echo htmlspecialchars($f['cae'] ?? ''); ?></td>
          <td>
            <?php if (!empty($f['pdf_url'])): ?>
              <a href="<?php echo htmlspecialchars($f['pdf_url']); ?>" target="_blank">Descargar</a>
            <?php else: ?>-
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/inc/layout_bottom.php'; ?>
