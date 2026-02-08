<?php
declare(strict_types=1);
require __DIR__ . "/bridge_auth.php";

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$tokenFile = '/etc/str/bridge_token';
$expected = is_readable($tokenFile) ? trim((string)file_get_contents($tokenFile)) : '';
$given = isset($_GET['t']) ? (string)$_GET['t'] : '';

if ($expected !== '') {
  if ($given === '' || !hash_equals($expected, $given)) {
    http_response_code(403);
    echo json_encode(['ok'=>false,'error'=>'forbidden'], JSON_UNESCAPED_SLASHES);
    exit;
  }
}

$dbPath = '/opt/ferozo3/web/str/save_the_rave.sqlite';
if (!is_readable($dbPath)) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'db_not_readable','db'=>$dbPath], JSON_UNESCAPED_SLASHES);
  exit;
}

try {
  $pdo = new PDO('sqlite:' . $dbPath, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);

  $summary = $pdo->query("
    SELECT
      COUNT(*) AS tickets,
      COALESCE(SUM(is_paid),0) AS paid,
      COALESCE(SUM(is_checked_in),0) AS checked_in,
      MAX(last_updated_at) AS last_updated_at
    FROM v_senforms_bridge_status
  ")->fetch() ?: [];

  $perEvent = $pdo->query("
    SELECT
      event_slug,
      COUNT(*) AS tickets,
      COALESCE(SUM(is_paid),0) AS paid,
      COALESCE(SUM(is_checked_in),0) AS checked_in,
      MAX(last_updated_at) AS last_updated_at
    FROM v_senforms_bridge_status
    GROUP BY event_slug
    ORDER BY event_slug
  ")->fetchAll();

  echo json_encode([
    'ok' => true,
    'generated_at' => gmdate('c'),
    'db' => basename($dbPath),
    'summary' => $summary,
    'per_event' => $perEvent,
  ], JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode([
    'ok'=>false,
    'error'=>'exception',
    'detail'=>substr($e->getMessage(), 0, 200),
  ], JSON_UNESCAPED_SLASHES);
}
