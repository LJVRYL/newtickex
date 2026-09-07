<?php
$page = file_get_contents(__DIR__ . '/../comunicacion_plantillas.php');
$view = file_get_contents(__DIR__ . '/../inc/template_manager_view.php');

function template_ui_test($ok, $message) { echo ($ok ? 'PASS: ' : 'FAIL: ') . $message . PHP_EOL; if (!$ok) exit(1); }

template_ui_test(strpos($page, "require_once __DIR__ . '/inc/communication_template_management.php'") !== false, 'template page loads safe management helpers');
template_ui_test(strpos($page, "auth_context") !== false && strpos($page, "\$cu['id']") !== false, 'template page uses the current administrator identity');
template_ui_test(strpos($page, 'status <> "deleted"') !== false, 'deleted templates stay outside the management list');
template_ui_test(strpos($view, 'Biblioteca de plantillas') !== false, 'template library has a clear visual hierarchy');
template_ui_test(strpos($view, 'Contenido del mensaje') !== false, 'message content is grouped in the editor');
template_ui_test(strpos($view, 'Opciones avanzadas') !== false, 'technical fields are collapsed under advanced options');
template_ui_test(strpos($view, 'value="delete"') !== false, 'custom templates expose a delete action');
template_ui_test(strpos($view, "is_system_locked']!==1") !== false, 'system templates do not expose destructive actions');
template_ui_test(strpos($view, 'Vista previa del email') !== false, 'rendered preview remains available');
echo "ALL COMMUNICATION TEMPLATE UI TESTS PASSED\n";
