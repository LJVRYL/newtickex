<?php
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
        $stmt = $pdo->prepare('SELECT id, nombre, email, fecha_registro, codigo FROM entradas WHERE codigo = :codigo');
        $stmt->execute([':codigo' => $codigo]);
        $entrada = $stmt->fetch(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    die('Error al leer la base: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}

$baseUrl   = 'https://str.tickex.com.ar';

$subject = '';
$body    = '';

if ($entrada) {
    $ticketUrl  = $baseUrl . '/entrada.php?c=' . urlencode($entrada['codigo']);   // lo que ve la persona
    $subject = 'Tu entrada #' . (int)$entrada['id'] . ' para Save The Rave';

    $body  = 'Hola ' . $entrada['nombre'] . ',' . "\n\n";
    $body .= "Te dejo tu entrada para Save The Rave:\n\n";
    $body .= 'Número de entrada: #' . (int)$entrada['id'] . "\n";
    $body .= 'Nombre / alias: ' . $entrada['nombre'] . "\n";
    $body .= 'Email registrado: ' . $entrada['email'] . "\n\n";
    $body .= "Abrí este link para ver tu QR de acceso:\n";
    $body .= $ticketUrl . "\n\n";
    $body .= "En la puerta vamos a escanear ese QR para validar tu entrada.\n";
    $body .= "Guardá este mensaje hasta la fecha del evento.\n";
    $body .= "¡Gracias por ser parte de Save The Rave!\n";
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <title>Texto de email – Save The Rave</title>
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
      max-width: 600px;
      background: #111;
      border-radius: 16px;
      padding: 20px 22px;
      box-shadow: 0 18px 36px rgba(0,0,0,0.6);
    }
    h1 {
      font-size: 1.3rem;
      margin-bottom: 12px;
    }
    label {
      font-size: 0.85rem;
      display: block;
      margin-top: 10px;
      margin-bottom: 4px;
      color: #ccc;
    }
    input[type="text"] {
      width: 100%;
      padding: 8px 10px;
      border-radius: 8px;
      border: 1px solid #444;
      background: #000;
      color: #f5f5f5;
      font-size: 0.9rem;
    }
    textarea {
      width: 100%;
      min-height: 200px;
      padding: 8px 10px;
      border-radius: 8px;
      border: 1px solid #444;
      background: #000;
      color: #f5f5f5;
      font-size: 0.9rem;
      resize: vertical;
      white-space: pre-wrap;
    }
    .small {
      font-size: 0.8rem;
      color: #888;
      margin-top: 6px;
    }
    .notfound {
      color: #ff8c8c;
    }
  </style>
</head>
<body>
  <div class="card">
    <h1>Texto de email – Save The Rave</h1>

    <?php if (!$entrada): ?>
      <p class="notfound">No se encontró la entrada para este código.</p>
    <?php else: ?>
      <p class="small">
        Copiá estos datos en tu Gmail: el asunto y el cuerpo del mensaje.
      </p>

      <label for="subject">Asunto sugerido</label>
      <input
        id="subject"
        type="text"
        readonly
        value="<?php echo htmlspecialchars($subject, ENT_QUOTES, 'UTF-8'); ?>"
      />

      <label for="body">Texto del email para copiar</label>
      <textarea id="body" readonly><?php echo htmlspecialchars($body, ENT_QUOTES, 'UTF-8'); ?></textarea>

      <p class="small">
        Tip: seleccioná el texto del cuerpo, copialo y pegalo directo en Gmail/Outlook.
      </p>
    <?php endif; ?>
  </div>
</body>
</html>
