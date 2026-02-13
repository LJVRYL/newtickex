<?php
$tg = isset($_SESSION['tipo_global']) ? $_SESSION['tipo_global'] : '';
$isSuper = in_array($tg, array('super_admin','superadmin'), true);
?>
<nav class="nav">
  <?php if(in_array($tg, array('admin_evento','super_admin','superadmin'), true)): ?>
    <a href="panel_admin.php">Panel</a>
    <a href="crear_evento.php">Crear Evento</a>
    <a href="panel_evento.php">Mis Eventos</a>
    <a href="catalogo_senforms.php">Catálogo SenForms</a>
    <?php if($isSuper): ?>
      <a href="bridge_senforms.php">Bridge SenForms</a>
    <?php endif; ?>
    <a href="mis_entradas.php">Mis Entradas</a>
    <a href="mis_clientes.php">Mis Clientes</a>
    <a href="economia_general.php">Economía General</a>
    <a href="inventario.php">Inventario</a>
    <a href="produccion.php">Producción</a>
    <a href="secundarios.php">Mi Staff</a>
    <a href="mi_sitio.php">Mi Sitio</a>
    <a href="mi_perfil.php">Mi Perfil</a>
  <?php endif; ?>
</nav>
