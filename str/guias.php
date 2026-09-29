<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_login();
$cu = current_user();
$role = tickex_admin_role($cu);
if (!in_array($role, array('admin_evento', 'super_admin', 'superadmin'), true)) {
    abort_404('No tenés permiso para acceder a las guías de organizadores.');
}

$guides = array(
    'inicio' => array(
        'number' => '01',
        'title' => 'Preparar tu cuenta',
        'time' => '5 minutos',
        'summary' => 'Completá tu identidad, el medio de cobro y la información que verá tu público.',
        'steps' => array(
            array('Revisá tus datos', 'Completá nombre, contacto y datos de cobro. Nunca compartas tu contraseña.', 'mi_perfil.php', 'Abrir mi perfil'),
            array('Conectá Mercado Pago', 'La cuenta conectada debe pertenecer al organizador que recibirá las ventas.', 'mercadopago_config.php', 'Configurar Mercado Pago'),
            array('Definí tu sitio', 'Cargá identidad, logo y enlaces públicos antes de compartir el evento.', 'mi_sitio.php', 'Configurar mi sitio'),
        ),
    ),
    'evento' => array(
        'number' => '02',
        'title' => 'Crear y publicar un evento',
        'time' => '10 minutos',
        'summary' => 'Configurá fecha, capacidad y entradas sin mezclar datos con otros eventos.',
        'steps' => array(
            array('Creá el evento', 'Usá un nombre claro, fechas reales y un enlace corto fácil de compartir.', 'crear_evento.php', 'Crear evento'),
            array('Configurá las entradas', 'Definí precio, cantidad de paquetes y cuántos QR entrega cada paquete.', 'panel_evento.php', 'Ir a mis eventos'),
            array('Controlá antes de publicar', 'Abrí el checkout como comprador y verificá textos, importes, cupo y destino del pago.', 'panel_evento.php', 'Revisar evento'),
        ),
    ),
    'ventas' => array(
        'number' => '03',
        'title' => 'Vender y emitir entradas',
        'time' => 'Operación diaria',
        'summary' => 'Diferenciá checkout, transferencia manual y cortesías para que caja y stock coincidan.',
        'steps' => array(
            array('Venta por checkout', 'Compartí únicamente el enlace público del evento. El QR se emite al confirmarse el pago.', 'panel_evento.php', 'Buscar enlace público'),
            array('Transferencia o monto libre', 'Usá Enviar Tickex, elegí venta manual e ingresá el monto realmente cobrado.', 'enviar_tickex.php', 'Enviar Tickex'),
            array('Cortesía', 'Elegí la modalidad gratuita. Descuenta capacidad pero no suma recaudación.', 'enviar_tickex.php', 'Emitir cortesía'),
            array('Control posterior', 'Comprobá entradas emitidas, pagadas, gratuitas y disponibles en el panel del evento.', 'panel_evento.php', 'Controlar emisión'),
        ),
    ),
    'equipo' => array(
        'number' => '04',
        'title' => 'Organizar staff y puerta',
        'time' => 'Antes de abrir puertas',
        'summary' => 'Cada persona debe ver sólo el evento y las herramientas necesarias para su función.',
        'steps' => array(
            array('Armá el equipo', 'Invitá a cada integrante y asignale un rol concreto.', 'secundarios.php', 'Gestionar staff'),
            array('Asigná eventos', 'Confirmá que nadie tenga acceso a eventos que no va a operar.', 'secundarios.php', 'Revisar asignaciones'),
            array('Probá puerta', 'Ingresá con una cuenta de puerta y verificá búsqueda, cobro, check-in y prevención de duplicados.', 'panel_evento.php', 'Abrir panel del evento'),
            array('Plan de contingencia', 'Definí quién toma decisiones si falla internet, un teléfono o un lector.', 'soporte.php', 'Consultar soporte'),
        ),
    ),
    'comunicacion' => array(
        'number' => '05',
        'title' => 'Comunicar sin duplicados',
        'time' => 'Antes de cada campaña',
        'summary' => 'Ordená contactos y audiencias antes de enviar; una campaña debe tener objetivo y destinatarios claros.',
        'steps' => array(
            array('Ordená contactos', 'Filtrá por evento, origen y relación antes de construir la audiencia.', 'superadmin_emails_db.php', 'Ver contactos'),
            array('Creá la audiencia', 'Guardá criterios reutilizables y revisá la cantidad de personas resultante.', 'comunicacion_audiencias.php', 'Crear audiencia'),
            array('Prepará contenido', 'Usá una plantilla, previsualizá enlaces y comprobá remitente y respuesta.', 'comunicacion_plantillas.php', 'Ver plantillas'),
            array('Enviá y monitoreá', 'Hacé una prueba pequeña, luego enviá y revisá fallos, aperturas y clics.', 'comunicacion_campanas.php', 'Gestionar campañas'),
        ),
    ),
    'cierre' => array(
        'number' => '06',
        'title' => 'Cerrar y revisar el evento',
        'time' => 'Después del evento',
        'summary' => 'Conciliá ventas, costos y accesos antes de considerar cerrado el evento.',
        'steps' => array(
            array('Conciliá entradas', 'Compará QR emitidos, pagos confirmados y check-ins.', 'panel_evento.php?estado=finalizados', 'Ver eventos finalizados'),
            array('Revisá facturación', 'Filtrá por evento, proveedor y estado; investigá cualquier pago pendiente.', 'facturacion_admin.php', 'Abrir facturación'),
            array('Cerrá la economía', 'Registrá costos faltantes y separá recaudación bruta de resultado neto.', 'economia_general.php', 'Ver economía'),
            array('Documentá incidentes', 'Si algo no coincidió, abrí una consulta con evento, horario y evidencia.', 'soporte.php', 'Crear consulta'),
        ),
    ),
);

