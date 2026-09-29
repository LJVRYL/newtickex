<?php
/** Run once from the CLI after a database backup; safe to rerun. No user roles are changed. */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
require_once __DIR__.'/inc/bootstrap.php';
require_once __DIR__.'/inc/producer_onboarding_requests.php';
$pdo=db();
$pdo->beginTransaction();
try {
    $hasLegacy=(bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='producer_self_service'")->fetchColumn();
    if (!$hasLegacy) throw new RuntimeException('Falta producer_self_service; aplicá primero su migración.');
    tickex_producer_requests_migrate($pdo);
    $total=(int)$pdo->query('SELECT COUNT(*) FROM producer_onboarding_requests')->fetchColumn();
    $imported=(int)$pdo->query("SELECT COUNT(*) FROM producer_onboarding_requests WHERE status='imported_auto'")->fetchColumn();
    $pdo->commit();
    echo 'Solicitudes: '.$total.'; altas históricas registradas: '.$imported.PHP_EOL;
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR,'No se aplicó la migración: '.$e->getMessage().PHP_EOL);
    exit(1);
}
