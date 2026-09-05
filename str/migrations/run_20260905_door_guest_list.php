<?php
require_once __DIR__ . '/../inc/door_guest_list.php';

$dbFile = __DIR__ . '/../save_the_rave.sqlite';
foreach ($argv as $arg) if (strpos($arg, '--db=') === 0) $dbFile = substr($arg, 5);
$pdo = new PDO('sqlite:' . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
tickex_door_list_ensure_schema($pdo);
echo 'Migration applied to: ' . $dbFile . PHP_EOL;
