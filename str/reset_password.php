<?php
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/password_reset_security.php';

$title = 'Restablecer contraseña';
$error = '';
$okMsg = '';
$token = isset($_GET['token']) ? trim($_GET['token']) : '';
$emailToken = '';
$pass1 = '';
$pass2 = '';
$csrf = function_exists('tickex_csrf_token') ? tickex_csrf_token() : '';

try {
    $pdo = db();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    tickex_password_reset_ensure_schema($pdo);
    tickex_password_reset_prune($pdo);
} catch (Exception $e) {
    $error = 'No se pudo preparar la base de datos.';
}

$tokenRow = null;
if ($token !== '' && $error === '') {
    $st = $pdo->prepare("SELECT *, CASE WHEN creado_en >= datetime('now','-1 hour') THEN 1 ELSE 0 END AS vigente
                         FROM password_reset_tokens WHERE token = :t LIMIT 1");
    $st->execute(array(':t' => $token));
    $tokenRow = $st->fetch(PDO::FETCH_ASSOC);
    if (!$tokenRow) {
        $error = 'Enlace de recuperación inválido.';
    } elseif (!empty($tokenRow['consumido_en'])) {
        $error = 'Este enlace ya fue utilizado. Pedí uno nuevo.';
    } elseif (empty($tokenRow['vigente'])) {
        $error = 'Este enlace venció. Pedí uno nuevo.';
    } else {
        $emailToken = $tokenRow['email'];
    }
} elseif ($token === '') {
    $error = 'Falta el token de recuperación.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === '') {
    $pass1 = isset($_POST['pass1']) ? trim($_POST['pass1']) : '';
    $pass2 = isset($_POST['pass2']) ? trim($_POST['pass2']) : '';
    $providedCsrf = isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '';

    if (!function_exists('tickex_csrf_verify') || !tickex_csrf_verify($providedCsrf)) {
        $error = 'La sesión venció. Volvé a abrir el enlace del email.';
    } elseif ($pass1 === '' || $pass2 === '') {
        $error = 'La contraseña es obligatoria.';
    } elseif (strlen($pass1) < 6) {
        $error = 'La contraseña debe tener al menos 6 caracteres.';
    } elseif ($pass1 !== $pass2) {
        $error = 'Las contraseñas no coinciden.';
    }

    if ($error === '') {
        $newHash = function_exists('password_hash') ? password_hash($pass1, PASSWORD_DEFAULT) : md5($pass1);

        // Actualizar en registro_pendientes (clientes)
        try {
            // asegurar columna
            try {
                $cols = $pdo->query('PRAGMA table_info(registro_pendientes)')->fetchAll(PDO::FETCH_ASSOC);
                $hasPass = false;
                foreach ($cols as $c) {
                    if (isset($c['name']) && $c['name'] === 'password_hash') { $hasPass = true; break; }
                }
                if (!$hasPass) {
                    $pdo->exec('ALTER TABLE registro_pendientes ADD COLUMN password_hash TEXT');
                }
            } catch (Exception $e) {
                // ignore alter errors
            }
            $stmtCli = $pdo->prepare('UPDATE registro_pendientes SET password_hash = :h WHERE email = :e COLLATE NOCASE');
            $stmtCli->execute(array(':h' => $newHash, ':e' => $emailToken));
        } catch (Exception $e) {
            // ignore
        }

        // Actualizar en usuarios si existe columna y fila
        try {
            $colsU = $pdo->query('PRAGMA table_info(usuarios)')->fetchAll(PDO::FETCH_ASSOC);
            $hasPwdU = false;
            foreach ($colsU as $c) {
                if (isset($c['name']) && $c['name'] === 'password_hash') { $hasPwdU = true; break; }
            }
            if ($hasPwdU) {
                $stmtU = $pdo->prepare('UPDATE usuarios SET password_hash = :h WHERE email = :e COLLATE NOCASE');
                $stmtU->execute(array(':h' => $newHash, ':e' => $emailToken));
            }
        } catch (Exception $e) {
            // ignore
        }

        // Marcar token como usado
        try {
            $pdo->prepare('UPDATE password_reset_tokens SET consumido_en = datetime("now") WHERE token = :t')
                ->execute(array(':t' => $token));
        } catch (Exception $e) {
            // ignore
        }

        $okMsg = 'Tu contraseña se actualizó. Ya podés iniciar sesión con la nueva clave.';
    }
}

require __DIR__ . '/inc/layout_top.php';
?>
<div class="card" style="max-width:520px;margin:0 auto 16px auto;text-align:center;">
  <h2>Restablecer contraseña</h2>
  <p style="color:var(--muted);margin-top:6px;font-size:14px;">Elegí una nueva contraseña para tu cuenta.</p>
</div>

<?php if ($okMsg !== ''): ?>
  <div class="card" style="max-width:520px;margin:0 auto 12px auto;">
    <div class="flash ok"><?php echo e($okMsg); ?> <a class="link" href="login.php" style="margin-left:8px;">Ir al login</a></div>
  </div>
<?php endif; ?>

<?php if ($error !== ''): ?>
  <div class="card" style="max-width:520px;margin:0 auto 12px auto;">
    <div class="flash err"><?php echo e($error); ?></div>
  </div>
<?php endif; ?>

<?php if ($error === '' && $okMsg === ''): ?>
<div class="card" style="max-width:520px;margin:0 auto 20px auto;">
  <div style="margin-bottom:10px;font-size:14px;">
    Email: <strong><?php echo e($emailToken); ?></strong>
  </div>
  <form method="post">
    <input type="hidden" name="_csrf" value="<?php echo e($csrf); ?>">
    <label for="pass1">Nueva contraseña (mín 6)</label>
    <input type="password" id="pass1" name="pass1" required autocomplete="new-password" value="<?php echo e($pass1); ?>">

    <label for="pass2">Repetir contraseña</label>
    <input type="password" id="pass2" name="pass2" required autocomplete="new-password" value="<?php echo e($pass2); ?>">

    <button class="btn" type="submit" style="width:100%;margin-top:14px;">Guardar contraseña</button>
  </form>
  <div style="margin-top:10px;font-size:14px;"><a class="link" href="login.php">Volver al login</a></div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/inc/layout_bottom.php'; ?>
