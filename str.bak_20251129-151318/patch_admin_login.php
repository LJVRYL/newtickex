<?php
$file = __DIR__ . '/admin.php';
$code = file_get_contents($file);

$search = <<<'SEARCH'
$isLogged = (!empty($_SESSION['is_admin']) && $_SESSION['is_admin'] === true);
$error = '';

// Procesar login
if (!$isLogged && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $user         = isset($_POST['user']) ? trim($_POST['user']) : '';
    $pass         = isset($_POST['pass']) ? $_POST['pass'] : '';
    $captcha_user = isset($_POST['captcha']) ? trim($_POST['captcha']) : '';

    $captcha_expected = isset($_SESSION['admin_captcha_answer'])
        ? $_SESSION['admin_captcha_answer']
        : null;

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
}
SEARCH;

$replace = <<<'REPLACE'
$isLogged = !empty($_SESSION['usuario']);
$error = '';

// Procesar login
if (!$isLogged && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $user         = isset($_POST['user']) ? trim($_POST['user']) : '';
    $pass         = isset($_POST['pass']) ? $_POST['pass'] : '';
    $captcha_user = isset($_POST['captcha']) ? trim($_POST['captcha']) : '';

    $captcha_expected = isset($_SESSION['admin_captcha_answer'])
        ? $_SESSION['admin_captcha_answer']
        : null;

    if ($captcha_expected === null || (int)$captcha_user !== (int)$captcha_expected) {
        $error = 'Credenciales o captcha incorrectos.';
    } else {
        // 1) Admin clásico
        if ($user === ADMIN_USER && $pass === ADMIN_PASS) {
            $_SESSION['usuario']  = 'admin';
            $_SESSION['rol']      = 'admin';
            $_SESSION['is_admin'] = true; // compatibilidad con código viejo

            unset($_SESSION['admin_captcha_answer']);
            header('Location: admin.php');
            exit;
        } else {
            // 2) Usuarios en tabla usuarios_admin (ej: puerta)
            $dbFileLogin = __DIR__ . '/save_the_rave.sqlite';

            try {
                $pdoLogin = new PDO('sqlite:' . $dbFileLogin);
                $pdoLogin->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

                $stmt = $pdoLogin->prepare('
                    SELECT username, password, rol, activo
                    FROM usuarios_admin
                    WHERE username = :u
                    LIMIT 1
                ');
                $stmt->execute([':u' => $user]);
                $uRow = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($uRow && (int)$uRow['activo'] === 1 && $pass === $uRow['password']) {
                    $_SESSION['usuario']  = $uRow['username'];
                    $_SESSION['rol']      = $uRow['rol'];
                    $_SESSION['is_admin'] = ($uRow['rol'] === 'admin');

                    unset($_SESSION['admin_captcha_answer']);
                    header('Location: admin.php');
                    exit;
                } else {
                    $error = 'Credenciales o captcha incorrectos.';
                }
            } catch (Exception $ex) {
                $error = 'Error al verificar usuario. (' . $ex->getMessage() . ')';
            }
        }
    }
}
REPLACE;

if (strpos($code, $search) === false) {
    fwrite(STDERR, "No se encontró el bloque esperado en admin.php\n");
    exit(1);
}

$code = str_replace($search, $replace, $code);
file_put_contents($file, $code);
echo "Login de admin.php actualizado.\n";
