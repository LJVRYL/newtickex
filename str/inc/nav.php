<?php
$tg = isset($_SESSION['tipo_global']) ? $_SESSION['tipo_global'] : '';
?>
<nav class="nav">
  <?php if(in_array($tg, array('admin_evento','super_admin','superadmin'), true)): ?>
    <a href="panel_admin.php">Panel</a>
    <a href="crear_evento.php">Crear Evento</a>
    <a href="panel_evento.php">Mis Eventos</a>
    <a href="mis_entradas.php">Mis Entradas</a>
    <a href="secundarios.php">Mi Staff</a>
    <a href="mi_sitio.php">Mi Sitio</a>
    <a href="mi_perfil.php">Mi Perfil</a>
  <?php endif; ?>
</nav>
