<?php
$page=file_get_contents(__DIR__.'/../comunicacion_audiencias.php');
$view=file_get_contents(__DIR__.'/../inc/audience_manager_view.php');
function audience_ui_assert($condition,$message){if(!$condition){fwrite(STDERR,"FAIL: $message\n");exit(1);}echo "PASS: $message\n";}
audience_ui_assert(strpos($page,'auth_context')!==false&&strpos($page,"['id']")!==false,'audiences use the current administrator identity');
audience_ui_assert(strpos($page,"\$action === 'delete'")!==false,'audiences expose a delete action');
audience_ui_assert(strpos($view,"confirm('¿Eliminar esta audiencia?")!==false,'audience deletion requires explicit confirmation');
audience_ui_assert(strpos($page,'status <> "deleted"')!==false,'deleted audiences disappear from management');
audience_ui_assert(strpos($view,'Filtros avanzados')!==false,'secondary filters use progressive disclosure');
audience_ui_assert(strpos($view,'Esta acción no envía ningún email')!==false,'audience estimation is clearly non-sending');
audience_ui_assert(strpos($page,"\$postAction === 'estimate_form'")!==false,'estimating preserves the audience form values');
audience_ui_assert(strpos($page,"'contact_type' => \$contactType")!==false,'audiences persist the semantic contact type filter');
audience_ui_assert(strpos($page,'creado_por_admin_id = :admin_id')!==false,'event options are scoped to the current administrator');
audience_ui_assert(strpos($view,'name="filter_event_id"')!==false&&strpos($view,'Todos mis eventos')!==false,'audiences select events by name instead of numeric ids');
audience_ui_assert(strpos($view,'name="filter_contact_type"')!==false,'audiences expose meaningful contact relationships');
audience_ui_assert(strpos($view,'data-audience-type="buyer"')!==false&&strpos($view,'data-audience-type="guest"')!==false&&strpos($view,'data-audience-type="prospect"')!==false,'frequent audience presets are available');
audience_ui_assert(strpos($view,'destinatario<?php')!==false,'saved audiences show their current recipient count');
$campaignHelpers=file_get_contents(__DIR__.'/../inc/communication_campaigns.php');
audience_ui_assert(substr_count($campaignHelpers,'status <> "deleted"')>=2,'deleted audiences cannot be selected for new campaigns');
echo "ALL COMMUNICATION AUDIENCE UI TESTS PASSED\n";
