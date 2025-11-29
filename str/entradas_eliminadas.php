<?php
session_start();

// Mismo check de login que en admin.php
if (empty($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) {
    header('Location: admin.php');
    exit;
}

function e($str) {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

$dbFile = __DIR__ . '/save_the_rave.sqlite';
if (!file_exists($dbFile)) {
    http_response_code(500);
    echo "Base de datos no encontrada en: " . e($dbFile);
    exit;
}

try {
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    http_response_code(500);
    echo "Error al conectar a la base: " . e($e->getMessage());
    exit;
}

$stmt = $pdo->query("
    SELECT *
    FROM entradas_eliminadas
    ORDER BY deleted_at DESC, id DESC
");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <title>Entradas eliminadas – Save The Rave</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <style>
    :root { color-scheme: dark; }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      background: #050505;
      color: #f5f5f5;
      padding: 16px;
    }
    h1 {
      margin: 0 0 12px;
      font-size: 1.3rem;
    }
    a.btn-link {
      display: inline-block;
      padding: 4px 10px;
      border-radius: 999px;
      background: #222;
      color: #eee;
      text-decoration: none;
      font-size: 0.8rem;
      margin-bottom: 10px;
    }
    a.btn-link:hover { background: #2d2d2d; }
    table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 8px;
      font-size: 0.85rem;
    }
    th, td {
      border-bottom: 1px solid #333;
      padding: 6px 8px;
      vertical-align: middle;
    }
    th {
      background: #181818;
      text-align: left;
      position: sticky;
      top: 0;
      z-index: 1;
    }
    tr:hover td { background: #111; }
  </style>
</head>
<body>

<h1>Entradas eliminadas</h1>

<a class="btn-link" href="admin.php">Volver al panel</a>

<table>
  <thead>
    <tr>
      <th>ID original</th>
      <th>Nombre</th>
      <th>Email</th>
      <th>Fecha registro</th>
      <th>Tipo</th>
      <th>Monto</th>
      <th>Código</th>
      <th>Checked in</th>
      <th>Eliminada en</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td><?php echo (int)$r['id']; ?></td>
      <td><?php echo e($r['nombre']); ?></td>
      <td><?php echo e($r['email']); ?></td>
      <td><?php echo e($r['fecha_registro']); ?></td>
      <td><?php echo e($r['tipo']); ?></td>
      <td><?php echo e($r['monto_pagado']); ?></td>
      <td><?php echo e($r['codigo']); ?></td>
      <td><?php echo (isset($r['checked_in']) && (int)$r['checked_in'] === 1) ? 'Sí' : 'No'; ?></td>
      <td><?php echo e($r['deleted_at']); ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>

</body>
</html>
