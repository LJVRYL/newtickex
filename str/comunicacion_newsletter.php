<?php
require_once __DIR__ . '/inc/bootstrap.php';

require_login();
$cu = current_user();
$tipoGlobal = isset($cu['tipo_global']) ? (string)$cu['tipo_global'] : (isset($_SESSION['tipo_global']) ? (string)$_SESSION['tipo_global'] : '');
$isSuper = in_array($tipoGlobal, array('super_admin', 'superadmin'), true);
$isAllowed = (is_admin() && ($isSuper || $tipoGlobal === 'admin_evento'));
if (!$isAllowed) {
    http_response_code(403);
    include __DIR__ . '/inc/layout_top.php';
    echo '<div class="card" style="max-width:640px;margin:32px auto;"><h2>Acceso restringido</h2><p>Solo para administradores.</p></div>';
    include __DIR__ . '/inc/layout_bottom.php';
    exit;
}

$title = 'Comunicacion - Newsletter';
include __DIR__ . '/inc/layout_top.php';
?>

<div class="card" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
  <a class="btn secondary" href="panel_admin.php">Volver</a>
  <div>
    <div class="muted" style="font-size:12px;letter-spacing:.08em;text-transform:uppercase;">📣 Comunicacion</div>
    <h2 style="margin:0;">Newsletter</h2>
  </div>
  <span class="muted">Base de diseno para el constructor de newsletter (fase inicial).</span>
</div>

<div class="card" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
  <a class="btn secondary" href="superadmin_emails_db.php">👥 Contactos</a>
  <a class="btn secondary" href="comunicacion_audiencias.php">Audiencias</a>
  <a class="btn" href="comunicacion_newsletter.php">Newsletter</a>
  <a class="btn secondary" href="comunicacion_plantillas.php">Plantillas</a>
  <a class="btn secondary" href="comunicacion_campanas.php">Campanas</a>
  <a class="btn secondary" href="comunicacion_estado_motor.php">Estado Motor</a>
  <a class="btn secondary" href="comunicacion_historial.php">Historial</a>
  <a class="btn secondary" href="comunicacion_healthcheck.php">Health Check</a>
</div>

<div class="card" style="display:flex;flex-direction:column;gap:12px;">
  <h3 style="margin:0;">Preview actual</h3>
  <p class="muted" style="margin:0;">Punto de partida para iterar contenido y estilo sin tocar codigo en cada envio.</p>
  <div style="display:flex;gap:8px;flex-wrap:wrap;">
    <a class="btn" href="templates/newsletters/save-the-rave_preview_local.html" target="_blank" rel="noopener">Abrir preview renderizado</a>
    <a class="btn secondary" href="templates/newsletters/save-the-rave_master_newsletter.html" target="_blank" rel="noopener">Abrir plantilla maestra</a>
    <a class="btn secondary" href="templates/newsletters/save-the-rave_master_content_example.json" target="_blank" rel="noopener">Abrir datos JSON</a>
  </div>
</div>

<div class="card" style="display:flex;flex-direction:column;gap:12px;">
  <h3 style="margin:0;">Diseno del futuro editor (sin programar aun)</h3>
  <ul style="margin:0; padding-left:20px; line-height:1.65;">
    <li>Datos basicos: titulo, edicion, fecha, lugar, artistas.</li>
    <li>Carga de imagenes: logo, flyer, lineup, sobre, fondo final.</li>
    <li>Texto editorial: intro, descripciones por artista, bloque sobre STR.</li>
    <li>Links: CTA de compra, Instagram, Tickex.</li>
    <li>Boton de generar preview automatico y boton de duplicar para nueva fecha.</li>
    <li>Exportacion HTML lista para campana en Comunicacion.</li>
  </ul>
</div>

<?php include __DIR__ . '/inc/layout_bottom.php'; ?>
