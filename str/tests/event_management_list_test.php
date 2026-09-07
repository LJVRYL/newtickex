<?php
function event_list_assert($condition,$message){if(!$condition){fwrite(STDERR,"FAIL: {$message}\n");exit(1);}echo "PASS: {$message}\n";}
$panel=file_get_contents(__DIR__.'/../panel_evento.php');
$view=file_get_contents(__DIR__.'/../inc/event_management_list.php');
event_list_assert(strpos($panel,"inc/event_lifecycle.php")!==false,'event list uses the shared lifecycle rules');
event_list_assert(strpos($panel,"isset(\$cu['id'])")!==false,'event ownership uses the current authenticated administrator');
event_list_assert(strpos($view,"array('activos','finalizados','todos')")!==false,'event list provides active finished and all filters');
event_list_assert(strpos($view,"tickex_event_is_current")!==false,'undated legacy events are not treated as active');
event_list_assert(strpos($view,'Emitidas')!==false && strpos($view,'Sin ingresar')!==false,'event cards use physical ticket metrics with clear labels');
event_list_assert(strpos($view,'crear_evento.php')!==false,'empty state and hero connect to event creation');
event_list_assert(strpos($view,'eliminar_evento.php')!==false && strpos($view,'csrf=')!==false,'trash action keeps its request protection');
echo "ALL EVENT MANAGEMENT LIST TESTS PASSED\n";
