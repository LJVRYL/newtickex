<?php
declare(strict_types=1);

// Compra pública: ahora redirige al checkout interno TotalCoin de Tickex, precargando datos del evento.
// URL: /comprar.php?id=12  (id = eventos.id de STR)

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
  http_response_code(400);
  header('Content-Type: text/plain; charset=utf-8');
  echo "Falta parámetro id (evento STR). Ej: /comprar.php?id=12\n";
  exit;
}

try {
  $dbPath = __DIR__ . '/save_the_rave.sqlite';
  $pdo = new PDO('sqlite:' . $dbPath, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);

  // Detectar slug del evento y forzar el nuevo EventPublicId si es 4aniversario
  $eventSlug = '';
  $stSlug = $pdo->prepare("SELECT slug FROM eventos WHERE id = :id LIMIT 1");
  if ($stSlug->execute([':id' => $id])) {
    $rowSlug = $stSlug->fetch();
    if ($rowSlug && isset($rowSlug['slug'])) {
      $eventSlug = strtolower(trim((string)$rowSlug['slug']));
    }
  }

  // Redirigimos al checkout interno TotalCoin con datos precargados
  $concept = $eventSlug !== '' ? $eventSlug : ('Evento-' . $id);
  $ref = 'str-' . ($eventSlug !== '' ? $eventSlug : $id) . '-' . time();
  $qs = http_build_query(array(
    'event'   => $id,
    'concept' => $concept,
    'ref'     => $ref,
  ));
  $url = 'checkout_totalcoin.php?' . $qs;
  header("Location: $url", true, 302);
  exit;

} catch (Throwable $e) {
  // No exponemos detalles al público
  http_response_code(500);
  header('Content-Type: text/plain; charset=utf-8');
  echo "Error interno.\n";
  exit;
}
