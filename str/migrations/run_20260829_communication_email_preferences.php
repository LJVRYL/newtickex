<?php

$dbFile = '';
foreach ($argv as $arg) {
    if (strpos($arg, '--db=') === 0) $dbFile = substr($arg, 5);
}
if ($dbFile === '') {
    fwrite(STDERR, "Usage: php run_20260829_communication_email_preferences.php --db=/path/to/copy.sqlite\n");
    exit(1);
}
if (!file_exists($dbFile)) {
    fwrite(STDERR, "Database not found: " . $dbFile . "\n");
    exit(1);
}

$pdo = new PDO('sqlite:' . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA busy_timeout = 15000');
$pdo->beginTransaction();
try {
    $pdo->exec('CREATE TABLE IF NOT EXISTS communication_email_preferences (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        organization_id INTEGER NOT NULL DEFAULT 1,
        admin_id INTEGER NOT NULL DEFAULT 0,
        email TEXT NOT NULL,
        token TEXT NOT NULL,
        unsubscribed_at TEXT,
        reason TEXT,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(organization_id, admin_id, email),
        UNIQUE(token)
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_comm_email_pref_scope ON communication_email_preferences(organization_id, admin_id, unsubscribed_at)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_comm_email_pref_email ON communication_email_preferences(email COLLATE NOCASE)');
    $pdo->commit();
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, 'Migration failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

echo 'Migration applied to: ' . $dbFile . PHP_EOL;
