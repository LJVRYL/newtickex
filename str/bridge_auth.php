<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$tokenFile = '/opt/ferozo3/web/.secrets/str_bridge_token';

$remote = $_SERVER['REMOTE_ADDR'] ?? '';
$debug  = (isset($_GET['debug']) && $_GET['debug'] === '1' && ($remote === '127.0.0.1' || $remote === '::1'));

$expected = @file_get_contents($tokenFile);

if ($debug) {
  $given = isset($_GET['t']) ? (string)$_GET['t'] : '';
  echo json_encode([
    "debug" => true,
    "remote_addr" => $remote,
    "token_file" => $tokenFile,
    "open_basedir" => (string)ini_get('open_basedir'),
    "read_expected" => ($expected !== false),
    "expected_len" => ($expected === false ? null : strlen($expected)),
    "expected_lastbyte" => ($expected === false ? null : ord(substr($expected, -1))),
    "given_len" => strlen($given),
    "given_lastbyte" => ($given === '' ? null : ord(substr($given, -1))),
  ]);
  exit;
}

if ($expected === false) {
  http_response_code(403);
  echo json_encode(["ok"=>false,"error"=>"forbidden"]);
  exit;
}

$expected = trim($expected);
$given = isset($_GET['t']) ? trim((string)$_GET['t']) : '';

if ($expected === '' || $given === '' || !hash_equals($expected, $given)) {
  http_response_code(403);
  echo json_encode(["ok"=>false,"error"=>"forbidden"]);
  exit;
}
