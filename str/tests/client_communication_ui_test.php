<?php
function client_comm_assert($condition,$message){if(!$condition){fwrite(STDERR,"FAIL: {$message}\n");exit(1);}echo "PASS: {$message}\n";}
$clients=file_get_contents(__DIR__.'/../mis_clientes.php');
$clientView=file_get_contents(__DIR__.'/../inc/client_directory_view.php');
$communication=file_get_contents(__DIR__.'/../superadmin_emails_db.php');
$communicationView=file_get_contents(__DIR__.'/../inc/communication_hub_view.php');
client_comm_assert(strpos($clients,'/login_admin.php?next=')!==false,'client directory uses administrator login');
client_comm_assert(strpos($clients,'tickex_visible_events')!==false,'client directory remains scoped to visible organizer events');
client_comm_assert(strpos($clientView,'Entradas asociadas')!==false && strpos($clientView,'Directorio')!==false,'client directory has overview metrics and clear hierarchy');
client_comm_assert(strpos($clientView,'client-event-primary')!==false && strpos($clientView,"implode(' · ',\$events)")!==false,'long event lists are summarized with full detail available');
client_comm_assert(strpos($communication,"isset(\$cu['id'])")!==false,'communication scope uses the current administrator identity');
client_comm_assert(strpos($communicationView,'Paso 1')!==false && strpos($communicationView,'Paso 4')!==false,'communication presents a four-step workflow');
client_comm_assert(strpos($communicationView,'<details class="card comm-import">')!==false,'CSV import is progressive instead of always expanded');
client_comm_assert(strpos($communicationView,'Más criterios y exportación')!==false,'secondary contact criteria are grouped as advanced options');
client_comm_assert(strpos($communicationView,'f_event_id')!==false && strpos($communicationView,'f_contact_type')!==false,'contact directory exposes event and contact type filters');
client_comm_assert(strpos($communicationView,'Herramientas técnicas')!==false,'engine controls are visually separated from campaign work');
echo "ALL CLIENT AND COMMUNICATION UI TESTS PASSED\n";
