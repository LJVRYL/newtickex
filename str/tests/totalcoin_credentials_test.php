<?php
if (PHP_SAPI !== 'cli') die("CLI only\n");

$credentialsFile = tempnam(sys_get_temp_dir(), 'tickex-tc-credentials-');
if ($credentialsFile === false) die("FAIL: cannot create temporary file\n");

$contents = "<?php\nreturn array('username' => 'file-user', 'password' => 'file-password');\n";
file_put_contents($credentialsFile, $contents);

putenv('TOTALCOIN_CREDENTIALS_FILE=' . $credentialsFile);
putenv('TOTALCOIN_USER');
putenv('TOTALCOIN_PASS');
require_once __DIR__ . '/../inc/totalcoin.php';

function credentials_assert($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }
    echo 'PASS: ' . $message . PHP_EOL;
}

$fromFile = tc_credentials();
credentials_assert($fromFile['username'] === 'file-user', 'username is loaded from secure file');
credentials_assert($fromFile['password'] === 'file-password', 'password is loaded from secure file');

putenv('TOTALCOIN_USER=env-user');
putenv('TOTALCOIN_PASS=env-password');
$fromEnv = tc_credentials();
credentials_assert($fromEnv['username'] === 'env-user', 'environment username overrides secure file');
credentials_assert($fromEnv['password'] === 'env-password', 'environment password overrides secure file');

@unlink($credentialsFile);

