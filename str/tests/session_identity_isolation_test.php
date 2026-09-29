<?php

$_SESSION = array();
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/event_access.php';

function session_identity_assert($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, 'FAIL: '.$message.PHP_EOL);
        exit(1);
    }
    echo 'PASS: '.$message.PHP_EOL;
}

// Reproduce el caso real: una sesión de superadmin queda abierta y luego
// inicia sesión un organizador cliente desde el mismo navegador.
$_SESSION = array(
    'admin_id' => 2,
    'user_id' => 10,
    'usuario_id' => 999,
    'tipo_global' => 'admin_evento',
    'es_admin' => true,
    'evento_id' => 15,
    'usuario' => 'agus'
);
tickex_normalize_session_identity();
$user = current_user();

session_identity_assert((int)$_SESSION['admin_id'] === 10, 'stale administrator id is replaced by the current login');
session_identity_assert((int)$user['id'] === 10, 'current user resolves the administrator identity');
session_identity_assert(tickex_admin_id($user) === 10, 'event access uses the current administrator id');

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE eventos (id INTEGER PRIMARY KEY, creado_por_admin_id INTEGER, borrado_en TEXT)');
$pdo->exec("INSERT INTO eventos (id,creado_por_admin_id,borrado_en) VALUES (15,2,NULL),(18,10,NULL)");
$visible = tickex_visible_events($pdo, $user);

session_identity_assert(count($visible) === 1 && (int)$visible[0]['id'] === 18, 'Agus only sees the event owned by Agus');
session_identity_assert(!tickex_can_access_event($pdo, 15, $user), 'Agus cannot open a SAVE THE RAVE event');
session_identity_assert(tickex_can_access_event($pdo, 18, $user), 'Agus can open the own event');

$_SESSION['csrf_token'] = 'keep-security-token';
tickex_clear_identity_session();
session_identity_assert(!isset($_SESSION['admin_id']) && !isset($_SESSION['usuario_id']), 'a new login clears the previous identity completely');
session_identity_assert(isset($_SESSION['csrf_token']) && $_SESSION['csrf_token'] === 'keep-security-token', 'session cleanup preserves unrelated security state');

// Un login de comprador debe retirar cualquier privilegio viejo.
$_SESSION = array(
    'usuario_id' => 45,
    'admin_id' => 2,
    'user_id' => 2,
    'es_admin' => true,
    'is_admin' => true,
    'tipo_global' => ''
);
tickex_normalize_session_identity();

session_identity_assert(!isset($_SESSION['admin_id']) && !isset($_SESSION['user_id']), 'buyer session cannot inherit administrator ids');
session_identity_assert(!is_admin(), 'buyer session cannot inherit administrator privileges');

echo 'ALL SESSION IDENTITY ISOLATION TESTS PASSED'.PHP_EOL;
