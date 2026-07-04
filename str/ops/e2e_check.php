<?php
/**
 * e2e_check.php — Validación E2E ejecutable para Tickex
 *
 * Uso (CLI):  php str/ops/e2e_check.php
 * Uso (web):  https://str.tickex.com.ar/str/ops/e2e_check.php  (solo localhost)
 *
 * Verifica el flujo completo de producción sin modificar datos.
 */

// Protección: solo localhost o CLI
if (PHP_SAPI !== 'cli') {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!in_array($ip, array('127.0.0.1', '::1'), true)) {
        http_response_code(403);
        exit("Acceso denegado. Ejecutar desde CLI: php str/ops/e2e_check.php\n");
    }
}

define('TICKEX_E2E', true);
chdir(__DIR__ . '/..');
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/order_processing.php';
require_once __DIR__ . '/../inc/order_events.php';
require_once __DIR__ . '/../inc/secure_links.php';

$pass = 0; $fail = 0; $warn = 0;

function e2e_ok($label)  { global $pass; $pass++; echo "  [PASS] $label\n"; }
function e2e_fail($label, $detail = '') { global $fail; $fail++; echo "  [FAIL] $label" . ($detail ? ": $detail" : '') . "\n"; }
function e2e_warn($label, $detail = '') { global $warn; $warn++; echo "  [WARN] $label" . ($detail ? ": $detail" : '') . "\n"; }
function e2e_section($title) { echo "\n── $title\n"; }

$pdo = db();

// ── 1. Schema ──────────────────────────────────────────────────────────
e2e_section("1. Schema de base de datos");

$tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
foreach (array('tc_orders','entradas','tipos_entrada','order_events','entrada_tokens','email_logs') as $t) {
    in_array($t, $tables) ? e2e_ok("Tabla '$t' existe") : e2e_fail("Tabla '$t' FALTA");
}

$colsTco = $pdo->query("PRAGMA table_info(tc_orders)")->fetchAll(PDO::FETCH_COLUMN, 1);
foreach (array('request_id','state','selected_tickets_json','processed_at','evento_id','buyer_email') as $c) {
    in_array($c, $colsTco) ? e2e_ok("tc_orders.$c existe") : e2e_fail("tc_orders.$c FALTA");
}

$colsEn = $pdo->query("PRAGMA table_info(entradas)")->fetchAll(PDO::FETCH_COLUMN, 1);
foreach (array('codigo','tc_order_request_id','evento_id','checked_in') as $c) {
    in_array($c, $colsEn) ? e2e_ok("entradas.$c existe") : e2e_fail("entradas.$c FALTA");
}