$selected = isset($_GET['guia']) ? (string)$_GET['guia'] : '';
if ($selected !== '' && !isset($guides[$selected])) $selected = '';
$title = 'Guías para organizadores';
include __DIR__ . '/inc/layout_top.php';
?>
<style>
.guide-shell{max-width:1180px;margin:0 auto;display:grid;gap:18px}.guide-hero{padding:30px;background:radial-gradient(circle at 88% 12%,rgba(56,210,238,.14),transparent 33%),linear-gradient(135deg,#151a35,#302064)}.guide-kicker{color:#5edbef;text-transform:uppercase;font-size:11px;font-weight:900;letter-spacing:.13em}.guide-hero h1{font-size:clamp(31px,5vw,48px);margin:6px 0}.guide-nav{display:flex;gap:8px;flex-wrap:wrap}.guide-nav a{padding:8px 11px;border:1px solid var(--line);border-radius:999px;text-decoration:none;color:var(--muted);font-size:12px;font-weight:800}.guide-nav a.active,.guide-nav a:hover{color:#fff;border-color:#755cf0;background:rgba(117,92,240,.18)}.guide-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.guide-card{padding:20px;display:grid;gap:16px}.guide-head{display:grid;grid-template-columns:auto minmax(0,1fr);gap:13px;align-items:start}.guide-number{display:grid;place-items:center;width:42px;height:42px;border-radius:12px;background:linear-gradient(135deg,#7048ef,#3bd5e9);font-weight:950}.guide-head h2{font-size:20px;margin:0 0 4px}.guide-head p{margin:0;color:var(--muted);font-size:13px}.guide-time{font-size:11px;color:#75deee;font-weight:800;margin-top:6px}.guide-steps{display:grid;gap:9px;counter-reset:guide-step}.guide-step{counter-increment:guide-step;padding:13px;border:1px solid var(--line);border-radius:13px;background:rgba(255,255,255,.025)}.guide-step strong:before{content:counter(guide-step) '. ';color:#78ddea}.guide-step p{font-size:13px;color:var(--muted);margin:5px 0 10px}.guide-step a{font-size:12px;font-weight:850}.guide-footer{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}.guide-all{color:var(--muted);font-size:13px}@media(max-width:760px){.guide-grid{grid-template-columns:1fr}.guide-hero{padding:22px}.guide-card{padding:16px}}
</style>
<main class="guide-shell">
  <section class="card guide-hero"><div class="guide-kicker">Aprender Tickex</div><h1>De la configuración al cierre.</h1><p class="muted">Recorridos cortos para operar con orden. Cada paso te lleva directamente a la herramienta correspondiente.</p></section>
  <nav class="guide-nav" aria-label="Filtrar guías"><a href="guias.php"<?php echo $selected===''?' class="active"':'';?>>Todas</a><?php foreach($guides as $key=>$guide):?><a href="guias.php?guia=<?php echo e($key);?>"<?php echo $selected===$key?' class="active"':'';?>><?php echo e($guide['number'].' · '.$guide['title']);?></a><?php endforeach;?></nav>
  <section class="guide-grid">
  <?php foreach($guides as $key=>$guide): if($selected!=='' && $selected!==$key) continue; ?>
    <article class="card guide-card" id="<?php echo e($key);?>"><header class="guide-head"><div class="guide-number"><?php echo e($guide['number']);?></div><div><h2><?php echo e($guide['title']);?></h2><p><?php echo e($guide['summary']);?></p><div class="guide-time"><?php echo e($guide['time']);?></div></div></header><div class="guide-steps"><?php foreach($guide['steps'] as $step):?><div class="guide-step"><strong><?php echo e($step[0]);?></strong><p><?php echo e($step[1]);?></p><a href="<?php echo e($step[2]);?>"><?php echo e($step[3]);?> →</a></div><?php endforeach;?></div></article>
  <?php endforeach; ?>
  </section>
  <section class="card guide-footer"><div><strong>¿No encontraste lo que necesitás?</strong><div class="guide-all">Abrí una consulta y vinculala con el evento correspondiente.</div></div><a class="btn" href="soporte.php">Ir a ayuda y soporte</a></section>
</main>
<?php include __DIR__ . '/inc/layout_bottom.php'; ?>
