<?php
$tg = isset($_SESSION['tipo_global']) ? $_SESSION['tipo_global'] : '';
?>
<nav class="nav">
  <?php if($tg==='super_admin'): ?>
    <a href="superadmin.php">SuperAdmin</a>
  <?php endif; ?>

  <?php if(in_array($tg, array('admin_evento','super_admin'), true)): ?>
    <a href="panel_admin.php">Panel</a>
    <a href="crear_evento.php">Crear evento</a>
    <a href="mis_entradas.php">Mis Entradas</a>
    <a href="descuentos.php">Descuentos</a>
    <a href="secundarios.php">Mi staff</a>
    <a href="mi_sitio.php">Mi sitio</a>
    <a href="mi_perfil.php">Mi perfil</a>
  <?php endif; ?>
</nav>
