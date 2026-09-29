<?php

$root = dirname(__DIR__);
$failures = 0;

function check_public_surface($condition, $message)
{
    global $failures;
    if ($condition) {
        echo 'PASS: ' . $message . PHP_EOL;
        return;
    }
    $failures++;
    echo 'FAIL: ' . $message . PHP_EOL;
}

$htaccess = file_get_contents($root . '/.htaccess');
check_public_surface(strpos($htaccess, 'sqlite') !== false, 'database files are denied by Apache rules');
check_public_surface(strpos($htaccess, 'tests|migrations|tools|patchs') !== false, 'internal code directories are denied by Apache rules');

$cliOnly = array(
    'apply_mapping_12.php',
    'check_setup.php',
    'crear_clientes_site_demo.php',
    'crear_tabla_clientes_sites.php',
    'crear_tabla_codigos_descuento.php',
    'debug_bridge_slugs.php',
    'debug_evento_12_slug.php',
    'diag_bridge_columns.php',
    'diag_bridge_query_12.php',
    'diag_check.php',
    'diag_db_structure.php',
    'diag_evento_12.php',
    'diag_stats_12.php',
    'diag_test.php',
    'diag_tipos_entrada.php',
    'diag_unified_12.php',
    'import_extras.php',
    'import_lista.php',
    'import_listas_tipos.php',
    'tmp_send_confirm_savethe.php',
    'usuarios.php',
);

foreach ($cliOnly as $file) {
    $source = file_get_contents($root . '/' . $file);
    check_public_surface(
        strpos($source, 'internal_cli_only.php') !== false,
        $file . ' is restricted to command-line use'
    );
}

foreach (array('tests', 'migrations', 'tools', 'patchs') as $directory) {
    $rule = trim((string) file_get_contents($root . '/' . $directory . '/.htaccess'));
    check_public_surface($rule === 'Require all denied', $directory . ' has a directory-level deny rule');
}

if ($failures > 0) {
    exit(1);
}
echo 'ALL PUBLIC SURFACE SECURITY TESTS PASSED' . PHP_EOL;
