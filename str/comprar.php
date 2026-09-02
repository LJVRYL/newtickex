<?php
declare(strict_types=1);

// Compra pública: ahora redirige al checkout interno TotalCoin de Tickex, precargando datos del evento.
// URL: /comprar.php?id=12  (id = eventos.id de STR)

require_once __DIR__ . '/inc/bootstrap.php';

// La redireccion contiene una referencia unica por intento. Evitar que el
// navegador reutilice un 302 anterior y termine reenviando una compra vieja.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
  http_response_code(400);
  header('Content-Type: text/plain; charset=utf-8');
  echo "Falta parámetro id (evento STR). Ej: /comprar.php?id=12\n";
  exit;
}

// Tracking revendedor (afiliado): ?aff=ID
$aff = 0;
if (isset($_GET['aff'])) {
  $aff = (int)$_GET['aff'];
}
if ($aff > 0) {
  // last-click: siempre pisa
  tickex_set_cookie('tickex_aff', (string)$aff, 30, '/');
}

try {
  $pdo = db();

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
  $ref = tickex_totalcoin_new_reference($eventSlug !== '' ? $eventSlug : (string)$id);
  $q = array(
    'event'   => $id,
    'concept' => $concept,
    'ref'     => $ref,
  );
  if ($aff > 0) {
    $q['aff'] = $aff;
  }
  $qs = http_build_query($q);
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
