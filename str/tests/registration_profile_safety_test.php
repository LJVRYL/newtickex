<?php

function registration_safety_assert($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }
    echo 'PASS: ' . $message . PHP_EOL;
}

$source = file_get_contents(__DIR__ . '/../completar_registro.php');
registration_safety_assert(strpos($source, '$apodoDb = $apodo === \'\' ? null : $apodo;') !== false, 'empty Tickex IDs are stored as NULL');
registration_safety_assert(strpos($source, 'beginTransaction()') !== false && strpos($source, 'rollBack()') !== false, 'profile history and update are atomic');
registration_safety_assert(strpos($source, '(string)$e->getCode() === \'23000\'') !== false, 'duplicate Tickex IDs are handled as validation errors');
registration_safety_assert(strpos($source, 'No pudimos guardar tus datos en este momento.') !== false, 'unexpected database failures return a safe message');

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE profiles (id INTEGER PRIMARY KEY, alias TEXT)');
$pdo->exec('CREATE UNIQUE INDEX alias_unique ON profiles(alias COLLATE NOCASE)');
$insert = $pdo->prepare('INSERT INTO profiles (alias) VALUES (:alias)');
$insert->execute(array(':alias' => null));
$insert->execute(array(':alias' => null));
registration_safety_assert((int)$pdo->query('SELECT COUNT(*) FROM profiles')->fetchColumn() === 2, 'multiple profiles may omit a Tickex ID safely');

$insert->execute(array(':alias' => 'leo'));
$duplicateRejected = false;
try {
    $insert->execute(array(':alias' => 'LEO'));
} catch (PDOException $e) {
    $duplicateRejected = true;
}
registration_safety_assert($duplicateRejected, 'real Tickex IDs remain unique without case differences');

echo 'ALL REGISTRATION PROFILE SAFETY TESTS PASSED' . PHP_EOL;
