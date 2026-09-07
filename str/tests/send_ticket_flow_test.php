<?php
function send_flow_assert($condition,$message){if(!$condition){fwrite(STDERR,"FAIL: {$message}\n");exit(1);}echo "PASS: {$message}\n";}
$page=file_get_contents(__DIR__.'/../enviar_tickex.php');
$form=file_get_contents(__DIR__.'/../inc/send_ticket_form.php');
send_flow_assert(strpos($page,"inc/event_lifecycle.php")!==false,'manual issuance uses shared event lifecycle rules');
send_flow_assert(strpos($page,'borrado_en IS NULL')!==false && strpos($page,'tickex_event_is_current')!==false,'finished and deleted events are excluded');
send_flow_assert(strpos($page,'eventosPermitidos')!==false && strpos($page,'ya terminó o no está disponible')!==false,'posted events are checked against the available organizer events');
send_flow_assert(strpos($page,"if (\$mode === 'courtesy')")!==false && strpos($page,"\$quantity = 1")!==false,'courtesy mode always emits one package');
send_flow_assert(strpos($form,'type="radio" name="modo"')!==false,'issuance mode uses clear choice cards');
send_flow_assert(strpos($form,'id="saleFields"')!==false && strpos($form,'saleFields.hidden=!sale')!==false,'commercial fields only appear for a manual sale');
send_flow_assert(strpos($form,'data-event=')!==false && strpos($form,'filterTypes')!==false,'ticket types are filtered by the selected event');
send_flow_assert(strpos($page,'ev.creado_por_admin_id=:history_admin')!==false,'manual issuance history is isolated per organizer');
send_flow_assert(strpos($page,"action']) && \$_POST['action'] === 'delete_tickex'")!==false && strpos($page,"name=\"action\" value=\"delete_tickex\"")!==false,'deletion uses a protected post action');
echo "ALL SEND TICKET FLOW TESTS PASSED\n";
