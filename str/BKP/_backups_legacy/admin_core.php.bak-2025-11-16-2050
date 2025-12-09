<?php
// Ruta de la base
$dbFile = __DIR__ . '/save_the_rave.sqlite';

// Si no existe la base, mostramos error claro
if (!file_exists($dbFile)) {
    http_response_code(500);
    echo "Base de datos no encontrada en: " . htmlspecialchars($dbFile, ENT_QUOTES, 'UTF-8');
    exit;
}

try {
    // Conexión a la base
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // --- Eliminar entrada si viene ?delete=ID ---
    if (isset($_GET['delete'])) {
        $id = (int) $_GET['delete'];
        if ($id > 0) {
            $stmtDel = $pdo->prepare('DELETE FROM entradas WHERE id = :id');
            $stmtDel->execute([':id' => $id]);
        }
        // Redirigimos al panel para evitar reenvíos
        header('Location: admin.php');
        exit;
    }

    // === CONTADORES ===
    $total = (int) $pdo->query("SELECT COUNT(*) FROM entradas")->fetchColumn();

    // Detectar si existe la columna 'checkin'
    $colsStmt = $pdo->query("PRAGMA table_info(entradas)");
    $cols = $colsStmt->fetchAll(PDO::FETCH_ASSOC);
    $hasCheckin = false;
    foreach ($cols as $col) {
        if (isset($col['name']) && $col['name'] === 'checkin') {
            $hasCheckin = true;
            break;
        }
    }

    if ($hasCheckin) {
        $checkins = (int) $pdo->query("SELECT COUNT(*) FROM entradas WHERE checkin = 1")->fetchColumn();
    } else {
        // Si no existe la columna, por ahora contamos 0 checkins
        $checkins = 0;
    }

    $faltan = max(0, $total - $checkins);

    // Obtener todas las entradas
    $stmt = $pdo->query("SELECT * FROM entradas ORDER BY id DESC");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    // Si algo peta en la base, mostramos el mensaje en pantalla
    http_response_code(500);
    echo "<!DOCTYPE html><html lang=\"es\"><head><meta charset=\"UTF-8\"><title>Error en Admin</title></head>";
    echo "<body style=\"background:#111;color:#f5f5f5;font-family:system-ui,-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;padding:20px;\">";
    echo "<h1>Error en el panel</h1>";
    echo "<p>Ocurrió un problema al leer la base de datos.</p>";
    echo "<pre style=\"margin-top:15px;padding:10px;border-radius:8px;background:#1a1a1a;color:#ffb3b3;white-space:pre-wrap;\">";
    echo htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
    echo "</pre></body></html>";
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <title>Save The Rave – Panel Admin</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <style>
    body {
      font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      background: #111;
      color: #f5f5f5;
      padding: 20px;
    }
    h1 { margin-bottom: 10px; }

    /* Tarjetas contadoras */
    .stats {
      display: flex;
      gap: 16px;
      margin-bottom: 18px;
      flex-wrap: wrap;
    }
    .card {
      background: #1a1a1a;
      padding: 16px 20px;
      border-radius: 14px;
      box-shadow: 0 8px 20px rgba(0,0,0,0.4);
      flex: 1 1 180px;
    }
    .card .label {
      font-size: 0.85rem;
      color: #aaa;
    }
    .card .value {
      font-size: 1.6rem;
      font-weight: 700;
      margin-top: 4px;
    }

    table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 16px;
      font-size: 0.9rem;
    }
    th, td {
      border-bottom: 1px solid #333;
      padding: 8px 10px;
    }
    th {
      background: #222;
      text-align: left;
    }
    tr:hover td { background: #1a1a1a; }

    .btn-del {
      background: #d71d24;
      padding: 4px 10px;
      border-radius: 6px;
      color: white;
      font-size: 0.8rem;
      text-decoration: none;
    }

    .logout {
      position: fixed;
      top: 20px;
      right: 20px;
      background: #333;
      padding: 6px 10px;
      border-radius: 8px;
      font-size: 0.85rem;
      text-decoration: none;
      color: #eee;
    }
    .logout:ho
  </style>
</head>
<body>

<a href="admin.php?logout=1" class="logout">Cerrar sesión</a>

<h1>Panel de Administración – Save The Rave</h1>

<!-- Contadores -->
<div class="stats">
  <div class="card">
    <div class="label">Entradas Totales</div>
    <div class="value"><?php echo $total; ?></div>
  </div>

  <div class="card">
    <div class="label">Check-ins realizados</div>
    <div class="value">
      <?php echo $checkins; ?>
    </div>
  </div>

  <div class="card">
    <div class="label">Faltan por escanear</div>
    <div class="value"><?php echo $faltan; ?></div>
  </div>
</div>

<!-- Tabla principal -->
<table>
  <tr>
    <th>ID</th>
    <th>Nombre</th>
    <th>Email</th>
    <th>Tipo</th>
    <th>Monto</th>
    <th>Check-in</th>
    <th>Acción</th>
  </tr>

  <?php foreach ($rows as $r): ?>
    <tr>
      <td><?php echo (int) $r['id']; ?></td>
      <td><?php echo htmlspecialchars($r['nombre'], ENT_QUOTES, 'UTF-8'); ?></td>
      <td><?php echo htmlspecialchars($r['email'], ENT_QUOTES, 'UTF-8'); ?></td>
      <td><?php echo htmlspecialchars($r['tipo'], ENT_QUOTES, 'UTF-8'); ?></td>
      <td><?php echo htmlspecialchars($r['monto'], ENT_QUOTES, 'UTF-8'); ?></td>
      <td>
        <?php
        if (isset($r['checkin']) && (int)$r['checkin'] === 1) {
            echo '✔';
        } else {
            echo '—';
        }
        ?>
      </td>
      <td>
        <a class="btn-del" href="?delete=<?php echo (int) $r['id']; ?>"
           onclick="return confirm('¿Eliminar esta entrada?');">
          borrar
        </a>
      </td>
    </tr>
  <?php endforeach; ?>
</table>

</body>
</html>
