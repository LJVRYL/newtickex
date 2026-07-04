<?php

if (!function_exists('tickex_secure_links_ensure_schema')) {
    function tickex_secure_links_ensure_schema($pdo)
    {
        static $done = false;
        if ($done) return;
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS entrada_tokens (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                entrada_id INTEGER NOT NULL UNIQUE,
                token TEXT NOT NULL UNIQUE,
                created_at TEXT NOT NULL
            )");
            $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_entrada_tokens_token ON entrada_tokens(token)");
            $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_entrada_tokens_entrada ON entrada_tokens(entrada_id)");
        } catch (Exception $e) {
            // ignore
        }
        $done = true;
    }
}

if (!function_exists('tickex_secure_random_token')) {
    function tickex_secure_random_token($bytesLen)
    {
        $len = (int)$bytesLen;
        if ($len <= 0) $len = 24;
        try {
            if (function_exists('random_bytes')) {
                return bin2hex(random_bytes($len));
            }
        } catch (Exception $e) {
            // ignore
        }
        if (function_exists('openssl_random_pseudo_bytes')) {
            $bin = @openssl_random_pseudo_bytes($len);
            if ($bin !== false) return bin2hex($bin);
        }
        return sha1(uniqid((string)mt_rand(), true)) . sha1((string)microtime(true) . (string)mt_rand());
    }
}

if (!function_exists('tickex_secure_token_for_entry')) {
    function tickex_secure_token_for_entry($pdo, $entradaId)
    {
        $eid = (int)$entradaId;
        if ($eid <= 0) return '';
        tickex_secure_links_ensure_schema($pdo);

        try {
            $st = $pdo->prepare("SELECT token FROM entrada_tokens WHERE entrada_id = :eid LIMIT 1");
            $st->execute(array(':eid' => $eid));
            $tok = (string)$st->fetchColumn();
            if ($tok !== '') return $tok;
        } catch (Exception $e) {
            // ignore
        }

        for ($i = 0; $i < 3; $i++) {
            $tok = tickex_secure_random_token(24);
            try {
                $ins = $pdo->prepare("INSERT INTO entrada_tokens (entrada_id, token, created_at) VALUES (:eid, :tok, :c)");
                $ins->execute(array(
                    ':eid' => $eid,
                    ':tok' => $tok,
                    ':c' => date('Y-m-d H:i:s'),
                ));
                return $tok;
            } catch (Exception $e) {
                try {
                    $st2 = $pdo->prepare("SELECT token FROM entrada_tokens WHERE entrada_id = :eid LIMIT 1");
                    $st2->execute(array(':eid' => $eid));
                    $tok2 = (string)$st2->fetchColumn();
                    if ($tok2 !== '') return $tok2;
                } catch (Exception $e2) {
                    // ignore
                }
            }
        }
        return '';
    }
}

if (!function_exists('tickex_secure_entry_id_from_token')) {
    function tickex_secure_entry_id_from_token($pdo, $token)
    {
        $tok = trim((string)$token);
        if ($tok === '') return 0;
        tickex_secure_links_ensure_schema($pdo);
        try {
            $st = $pdo->prepare("SELECT entrada_id FROM entrada_tokens WHERE token = :tok LIMIT 1");
            $st->execute(array(':tok' => $tok));
            return (int)$st->fetchColumn();
        } catch (Exception $e) {
            return 0;
        }
    }
}

if (!function_exists('tickex_secure_ticket_url')) {
    function tickex_secure_ticket_url($pdo, $baseUrl, $entradaId, $codigoFallback)
    {
        $base = rtrim((string)$baseUrl, '/');
        $tok = tickex_secure_token_for_entry($pdo, (int)$entradaId);
        if ($tok !== '') return $base . '/ticket.php?t=' . urlencode($tok);
        return $base . '/ticket.php?c=' . urlencode((string)$codigoFallback);
    }
}

if (!function_exists('tickex_secure_checkin_url')) {
    function tickex_secure_checkin_url($pdo, $baseUrl, $entradaId, $codigoFallback)
    {
        $base = rtrim((string)$baseUrl, '/');
        $tok = tickex_secure_token_for_entry($pdo, (int)$entradaId);
        if ($tok !== '') return $base . '/checkin.php?t=' . urlencode($tok);
        return $base . '/checkin.php?c=' . urlencode((string)$codigoFallback);
    }
}
