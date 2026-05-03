<?php
session_start();

// Determinar pestaña activa
$tab = isset($_GET['tab']) ? $_GET['tab'] : 'admins';

// Cargar datos según pestaña
$dbFile = __DIR__ . '/save_the_rave.sqlite';

try {
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Cargar admins
    $stmt = $pdo->query("
        SELECT id, username, password, rol, tipo_global, rol_evento, activo
        FROM usuarios_admin
        ORDER BY id ASC
    ");
    $adminRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Cargar clientes (usuarios registrados completos)
    $stmtClientes = $pdo->query("
        SELECT id, nombre, apellido, email, rol, verificado, email_confirmado, creado_en
        FROM usuarios
        ORDER BY id ASC
    ");
    $clienteRows = $stmtClientes->fetchAll(PDO::FETCH_ASSOC);

    // Cargar registros pendientes (no completados)
    $stmtPendientes = $pdo->query("
        SELECT id, email, nombre, apellido, apodo, token, creado_en, completado_en
        FROM registro_pendientes
        ORDER BY id DESC
    ");
    $pendienteRows = $stmtPendientes->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
    http_response_code(500);
    echo "<pre>Error al leer usuarios: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . "</pre>";
    exit;
}

function e($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Gestor de usuarios – TICKEX (STR)</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body { margin:0; padding:16px; font-family: system-ui, -apple-system, sans-serif; background:#050505; color:#f5f5f5; }
    h1 { font-size:1.4rem; margin:0 0 4px; }
    .sub { font-size:0.85rem; color:#a1a1aa; margin-bottom:16px; }
    .btn-link { display:inline-block; padding:6px 10px; border-radius:999px; background:#222; color:#eee; text-decoration:none; font-size:0.8rem; margin-right:8px; }
    .btn-link:hover { background:#2d2d2d; }
    .tabs { display:flex; gap:4px; margin-bottom:16px; border-bottom:1px solid #27272a; padding-bottom:8px; }
    .tab { padding:8px 16px; border-radius:6px; background:#1a1a1a; color:#a1a1aa; text-decoration:none; font-size:0.9rem; }
    .tab:hover { background:#2a2a2a; color:#fff; }
    .tab.active { background:#2563eb; color:#fff; }
    .tab .count { display:inline-block; background:#333; color:#ccc; padding:2px 6px; border-radius:10px; font-size:0.75rem; margin-left:6px; }
    .tab.active .count { background:#1d4ed8; color:#fff; }
    table { width:100%; border-collapse:collapse; margin-top:12px; font-size:0.85rem; }
    th, td { padding:8px 10px; border-bottom:1px solid #27272a; }
    th { background:#18181b; text-align:left; }
    tr:hover td { background:#111827; }
    .badge { display:inline-block; padding:2px 8px; border-radius:999px; font-size:0.7rem; }
    .badge-super { background:#1d4ed8; color:#bfdbfe; }
    .badge-admin-evento { background:#064e3b; color:#a7f3d0; }
    .badge-staff { background:#4b5563; color:#e5e7eb; }
    .badge-off { background:#7f1d1d; color:#fecaca; }
    .badge-pending { background:#854d0e; color:#fef3c7; }
    .badge-verified { background:#065f46; color:#a7f3d0; }
    .small-note { font-size:0.8rem; color:#9ca3af; margin-top:10px; line-height:1.4; }
    .empty { color:#6b7280; padding:20px; text-align:center; }
  </style>
</head>
<body>

<h1>Gestor de usuarios – TICKEX (STR)</h1>
<div class="sub">Vista completa de usuarios del sistema: admins, clientes registrados y solicitudes pendientes.</div>

<div><a class="btn-link" href="admin.php">⬅ Volver al panel principal</a></div>

<!-- Tabs -->
<div class="tabs">
  <a class="tab <?php echo $tab === 'admins' ? 'active' : ''; ?>" href="?tab=admins">Admins <span class="count"><?php echo count($adminRows); ?></span></a>
  <a class="tab <?php echo $tab === 'clientes' ? 'active' : ''; ?>" href="?tab=clientes">Clientes <span class="count"><?php echo count($clienteRows); ?></span></a>
  <a class="tab <?php echo $tab === 'pendientes' ? 'active' : ''; ?>" href="?tab=pendientes">Pendientes <span class="count"><?php echo count($pendienteRows); ?></span></a>
</div>

<?php if ($tab === 'admins'): ?>
<table>
  <thead><tr><th>#</th><th>Username</th><th>Tipo global</th><th>Rol (legacy)</th><th>Rol en evento</th><th>Activo</th></tr></thead>
  <tbody>
    <?php foreach ($adminRows as $r): ?>
      <tr>
        <td><?php echo (int)$r['id']; ?></td>
        <td><?php echo e($r['username']); ?></td>
        <td>
          <?php if ($r['tipo_global'] === 'super_admin'): ?><span class="badge badge-super">super_admin</span>
          <?php elseif ($r['tipo_global'] === 'admin_evento'): ?><span class="badge badge-admin-evento">admin_evento</span>
          <?php elseif ($r['tipo_global'] === 'staff_evento'): ?><span class="badge badge-staff">staff_evento</span>
          <?php else: ?><?php echo e($r['tipo_global']); ?><?php endif; ?>
        </td>
        <td><?php echo e($r['rol']); ?></td>
        <td><?php echo $r['rol_evento'] !== null ? e($r['rol_evento']) : '—'; ?></td>
        <td><?php echo (int)$r['activo'] === 1 ? '<span class="badge">ON</span>' : '<span class="badge badge-off">OFF</span>'; ?></td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<div class="small-note">– <strong>super_admin</strong>: controla toda la plataforma.<br>– <strong>admin_evento</strong>: puede crear eventos y staff.<br>– <strong>staff_evento</strong>: usuarios con permisos acotados.</div>

<?php elseif ($tab === 'clientes'): ?>
<?php if (empty($clienteRows)): ?><div class="empty">No hay clientes registrados.</div>
<?php else: ?>
<table>
  <thead><tr><th>#</th><th>Nombre</th><th>Apellido</th><th>Email</th><th>Verificado</th><th>Email confirmado</th><th>Registrado</th></tr></thead>
  <tbody>
    <?php foreach ($clienteRows as $c): ?>
      <tr>
        <td><?php echo (int)$c['id']; ?></td>
        <td><?php echo e($c['nombre']); ?></td>
        <td><?php echo e($c['apellido']); ?></td>
        <td><?php echo e($c['email']); ?></td>
        <td><?php echo !empty($c['verificado']) ? '<span class="badge badge-verified">✓</span>' : '<span class="badge badge-off">✗</span>'; ?></td>
        <td><?php echo !empty($c['email_confirmado']) ? '<span class="badge badge-verified">✓</span>' : '<span class="badge badge-off">✗</span>'; ?></td>
        <td><?php echo e($c['creado_en']); ?></td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
<div class="small-note">Total: <?php echo count($clienteRows); ?> clientes registrados.</div>

<?php elseif ($tab === 'pendientes'): ?>
<?php if (empty($pendienteRows)): ?><div class="empty">No hay registros pendientes.</div>
<?php else: ?>
<table>
  <thead><tr><th>#</th><th>Email</th><th>Nombre</th><th>Apellido</th><th>Apodo (Tickex ID)</th><th>Estado</th><th>Creado</th></tr></thead>
  <tbody>
    <?php foreach ($pendienteRows as $p): ?>
      <tr>
        <td><?php echo (int)$p['id']; ?></td>
        <td><?php echo e($p['email']); ?></td>
        <td><?php echo e($p['nombre'] ?? ''); ?></td>
        <td><?php echo e($p['apellido'] ?? ''); ?></td>
        <td><?php echo e($p['apodo'] ?? ''); ?></td>
        <td><?php echo !empty($p['completado_en']) ? '<span class="badge badge-verified">Completado</span>' : '<span class="badge badge-pending">Pendiente</span>'; ?></td>
        <td><?php echo e($p['creado_en']); ?></td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
<div class="small-note">Registros iniciados pero <strong>no completados</strong> (sin password). Total: <?php echo count($pendienteRows); ?>.</div>

<?php endif; ?>
</body>
</html>
