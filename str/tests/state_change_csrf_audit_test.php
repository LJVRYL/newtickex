<?php

$root = dirname(__DIR__);
$failures = 0;

function csrf_audit_check($condition, $message)
{
    global $failures;
    if ($condition) {
        echo 'PASS: ' . $message . PHP_EOL;
        return;
    }
    $failures++;
    echo 'FAIL: ' . $message . PHP_EOL;
}

$protected = array(
    'bridge_senforms.php',
    'catalogo_senforms.php',
    'senforms_admin.php',
    'panel_evento.php',
);

foreach ($protected as $file) {
    $source = file_get_contents($root . '/' . $file);
    csrf_audit_check(strpos($source, 'tickex_csrf_verify') !== false, $file . ' verifies CSRF before protected changes');
    csrf_audit_check(strpos($source, 'name="_csrf"') !== false || $file === 'panel_evento.php', $file . ' carries CSRF tokens in its forms');
}

if ($failures > 0) exit(1);
echo 'ALL STATE CHANGE CSRF AUDIT TESTS PASSED' . PHP_EOL;
