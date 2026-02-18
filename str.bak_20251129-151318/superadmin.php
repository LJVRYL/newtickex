<?php
session_start();

$dbFile = __DIR__ . '/save_the_rave.sqlite';

try {
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Throwable $e) {
    http_response_code(500);
    echo "Error DB: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
    exit;
}

// Verificar que el usuario logueado existe y sea super_admin
if (empty($_SESSION['usuario'])) {
    header('Location: admin.php');
    exit;
}

$stmt = $pdo->prepare("
    SELECT id, username, tipo_global
    FROM usuarios_admin
    WHERE username = :u
    LIMIT 1
");
$stmt->execute([':u' => $_SESSION['usuario']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user || $user['tipo_global'] !== 'super_admin') {
    // Si no es super admin, lo mandamos al panel normal
    header('Location: admin.php');
    exit;
}

// Stats simples de admins
$countAdmins = (int)$pdo->query("SELECT COUNT(*) FROM usuarios_admin")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Super Admin – TICKEX</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body {
      margin:0;
      padding:16px;
      font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      background:#020617;
      color:#e5e7eb;
    }
    h1 {
      font-size:1.5rem;
      margin:0 0 4px;
    }
    .sub {
      font-size:0.9rem;
      color:#9ca3af;
      margin-bottom:16px;
    }
    .top-bar {
      display:flex;
      justify-content:space-between;
      align-items:center;
      flex-wrap:wrap;
      gap:12px;
      margin-bottom:16px;
    }
    .btn-link {
      display:inline-block;
      padding:6px 10px;
      border-radius:999px;
      background:#111827;
      color:#e5e7eb;
      text-decoration:none;
      font-size:0.8rem;
      margin-right:8px;
      border:1px solid #1f2937;
    }
    .btn-link:hover { background:#1f2937; }
    .pill {
      font-size:0.8rem;
      color:#9ca3af;
    }
    .pill strong { color:#e5e7eb; }

    .cards {
      display:flex;
      flex-wrap:wrap;
      gap:12px;
      margin-top:8px;
      margin-bottom:16px;
    }
    .card {
      background:#020617;
      border-radius:14px;
      padding:14px 16px;
      border:1px solid #1f2937;
      box-shadow:0 10px 25px rgba(0,0,0,0.5);
      flex:1 1 220px;
    }
    .card-title {
      font-size:0.9rem;
      color:#9ca3af;
      margin-bottom:4px;
    }
    .card-value {
      font-size:1.6rem;
      font-weight:700;
      margin-bottom:2px;
    }
    .card-desc {
      font-size:0.8rem;
      color:#6b7280;
    }

    ul {
      font-size:0.85rem;
      color:#9ca3af;
      padding-left:18px;
    }
    li { margin-bottom:4px; }

    .logout {
      position:fixed;
      top:10px;
      right:10px;
      background:#111827;
      padding:6px 10px;
      border-radius:999px;
      text-decoration:none;
      color:#e5e7eb;
      font-size:0.75rem;
      border:1px solid #1f2937;
    }
    .logout:hover { background:#1f2937; }
  </style>
</head>
<body>

<a href="admin.php?logout=1" class="logout">Cerrar sesión</a>

<div class="top-bar">
  <div>
    <h1>Super Admin – TICKEX</h1>
    <div class="sub">
      Usuario: <strong><?php echo htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8'); ?></strong> (super_admin)
    </div>
  </div>
  <div>
    <a class="btn-link" href="admin.php">Panel STR actual</a>
    <a class="btn-link" href="eventos.php" target="_blank">Gestor de eventos</a>
    <a class="btn-link" href="usuarios.php" target="_blank">Gestor de admins</a>
  </div>
</div>

<div class="cards">
  <div class="card">
    <div class="card-title">Usuarios administrativos</div>
    <div class="card-value"><?php echo $countAdmins; ?></div>
    <div class="card-desc">super_admin, admins de evento y staff de evento registrados en STR.</div>
  </div>

  <div class="card">
    <div class="card-title">Panel STR</div>
    <div class="card-desc">
      El panel actual (<code>admin.php</code>) seguirá siendo el admin del evento Save The Rave (STR).<br>
      Desde acá vamos a ir separando la administración de plataforma (Tickex) de la de evento.
    </div>
  </div>
</div>

<div style="margin-top:10px;">
  <div class="card">
    <div class="card-title">Qué va a vivir en este panel</div>
    <ul>
      <li>Crear y administrar <strong>organizadores / admins de evento</strong>.</li>
      <li>Crear y administrar <strong>eventos</strong> (STR, RETRO, etc.).</li>
      <li>Ver un resumen global de la plataforma Tickex.</li>
      <li>Más adelante: conexión con el Tickex nativo y la pasarela de pagos.</li>
    </ul>
  </div>
</div>

</body>
</html>