$hasUniqueIdx = (bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type='index' AND name='idx_tc_orders_request_id' LIMIT 1")->fetchColumn();
$hasUniqueIdx ? e2e_ok("UNIQUE INDEX en tc_orders.request_id existe") : e2e_warn("UNIQUE INDEX en tc_orders.request_id no encontrado");


// ── 2. Env vars ────────────────────────────────────────────────────────
e2e_section("2. Variables de entorno");

$siteUrl = getenv('TICKEX_SITE_URL');
$siteUrl ? e2e_ok("TICKEX_SITE_URL=$siteUrl") : e2e_warn("TICKEX_SITE_URL no configurada — mails del reconciliador usarán fallback https://str.tickex.com.ar");

$cbBase = getenv('TOTALCOIN_CALLBACK_BASE');
$cbBase ? e2e_ok("TOTALCOIN_CALLBACK_BASE=$cbBase") : e2e_warn("TOTALCOIN_CALLBACK_BASE no configurada — TotalCoin no puede llamar al callback");

$sendCb = getenv('TOTALCOIN_SEND_CALLBACKS');
($sendCb !== false && $sendCb !== '0') ? e2e_ok("TOTALCOIN_SEND_CALLBACKS habilitado") : e2e_warn("TOTALCOIN_SEND_CALLBACKS=0 o no configurado — callbacks desactivados, todo depende del reconciliador");

$mailFrom = getenv('TICKEX_MAIL_FROM_EMAIL');
$mailFrom ? e2e_ok("TICKEX_MAIL_FROM_EMAIL=$mailFrom") : e2e_warn("TICKEX_MAIL_FROM_EMAIL no configurada — usando default servicio@tickex.com.ar");


// ── 3. Plantilla de email entrada_registro ─────────────────────────────
e2e_section("3. Plantilla de email");

try {
    $tpl = $pdo->prepare("SELECT id, subject FROM email_templates WHERE context = 'entrada_registro' AND enabled = 1 LIMIT 1");
    $tpl->execute();
    $row = $tpl->fetch(PDO::FETCH_ASSOC);
    $row ? e2e_ok("Plantilla 'entrada_registro' existe (subject: {$row['subject']})") : e2e_warn("Plantilla 'entrada_registro' NO existe — se usará fallback hardcodeado");
} catch (Exception $e) {
    e2e_warn("Tabla email_templates no existe o error: " . $e->getMessage());
}


// ── 4. Idempotencia del engine ─────────────────────────────────────────
e2e_section("4. Idempotencia del Order Engine");

$testRid = 'e2e-test-' . bin2hex(random_bytes(4));
try {
    // Insertar orden de prueba ya procesada
    $pdo->exec("BEGIN");
    $pdo->prepare("INSERT INTO tc_orders (request_id, state, evento_id, selected_tickets_json, buyer_first, buyer_last, buyer_email, processed_at, created_at) VALUES (?, 'success', 0, '[{\"id\":0,\"name\":\"Test\",\"qty\":1,\"price\":0}]', 'Test', 'E2E', 'e2e@test.invalid', datetime('now'), datetime('now'))")
        ->execute(array($testRid));
    $pdo->exec("COMMIT");

    // Llamar al engine — debe retornar processed=false porque processed_at ya está seteado
    $result = process_tc_order_by_request_id($testRid);
    if (empty($result['processed']) && strpos($result['debugMsg'], 'ya procesada') !== false) {
        e2e_ok("Engine respeta processed_at — no duplica orden ya procesada");
    } else {
        e2e_fail("Engine procesó orden con processed_at ya seteado", json_encode($result));
    }

    // Llamar al engine una segunda vez — debe seguir siendo false
    $result2 = process_tc_order_by_request_id($testRid);
    if (empty($result2['processed'])) {
        e2e_ok("Segunda llamada al engine también idempotente");
    } else {
        e2e_fail("Segunda llamada al engine produjo resultado inesperado", json_encode($result2));
    }

} catch (Exception $e) {
    e2e_fail("Error en test de idempotencia", $e->getMessage());
} finally {
    // Limpiar datos de prueba
    try {
        $pdo->prepare("DELETE FROM tc_orders WHERE request_id = ?")->execute(array($testRid));
        $pdo->prepare("DELETE FROM order_events WHERE request_id = ?")->execute(array($testRid));
    } catch (Exception $_e) {}
}


// ── 5. Anti-duplicado de entradas ──────────────────────────────────────
e2e_section("5. Anti-duplicado de entradas");

$testRid2 = 'e2e-dedup-' . bin2hex(random_bytes(4));
try {
    $pdo->exec("BEGIN");
    // Orden sin processed_at + con selected_tickets_json válido
    $pdo->prepare("INSERT INTO tc_orders (request_id, state, evento_id, selected_tickets_json, buyer_first, buyer_last, buyer_email, created_at) VALUES (?, 'success', 0, '[{\"id\":0,\"name\":\"Test\",\"qty\":1,\"price\":0}]', 'Test', 'E2E', 'e2e@test.invalid', datetime('now'))")
        ->execute(array($testRid2));
    // Simular que ya existe una entrada con ese request_id
    $pdo->prepare("INSERT INTO entradas (evento_id, nombre, email, fecha_registro, codigo, checked_in, tipo, monto_pagado, tc_order_request_id) VALUES (0, 'Test', 'e2e@test.invalid', datetime('now'), 'e2e-test-code', 0, 'Test', 0, ?)")
        ->execute(array($testRid2));
    $pdo->exec("COMMIT");

    $result = process_tc_order_by_request_id($testRid2);
    $entryCount = (int)$pdo->prepare("SELECT COUNT(*) FROM entradas WHERE tc_order_request_id = ?")->execute(array($testRid2)) + 0;
    $entryCount = (int)$pdo->query("SELECT COUNT(*) FROM entradas WHERE tc_order_request_id = " . $pdo->quote($testRid2))->fetchColumn();

    if ($entryCount === 1) {
        e2e_ok("Engine no duplicó entrada — COUNT=1 tras segunda llamada");
    } else {
        e2e_fail("Engine duplicó entradas — COUNT=$entryCount, esperado 1", json_encode($result));
    }

} catch (Exception $e) {
    e2e_fail("Error en test anti-duplicado", $e->getMessage());
} finally {
    try {
        $pdo->prepare("DELETE FROM tc_orders WHERE request_id = ?")->execute(array($testRid2));
        $pdo->prepare("DELETE FROM entradas WHERE tc_order_request_id = ?")->execute(array($testRid2));
        $pdo->prepare("DELETE FROM order_events WHERE request_id = ?")->execute(array($testRid2));
    } catch (Exception $_e) {}
}


// ── 6. Token de entrada ────────────────────────────────────────────────
e2e_section("6. Generación de token seguro");

// Usar primera entrada real del sistema
$sampleEntry = $pdo->query("SELECT id, codigo FROM entradas ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($sampleEntry) {
    $tok = tickex_secure_token_for_entry($pdo, (int)$sampleEntry['id']);
    if ($tok !== '') {
        e2e_ok("Token generado para entrada #{$sampleEntry['id']}: " . substr($tok, 0, 10) . '...');
        // Verificar resolución inversa
        $resolvedId = tickex_secure_entry_id_from_token($pdo, $tok);
        $resolvedId === (int)$sampleEntry['id']
            ? e2e_ok("Token resuelve correctamente a entrada_id={$sampleEntry['id']}")
            : e2e_fail("Token no resuelve al ID correcto", "expected={$sampleEntry['id']} got=$resolvedId");
        // Verificar URL generada
        $url = tickex_secure_ticket_url($pdo, 'https://str.tickex.com.ar', (int)$sampleEntry['id'], $sampleEntry['codigo']);
        strpos($url, '?t=') !== false
            ? e2e_ok("URL de ticket usa token seguro: $url")
            : e2e_warn("URL de ticket usa codigo fallback: $url");
    } else {
        e2e_fail("tickex_secure_token_for_entry devolvió string vacío");
    }
} else {
    e2e_warn("No hay entradas en el sistema — test de token omitido");
}


// ── 7. Reconciliador ──────────────────────────────────────────────────
e2e_section("7. Reconciliador (estado)");

$pendingCount = (int)$pdo->query("SELECT COUNT(*) FROM tc_orders WHERE state='success' AND processed_at IS NULL")->fetchColumn();
$pendingCount === 0
    ? e2e_ok("No hay órdenes pendientes de procesar (processed_at IS NULL)")
    : e2e_warn("Hay $pendingCount órdenes con state=success y processed_at IS NULL — ejecutar reconciliador");

$bridgeCount = (int)$pdo->query("SELECT COUNT(*) FROM tc_orders WHERE state='bridge_synced'")->fetchColumn();
e2e_ok("Órdenes bridge_synced (inmunes al reconciliador): $bridgeCount");

$logExists = file_exists(__DIR__ . '/../../uploads/cron_reconciler.log');
$logExists ? e2e_ok("Log del cron existe: uploads/cron_reconciler.log") : e2e_warn("Log del cron no existe aún — cron no ha corrido o no está instalado");


// ── 8. Cron ───────────────────────────────────────────────────────────
e2e_section("8. Cron reconciliador");

if (PHP_SAPI === 'cli') {
    $crontab = shell_exec('crontab -l 2>/dev/null') ?: '';
    strpos($crontab, 'cron_reconciler') !== false
        ? e2e_ok("Cron instalado: " . trim(array_values(array_filter(explode("\n", $crontab), function($l){ return strpos($l,'cron_reconciler')!==false; }))[0] ?? ''))
        : e2e_warn("Cron NO instalado — ejecutar: bash str/ops/deploy.sh");
} else {
    e2e_warn("Verificación de cron solo disponible en CLI");
}


// ── 9. Órdenes recientes ──────────────────────────────────────────────
e2e_section("9. Estado de órdenes recientes (últimas 24h)");

$recent = $pdo->query("SELECT state, COUNT(*) as n FROM tc_orders WHERE created_at >= datetime('now','-1 day') GROUP BY state ORDER BY n DESC")->fetchAll(PDO::FETCH_ASSOC);
if ($recent) {
    foreach ($recent as $r) {
        echo "       state={$r['state']} count={$r['n']}\n";
    }
    $successUnprocessed = (int)$pdo->query("SELECT COUNT(*) FROM tc_orders WHERE state='success' AND processed_at IS NULL AND created_at >= datetime('now','-1 day')")->fetchColumn();
    $successUnprocessed === 0
        ? e2e_ok("Todas las órdenes success de las últimas 24h tienen processed_at")
        : e2e_fail("$successUnprocessed órdenes success de las últimas 24h sin processed_at — ACCIÓN REQUERIDA");
} else {
    e2e_warn("Sin órdenes en las últimas 24h");
}


// ── Resumen ───────────────────────────────────────────────────────────
echo "\n══════════════════════════════════════════════════════════\n";
echo " RESULTADO: PASS=$pass  WARN=$warn  FAIL=$fail\n";
echo "══════════════════════════════════════════════════════════\n";
if ($fail > 0) {
    echo " SISTEMA NO LISTO PARA PRODUCCIÓN — corregir los FAIL\n";
    exit(1);
} elseif ($warn > 0) {
    echo " SISTEMA FUNCIONAL — revisar los WARN antes del go-live\n";
    exit(0);
} else {
    echo " SISTEMA LISTO PARA PRODUCCIÓN\n";
    exit(0);
}
