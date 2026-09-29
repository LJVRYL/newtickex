<?php

$dbFile = '';
foreach ($argv as $arg) {
    if (strpos($arg, '--db=') === 0) $dbFile = substr($arg, 5);
}
if ($dbFile === '' || !file_exists($dbFile)) {
    fwrite(STDERR, "Usage: php run_20260831_mercadopago_client_provider.php --db=/path/to/copy.sqlite\n");
    exit(1);
}

require_once __DIR__ . '/../inc/mercadopago_marketplace.php';
$pdo = new PDO('sqlite:' . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA busy_timeout = 15000');
$pdo->beginTransaction();
try {
    tickex_mp_ensure_schema($pdo);

    // La cuenta historica #2 es SAVE THE RAVE. Todas las demas cuentas son
    // organizadores cliente y deben usar Mercado Pago.
    $pdo->exec("UPDATE mercadopago_admin_policies SET account_type='client',updated_at=CURRENT_TIMESTAMP WHERE admin_id<>2");
    $pdo->exec("INSERT OR IGNORE INTO mercadopago_admin_policies (admin_id,account_type,created_at,updated_at) VALUES (2,'str_owner',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)");
    $pdo->exec("UPDATE mercadopago_admin_policies SET account_type='str_owner',updated_at=CURRENT_TIMESTAMP WHERE admin_id=2");

    $pdo->exec("UPDATE mercadopago_event_configs SET provider='mercadopago',updated_at=CURRENT_TIMESTAMP WHERE event_id IN (SELECT id FROM eventos WHERE creado_por_admin_id<>2)");
    $pdo->commit();
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, 'Migration failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

echo 'Migration applied to: ' . $dbFile . PHP_EOL;
