<?php
$file = __DIR__ . '/admin.php';
$code = file_get_contents($file);

$search = <<<'SEARCH'
                if ($uRow && (int)$uRow['activo'] === 1 && $pass === $uRow['password']) {
                    $_SESSION['usuario']  = $uRow['username'];
                    $_SESSION['rol']      = $uRow['rol'];
                    $_SESSION['is_admin'] = ($uRow['rol'] === 'admin');

                    unset($_SESSION['admin_captcha_answer']);

                    if ($uRow['rol'] === 'puerta') {
                        header('Location: puerta.php');
                    } else {
                        header('Location: admin.php');
                    }
                    exit;
                } else {
SEARCH;

$replace = <<<'REPLACE'
                if ($uRow && (int)$uRow['activo'] === 1 && $pass === $uRow['password']) {
                    $_SESSION['usuario']     = $uRow['username'];
                    $_SESSION['rol']         = $uRow['rol'];
                    $_SESSION['tipo_global'] = isset($uRow['tipo_global']) ? $uRow['tipo_global'] : null;
                    $_SESSION['is_admin']    = ($uRow['rol'] === 'admin');

                    unset($_SESSION['admin_captcha_answer']);

                    if ($_SESSION['tipo_global'] === 'super_admin') {
                        header('Location: superadmin.php');
                    } elseif ($uRow['rol'] === 'puerta') {
                        header('Location: puerta.php');
                    } else {
                        header('Location: admin.php');
                    }
                    exit;
                } else {
REPLACE;

if (strpos($code, $search) === false) {
    fwrite(STDERR, "No se encontró el bloque de login de usuarios_admin esperado en admin.php\n");
    exit(1);
}

$code = str_replace($search, $replace, $code);
file_put_contents($file, $code);
echo "Redirección de SuperAdmin agregada en admin.php.\n";
