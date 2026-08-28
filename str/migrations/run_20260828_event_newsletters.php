<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

$dbFile = '';
foreach ($argv as $arg) {
    if (strpos($arg, '--db=') === 0) $dbFile = substr($arg, 5);
}
if ($dbFile === '' || !is_file($dbFile)) {
    fwrite(STDERR, "Usage: php run_20260828_event_newsletters.php --db=/path/to/copy.sqlite\n");
    exit(1);
}

try {
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    require_once dirname(__DIR__) . '/inc/event_newsletters.php';
    event_newsletters_ensure_schema($pdo);
    echo "Migration applied to: " . $dbFile . PHP_EOL;
} catch (Exception $e) {
    fwrite(STDERR, "Migration failed: " . $e->getMessage() . PHP_EOL);
    exit(1);
}
