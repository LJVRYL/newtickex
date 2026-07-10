<?php
// Webhook compatible con /notification/tcwbh de SenForms para TotalCoin
// Valida API key y registra payload; aquí deberías marcar la orden pagada.
require_once __DIR__.'/inc/bootstrap.php';

$apiKeyHeader = isset($_SERVER['HTTP_API_KEY']) ? $_SERVER['HTTP_API_KEY'] : '';
$expected = getenv('TOTALCOIN_WEBHOOK_KEY') ?: 'sZ5&$xQj4!pBn#9tYr8^vGu1W@mC2*kD';

if ($apiKeyHeader !== $expected) {
    http_response_code(401);
    echo 'unauthorized';
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    $data = $_POST; // fallback si viene form-encoded
}

$concepto = isset($data['Concepto']) ? $data['Concepto'] : (isset($data['concepto']) ? $data['concepto'] : '');
$estado   = strtoupper(trim((string)(isset($data['Estado']) ? $data['Estado'] : (isset($data['estado']) ? $data['estado'] : ''))));

// Log simple en disco para depurar
$logLine = json_encode(array('ts'=>date('c'),'concepto'=>$concepto,'estado'=>$estado,'raw'=>$data), JSON_UNESCAPED_UNICODE);
@file_put_contents(__DIR__.'/logs_totalcoin_webhook.log', $logLine.PHP_EOL, FILE_APPEND);

if ($estado === 'APROBADO') {
    // TODO: buscar la orden en Tickex por $concepto o referencia y marcar pagada
    http_response_code(200);
    echo 'ok';
    exit;
}

http_response_code(200);
echo 'ignored';
