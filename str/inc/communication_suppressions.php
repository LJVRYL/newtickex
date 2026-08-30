<?php

if (!function_exists('communication_suppressions_ensure_schema')) {
    function communication_suppressions_ensure_schema($pdo)
    {
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
    }
}

if (!function_exists('communication_suppressions_make_token')) {
    function communication_suppressions_make_token()
    {
        if (function_exists('random_bytes')) {
            try {
                return bin2hex(random_bytes(24));
            } catch (Exception $e) {
                // fallback below
            }
        }
        return sha1(uniqid(mt_rand(), true)) . sha1(microtime(true) . mt_rand());
    }
}

if (!function_exists('communication_suppressions_token_for')) {
    function communication_suppressions_token_for($pdo, $organizationId, $adminId, $email)
    {
        communication_suppressions_ensure_schema($pdo);
        $email = strtolower(trim((string)$email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return '';

        $st = $pdo->prepare('SELECT token FROM communication_email_preferences WHERE organization_id = :org AND admin_id = :aid AND email = :email COLLATE NOCASE LIMIT 1');
        $st->execute(array(':org' => (int)$organizationId, ':aid' => (int)$adminId, ':email' => $email));
        $token = (string)$st->fetchColumn();
        if ($token !== '') return $token;

        $token = communication_suppressions_make_token();
        $ins = $pdo->prepare('INSERT OR IGNORE INTO communication_email_preferences (organization_id, admin_id, email, token, created_at, updated_at) VALUES (:org, :aid, :email, :token, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)');
        $ins->execute(array(':org' => (int)$organizationId, ':aid' => (int)$adminId, ':email' => $email, ':token' => $token));

        $st->execute(array(':org' => (int)$organizationId, ':aid' => (int)$adminId, ':email' => $email));
        return (string)$st->fetchColumn();
    }
}

if (!function_exists('communication_suppressions_is_suppressed')) {
    function communication_suppressions_is_suppressed($pdo, $organizationId, $adminId, $email)
    {
        communication_suppressions_ensure_schema($pdo);
        $st = $pdo->prepare('SELECT 1 FROM communication_email_preferences WHERE organization_id = :org AND admin_id IN (0, :aid) AND email = :email COLLATE NOCASE AND unsubscribed_at IS NOT NULL LIMIT 1');
        $st->execute(array(':org' => (int)$organizationId, ':aid' => (int)$adminId, ':email' => trim((string)$email)));
        return (bool)$st->fetchColumn();
    }
}

if (!function_exists('communication_suppressions_unsubscribe_token')) {
    function communication_suppressions_unsubscribe_token($pdo, $token)
    {
        communication_suppressions_ensure_schema($pdo);
        $token = trim((string)$token);
        if ($token === '') return false;
        $st = $pdo->prepare('UPDATE communication_email_preferences SET unsubscribed_at = COALESCE(unsubscribed_at, CURRENT_TIMESTAMP), reason = COALESCE(reason, :reason), updated_at = CURRENT_TIMESTAMP WHERE token = :token');
        $st->execute(array(':reason' => 'public_unsubscribe', ':token' => $token));
        return ($st->rowCount() > 0);
    }
}

if (!function_exists('communication_suppressions_base_url')) {
    function communication_suppressions_base_url()
    {
        $configured = getenv('TICKEX_SITE_URL');
        if (is_string($configured) && trim($configured) !== '') return rtrim(trim($configured), '/');
        $host = isset($_SERVER['HTTP_HOST']) ? trim((string)$_SERVER['HTTP_HOST']) : '';
        if ($host !== '') {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            return $scheme . '://' . $host;
        }
        return 'https://str.tickex.com.ar';
    }
}

if (!function_exists('communication_suppressions_url')) {
    function communication_suppressions_url($token)
    {
        return communication_suppressions_base_url() . '/unsubscribe.php?token=' . rawurlencode((string)$token);
    }
}

if (!function_exists('communication_suppressions_append_footer')) {
    function communication_suppressions_append_footer($bodyHtml, $bodyText, $url)
    {
        $bodyHtml = (string)$bodyHtml;
        $bodyText = (string)$bodyText;
        $url = (string)$url;
        if ($url === '') return array('body_html' => $bodyHtml, 'body_text' => $bodyText);

        if (strpos($bodyHtml, $url) === false) {
            $safeUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
            $footer = '<div style="margin-top:28px;padding-top:16px;border-top:1px solid #dddddd;font-size:12px;color:#666666;text-align:center;">Si no querés recibir más comunicaciones de este organizador, <a href="' . $safeUrl . '">podés darte de baja aquí</a>.</div>';
            if (stripos($bodyHtml, '</body>') !== false) {
                $bodyHtml = preg_replace('/<\/body>/i', $footer . '</body>', $bodyHtml, 1);
            } else {
                $bodyHtml .= $footer;
            }
        }
        if (strpos($bodyText, $url) === false) {
            $bodyText .= "\n\nPara dejar de recibir comunicaciones de este organizador:\n" . $url . "\n";
        }
        return array('body_html' => $bodyHtml, 'body_text' => $bodyText);
    }
}
