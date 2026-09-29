<?php
require_once __DIR__ . '/../inc/checkout_ticket_catalog.php';

function catalog_assert($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: " . $message . "\n");
        exit(1);
    }
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE tipos_entrada (id INTEGER PRIMARY KEY, evento_id INTEGER, cantidad_disponible INTEGER, visible_publico INTEGER)');
$pdo->exec('INSERT INTO tipos_entrada (id, evento_id, cantidad_disponible, visible_publico) VALUES (1, 10, 0, 1), (2, 11, 5, 0)');

catalog_assert(!tickex_checkout_legacy_fallback_allowed($pdo, 10, true), 'sold-out catalog cannot fall back to historical entries');
catalog_assert(!tickex_checkout_legacy_fallback_allowed($pdo, 11, true), 'hidden catalog cannot fall back to historical entries');
catalog_assert(tickex_checkout_legacy_fallback_allowed($pdo, 12, true), 'legacy event without catalog may use historical entries');
catalog_assert(tickex_checkout_legacy_fallback_allowed($pdo, 10, false), 'legacy deployment without types table remains supported');
catalog_assert(tickex_checkout_legacy_fallback_allowed($pdo, 0, true), 'non-event checkout remains supported');

echo "checkout ticket catalog PASS\n";
