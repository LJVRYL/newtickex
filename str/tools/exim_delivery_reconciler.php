<?php

$dbFile = '';
$logFile = getenv('TICKEX_EXIM_MAINLOG');
if (!is_string($logFile) || trim($logFile) === '') $logFile = '/var/spool/exim/log/mainlog';
foreach ($argv as $arg) {
    if (strpos($arg, '--db=') === 0) $dbFile = substr($arg, 5);
    elseif (strpos($arg, '--log=') === 0) $logFile = substr($arg, 6);
}
if ($dbFile !== '') putenv('TICKEX_DB_FILE=' . $dbFile);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/communication_delivery_feedback.php';

try {
    $result = communication_delivery_feedback_process_log(db(), $logFile);
    echo 'Lineas leidas: ' . (int)$result['lines'] . PHP_EOL;
    echo 'Eventos reconocidos: ' . (int)$result['parsed'] . PHP_EOL;
    echo 'Eventos nuevos: ' . (int)$result['stored'] . PHP_EOL;
    echo 'Aceptados: ' . (int)$result['accepted'] . PHP_EOL;
    echo 'Entregados: ' . (int)$result['delivered'] . PHP_EOL;
    echo 'Demorados: ' . (int)$result['deferred'] . PHP_EOL;
    echo 'Rebotados: ' . (int)$result['bounced'] . PHP_EOL;
} catch (Exception $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
