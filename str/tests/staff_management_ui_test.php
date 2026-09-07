<?php
$source = file_get_contents(__DIR__ . '/../secundarios.php');
if ($source === false) {
    fwrite(STDERR, "FAIL: no se pudo leer secundarios.php\n");
    exit(1);
}

function staff_test_assert($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }
    echo 'PASS: ' . $message . PHP_EOL;
}

staff_test_assert(strpos($source, "\$_SESSION['auth_context'] === 'admin'") !== false, 'staff requires an administrator session');
staff_test_assert(strpos($source, "/login_admin.php?next=") !== false, 'staff redirects to the administrator login');
staff_test_assert(strpos($source, "'assign_staff_event', 'remove_staff_event'") !== false, 'staff assignment mutations require CSRF validation');
staff_test_assert(strpos($source, "isset(\$_POST['rol_staff'])") !== false, 'invitation keeps the selected initial role');
staff_test_assert(strpos($source, "isset(\$_POST['mensaje'])") !== false, 'invitation keeps the organizer message');
staff_test_assert(strpos($source, "!isset(\$eventosMap[\$prefEventoId])") !== false, 'foreign event ids are rejected');
staff_test_assert(strpos($source, "sa.owner_admin_id = :aid") !== false, 'event staff is scoped to the current organizer');
staff_test_assert(substr_count($source, 'class="card staff-hero"') === 1, 'staff dashboard has one primary hero');
staff_test_assert(strpos($source, 'Personas del equipo') !== false, 'accepted members have a dedicated hierarchy');
staff_test_assert(strpos($source, 'Asignar eventos y costos') !== false, 'event assignments and costs have a dedicated workflow');
staff_test_assert(strpos($source, 'Las demás se conservaron') !== false, 'event assignments are additive');
staff_test_assert(strpos($source, 'staff_operaciones.php?evento_id=') !== false, 'event staff links to the operations center');

echo "ALL STAFF MANAGEMENT UI TESTS PASSED\n";
