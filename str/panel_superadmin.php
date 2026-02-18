<?php
// panel_superadmin.php
// Panel maestro para superadmin
require_once __DIR__ . '/inc/bootstrap.php';

$cu = current_user();
if (empty($cu['tipo_global']) || $cu['tipo_global'] !== 'super_admin') {
    header('Location: panel_admin.php');
    exit;
}

// Ocultar nav global y quick-links en superadmin
// Datos para el dashboard superadmin
$title = 'Panel Superadmin';
$hideNav = true;
$pdo = db();


// Total de usuarios registrados
try {
  $totalUsuarios = (int)$pdo->query("SELECT COUNT(*) FROM usuarios")->fetchColumn();
} catch (Exception $e) { $totalUsuarios = 0; }
// Total de administradores
try {
  $totalAdmins = (int)$pdo->query("SELECT COUNT(*) FROM usuarios_admin")->fetchColumn();
} catch (Exception $e) { $totalAdmins = 0; }
// Ventas totales (suma de precios de entradas vendidas)
try {
  $ventasTotales = (float)$pdo->query("SELECT SUM(precio_pagado) FROM entradas WHERE estado='pagada' OR estado IS NULL")->fetchColumn();
  if ($ventasTotales === null) $ventasTotales = 0;
} catch (Exception $e) { $ventasTotales = 0; }

