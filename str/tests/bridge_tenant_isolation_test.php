<?php

require_once __DIR__ . '/../inc/unified_tickets.php';

function bridge_tenant_ok($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, 'FAIL: '.$message.PHP_EOL);
        exit(1);
    }
    echo 'PASS: '.$message.PHP_EOL;
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE eventos (id INTEGER PRIMARY KEY, slug TEXT, creado_por_admin_id INTEGER)');
$pdo->exec('CREATE TABLE mercadopago_admin_policies (admin_id INTEGER PRIMARY KEY, account_type TEXT)');
$pdo->exec('CREATE TABLE bridge_event_map (id INTEGER PRIMARY KEY AUTOINCREMENT, evento_id INTEGER, bridge_slug TEXT, created_at TEXT)');
$pdo->exec('CREATE TABLE entradas (id INTEGER PRIMARY KEY, evento_id INTEGER, nombre TEXT, email TEXT, codigo TEXT, tipo TEXT, monto_pagado REAL, checked_in INTEGER, checked_in_at TEXT, created_at TEXT, oculto INTEGER DEFAULT 0)');
$pdo->exec('CREATE TABLE senforms_bridge_tickets (id INTEGER PRIMARY KEY, event_slug TEXT, is_paid INTEGER, selected_type_name TEXT, price REAL, buyer_name TEXT, buyer_email TEXT, is_checked_in INTEGER, created_at TEXT)');
$pdo->exec("INSERT INTO eventos VALUES (15,'sab5',2),(18,'test2',10)");
$pdo->exec("INSERT INTO mercadopago_admin_policies VALUES (2,'str_owner'),(10,'client')");
$pdo->exec("INSERT INTO bridge_event_map (evento_id,bridge_slug,created_at) VALUES (15,'savetherave7-3','2026-02-13'),(18,'savetherave7-3','2026-02-13')");
$pdo->exec("INSERT INTO senforms_bridge_tickets VALUES (468,'savetherave7-3',1,'Entrada General',12000,'Comprador STR','buyer@example.com',0,'2026-02-13')");

bridge_tenant_ok(tickex_event_bridge_allowed($pdo, 15), 'internal SAVE THE RAVE event can use Bridge');
bridge_tenant_ok(!tickex_event_bridge_allowed($pdo, 18), 'client event cannot use Bridge');
bridge_tenant_ok(get_mapped_bridge_slugs($pdo, 15) === array('savetherave7-3'), 'internal event keeps its Bridge mapping');
bridge_tenant_ok(get_mapped_bridge_slugs($pdo, 18) === array(), 'stale client mapping is never exposed');
bridge_tenant_ok(!set_bridge_mapping($pdo, 18, 'savetherave7-3'), 'client event cannot create a Bridge mapping');

$strEntries = get_unified_entries($pdo, 15);
$clientEntries = get_unified_entries($pdo, 18);
bridge_tenant_ok(count($strEntries) === 1 && $strEntries[0]['source'] === 'TICKEX', 'internal event still reads its legacy ticket');
bridge_tenant_ok(count($clientEntries) === 0, 'client event cannot inherit legacy tickets through a reused id');

$clientStats = get_unified_stats($pdo, 18);
bridge_tenant_ok((int)$clientStats['total'] === 0 && (int)$clientStats['paid'] === 0, 'client event metrics exclude Bridge data');

echo 'ALL BRIDGE TENANT ISOLATION TESTS PASSED'.PHP_EOL;
