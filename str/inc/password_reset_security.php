<?php

if (!function_exists('tickex_password_reset_ensure_schema')) {
    function tickex_password_reset_ensure_schema($pdo)
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS password_reset_tokens (
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          email TEXT NOT NULL,
          token TEXT NOT NULL,
          creado_en TEXT,
          consumido_en TEXT
        )");
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_prt_token ON password_reset_tokens(token)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_prt_email ON password_reset_tokens(email COLLATE NOCASE)');

        $pdo->exec("CREATE TABLE IF NOT EXISTS password_reset_rate_limits (
          scope TEXT NOT NULL,
          key_hash TEXT NOT NULL,
          window_started_at TEXT NOT NULL,
          attempts INTEGER NOT NULL DEFAULT 0,
          updated_at TEXT NOT NULL,
          PRIMARY KEY (scope, key_hash)
        )");
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_prrl_updated_at ON password_reset_rate_limits(updated_at)');
    }
}

if (!function_exists('tickex_password_reset_normalize_email')) {
    function tickex_password_reset_normalize_email($email)
    {
        return strtolower(trim((string)$email));
    }
}

if (!function_exists('tickex_password_reset_account_exists')) {
    function tickex_password_reset_account_exists($pdo, $email)
    {
        $normalized = tickex_password_reset_normalize_email($email);
        foreach (array('usuarios', 'registro_pendientes') as $table) {
            try {
                $st = $pdo->prepare('SELECT 1 FROM ' . $table . ' WHERE email = :email COLLATE NOCASE LIMIT 1');
                $st->execute(array(':email' => $normalized));
                if ($st->fetchColumn()) return true;
            } catch (Exception $e) {
                // La tabla puede no existir en instalaciones antiguas.
            }
        }
        return false;
    }
}

if (!function_exists('tickex_password_reset_prune')) {
    function tickex_password_reset_prune($pdo)
    {
        tickex_password_reset_ensure_schema($pdo);
        $pdo->exec("DELETE FROM password_reset_tokens
                    WHERE creado_en IS NULL
                       OR creado_en < datetime('now','-2 days')
                       OR (consumido_en IS NOT NULL AND consumido_en < datetime('now','-1 day'))");
        $pdo->exec("DELETE FROM password_reset_rate_limits
                    WHERE updated_at < datetime('now','-2 days')");
    }
}

if (!function_exists('tickex_password_reset_rate_limited')) {
    function tickex_password_reset_rate_limited($pdo, $scope, $identifier, $maxAttempts, $windowSeconds)
    {
        tickex_password_reset_ensure_schema($pdo);
        $scope = trim((string)$scope);
        $keyHash = hash('sha256', $scope . '|' . trim((string)$identifier));
        $maxAttempts = max(1, (int)$maxAttempts);
        $windowSeconds = max(60, (int)$windowSeconds);
        $modifier = '-' . $windowSeconds . ' seconds';

        $insert = $pdo->prepare("INSERT OR IGNORE INTO password_reset_rate_limits
            (scope, key_hash, window_started_at, attempts, updated_at)
            VALUES (:scope, :key_hash, datetime('now'), 0, datetime('now'))");
        $insert->execute(array(':scope' => $scope, ':key_hash' => $keyHash));

        $update = $pdo->prepare("UPDATE password_reset_rate_limits SET
            attempts = CASE
                WHEN window_started_at <= datetime('now', :modifier_attempts) THEN 1
                ELSE attempts + 1
            END,
            window_started_at = CASE
                WHEN window_started_at <= datetime('now', :modifier_window) THEN datetime('now')
                ELSE window_started_at
            END,
            updated_at = datetime('now')
            WHERE scope = :scope AND key_hash = :key_hash");
        $update->execute(array(
            ':modifier_attempts' => $modifier,
            ':modifier_window' => $modifier,
            ':scope' => $scope,
            ':key_hash' => $keyHash,
        ));

        $select = $pdo->prepare('SELECT attempts FROM password_reset_rate_limits WHERE scope = :scope AND key_hash = :key_hash');
        $select->execute(array(':scope' => $scope, ':key_hash' => $keyHash));
        return (int)$select->fetchColumn() > $maxAttempts;
    }
}

if (!function_exists('tickex_password_reset_create_token')) {
    function tickex_password_reset_create_token($pdo, $email)
    {
        tickex_password_reset_ensure_schema($pdo);
        $normalized = tickex_password_reset_normalize_email($email);
        $token = function_exists('random_bytes')
            ? bin2hex(random_bytes(32))
            : hash('sha256', uniqid(mt_rand(), true));

        $pdo->beginTransaction();
        try {
            $delete = $pdo->prepare('DELETE FROM password_reset_tokens WHERE email = :email COLLATE NOCASE');
            $delete->execute(array(':email' => $normalized));
            $insert = $pdo->prepare("INSERT INTO password_reset_tokens (email, token, creado_en)
                                     VALUES (:email, :token, datetime('now'))");
            $insert->execute(array(':email' => $normalized, ':token' => $token));
            $pdo->commit();
            return $token;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}