// Listado de administradores
try {
  $admins = $pdo->query("SELECT id, username, nombre, email, activo FROM usuarios_admin ORDER BY id DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $admins = array(); }
// Usuarios registrados y aprobados
try {
  $usuariosAprobados = $pdo->query("SELECT id, nombre, apellido, email, creado_en FROM usuarios WHERE aprobado=1 ORDER BY id DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $usuariosAprobados = array(); }
// Últimos registros (usuarios nuevos)
try {
  $ultimosRegistros = $pdo->query("SELECT id, nombre, apellido, email, rol, tipo_global, email_confirmado, creado_en FROM usuarios ORDER BY id DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $ultimosRegistros = array(); }
// Últimos tickets emitidos
try {
  $ultimosTickets = $pdo->query("SELECT id, email, nombre, tipo, fecha, created_at FROM entradas ORDER BY id DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $ultimosTickets = array(); }


include __DIR__ . '/inc/layout_top.php';
?>
<style>
/* Sidebar igual al nav global */
.sadmin-sidebar {
  position: fixed;
  top: 0;
  left: 0;
  height: 100vh;
  width: 220px;
  max-width: 220px;
  min-width: 180px;
  background: var(--panel, #111827);
  border-right: 1px solid var(--line, #1f2937);
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding: 72px 14px 16px;
  z-index: 200;
  box-shadow: 8px 0 18px rgba(0,0,0,.25);
}
.sadmin-sidebar a {
  color: var(--ink, #e5e7eb);
  text-decoration: none;
  padding: 8px 12px;
  border-radius: 10px;
  border: 1px solid var(--line, #1f2937);
  background: var(--panel-2, #0f172a);
  width: 100%;
  max-width: 192px;
  min-width: 120px;
  display: flex;
  align-items: center;
  font-weight: 600;
  font-size: 14px;
  text-align: left;
  min-height: 32px;
  margin-bottom: 8px;
  transition: border-color .2s, background .2s, color .2s;
  box-sizing: border-box;
}
.sadmin-sidebar a:hover, .sadmin-sidebar a.active {
  border-color: var(--acc, #22d3ee);
  background: var(--panel, #111827);
  color: var(--acc, #22d3ee);
}
.sadmin-sidebar a:hover, .sadmin-sidebar a.active {
  background: var(--panel, #23232a);
  color: var(--acc, #22d3ee);
}
.sadmin-sidebar a:hover, .sadmin-sidebar a.active {
  border-color: var(--acc, #22d3ee);
  background: var(--panel, #111827);
.sadmin-layout {
  display: flex;
  flex-direction: row;
  min-height: 100vh;
  background: var(--bg, #0b1020);
  justify-content: center;
  align-items: flex-start;
}
.sadmin-main {
  flex: 1 1 0%;
  min-width: 0;
  max-width: 100vw;
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 40px 0 24px 0;
  box-sizing: border-box;
}
.sadmin-main-inner {
  width: 100%;
  max-width: 900px;
  margin: 0 auto;
  display: flex;
  flex-direction: column;
  gap: 24px;
}
.sadmin-main .card {
  width: 100%;
  max-width: 100%;
  margin: 0;
}
    border-right: none;
    border-bottom: 1px solid var(--line, #1f2937);
    padding: 12px 4px 8px 4px;
    box-shadow: none;
    gap: 0;
  }
  .sadmin-sidebar a {
    flex: 1 1 auto;
    min-width: 0;
    text-align: center;
  .sadmin-layout {
    flex-direction: column;
    align-items: stretch;
  }
  .sadmin-main {
    padding: 18px 6px;
    max-width: 100vw;
    align-items: center;
  }
  .sadmin-main-inner {
    max-width: 100vw;
  }
    margin-bottom: 0;
    border-radius: 8px;
    font-size: 13px;
    padding: 10px 0;
    border-bottom: 3px solid transparent;
    border-right: none;
  }
  .sadmin-sidebar a.active, .sadmin-sidebar a:hover {
    border-bottom: 3px solid var(--acc, #22d3ee);
    border-color: var(--acc, #22d3ee);
    background: var(--panel, #111827);
    color: var(--acc, #22d3ee);
  }
}
/* Centrar y limitar ancho del contenido principal */
/* Centrar visualmente el contenido principal respecto a la pantalla */
.sadmin-main {
  margin-left: 260px;
  padding: 40px 0 24px 0;
  min-width: 0;
  max-width: 100vw;
  display: block;
  box-sizing: border-box;
}
.sadmin-main-inner {
  max-width: 900px;
  margin: 0 auto;
  display: flex;
  flex-direction: column;
  gap: 24px;
}
.sadmin-main .card {
  width: 100%;
  max-width: 100%;
  margin: 0;
}
@media (max-width:900px) {
  .sadmin-main {
    margin-left: 0;
    padding: 18px 6px;
    max-width: 100vw;
    align-items: center;
  }
  .sadmin-main > .card {
    max-width: 100vw;
  }
}
</style>
<nav class="nav" style="position:fixed;top:0;left:0;height:100vh;width:220px;">
  <a href="#panel">Panel</a>
  <a href="#admins">Administradores</a>
  <a href="#usuarios">Usuarios</a>
  <a href="#registros">Últimos Registros</a>
  <a href="#tickets">Tickets emitidos</a>
  <a href="#economia">Economía Global</a>
</nav>
<div class="sadmin-main">
  <div class="sadmin-main-inner">
    <div id="panel" class="card">
      <h2 style="margin-top:0;">Panel</h2>
      <div style="display:flex;gap:32px;flex-wrap:wrap;justify-content:space-between;">
        <div><strong>Total usuarios:</strong> <?php echo $totalUsuarios; ?></div>
        <div><strong>Total administradores:</strong> <?php echo $totalAdmins; ?></div>
        <div><strong>Ventas totales:</strong> $<?php echo number_format($ventasTotales, 2, ',', '.'); ?></div>
      </div>
    </div>

    <div id="admins" class="card">
      <h2 style="margin-top:0;">Administradores</h2>
      <table class="table" style="min-width:600px;">
        <thead>
          <tr><th>ID</th><th>Usuario</th><th>Nombre</th><th>Email</th><th>Activo</th></tr>
        </thead>
        <tbody>
          <?php foreach ($admins as $a): ?>
          <tr>
            <td><?php echo (int)$a['id']; ?></td>
            <td><?php echo e($a['username']); ?></td>
            <td><?php echo e($a['nombre']); ?></td>
            <td><?php echo e($a['email'] ?? ''); ?></td>
            <td><?php echo ((int)($a['activo'] ?? 0) === 1) ? '✔' : '✖'; ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div id="usuarios" class="card">
      <h2 style="margin-top:0;">Usuarios registrados y aprobados</h2>
      <table class="table" style="min-width:600px;">
        <thead>
          <tr><th>ID</th><th>Nombre</th><th>Email</th><th>Fecha</th></tr>
        </thead>
        <tbody>
          <?php foreach ($usuariosAprobados as $u): ?>
          <tr>
            <td><?php echo (int)$u['id']; ?></td>
            <td><?php echo e(trim(($u['nombre'] ?? '') . ' ' . ($u['apellido'] ?? ''))); ?></td>
            <td><?php echo e($u['email']); ?></td>
            <td><?php echo e($u['creado_en'] ?? ''); ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div id="registros" class="card">
      <h2 style="margin-top:0;">Últimos registros</h2>
      <table class="table" style="min-width:600px;">
        <thead>
          <tr><th>ID</th><th>Nombre</th><th>Email</th><th>Rol</th><th>Confirmado</th><th>Fecha</th></tr>
        </thead>
        <tbody>
          <?php foreach ($ultimosRegistros as $u): ?>
          <tr>
            <td><?php echo (int)$u['id']; ?></td>
            <td><?php echo e(trim(($u['nombre'] ?? '') . ' ' . ($u['apellido'] ?? ''))); ?></td>
            <td><?php echo e($u['email']); ?></td>
            <td><?php echo e($u['rol'] ?? $u['tipo_global']); ?></td>
            <td><?php echo !empty($u['email_confirmado']) ? '✔' : '✖'; ?></td>
            <td><?php echo e($u['creado_en'] ?? ''); ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div id="tickets" class="card">
      <h2 style="margin-top:0;">Últimos tickets emitidos</h2>
      <table class="table" style="min-width:600px;">
        <thead>
          <tr><th>ID</th><th>Email</th><th>Nombre</th><th>Tipo</th><th>Fecha</th></tr>
        </thead>
        <tbody>
          <?php foreach ($ultimosTickets as $t): ?>
          <tr>
            <td><?php echo (int)$t['id']; ?></td>
            <td><?php echo e($t['email'] ?? ''); ?></td>
            <td><?php echo e($t['nombre'] ?? ''); ?></td>
            <td><?php echo e($t['tipo'] ?? ''); ?></td>
            <td><?php echo e($t['fecha'] ?? $t['created_at'] ?? ''); ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div id="economia" class="card">
      <h2 style="margin-top:0;">Economía Global</h2>
      <div class="muted">(Próximamente: resumen de ventas, ingresos y egresos globales)</div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/inc/layout_bottom.php'; ?>
