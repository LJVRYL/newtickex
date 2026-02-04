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

// JSON o x-www-form-urlencoded
if (str_contains($ct, 'application/json')) {
  $data = json_decode($raw, true);
  if (!is_array($data)) $data = [];
} else {
  $data = $_POST ?? [];
  if (!is_array($data)) $data = [];
}

$secret = (string)($data['secret'] ?? '');
if ($secret === '' || $secret !== 'dev-secret') {
  // En local usamos dev-secret. En producción después lo cambiamos a archivo fuera del repo.
  respond(401, ['ok' => false, 'error' => 'unauthorized']);
}

$eventSlug = trim((string)($data['event_slug'] ?? ''));
if ($eventSlug === '') {
  respond(400, ['ok' => false, 'error' => 'missing_event_slug']);
}

$nombre = trim((string)($data['nombre'] ?? ''));
$apellido = trim((string)($data['apellido'] ?? ''));
$email  = trim((string)($data['email'] ?? ''));

$legacySource = (string)($data['legacy_source'] ?? 'tickex_old');
$legacyRef    = (string)($data['legacy_ref'] ?? '');
$status       = (string)($data['status'] ?? 'created'); // created|paid|etc

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

  // Tabla aislada: no tocamos tu esquema existente
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS bridge_ventas (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      creado_en TEXT NOT NULL,
      event_slug TEXT NOT NULL,
      nombre TEXT,
      apellido TEXT,
      email TEXT,
      status TEXT,
      legacy_source TEXT,
      legacy_ref TEXT,
      payload_json TEXT NOT NULL,
      ip TEXT,
      user_agent TEXT
    );
  ");
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_bridge_ventas_event ON bridge_ventas(event_slug);");
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_bridge_ventas_email ON bridge_ventas(email);");
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_bridge_ventas_ref ON bridge_ventas(legacy_ref);");

  $payloadJson = json_encode([
    'received_at' => date('c'),
    'content_type' => $ct,
    'data' => $data,
    'raw'  => $raw,
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

  $stmt = $pdo->prepare("
    INSERT INTO bridge_ventas
      (creado_en, event_slug, nombre, apellido, email, status, legacy_source, legacy_ref, payload_json, ip, user_agent)
    VALUES
      (:creado_en, :event_slug, :nombre, :apellido, :email, :status, :legacy_source, :legacy_ref, :payload_json, :ip, :ua)
  ");

  $stmt->execute([
    ':creado_en' => date('c'),
    ':event_slug' => $eventSlug,
    ':nombre' => $nombre !== '' ? $nombre : null,
    ':apellido' => $apellido !== '' ? $apellido : null,
    ':email' => $email !== '' ? $email : null,
    ':status' => $status !== '' ? $status : null,
    ':legacy_source' => $legacySource !== '' ? $legacySource : null,
    ':legacy_ref' => $legacyRef !== '' ? $legacyRef : null,
    ':payload_json' => $payloadJson,
    ':ip' => $_SERVER['REMOTE_ADDR'] ?? null,
    ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? null,
  ]);

  respond(200, ['ok' => true, 'id' => (int)$pdo->lastInsertId()]);

} catch (Throwable $e) {
  @file_put_contents(__DIR__ . '/../bridge_ingest_error.log',
    date('c') . " " . $e->getMessage() . "\n",
    FILE_APPEND
  );
  respond(500, ['ok' => false, 'error' => 'db_error']);
}
