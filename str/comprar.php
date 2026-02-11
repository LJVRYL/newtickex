<?php
declare(strict_types=1);

// Compra pública: redirige a Tickex usando el mapeo en SQLite (tickex_event_map)
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

  $st = $pdo->prepare("SELECT event_public_id, event_slug FROM tickex_event_map WHERE str_event_id = :id LIMIT 1");
  $st->execute([':id' => $id]);
  $row = $st->fetch();

  $publicId = ($row && !empty($row['event_public_id'])) ? trim((string)$row['event_public_id']) : '';

  $targetSlug = '4aniversario';
  $targetPublicId = '08462152-64ed-4444-89ff-37c01d5b56c0';

  if ($eventSlug === $targetSlug && $publicId !== $targetPublicId) {
    $sql = $row
      ? "UPDATE tickex_event_map SET event_slug = :slug, event_public_id = :pub WHERE str_event_id = :id"
      : "INSERT INTO tickex_event_map (str_event_id, event_slug, event_public_id) VALUES (:id, :slug, :pub)";

    $stUp = $pdo->prepare($sql);
    $stUp->execute([
      ':id'   => $id,
      ':slug' => $targetSlug,
      ':pub'  => $targetPublicId,
    ]);

    $publicId = $targetPublicId;
  }

  if ($publicId === '') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "No existe mapeo Tickex para este evento STR (id=$id).\n";
    exit;
  }
  $url = "https://tickex.com.ar/Ticket/PublicTicket?EventPublicId=" . rawurlencode($publicId);

  header("Location: $url", true, 302);
  exit;

} catch (Throwable $e) {
  // No exponemos detalles al público
  http_response_code(500);
  header('Content-Type: text/plain; charset=utf-8');
  echo "Error interno.\n";
  exit;
}
