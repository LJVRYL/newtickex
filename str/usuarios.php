<?php
session_start();

// Sólo admin "plataforma" (rol=admin en la sesión) puede ver esto por ahora
if (!isset($_SESSION['rol']) || $_SESSION['rol'] !== 'admin') {
    header('Location: admin.php');
    exit;
}

// Cargar admins desde la base de STR (por ahora usamos esta como core)
$dbFile = __DIR__ . '/save_the_rave.sqlite';

try {
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $pdo->query("
        SELECT id, username, password, rol, tipo_global, rol_evento, activo
        FROM usuarios_admin
        ORDER BY id ASC
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
    http_response_code(500);
    echo "<pre>Error al leer usuarios_admin: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . "</pre>";
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
    body {
      margin:0;
      padding:16px;
      font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      background:#050505;
      color:#f5f5f5;
    }
    h1 {
      font-size:1.4rem;
      margin:0 0 4px;
    }
    .sub {
      font-size:0.85rem;
      color:#a1a1aa;
      margin-bottom:16px;
    }
    .btn-link {
      display:inline-block;
      padding:6px 10px;
      border-radius:999px;
      background:#222;
      color:#eee;
      text-decoration:none;
      font-size:0.8rem;
      margin-right:8px;
    }
    .btn-link:hover { background:#2d2d2d; }

    table {
      width:100%;
      border-collapse:collapse;
      margin-top:12px;
      font-size:0.85rem;
    }
    th, td {
      padding:6px 8px;
      border-bottom:1px solid #27272a;
    }
    th {
      background:#18181b;
      text-align:left;
    }
    tr:hover td {
      background:#111827;
    }
    .badge {
      display:inline-block;
      padding:2px 8px;
      border-radius:999px;
      font-size:0.7rem;
    }
    .badge-super {
      background:#1d4ed8;
      color:#bfdbfe;
    }
    .badge-admin-evento {
      background:#064e3b;
      color:#a7f3d0;
    }
    .badge-staff {
      background:#4b5563;
      color:#e5e7eb;
    }
    .badge-off {
      background:#7f1d1d;
      color:#fecaca;
    }
    .small-note {
      font-size:0.8rem;
      color:#9ca3af;
      margin-top:10px;
      line-height:1.4;
    }
  </style>
</head>
<body>

<h1>Gestor de usuarios – TICKEX (STR)</h1>
<div class="sub">
  Vista de usuarios administrativos actuales (super admin, admins de evento, staff de evento).<br>
  Más adelante, desde acá vamos a poder crear y editar estos usuarios.
</div>

<div>
  <a class="btn-link" href="admin.php">⬅ Volver al panel principal</a>
</div>

<table>
  <thead>
    <tr>
      <th>#</th>
      <th>Username</th>
      <th>Tipo global</th>
      <th>Rol (legacy)</th>
      <th>Rol en evento</th>
      <th>Activo</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><?php echo (int)$r['id']; ?></td>
        <td><?php echo e($r['username']); ?></td>
        <td>
          <?php if ($r['tipo_global'] === 'super_admin'): ?>
            <span class="badge badge-super">super_admin</span>
          <?php elseif ($r['tipo_global'] === 'admin_evento'): ?>
            <span class="badge badge-admin-evento">admin_evento</span>
          <?php elseif ($r['tipo_global'] === 'staff_evento'): ?>
            <span class="badge badge-staff">staff_evento</span>
          <?php else: ?>
            <?php echo e($r['tipo_global']); ?>
          <?php endif; ?>
        </td>
        <td><?php echo e($r['rol']); ?></td>
        <td><?php echo $r['rol_evento'] !== null ? e($r['rol_evento']) : '—'; ?></td>
        <td>
          <?php if ((int)$r['activo'] === 1): ?>
            <span class="badge">ON</span>
          <?php else: ?>
            <spa
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>

<div class="small-note">
  Notas rápidas:<br>
  – <strong>super_admin</strong>: controla toda la plataforma (vos).<br>
  – <strong>admin_evento</strong>: podrá crear eventos y staff para sus eventos.<br>
  – <strong>staff_evento</strong>: usuarios como <code>puerta</code>, con permisos acotados por evento.<br>
  Más adelante desde acá vamos a poder crear nuevos usuarios, cambiar contraseñas y permisos.
</div>

</body>
</html>
