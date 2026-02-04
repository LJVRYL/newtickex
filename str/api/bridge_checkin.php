<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

function respond(int $code, array $data): void {
  http_response_code($code);
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  respond(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

$raw = (string)file_get_contents('php://input');
$ct  = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
$data = [];

if (str_contains($ct, 'application/json')) {
  $data = json_decode($raw, true);
  if (!is_array($data)) $data = [];
} else {
  $data = $_POST ?? [];
  if (!is_array($data)) $data = [];
}

$secret = (string)($data['secret'] ?? '');
if ($secret === '' || $secret !== 'dev-secret') {
  respond(401, ['ok' => false, 'error' => 'unauthorized']);
}

$eventSlug = trim((string)($data['event_slug'] ?? ''));
if ($eventSlug === '') {
  respond(400, ['ok' => false, 'error' => 'missing_event_slug']);
}

/**
 * ticket_ref: algo que identifique la entrada en el sistema viejo
 * Puede ser: ticket_id, qr_token, codigo_unico, hash, etc.
 * Mientras sea estable, sirve.
 */
$ticketRef = trim((string)($data['ticket_ref'] ?? ''));
if ($ticketRef === '') {
  respond(400, ['ok' => false, 'error' => 'missing_ticket_ref']);
}

$result  = (string)($data['result'] ?? 'ok'); // ok|reject
$reason  = (string)($data['reason'] ?? '');
$legacySource = (string)($data['legacy_source'] ?? 'tickex_old');
$legacyCheckinId = (string)($data['legacy_checkin_id'] ?? '');

$email = trim((string)($data['email'] ?? ''));
$nombre = trim((string)($data['nombre'] ?? ''));
$apellido = trim((string)($data['apellido'] ?? ''));

$dbPath = __DIR__ . '/../save_the_rave.sqlite';
if (!is_file($dbPath)) {
  respond(500, ['ok' => false, 'error' => 'db_not_found', 'db' => $dbPath]);
}

try {
  $pdo = new PDO('sqlite:' . $dbPath, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
  $pdo->exec("PRAGMA busy_timeout = 4000;");

  $pdo->exec("
    CREATE TABLE IF NOT EXISTS bridge_checkins (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      creado_en TEXT NOT NULL,
      event_slug TEXT NOT NULL,
      ticket_ref TEXT NOT NULL,
      result TEXT NOT NULL,
      reason TEXT,
      legacy_source TEXT,
      legacy_checkin_id TEXT,
      nombre TEXT,
      apellido TEXT,
      email TEXT,
      payload_json TEXT NOT NULL,
      ip TEXT,
      user_agent TEXT
    );
  ");
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_bridge_checkins_event ON bridge_checkins(event_slug);");
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_bridge_checkins_ticket ON bridge_checkins(ticket_ref);");
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_bridge_checkins_email ON bridge_checkins(email);");

  // Anti-duplicados opcional: si mandan el mismo ticket_ref OK varias veces, queda igual registrado.
  // Si querés bloquear duplicados más adelante, lo hacemos con UNIQUE(event_slug,ticket_ref,result).

  $payloadJson = json_encode([
    'received_at' => date('c'),
    'content_type' => $ct,
    'data' => $data,
    'raw'  => $raw,
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

  $stmt = $pdo->prepare("
    INSERT INTO bridge_checkins
      (creado_en, event_slug, ticket_ref, result, reason, legacy_source, legacy_checkin_id,
       nombre, apellido, email, payload_json, ip, user_agent)
    VALUES
      (:creado_en, :event_slug, :ticket_ref, :result, :reason, :legacy_source, :legacy_checkin_id,
       :nombre, :apellido, :email, :payload_json, :ip, :ua)
  ");

  $stmt->execute([
    ':creado_en' => date('c'),
    ':event_slug' => $eventSlug,
    ':ticket_ref' => $ticketRef,
    ':result' => $result !== '' ? $result : 'ok',
    ':reason' => $reason !== '' ? $reason : null,
    ':legacy_source' => $legacySource !== '' ? $legacySource : null,
    ':legacy_checkin_id' => $legacyCheckinId !== '' ? $legacyCheckinId : null,
    ':nombre' => $nombre !== '' ? $nombre : null,
    ':apellido' => $apellido !== '' ? $apellido : null,
    ':email' => $email !== '' ? $email : null,
    ':payload_json' => $payloadJson,
    ':ip' => $_SERVER['REMOTE_ADDR'] ?? null,
    ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? null,
  ]);

  respond(200, ['ok' => true, 'id' => (int)$pdo->lastInsertId()]);

} catch (Throwable $e) {
  @file_put_contents(__DIR__ . '/../bridge_checkin_error.log',
    date('c') . " " . $e->getMessage() . "\n",
    FILE_APPEND
  );
  respond(500, ['ok' => false, 'error' => 'db_error']);
}
