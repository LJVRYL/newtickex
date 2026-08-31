<?php
require_once __DIR__.'/inc/bootstrap.php';
require_once __DIR__.'/inc/unified_tickets.php';
require_once __DIR__.'/inc/manual_income.php';

require_login();

$eventoId = 0;
if (isset($_GET['evento_id'])) $eventoId = (int)$_GET['evento_id'];
if ($eventoId <= 0) abort_404('Falta evento_id');

$pdo = db();

// permisos simplificados: reusar la lógica de panel_evento si existe
$cu = function_exists('current_user') ? current_user() : (isset($_SESSION['user'])?$_SESSION['user']:null);
$rol = isset($cu['tipo_global']) ? $cu['tipo_global'] : (isset($cu['rol']) ? $cu['rol'] : '');
if (!in_array($rol, array('admin_evento','super_admin','superadmin'), true)) {
    http_response_code(403);
    include __DIR__.'/inc/layout_top.php';
    echo "<div class='card error'><h2>Acceso denegado</h2><p>No tenés permiso para ver esto.</p></div>";
    include __DIR__.'/inc/layout_bottom.php';
    exit;
}

// Obtener evento
$stmtEv = $pdo->prepare("SELECT * FROM eventos WHERE id = ?");
$stmtEv->execute(array($eventoId));
$evento = $stmtEv->fetch(PDO::FETCH_ASSOC);
if (!$evento) abort_404('Evento no encontrado');

// Estadísticas económicas
$ecoStats = get_economic_stats($pdo, $eventoId);

$title = 'Economía – ' . ($evento['nombre'] ?? 'Evento');
include __DIR__.'/inc/layout_top.php';
?>

<div class="card">
  <h2>Economía — <?php echo e($evento['nombre']); ?></h2>
  <div style="margin-bottom:8px;"><a class="link" href="panel_evento.php?evento_id=<?php echo (int)$eventoId; ?>">← Volver al panel</a></div>

  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin-bottom:20px;">
    <div class="card" style="margin:0;background:var(--panel-2);">
      <div class="muted" style="font-size:12px;">Paquetes vendidos</div>
      <div style="font-size:28px;font-weight:700;margin-top:4px;">
        <?php echo (int)$ecoStats['entradas_vendidas']; ?>
      </div>
    </div>
    <div class="card" style="margin:0;background:var(--panel-2);">
      <div class="muted" style="font-size:12px;">Total recaudado</div>
      <div style="font-size:28px;font-weight:700;margin-top:4px;color:var(--ok);">
        $<?php echo number_format($ecoStats['total_recaudado'], 2); ?>
      </div>
    </div>
    <?php if ($ecoStats['manual_income'] != 0): ?>
    <div class="card" style="margin:0;background:var(--panel-2);">
      <div class="muted" style="font-size:12px;">Manual (otros/varios)</div>
      <div style="font-size:28px;font-weight:700;margin-top:4px;color:<?php echo ($ecoStats['manual_income'] >= 0 ? 'var(--info)' : 'var(--warn)'); ?>;">
        $<?php echo number_format($ecoStats['manual_income'], 2); ?>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <h3>Desglose por tipo</h3>
  <?php if (!empty($ecoStats['por_tipo'])): ?>
  <div style="overflow:auto;">
    <table class="table">
      <tr>
        <th>Tipo</th>
        <th style="text-align:center;">Cantidad</th>
        <th style="text-align:right;">Monto unitario</th>
        <th style="text-align:right;">Total</th>
        <th style="text-align:center;">Origen</th>
      </tr>
      <?php foreach ($ecoStats['por_tipo'] as $datos):
        $cantidad = isset($datos['cantidad']) ? (int)$datos['cantidad'] : 0;
        $monto_total = isset($datos['monto']) ? (float)$datos['monto'] : 0;
        $monto_unit = $cantidad>0 ? $monto_total/$cantidad : 0;
        $origen = $datos['origen'] ?? 'Desconocido';
        $tipoLabel = $datos['tipo'] ?? 'Sin tipo';
        $isNegative = $monto_total < 0;
      ?>
      <tr>
        <td style="font-weight:700;"><?php echo e($tipoLabel); ?></td>
        <td style="text-align:center;"><?php echo $cantidad; ?></td>
        <td style="text-align:right;">$<?php echo number_format($monto_unit,2); ?></td>
        <td style="text-align:right;font-weight:700;color:<?php echo $isNegative ? 'var(--warn)' : 'inherit'; ?>;">$<?php echo number_format($monto_total,2); ?></td>
        <td style="text-align:center;font-size:11px;color:<?php echo ($origen==='TICKEX'?'var(--info)':'var(--ok)'); ?>;"><?php echo e($origen); ?></td>
      </tr>
      <?php endforeach; ?>
      <tr style="border-top:2px solid var(--line);font-weight:700;background:var(--panel-2);">
        <td>TOTAL</td>
        <td style="text-align:center;"><?php echo (int)$ecoStats['entradas_vendidas']; ?></td>
        <td style="text-align:right;">-</td>
        <td style="text-align:right;color:var(--ok);">$<?php echo number_format($ecoStats['total_recaudado'],2); ?></td>
        <td></td>
      </tr>
    </table>
  </div>
  <?php else: ?>
    <div style="padding:10px;background:var(--panel-2);border-radius:4px;color:var(--muted);">No hay datos para mostrar.</div>
  <?php endif; ?>

</div>

<?php include __DIR__.'/inc/layout_bottom.php';
?>
