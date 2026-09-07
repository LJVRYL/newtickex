<?php
$root = __DIR__ . '/..';
$pages = array(
    'campaigns' => file_get_contents($root . '/comunicacion_campanas.php'),
    'engine' => file_get_contents($root . '/comunicacion_estado_motor.php'),
    'history' => file_get_contents($root . '/comunicacion_historial.php'),
    'health' => file_get_contents($root . '/comunicacion_healthcheck.php'),
);
$views = array(
    'campaigns' => file_get_contents($root . '/inc/campaign_manager_view.php'),
    'engine' => file_get_contents($root . '/inc/communication_engine_view.php'),
    'history' => file_get_contents($root . '/inc/communication_history_view.php'),
    'health' => file_get_contents($root . '/inc/communication_health_view.php'),
);
function comm_ui_test($ok, $message) { echo ($ok ? 'PASS: ' : 'FAIL: ') . $message . PHP_EOL; if (!$ok) exit(1); }
foreach ($pages as $name => $page) {
    comm_ui_test(strpos($page, 'auth_context') !== false && strpos($page, "\$cu['id']") !== false, $name . ' uses the current administrator identity');
}
comm_ui_test(strpos($views['campaigns'], 'js-send-now-btn') !== false && strpos($pages['campaigns'], 'communication_campaign_dispatch.php') !== false, 'campaign send confirmation flow remains connected');
comm_ui_test(strpos($views['campaigns'], 'estimate_form') !== false && strpos($views['campaigns'], 'preview_form') !== false && strpos($views['campaigns'], 'value="save"') !== false, 'campaign preparation actions remain available');
comm_ui_test(strpos($views['campaigns'], 'Favoritas') !== false && strpos($views['campaigns'], 'Campañas actuales') !== false && strpos($views['campaigns'], 'Campañas archivadas') !== false, 'campaign library is grouped by operational priority');
comm_ui_test(strpos($views['campaigns'], 'toggle_favorite') !== false && strpos($views['campaigns'], 'remove_campaign') !== false, 'campaign favorite and safe removal actions are available');
comm_ui_test(strpos($views['engine'], 'run_worker_now') !== false && strpos($views['engine'], 'retry_run') !== false && strpos($views['engine'], 'resume_run') !== false && strpos($views['engine'], 'cancel_run') !== false, 'engine operational actions remain available');
comm_ui_test(strpos($views['engine'], 'value="remove_run"') !== false && strpos($pages['engine'], 'communication_ops_action_remove_run') !== false, 'completed executions can be removed from the operational view');
comm_ui_test(strpos($views['history'], 'unique_opens') !== false && strpos($views['history'], 'unique_clicks') !== false && strpos($views['history'], 'confirmed_orders') !== false, 'history keeps engagement and conversion metrics');
comm_ui_test(strpos($views['history'], "runDetail['recipients']") !== false, 'history keeps recipient delivery detail');
comm_ui_test(strpos($views['health'], 'run_integrity_checks') !== false && strpos($views['health'], 'test_render') !== false && strpos($views['health'], 'test_audience') !== false && strpos($views['health'], 'test_execution_simulation') !== false && strpos($views['health'], 'test_transport_simulation') !== false, 'diagnostic checks remain available');
comm_ui_test(strpos(file_get_contents($root . '/inc/communication_operations_ui.php'), 'communication_ui_ops_nav') !== false, 'operational screens share one navigation hierarchy');
echo "ALL COMMUNICATION OPERATIONS UI TESTS PASSED\n";
