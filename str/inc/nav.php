<?php
$tg = isset($_SESSION['tipo_global']) ? $_SESSION['tipo_global'] : '';
$isSuper = in_array($tg, array('super_admin','superadmin'), true);
?>
<nav class="nav">
  <?php if ($isSuper): ?>
    <a href="panel_admin.php">Panel Superadmin</a>
    <a href="superadmin_totalcoi.php">Totalcoin</a>
    <a href="superadmin_eventos.php">Ver todos los eventos</a>
    <a href="superadmin_revendedores.php">Revendedores</a>
    <a href="superadmin_usuarios.php">Usuarios</a>
    <a href="superadmin_emails_db.php">Base Emails</a>
    <a href="superadmin_emails.php">Emails</a>
    <a href="superadmin_email_templates.php">Plantillas Emails</a>
    <a href="superadmin_economia_general.php">Economía</a>
    <a href="facturacion_admin.php">Facturación</a>
    <a href="roles_staff.php">Roles Staff</a>
  <?php elseif (in_array($tg, array('admin_evento'), true)): ?>
    <a href="panel_admin.php">Panel Admin</a>
    <a href="crear_evento.php">Crear Evento</a>
    <a href="enviar_tickex.php">Enviar Tickex</a>
    <a href="panel_evento.php">Mis Eventos</a>
    <a href="mis_entradas.php">Mis Entradas</a>
    <a href="mis_clientes.php">Mis Clientes</a>
    <a href="admin_revendedores.php">Revendedores</a>
    <a href="economia_general.php">Economía</a>
    <a href="facturacion_admin.php">Facturación</a>
    <a href="inventario.php">Inventario</a>
    <a href="produccion.php">Producción</a>
    <a href="secundarios.php">Staff</a>
    <a href="roles_staff.php">Roles Staff</a>
    <a href="mi_sitio.php">Mi Sitio</a>
    <a href="mi_perfil.php">Mi Perfil</a>
  <?php endif; ?>
</nav>
