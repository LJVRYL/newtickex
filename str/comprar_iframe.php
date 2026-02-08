<?php
declare(strict_types=1);

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { http_response_code(400); echo "Falta id\n"; exit; }

try {
  $pdo = new PDO('sqlite:' . __DIR__ . '/save_the_rave.sqlite', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);

  $st = $pdo->prepare("SELECT event_public_id FROM tickex_event_map WHERE str_event_id = :id LIMIT 1");
  $st->execute([':id' => $id]);
  $row = $st->fetch();

  if (!$row || empty($row['event_public_id'])) {
    http_response_code(404);
    echo "No existe mapeo Tickex para str_event_id=$id\n";
    exit;
  }

  $publicId = trim((string)$row['event_public_id']);
  $tickexUrl = "https://tickex.com.ar/Ticket/PublicTicket?EventPublicId=" . rawurlencode($publicId);

} catch (Throwable $e) {
  http_response_code(500);
  echo "Error interno\n";
  exit;
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Comprar entradas</title>
  <style>
    html,body{height:100%;margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial;}
    .top{padding:10px 12px;background:#111;color:#fff;display:flex;gap:10px;align-items:center;flex-wrap:wrap}
    .top a{color:#fff;text-decoration:underline}
    .wrap{height:calc(100% - 52px);}
    iframe{width:100%;height:100%;border:0;}
  </style>
</head>
<body>
  <div class="top">
    <div>Compra segura (Tickex)</div>
    <a href="<?php echo htmlspecialchars($tickexUrl, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">Abrir en pestaña</a>
  </div>
  <div class="wrap">
    <iframe src="<?php echo htmlspecialchars($tickexUrl, ENT_QUOTES, 'UTF-8'); ?>" referrerpolicy="no-referrer"></iframe>
  </div>
</body>
</html>
