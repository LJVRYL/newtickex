<?php

$dbFile = '';
foreach ($argv as $arg) if (strpos($arg, '--db=') === 0) $dbFile = substr($arg, 5);
if ($dbFile === '' || !is_file($dbFile)) {
    fwrite(STDERR, "Usage: php run_20260905_event_global_capacity.php --db=/path/to/database.sqlite\n");
    exit(1);
}
require_once __DIR__ . '/../inc/event_capacity.php';
$pdo = new PDO('sqlite:' . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA busy_timeout=15000');
tickex_event_capacity_ensure_schema($pdo);
$events = $pdo->query('SELECT id,capacidad_total FROM eventos')->fetchAll(PDO::FETCH_ASSOC);
$updated = 0;
foreach ($events as $event) {
    if ((int)$event['capacidad_total'] > 0) continue;
    $issued = tickex_event_capacity_issued($pdo, (int)$event['id']);
    $legacy = tickex_event_capacity_legacy_limit($pdo, (int)$event['id']);
    $capacity = max($issued, (int)$legacy);
    if ($capacity <= 0) continue;
    $st = $pdo->prepare('UPDATE eventos SET capacidad_total=:capacity WHERE id=:event AND capacidad_total IS NULL');
    $st->execute(array(':capacity'=>$capacity, ':event'=>(int)$event['id']));
    $updated += $st->rowCount();
}
echo 'Migration applied to: ' . $dbFile . PHP_EOL;
echo 'Events initialized: ' . $updated . PHP_EOL;
