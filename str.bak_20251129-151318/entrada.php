<?php
// entrada.php – muestra el QR de la entrada, pero NO marca check-in
date_default_timezone_set('America/Argentina/Buenos_Aires');

$dbFile = __DIR__ . '/save_the_rave.sqlite';
if (!file_exists($dbFile)) {
    die('Base de datos no encontrada.');
}

$codigo = isset($_GET['c']) ? trim($_GET['c']) : '';

try {
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $entrada = null;
    if ($codigo !== '') {
        $stmt = $pdo->prepare('SELECT id, nombre, email, fecha_registro, codigo, checked_in, checked_in_at FROM entradas WHERE codigo = :codigo');
        $stmt->execute([':codigo' => $codigo]);
        $entrada = $stmt->fetch(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    die('Error al leer la base: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}

$baseUrl    = 'https://str.tickex.com.ar';

// ⚠️ Ojo: el QR apunta al checkin.php → eso es lo que se escanea en puerta
$checkinUrl = $baseUrl . '/checkin.php?c=' . urlencode($codigo);
$qrUrl      = 'https://api.qrserver.com/v1/create-qr-code/?size=260x260&data=' . urlencode($checkinUrl);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <title>Tu entrada – Save The Rave</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <style>
    body {
      font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      background: #000;
      color: #f5f5f5;
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 20px;
    }
    .card {
      width: 100%;
      max-width: 420px;
      background: #111;
      border-radius: 16px;
      padding: 20px 22px;
      box-shadow: 0 18px 36px rgba(0,0,0,0.6);
      text-align: center;
    }
    h1 {
      font-size: 1.4rem;
      margin-bottom: 12px;
    }
    .info {
      font-size: 0.9rem;
      margin-bottom: 12px;
    }
    .info small {
      display: block;
      font-size: 0.75rem;
      color: #aaa;
    }
    .qr {
      margin-top: 10px;
      margin-bottom: 12px;
    }
    .qr img {
      max-width: 100%;
      height: auto;
      border-radius: 8px;
      background: #fff;
      padding: 6px;
    }
    .small {
      font-size: 0.75rem;
      color: #aaa;
    }
    .notfound {
      color: #ff9b9b;
    }
  </style>
</head>
<body>
  <div class="card">
    <?php if (!$entrada): ?>
      <h1>Código no válido</h1>
      <p class="notfound">No encontramos una entrada para este link.</p>
    <?php else: ?>
      <h1>Tu entrada – Save The Rave</h1>
      <p class="info">
        #<?php echo (int)$entrada['id']; ?> –
        <?php echo htmlspecialchars($entrada['nombre'], ENT_QUOTES, 'UTF-8'); ?>
        <small><?php echo htmlspecialchars($entrada['email'], ENT_QUOTES, 'UTF-8'); ?></small>
      </p>

      <div class="qr">
        <img
          src="<?php echo htmlspecialchars($qrUrl, ENT_QUOTES, 'UTF-8'); ?>"
          alt="QR de entrada"
        />
      </div>

      <p class="small">
        Mostrá este QR en la puerta. Nuestro lector va a escanearlo y registrar tu ingreso.
      </p>

      <?php if ((int)$entrada['checked_in'] === 1 && !empty($entrada['checked_in_at'])): ?>
        <p class="small">
          Esta entrada ya figura como chequeada el
          <?php echo htmlspecialchars($entrada['checked_in_at'], ENT_QUOTES, 'UTF-8'); ?>.
        </p>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</body>
</html>
