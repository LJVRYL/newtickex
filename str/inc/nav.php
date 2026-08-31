<?php
$tg = isset($_SESSION['tipo_global']) ? $_SESSION['tipo_global'] : '';
$isSuper = in_array($tg, array('super_admin','superadmin'), true);
?>
<nav class="nav">
  <?php if ($isSuper): ?>
    <a href="panel_admin.php">Panel Superadmin</a>
    <a href="ingresos_totalcoin.php">Ingresos TotalCoin</a>
    <a href="superadmin_totalcoi.php">Totalcoin</a>
    <a href="superadmin_eventos.php">Ver todos los eventos</a>
    <a href="superadmin_usuarios.php">Usuarios</a>
    <a href="superadmin_emails_db.php">📣 Comunicación</a>
    <a href="superadmin_emails.php">Emails</a>
    <a href="superadmin_email_templates.php">Plantillas Emails</a>
    <a href="superadmin_economia_general.php">Economía</a>
    <a href="facturacion_admin.php">Facturación</a>
    <a href="mercadopago_config.php">Mercado Pago</a>
    <a href="secundarios.php">Staff</a>
  <?php elseif (in_array($tg, array('admin_evento'), true)): ?>
    <a href="panel_admin.php">Panel Admin</a>
    <a href="crear_evento.php">Crear Evento</a>
    <a href="superadmin_emails_db.php">📣 Comunicación</a>
    <a href="enviar_tickex.php">Enviar Tickex</a>
    <a href="panel_evento.php">Mis Eventos</a>
    <a href="mis_entradas.php">Mis Entradas</a>
    <a href="mis_clientes.php">Mis Clientes</a>
    <a href="economia_general.php">Economía</a>
    <a href="facturacion_admin.php">Facturación</a>
    <a href="mercadopago_config.php">Mercado Pago</a>
    <a href="inventario.php">Inventario</a>
    <a href="secundarios.php">Staff</a>
    <a href="mi_sitio.php">Mi Sitio</a>
    <a href="mi_perfil.php">Mi Perfil</a>
  <?php endif; ?>
</nav>
