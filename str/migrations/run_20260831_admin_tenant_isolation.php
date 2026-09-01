<?php
$dbFile = __DIR__ . '/../save_the_rave.sqlite';
foreach ($argv as $arg) if (strpos($arg, '--db=') === 0) $dbFile = substr($arg, 5);
$pdo = new PDO('sqlite:' . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$cols = $pdo->query('PRAGMA table_info(inv_items)')->fetchAll(PDO::FETCH_ASSOC);
$hasOwner = false;
foreach ($cols as $col) if (isset($col['name']) && $col['name'] === 'owner_admin_id') $hasOwner = true;
if (!$hasOwner) $pdo->exec('ALTER TABLE inv_items ADD COLUMN owner_admin_id INTEGER');
// El inventario anterior a la separación pertenece a la cuenta histórica de SAVE THE RAVE (admin 2).
$pdo->exec('UPDATE inv_items SET owner_admin_id=2 WHERE owner_admin_id IS NULL');
$pdo->exec('CREATE INDEX IF NOT EXISTS idx_inv_items_owner ON inv_items(owner_admin_id)');
echo 'Migration applied to: ' . $dbFile . PHP_EOL;
