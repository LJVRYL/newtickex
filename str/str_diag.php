<?php
// STR diag logger: NO depende de error_log, escribe directo a archivo seguro fuera del docroot.
define('STR_DIAG_LOG', '/opt/ferozo3/web/.secrets/str_php_app.log');

function str_diag_log($msg) {
  $ts = date('Y-m-d H:i:s');
  $line = "[$ts] $msg\n";
  // LOCK_EX para evitar carreras
  @file_put_contents(STR_DIAG_LOG, $line, FILE_APPEND | LOCK_EX);
}

set_error_handler(function($severity, $message, $file, $line){
  str_diag_log("[STR][PHP] $message in $file:$line");
  return false;
});

set_exception_handler(function($e){
  $uri = $_SERVER['REQUEST_URI'] ?? '-';
  $method = $_SERVER['REQUEST_METHOD'] ?? '-';
  str_diag_log("[STR][EXC] ".get_class($e).": ".$e->getMessage()." in ".$e->getFile().":".$e->getLine()." uri=$uri method=$method");
});

register_shutdown_function(function(){
  $e = error_get_last();
  if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
    $uri = $_SERVER['REQUEST_URI'] ?? '-';
    $method = $_SERVER['REQUEST_METHOD'] ?? '-';
    str_diag_log("[STR][FATAL] {$e['message']} in {$e['file']}:{$e['line']} uri=$uri method=$method");
  }
});
