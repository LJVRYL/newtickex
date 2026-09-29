<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$dbFile='';foreach($argv as $arg)if(strpos($arg,'--db=')===0)$dbFile=substr($arg,5);
if($dbFile===''||!is_file($dbFile)){fwrite(STDERR,"Uso: php run_20260908_organizer_branding_domains.php --db=/ruta/copia.sqlite\n");exit(1);}
require_once __DIR__.'/../inc/organizer_site.php';
try{$pdo=new PDO('sqlite:'.$dbFile);$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->exec('PRAGMA busy_timeout=15000');tickex_organizer_site_ensure_schema($pdo);if($pdo->query('PRAGMA integrity_check')->fetchColumn()!=='ok')throw new RuntimeException('Falló la integridad SQLite.');echo 'Sitios, marca y dominios preparados en: '.$dbFile.PHP_EOL;}catch(Exception $e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);}
