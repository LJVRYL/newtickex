<?php
require_once __DIR__ . '/../inc/login_security.php';

function launch_security_assert($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }
    echo 'PASS: ' . $message . PHP_EOL;
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE usuarios_admin(id INTEGER PRIMARY KEY,password TEXT)');
$pdo->exec('CREATE TABLE registro_pendientes(id INTEGER PRIMARY KEY,password_hash TEXT)');

$modern = password_hash('clave-segura-123', PASSWORD_DEFAULT);
$modernCheck = tickex_password_verify_compat('clave-segura-123', $modern);
launch_security_assert($modernCheck['valid'] && !$modernCheck['needs_upgrade'], 'modern passwords are verified without downgrade');

$pdo->exec("INSERT INTO usuarios_admin VALUES(1,'" . md5('clave-antigua-123') . "')");
$legacyCheck = tickex_password_verify_compat('clave-antigua-123', md5('clave-antigua-123'));
launch_security_assert($legacyCheck['valid'] && $legacyCheck['needs_upgrade'], 'legacy MD5 passwords are accepted only for migration');
launch_security_assert(tickex_password_upgrade($pdo, 'usuarios_admin', 'password', 1, 'clave-antigua-123'), 'legacy administrator password is upgraded');
$upgraded = (string)$pdo->query('SELECT password FROM usuarios_admin WHERE id=1')->fetchColumn();
launch_security_assert(password_verify('clave-antigua-123', $upgraded), 'upgraded password uses a one-way hash');
launch_security_assert(!tickex_password_verify_compat('incorrecta', $modern)['valid'], 'wrong passwords remain rejected');

$_SESSION = array();
launch_security_assert(!tickex_login_throttled('admin', 2, 600), 'administrator login starts available');
tickex_login_record_failure('admin');
tickex_login_record_failure('admin');
launch_security_assert(tickex_login_throttled('admin', 2, 600), 'administrator brute-force attempts are throttled');
tickex_login_clear_failures('admin');
launch_security_assert(!tickex_login_throttled('admin', 2, 600), 'successful login clears the throttle');

$adminLogin = file_get_contents(__DIR__ . '/../login_admin.php');
$buyerLogin = file_get_contents(__DIR__ . '/../login.php');
$profile = file_get_contents(__DIR__ . '/../completar_registro.php');
$feature = file_get_contents(__DIR__ . '/../enable_claude_haiku.php');
$templates = file_get_contents(__DIR__ . '/../plantillas_entrada.php');
launch_security_assert(strpos($adminLogin, 'tickex_csrf_verify') !== false && strpos($adminLogin, 'tickex_login_throttled') !== false, 'administrator login has CSRF and throttling');
launch_security_assert(strpos($buyerLogin, 'Primer seteo de contraseña') === false, 'pending accounts cannot be claimed from the login form');
launch_security_assert(strpos($profile, 'getimagesize') !== false && strpos($profile, "'image/jpeg' => 'jpg'") !== false, 'profile images are validated by content');
launch_security_assert(strpos($feature, 'tickex_csrf_verify') !== false && strpos($feature, "array('super_admin', 'superadmin')") !== false, 'global feature switch is superadmin-only and CSRF protected');
launch_security_assert(strpos($templates, "\$_GET['del_id']") === false && strpos($templates, "\$_POST['del_id']") !== false && strpos($templates, 'tickex_csrf_verify') !== false, 'ticket templates use protected POST deletion');

foreach (array('economia_general.php', 'mi_perfil_fixed.php', 'mis_salones.php', 'registro_pendientes_admin.php', 'superadmin_email_templates.php', 'superadmin_emails.php', 'superadmin_eventos.php') as $protectedFormFile) {
    $source = file_get_contents(__DIR__ . '/../' . $protectedFormFile);
    launch_security_assert(strpos($source, 'tickex_csrf_verify') !== false && strpos($source, 'name="_csrf"') !== false, $protectedFormFile . ' protects authenticated writes against CSRF');
}

foreach (array('admin_core.php', 'admin_original.php', 'admin_legacy_selector.php') as $legacyFile) {
    $source = file_get_contents(__DIR__ . '/../' . $legacyFile);
    launch_security_assert(strpos($source, "header('Location: panel_admin.php'") !== false && strpos($source, 'DELETE FROM entradas') === false, $legacyFile . ' no longer exposes legacy data operations');
}
$brokenDiscounts = file_get_contents(__DIR__ . '/../descuentos_roto_20251126_180535.php');
launch_security_assert(strpos($brokenDiscounts, "header('Location: descuentos.php'") !== false, 'broken historical discount page redirects to the maintained screen');

echo "ALL LAUNCH SECURITY TESTS PASSED\n";
