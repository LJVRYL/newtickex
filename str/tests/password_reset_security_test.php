<?php
require_once __DIR__ . '/../inc/password_reset_security.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE usuarios (id INTEGER PRIMARY KEY, email TEXT NOT NULL)');
$pdo->exec('CREATE TABLE usuarios_admin (id INTEGER PRIMARY KEY, email TEXT NOT NULL, password TEXT)');
$pdo->exec('CREATE TABLE registro_pendientes (id INTEGER PRIMARY KEY, email TEXT NOT NULL, password_hash TEXT)');
$pdo->exec("INSERT INTO usuarios (id,email) VALUES (1,'persona@example.com')");
$pdo->exec("INSERT INTO usuarios_admin (id,email,password) VALUES (2,'admin@example.com','legacy')");

function password_reset_assert($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }
    echo 'PASS: ' . $message . PHP_EOL;
}

tickex_password_reset_ensure_schema($pdo);
password_reset_assert(tickex_password_reset_account_exists($pdo, 'PERSONA@example.com'), 'existing account is found case-insensitively');
password_reset_assert(tickex_password_reset_account_exists($pdo, 'ADMIN@example.com'), 'administrator account can use the unified recovery flow');
password_reset_assert(!tickex_password_reset_account_exists($pdo, 'inventado@example.com'), 'unknown account is rejected');

$newHash = password_hash('nueva-clave-segura', PASSWORD_DEFAULT);
password_reset_assert(tickex_password_reset_update_accounts($pdo, 'ADMIN@example.com', $newHash) === 1, 'password reset updates the administrator account');
password_reset_assert(password_verify('nueva-clave-segura', $pdo->query("SELECT password FROM usuarios_admin WHERE id=2")->fetchColumn()), 'administrator password is stored as a one-way hash');

$first = tickex_password_reset_create_token($pdo, 'persona@example.com');
$second = tickex_password_reset_create_token($pdo, 'PERSONA@example.com');
password_reset_assert($first !== $second, 'a new request rotates the token');
password_reset_assert((int)$pdo->query('SELECT COUNT(*) FROM password_reset_tokens')->fetchColumn() === 1, 'only one token remains per account');
password_reset_assert((int)$pdo->query("SELECT COUNT(*) FROM password_reset_tokens WHERE token = '" . $second . "'")->fetchColumn() === 1, 'latest token remains active');

password_reset_assert(!tickex_password_reset_rate_limited($pdo, 'ip', '127.0.0.1', 2, 900), 'first request is allowed');
password_reset_assert(!tickex_password_reset_rate_limited($pdo, 'ip', '127.0.0.1', 2, 900), 'second request is allowed');
password_reset_assert(tickex_password_reset_rate_limited($pdo, 'ip', '127.0.0.1', 2, 900), 'excess request is blocked');

$pdo->exec("UPDATE password_reset_tokens SET creado_en = datetime('now','-3 days')");
tickex_password_reset_prune($pdo);
password_reset_assert((int)$pdo->query('SELECT COUNT(*) FROM password_reset_tokens')->fetchColumn() === 0, 'expired tokens are pruned');

