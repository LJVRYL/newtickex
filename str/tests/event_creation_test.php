<?php
require_once __DIR__.'/../inc/event_creation.php';
require_once __DIR__.'/../inc/event_presentation.php';
function event_creation_assert($condition,$message){if(!$condition){fwrite(STDERR,"FAIL: {$message}\n");exit(1);}echo "PASS: {$message}\n";}
event_creation_assert(tickex_event_creation_slugify('Fiesta Ácida Nº 30')==='fiesta-acida-no-30','event name becomes a clean public identifier');
$valid=array('nombre'=>'Evento prueba','slug'=>'evento-prueba','fecha_desde'=>'2026-09-10','fecha_hasta'=>'2026-09-10','hora_puertas'=>'19:00','descripcion'=>'Todo listo','capacidad_total'=>300);
event_creation_assert(tickex_event_creation_validate($valid)==='','complete event data is accepted');
$missing=$valid;$missing['fecha_hasta']='';event_creation_assert(tickex_event_creation_validate($missing)!=='','an undated event cannot be created');
$reversed=$valid;$reversed['fecha_hasta']='2026-09-09';event_creation_assert(tickex_event_creation_validate($reversed)!=='','end date cannot precede start date');
$capacity=$valid;$capacity['capacidad_total']=0;event_creation_assert(tickex_event_creation_validate($capacity)!=='','event capacity must be positive');
$door=$valid;$door['hora_puertas']='';event_creation_assert(tickex_event_creation_validate($door)!=='','door time is required');
$source=file_get_contents(__DIR__.'/../crear_evento.php');
event_creation_assert(strpos($source,'tickex_csrf_verify')!==false && strpos($source,'name="_csrf"')!==false,'event creation is protected against cross-site requests');
event_creation_assert(strpos($source,'/login_admin.php?next=')!==false,'event creation uses administrator login');
event_creation_assert(strpos($source,'Identidad')!==false && strpos($source,'Planificación')!==false && strpos($source,'Presentación')!==false,'event creation has three guided sections');
event_creation_assert(strpos($source,'getimagesize')!==false,'flyer validation checks real image content');
echo "ALL EVENT CREATION TESTS PASSED\n";
