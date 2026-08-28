<?php
// Usage: php run_20260828_ticket_qr_quantity.php --db=/path/to/copy.sqlite

function ticket_qr_migration_arg_db($argv)
{
    foreach ($argv as $arg) {
        if (strpos($arg, '--db=') === 0) return substr($arg, 5);
    }
    return '';
}

function ticket_qr_migration_columns($pdo, $table)
{
    $cols = array();
    $st = $pdo->query('PRAGMA table_info(' . $table . ')');
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (isset($row['name'])) $cols[(string)$row['name']] = true;
    }
    return $cols;
}

$dbFile = ticket_qr_migration_arg_db($argv);
if ($dbFile === '' || !is_file($dbFile)) {
    fwrite(STDERR, "Usage: php run_20260828_ticket_qr_quantity.php --db=/path/to/copy.sqlite\n");
    exit(2);
}

$pdo = new PDO('sqlite:' . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$templateCols = ticket_qr_migration_columns($pdo, 'plantillas_entrada');
if (!isset($templateCols['qr_quantity'])) {
    $pdo->exec('ALTER TABLE plantillas_entrada ADD COLUMN qr_quantity INTEGER NOT NULL DEFAULT 1');
}

$ticketTypeCols = ticket_qr_migration_columns($pdo, 'tipos_entrada');
if (!isset($ticketTypeCols['qr_quantity'])) {
    $pdo->exec('ALTER TABLE tipos_entrada ADD COLUMN qr_quantity INTEGER NOT NULL DEFAULT 1');
}

$pdo->exec('UPDATE plantillas_entrada SET qr_quantity = 1 WHERE qr_quantity IS NULL OR qr_quantity < 1');
$pdo->exec('UPDATE tipos_entrada SET qr_quantity = 1 WHERE qr_quantity IS NULL OR qr_quantity < 1');

echo "Migration applied to: " . $dbFile . "\n";
