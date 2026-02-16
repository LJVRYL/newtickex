<?php
$tg = isset($_SESSION['tipo_global']) ? $_SESSION['tipo_global'] : '';
$isSuper = in_array($tg, array('super_admin','superadmin'), true);
?>
<nav class="nav">
  <?php if(in_array($tg, array('admin_evento','super_admin','superadmin'), true)): ?>
    <a href="panel_admin.php">Panel Admin</a>
    <a href="crear_evento.php">Crear Evento</a>
    <a href="enviar_tickex.php">Enviar Tickex</a>

    <a href="panel_evento.php">Mis Eventos</a>
    <a href="mis_entradas.php">Mis Entradas</a>
    <a href="mis_clientes.php">Mis Clientes</a>
    <a href="inventario.php">Inventario</a>
    <a href="produccion.php">Producción</a>
    <a href="secundarios.php">Staff</a>
    <a href="mi_sitio.php">Mi Sitio</a>
    <a href="mi_perfil.php">Mi Perfil</a>
  <?php endif; ?>
</nav>
