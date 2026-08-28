<?php
require_once __DIR__ . '/../inc/password_reset_security.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE usuarios (id INTEGER PRIMARY KEY, email TEXT NOT NULL)');
$pdo->exec('CREATE TABLE registro_pendientes (id INTEGER PRIMARY KEY, email TEXT NOT NULL)');
$pdo->exec("INSERT INTO usuarios (id,email) VALUES (1,'persona@example.com')");

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
password_reset_assert(!tickex_password_reset_account_exists($pdo, 'inventado@example.com'), 'unknown account is rejected');

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

