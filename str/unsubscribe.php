<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/communication_suppressions.php';

$pdo = db();
$token = isset($_REQUEST['token']) ? trim((string)$_REQUEST['token']) : '';
$completed = false;
$invalid = ($token === '' || !preg_match('/^[a-f0-9]{40,80}$/i', $token));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$invalid) {
    $completed = communication_suppressions_unsubscribe_token($pdo, $token);
    if (!$completed) $invalid = true;
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Preferencias de email | Tickex</title>
  <style>
    body{margin:0;background:#070914;color:#f5f7ff;font-family:Arial,sans-serif;padding:24px}
    .card{max-width:580px;margin:10vh auto;background:#111526;border:1px solid #29304a;border-radius:18px;padding:28px;box-shadow:0 18px 55px rgba(0,0,0,.35)}
    h1{font-size:26px;margin-top:0}p{line-height:1.55;color:#c9d0e3}.btn{background:#28d17c;color:#07120c;border:0;border-radius:999px;padding:12px 20px;font-weight:700;cursor:pointer}
  </style>
</head>
<body>
  <main class="card">
    <?php if ($completed): ?>
      <h1>Preferencia actualizada</h1>
      <p>Ya no vas a recibir campañas de este organizador. Los emails necesarios para compras, entradas o seguridad de tu cuenta pueden seguir llegando.</p>
    <?php elseif ($invalid): ?>
      <h1>Enlace inválido</h1>
      <p>Este enlace de baja no existe o ya no es válido.</p>
    <?php else: ?>
      <h1>Dejar de recibir comunicaciones</h1>
      <p>Esta acción desactiva los newsletters y campañas de este organizador. No afecta tus entradas ni los mensajes necesarios para operar tu cuenta.</p>
      <form method="post">
        <input type="hidden" name="token" value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">
        <button class="btn" type="submit">Confirmar baja</button>
      </form>
    <?php endif; ?>
  </main>
</body>
</html>
