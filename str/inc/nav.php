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
    <a href="superadmin_revendedores.php">Revendedores</a>
    <a href="superadmin_usuarios.php">Usuarios</a>
    <div style="padding:8px 12px 2px;color:var(--muted);font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;">📣 Comunicacion</div>
    <a href="superadmin_emails_db.php">👥 Contactos</a>
    <a href="comunicacion_audiencias.php">Audiencias</a>
    <a href="comunicacion_newsletter.php">Newsletter</a>
    <a href="comunicacion_plantillas.php">Plantillas</a>
    <a href="comunicacion_campanas.php">Campanas</a>
    <a href="comunicacion_estado_motor.php">Estado Motor</a>
    <a href="comunicacion_historial.php">Historial</a>
    <a href="comunicacion_healthcheck.php">Health Check</a>
    <a href="superadmin_emails.php">Emails</a>
    <a href="superadmin_email_templates.php">Plantillas Emails</a>
    <a href="superadmin_economia_general.php">Economía</a>
    <a href="facturacion_admin.php">Facturación</a>
    <a href="roles_staff.php">Roles Staff</a>
    <a href="access_links.php">Links de acceso</a>
  <?php elseif (in_array($tg, array('admin_evento'), true)): ?>
    <a href="panel_admin.php">Panel Admin</a>
    <a href="ingresos_totalcoin.php">Ingresos TotalCoin</a>
    <a href="crear_evento.php">Crear Evento</a>
    <div style="padding:8px 12px 2px;color:var(--muted);font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;">📣 Comunicacion</div>
    <a href="superadmin_emails_db.php">👥 Contactos</a>
    <a href="comunicacion_audiencias.php">Audiencias</a>
    <a href="comunicacion_newsletter.php">Newsletter</a>
    <a href="comunicacion_plantillas.php">Plantillas</a>
    <a href="comunicacion_campanas.php">Campanas</a>
    <a href="comunicacion_estado_motor.php">Estado Motor</a>
    <a href="comunicacion_historial.php">Historial</a>
    <a href="comunicacion_healthcheck.php">Health Check</a>
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
    <a href="access_links.php">Links de acceso</a>
    <a href="mi_sitio.php">Mi Sitio</a>
    <a href="mi_perfil.php">Mi Perfil</a>
  <?php endif; ?>
</nav>
