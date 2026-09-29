<?php
$page = file_get_contents(__DIR__ . '/../panel_staff.php');
$layout = file_get_contents(__DIR__ . '/../inc/layout_top.php');

function staff_portal_ui_assert($condition, $message)
{
    if (!$condition) { fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL); exit(1); }
    echo 'PASS: ' . $message . PHP_EOL;
}

staff_portal_ui_assert(strpos($page, "isset(\$_SESSION['usuario_id'])") !== false, 'staff portal still requires a customer identity');
staff_portal_ui_assert(strpos($page, "tickex_staff_role_permissions") !== false, 'staff portal still derives actions from role permissions');
staff_portal_ui_assert(strpos($page, "tickex_csrf_verify") !== false, 'staff mutations remain CSRF protected');
staff_portal_ui_assert(strpos($page, 'staff-hero-main') !== false && strpos($page, 'Acciones principales') !== false, 'staff portal has a clear operational hierarchy');
staff_portal_ui_assert(strpos($page, 'Turnos y tareas') !== false && strpos($page, 'Personas e ingresos') !== false, 'staff work and access control are separated into sections');
staff_portal_ui_assert(strpos($page, '/tickex-isotipo.svg') !== false, 'staff portal uses the Tickex brand symbol');
staff_portal_ui_assert(strpos($layout, 'page-staff-dashboard') !== false, 'shared layout identifies the staff dashboard');

echo 'ALL STAFF PORTAL UI TESTS PASSED' . PHP_EOL;
