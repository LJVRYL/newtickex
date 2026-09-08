<?php
$tg = isset($_SESSION['tipo_global']) ? $_SESSION['tipo_global'] : '';
$isSuper = in_array($tg, array('super_admin','superadmin'), true);
$navCurrent = basename(isset($_SERVER['SCRIPT_NAME']) ? (string)$_SERVER['SCRIPT_NAME'] : '');
$navClass = function ($pages) use ($navCurrent) {
  return in_array($navCurrent, (array)$pages, true) ? ' class="active" aria-current="page"' : '';
};
?>
<nav class="nav">
  <a class="tx-side-brand" href="panel_admin.php" aria-label="Ir al panel principal">
    <span class="tx-side-brand-mark" aria-hidden="true">
      <img src="tickex-isotipo.svg" alt="">
    </span>
    <span class="tx-side-brand-copy"><strong>TICKEX</strong><small>Backstage</small></span>
  </a>

  <?php if ($isSuper): ?>
    <span class="tx-nav-label">Plataforma</span>
    <a<?php echo $navClass(array('panel_admin.php','superadmin.php')); ?> href="panel_admin.php">Panel Superadmin</a>
    <a<?php echo $navClass(array('superadmin_eventos.php','panel_evento.php')); ?> href="superadmin_eventos.php">Todos los eventos</a>
    <a<?php echo $navClass(array('superadmin_usuarios.php')); ?> href="superadmin_usuarios.php">Usuarios</a>

    <span class="tx-nav-label">Operación</span>
    <a<?php echo $navClass(array('superadmin_emails_db.php','superadmin_emails.php','superadmin_email_templates.php')); ?> href="superadmin_emails_db.php">Comunicación</a>
    <a<?php echo $navClass(array('superadmin_economia_general.php')); ?> href="superadmin_economia_general.php">Economía</a>
    <a<?php echo $navClass(array('facturacion_admin.php')); ?> href="facturacion_admin.php">Facturación</a>
    <a<?php echo $navClass(array('mercadopago_config.php')); ?> href="mercadopago_config.php">Mercado Pago</a>
    <a<?php echo $navClass(array('secundarios.php')); ?> href="secundarios.php">Staff</a>
    <a<?php echo $navClass(array('superadmin_soporte.php')); ?> href="superadmin_soporte.php">Soporte de clientes</a>
    <a<?php echo $navClass(array('superadmin_suscripciones.php')); ?> href="superadmin_suscripciones.php">Planes y suscripciones</a>
    <a<?php echo $navClass(array('superadmin_sites.php')); ?> href="superadmin_sites.php">Sitios y dominios</a>
    <a<?php echo $navClass(array('superadmin_legales.php')); ?> href="superadmin_legales.php">Legales</a>

    <span class="tx-nav-label">Soporte interno</span>
    <a<?php echo $navClass(array('superadmin_infraestructura.php')); ?> href="superadmin_infraestructura.php">Infraestructura</a>
    <a<?php echo $navClass(array('superadmin_email_delivery.php')); ?> href="superadmin_email_delivery.php">Entrega de emails</a>
    <a<?php echo $navClass(array('ingresos_totalcoin.php')); ?> href="ingresos_totalcoin.php">Ingresos TotalCoin</a>
    <a<?php echo $navClass(array('superadmin_totalcoi.php')); ?> href="superadmin_totalcoi.php">TotalCoin</a>
  <?php elseif (in_array($tg, array('admin_evento'), true)): ?>
    <span class="tx-nav-label">Inicio</span>
    <a<?php echo $navClass(array('panel_admin.php')); ?> href="panel_admin.php">Panel</a>
    <a<?php echo $navClass(array('crear_evento.php')); ?> href="crear_evento.php">Crear evento</a>

    <span class="tx-nav-label">Eventos</span>
    <a<?php echo $navClass(array('panel_evento.php')); ?> href="panel_evento.php">Mis eventos</a>
    <a<?php echo $navClass(array('enviar_tickex.php')); ?> href="enviar_tickex.php">Enviar Tickex</a>
    <a<?php echo $navClass(array('mis_entradas.php')); ?> href="mis_entradas.php">Mis entradas</a>
    <a<?php echo $navClass(array('mis_clientes.php')); ?> href="mis_clientes.php">Mis clientes</a>

    <span class="tx-nav-label">Gestión</span>
    <a<?php echo $navClass(array('superadmin_emails_db.php')); ?> href="superadmin_emails_db.php">Comunicación</a>
    <a<?php echo $navClass(array('economia_general.php','economia_evento.php')); ?> href="economia_general.php">Economía</a>
    <a<?php echo $navClass(array('facturacion_admin.php')); ?> href="facturacion_admin.php">Facturación</a>
    <a<?php echo $navClass(array('mercadopago_config.php')); ?> href="mercadopago_config.php">Mercado Pago</a>
    <a<?php echo $navClass(array('inventario.php')); ?> href="inventario.php">Inventario</a>
    <a<?php echo $navClass(array('secundarios.php')); ?> href="secundarios.php">Staff</a>

    <span class="tx-nav-label">Cuenta</span>
    <a<?php echo $navClass(array('mi_sitio.php')); ?> href="mi_sitio.php">Mi sitio</a>
    <a<?php echo $navClass(array('mi_perfil.php')); ?> href="mi_perfil.php">Mi perfil</a>
    <a<?php echo $navClass(array('soporte.php','guias.php')); ?> href="soporte.php">Ayuda y soporte</a>
    <a<?php echo $navClass(array('suscripcion.php')); ?> href="suscripcion.php">Mi plan</a>
  <?php endif; ?>
</nav>
