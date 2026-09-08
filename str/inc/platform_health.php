<?php

if(!function_exists('tickex_platform_health_add')){
 function tickex_platform_health_add(&$checks,$key,$label,$status,$value,$detail,$group){$checks[]=array('key'=>$key,'label'=>$label,'status'=>$status,'value'=>$value,'detail'=>$detail,'group'=>$group);}
}
if(!function_exists('tickex_platform_health_version')){
 function tickex_platform_health_version($raw){return preg_match('/(\d+\.\d+(?:\.\d+)?)/',(string)$raw,$m)?$m[1]:'';}
}
if(!function_exists('tickex_platform_health_latest_backup')){
 function tickex_platform_health_latest_backup($dbPath){$dirs=array(dirname($dbPath).DIRECTORY_SEPARATOR.'BKP',dirname(dirname(dirname($dbPath))).DIRECTORY_SEPARATOR.'deploy_backups');$latest=null;foreach($dirs as $dir){if(!is_dir($dir))continue;$patterns=array($dir.DIRECTORY_SEPARATOR.'*.sqlite*',$dir.DIRECTORY_SEPARATOR.'*'.DIRECTORY_SEPARATOR.'*.sqlite*');foreach($patterns as $pattern){$files=glob($pattern);if(!is_array($files))continue;foreach($files as $file){if(!is_file($file)||realpath($file)===realpath($dbPath))continue;$mtime=@filemtime($file);if($mtime!==false&&($latest===null||$mtime>$latest['mtime']))$latest=array('path'=>$file,'mtime'=>$mtime,'size'=>(int)@filesize($file));}}}return $latest;}
}
if(!function_exists('tickex_platform_health_report')){
 function tickex_platform_health_report($pdo){
  $checks=array();
  $php=PHP_VERSION;
  $phpStatus=version_compare($php,'8.3.0','>=')?'ok':(version_compare($php,'8.2.0','>=')?'warning':'critical');
  tickex_platform_health_add($checks,'php','PHP',$phpStatus,$php,$phpStatus==='critical'?'Versión fuera de soporte. Objetivo recomendado: PHP 8.4, previa prueba de compatibilidad.':($phpStatus==='warning'?'Tiene soporte limitado. Planificar PHP 8.4.':'Runtime compatible; mantener parches de seguridad.'),'runtime');
  $required=array('pdo_sqlite'=>'PDO SQLite','sqlite3'=>'SQLite3','openssl'=>'OpenSSL','curl'=>'cURL','mbstring'=>'Multibyte','json'=>'JSON');
  foreach($required as $extension=>$label)tickex_platform_health_add($checks,'ext_'.$extension,$label,extension_loaded($extension)?'ok':'critical',extension_loaded($extension)?'Disponible':'No disponible','Extensión necesaria para funciones centrales.','runtime');
  tickex_platform_health_add($checks,'ext_zip','ZIP',extension_loaded('zip')?'ok':'warning',extension_loaded('zip')?'Disponible':'No disponible','Es opcional para el núcleo, pero una carga rota genera el warning de inicio que debe corregirse.','runtime');
  tickex_platform_health_add($checks,'opcache','OPcache',extension_loaded('Zend OPcache')||function_exists('opcache_get_status')?'ok':'warning',extension_loaded('Zend OPcache')||function_exists('opcache_get_status')?'Disponible':'No detectado','Recomendado en producción para rendimiento y estabilidad.','runtime');

  $sqlite=(string)$pdo->query('SELECT sqlite_version()')->fetchColumn();
  $sqliteStatus=version_compare($sqlite,'3.51.3','>=')?'ok':'warning';
  tickex_platform_health_add($checks,'sqlite','SQLite',$sqliteStatus,$sqlite,$sqliteStatus==='ok'?'Versión posterior a la corrección del problema WAL de 2026.':'Planificar una actualización del SQLite incluido en PHP.','database');
  $integrity=(string)$pdo->query('PRAGMA quick_check')->fetchColumn();
  tickex_platform_health_add($checks,'integrity','Integridad rápida',$integrity==='ok'?'ok':'critical',$integrity,$integrity==='ok'?'La estructura respondió correctamente.':'Respaldar y detener escrituras antes de reparar.','database');
  $journal=strtolower((string)$pdo->query('PRAGMA journal_mode')->fetchColumn());
  tickex_platform_health_add($checks,'journal','Concurrencia SQLite',$journal==='wal'?'ok':'warning',strtoupper($journal),$journal==='wal'?'WAL reduce bloqueos entre lecturas y escrituras.':'Evaluar WAL en una copia y bajo carga antes de activarlo.','database');
  $foreign=(int)$pdo->query('PRAGMA foreign_keys')->fetchColumn();
  tickex_platform_health_add($checks,'foreign_keys','Claves foráneas',$foreign===1?'ok':'warning',$foreign===1?'Activas':'Inactivas',$foreign===1?'La conexión valida relaciones declaradas.':'Revisar compatibilidad antes de activarlas globalmente.','database');
  $dbList=$pdo->query('PRAGMA database_list')->fetchAll(PDO::FETCH_ASSOC);$dbPath='';foreach($dbList as $row)if(isset($row['name'])&&$row['name']==='main'){$dbPath=(string)$row['file'];break;}
  if($dbPath!==''&&is_file($dbPath)){
   $size=(int)filesize($dbPath);$free=@disk_free_space(dirname($dbPath));$freeStatus=$free!==false&&$free<1073741824?'critical':($free!==false&&$free<5368709120?'warning':'ok');
   tickex_platform_health_add($checks,'db_size','Tamaño de base','info',number_format($size/1048576,1,',','.').' MB','Archivo principal SQLite.','database');
   tickex_platform_health_add($checks,'disk','Espacio libre',$freeStatus,$free===false?'No disponible':number_format($free/1073741824,1,',','.').' GB','Mantener margen suficiente para WAL, correos, uploads y backups.','storage');
   tickex_platform_health_add($checks,'db_writable','Permisos de base',is_writable($dbPath)?'ok':'critical',is_writable($dbPath)?'Escribible':'Sin escritura','El usuario de PHP debe poder escribir la base y su directorio.','storage');
   $backup=tickex_platform_health_latest_backup($dbPath);
   if($backup){$age=(time()-$backup['mtime'])/3600;$backupStatus=$age<=48?'ok':($age<=168?'warning':'critical');tickex_platform_health_add($checks,'backup','Último backup',$backupStatus,date('d/m/Y H:i',$backup['mtime']),$age<=48?'Copia reciente detectada.':'La última copia detectada tiene '.(int)round($age).' horas.','storage');}
   else tickex_platform_health_add($checks,'backup','Último backup','critical','No detectado','Configurar y comprobar copias automáticas fuera del directorio público.','storage');
  }
  $server=isset($_SERVER['SERVER_SOFTWARE'])?(string)$_SERVER['SERVER_SOFTWARE']:'CLI / no informado';$apache=tickex_platform_health_version($server);$serverStatus='info';$serverDetail='Verificar desde el VPS y mantener los parches del proveedor.';if(stripos($server,'apache')!==false&&$apache!==''){$serverStatus=version_compare($apache,'2.4.68','>=')?'ok':'warning';$serverDetail=$serverStatus==='ok'?'Versión base actualizada. Confirmar parches del sistema operativo.':'Revisar boletines del sistema: la versión base es anterior a Apache 2.4.68.';}
  tickex_platform_health_add($checks,'server','Servidor web',$serverStatus,$server,$serverDetail,'server');
  $https=!empty($_SERVER['HTTPS'])&&strtolower((string)$_SERVER['HTTPS'])!=='off';if(!$https&&isset($_SERVER['HTTP_X_FORWARDED_PROTO']))$https=strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO'])==='https';
  tickex_platform_health_add($checks,'https','HTTPS',$https?'ok':'warning',$https?'Activo':'No detectado',$https?'La solicitud llegó por canal seguro.':'En local es normal; en producción debe estar activo y correctamente informado por el proxy.','server');
  $secretPath=dirname(dirname(__DIR__)).DIRECTORY_SEPARATOR.'.secrets'.DIRECTORY_SEPARATOR.'mercadopago_marketplace.php';
  tickex_platform_health_add($checks,'secrets','Secretos Mercado Pago',is_file($secretPath)?'ok':'warning',is_file($secretPath)?'Archivo configurado':'No detectado','Nunca se muestran valores sensibles en este diagnóstico.','security');
  $counts=array('ok'=>0,'warning'=>0,'critical'=>0,'info'=>0);foreach($checks as $check){$status=$check['status'];if(isset($counts[$status]))$counts[$status]++;}
  return array('generated_at'=>date('c'),'checks'=>$checks,'counts'=>$counts,'overall'=>$counts['critical']>0?'critical':($counts['warning']>0?'warning':'ok'));
 }
}
