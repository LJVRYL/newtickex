<?php
$file = __DIR__ . '/admin.php';
$code = file_get_contents($file);

$search = <<<'SEARCH'
    if (
        $user === ADMIN_USER &&
        $pass === ADMIN_PASS &&
        $captcha_expected !== null &&
        (int)$captcha_user === (int)$captcha_expected
    ) {
        $_SESSION['is_admin'] = true;
        unset($_SESSION['admin_captcha_answer']);
        header('Location: admin.php');
        exit;
    } else {
        $error = 'Credenciales o captcha incorrectos.';
    }
SEARCH;

$replace = <<<'REPLACE'
    if (
        $user === ADMIN_USER &&
        $pass === ADMIN_PASS &&
        $captcha_expected !== null &&
        (int)$captcha_user === (int)$captcha_expected
    ) {
        $_SESSION['is_admin'] = true;
        // NUEVO: también guardamos el nombre de usuario para los paneles nuevos
        $_SESSION['usuario']  = ADMIN_USER;
        unset($_SESSION['admin_captcha_answer']);
        header('Location: admin.php');
        exit;
    } else {
        $error = 'Credenciales o captcha incorrectos.';
    }
REPLACE;

if (strpos($code, $search) === false) {
    fwrite(STDERR, "No se encontró el bloque de login esperado en admin.php\n");
    exit(1);
}

$code = str_replace($search, $replace, $code);
file_put_contents($file, $code);
echo "Login de admin.php actualizado para setear \$_SESSION['usuario'].\n";
