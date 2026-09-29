<?php
// Compatibilidad con enlaces históricos: convierte el código interno en enlace opaco.
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/secure_links.php';

$codigo = isset($_GET['c']) ? trim((string)$_GET['c']) : '';
if ($codigo === '') { http_response_code(400); echo 'Falta la entrada.'; exit; }

$pdo = db();
$st = $pdo->prepare('SELECT id, codigo FROM entradas WHERE codigo = :codigo LIMIT 1');
$st->execute(array(':codigo' => $codigo));
$entrada = $st->fetch(PDO::FETCH_ASSOC);
if (!$entrada) { http_response_code(404); echo 'Entrada no encontrada.'; exit; }

header('Cache-Control: no-store, private');
header('Location: ' . tickex_secure_ticket_url($pdo, '', (int)$entrada['id'], (string)$entrada['codigo']), true, 302);
exit;
