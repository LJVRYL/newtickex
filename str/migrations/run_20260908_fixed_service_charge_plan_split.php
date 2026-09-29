<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$dbFile = '';
foreach ($argv as $arg) if (strpos($arg, '--db=') === 0) $dbFile = substr($arg, 5);
if ($dbFile === '' || !is_file($dbFile)) {
    fwrite(STDERR, "Uso: php run_20260908_fixed_service_charge_plan_split.php --db=/ruta/copia.sqlite\n");
    exit(1);
}
require_once __DIR__ . '/../inc/subscriptions.php';
try {
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA busy_timeout=15000');
    $pdo->beginTransaction();
    $terms = tickex_subscription_apply_commercial_fees($pdo);
    $hasMpSettings = (int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='mercadopago_platform_settings'")->fetchColumn() > 0;
    if ($hasMpSettings) $pdo->exec('UPDATE mercadopago_platform_settings SET total_cost_target_percent=15,updated_at=CURRENT_TIMESTAMP WHERE id=1');
    $integrity = $pdo->query('PRAGMA integrity_check')->fetchColumn();
    if ($integrity !== 'ok') throw new RuntimeException('Integridad SQLite: ' . $integrity);
    $pdo->commit();
    echo 'Modelo comercial actualizado: ' . json_encode($terms) . PHP_EOL;
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, 'No se pudo aplicar la migración: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
