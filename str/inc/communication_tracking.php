<?php

if (!function_exists('communication_tracking_table_has_column')) {
    function communication_tracking_table_has_column($pdo, $table, $column)
    {
        $table = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$table);
        if ($table === '') return false;
        $rows = $pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            if (isset($row['name']) && (string)$row['name'] === (string)$column) return true;
        }
        return false;
    }
}

if (!function_exists('communication_tracking_table_exists')) {
    function communication_tracking_table_exists($pdo, $table)
    {
        $st = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=:name LIMIT 1");
        $st->execute(array(':name' => (string)$table));
        return (bool)$st->fetchColumn();
    }
}

if (!function_exists('communication_tracking_ensure_order_columns')) {
    function communication_tracking_ensure_order_columns($pdo)
    {
        if (!communication_tracking_table_exists($pdo, 'tc_orders')) return;
        $adds = array(
            'communication_campaign_id' => 'ALTER TABLE tc_orders ADD COLUMN communication_campaign_id INTEGER',
            'communication_run_id' => 'ALTER TABLE tc_orders ADD COLUMN communication_run_id INTEGER',
            'communication_recipient_fingerprint' => 'ALTER TABLE tc_orders ADD COLUMN communication_recipient_fingerprint TEXT',
            'communication_tracking_token' => 'ALTER TABLE tc_orders ADD COLUMN communication_tracking_token TEXT',
        );
        foreach ($adds as $column => $sql) {
            if (!communication_tracking_table_has_column($pdo, 'tc_orders', $column)) $pdo->exec($sql);
        }
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_tc_orders_comm_campaign ON tc_orders(communication_campaign_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_tc_orders_comm_run ON tc_orders(communication_run_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_tc_orders_comm_token ON tc_orders(communication_tracking_token)');
    }
}

if (!function_exists('communication_tracking_ensure_schema')) {
    function communication_tracking_ensure_schema($pdo)
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS communication_tracking_links (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            token TEXT NOT NULL,
            organization_id INTEGER NOT NULL DEFAULT 1,
            admin_id INTEGER NOT NULL DEFAULT 0,
            campaign_id INTEGER NOT NULL,
            run_id INTEGER NOT NULL,
            recipient_id INTEGER NOT NULL,
            recipient_fingerprint TEXT NOT NULL,
            kind TEXT NOT NULL,
            destination_url TEXT NOT NULL DEFAULT "",
            event_id INTEGER,
            event_count INTEGER NOT NULL DEFAULT 0,
            first_event_at TEXT,
            last_event_at TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(token)
        )');
        $pdo->exec('CREATE TABLE IF NOT EXISTS communication_tracking_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tracking_link_id INTEGER NOT NULL,
            event_type TEXT NOT NULL,
            event_key TEXT NOT NULL,
            ip_hash TEXT,
            user_agent TEXT,
            referer TEXT,
            occurred_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(event_key)
        )');
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_comm_tracking_link_identity ON communication_tracking_links(run_id, recipient_id, kind, destination_url)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_comm_tracking_link_run ON communication_tracking_links(run_id, kind, event_count)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_comm_tracking_link_campaign ON communication_tracking_links(campaign_id, kind, event_count)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_comm_tracking_event_link ON communication_tracking_events(tracking_link_id, event_type, occurred_at)');
        communication_tracking_ensure_order_columns($pdo);
    }
}

if (!function_exists('communication_tracking_make_token')) {
    function communication_tracking_make_token()
    {
        if (function_exists('random_bytes')) {
            try { return bin2hex(random_bytes(24)); } catch (Exception $e) {}
        }
        return sha1(uniqid(mt_rand(), true)) . substr(sha1(microtime(true) . mt_rand()), 0, 8);
    }
}

if (!function_exists('communication_tracking_base_url')) {
    function communication_tracking_base_url()
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

if (!function_exists('communication_tracking_is_checkout_url')) {
    function communication_tracking_is_checkout_url($url)
    {
        $decoded = html_entity_decode(trim((string)$url), ENT_QUOTES, 'UTF-8');
        if ($decoded === '') return false;
        $parts = @parse_url($decoded);
        if (!is_array($parts)) return false;
        if (!empty($parts['host'])) {
            $host = strtolower((string)$parts['host']);
            $currentHost = isset($_SERVER['HTTP_HOST']) ? strtolower(preg_replace('/:\d+$/', '', (string)$_SERVER['HTTP_HOST'])) : '';
            $isTickex = ($host === 'tickex.com.ar' || substr($host, -14) === '.tickex.com.ar');
            $isLocal = in_array($host, array('localhost', '127.0.0.1', '::1'), true);
            if (!$isTickex && !$isLocal && ($currentHost === '' || $host !== $currentHost)) return false;
        }
        $path = isset($parts['path']) ? strtolower((string)$parts['path']) : '';
        return basename($path) === 'checkout_totalcoin.php';
    }
}

if (!function_exists('communication_tracking_event_id_from_url')) {
    function communication_tracking_event_id_from_url($url)
    {
        $parts = @parse_url(html_entity_decode((string)$url, ENT_QUOTES, 'UTF-8'));
        if (!is_array($parts) || empty($parts['query'])) return 0;
        $query = array();
        parse_str((string)$parts['query'], $query);
        return isset($query['event']) ? max(0, (int)$query['event']) : 0;
    }
}

if (!function_exists('communication_tracking_link_token')) {
    function communication_tracking_link_token($pdo, $context, $kind, $destinationUrl)
    {
        communication_tracking_ensure_schema($pdo);
        $context = is_array($context) ? $context : array();
        $runId = isset($context['run_id']) ? (int)$context['run_id'] : 0;
        $recipientId = isset($context['recipient_id']) ? (int)$context['recipient_id'] : 0;
        $kind = (string)$kind;
        $destinationUrl = (string)$destinationUrl;
        if ($runId <= 0 || $recipientId <= 0 || !in_array($kind, array('open', 'click'), true)) return '';

        $st = $pdo->prepare('SELECT token FROM communication_tracking_links WHERE run_id=:run AND recipient_id=:recipient AND kind=:kind AND destination_url=:dest LIMIT 1');
        $params = array(':run' => $runId, ':recipient' => $recipientId, ':kind' => $kind, ':dest' => $destinationUrl);
        $st->execute($params);
        $existing = (string)$st->fetchColumn();
        if ($existing !== '') return $existing;

        $token = communication_tracking_make_token();
        $ins = $pdo->prepare('INSERT OR IGNORE INTO communication_tracking_links (token,organization_id,admin_id,campaign_id,run_id,recipient_id,recipient_fingerprint,kind,destination_url,event_id,created_at) VALUES (:token,:org,:admin,:campaign,:run,:recipient,:fingerprint,:kind,:dest,:event,CURRENT_TIMESTAMP)');
        $ins->execute(array(
            ':token' => $token,
            ':org' => isset($context['organization_id']) ? (int)$context['organization_id'] : 1,
            ':admin' => isset($context['admin_id']) ? (int)$context['admin_id'] : 0,
            ':campaign' => isset($context['campaign_id']) ? (int)$context['campaign_id'] : 0,
            ':run' => $runId,
            ':recipient' => $recipientId,
            ':fingerprint' => isset($context['recipient_fingerprint']) ? (string)$context['recipient_fingerprint'] : '',
            ':kind' => $kind,
            ':dest' => $destinationUrl,
            ':event' => ($kind === 'click') ? communication_tracking_event_id_from_url($destinationUrl) : 0,
        ));
        $st->execute($params);
        return (string)$st->fetchColumn();
    }
}

if (!function_exists('communication_tracking_click_url')) {
    function communication_tracking_click_url($token)
    {
        return communication_tracking_base_url() . '/communication_click.php?t=' . rawurlencode((string)$token);
    }
}

if (!function_exists('communication_tracking_open_url')) {
    function communication_tracking_open_url($token)
    {
        return communication_tracking_base_url() . '/communication_open.php?t=' . rawurlencode((string)$token) . '.gif';
    }
}

if (!function_exists('communication_tracking_instrument_message')) {
    function communication_tracking_instrument_message($pdo, $context, $bodyHtml, $bodyText)
    {
        $bodyHtml = (string)$bodyHtml;
        $bodyText = (string)$bodyText;
        $replacements = array();
        $bodyHtml = preg_replace_callback('/href\s*=\s*(["\'])(.*?)\1/i', function ($match) use ($pdo, $context, &$replacements) {
            $destination = html_entity_decode((string)$match[2], ENT_QUOTES, 'UTF-8');
            if (!communication_tracking_is_checkout_url($destination)) return $match[0];
            $token = communication_tracking_link_token($pdo, $context, 'click', $destination);
            if ($token === '') return $match[0];
            $tracked = communication_tracking_click_url($token);
            $replacements[$destination] = $tracked;
            return 'href=' . $match[1] . htmlspecialchars($tracked, ENT_QUOTES, 'UTF-8') . $match[1];
        }, $bodyHtml);

        foreach ($replacements as $destination => $tracked) {
            $bodyText = str_replace($destination, $tracked, $bodyText);
        }

        $openToken = communication_tracking_link_token($pdo, $context, 'open', '');
        if ($openToken !== '') {
            $pixel = '<img src="' . htmlspecialchars(communication_tracking_open_url($openToken), ENT_QUOTES, 'UTF-8') . '" width="1" height="1" alt="" style="display:block;width:1px;height:1px;border:0;overflow:hidden;" />';
            if (stripos($bodyHtml, '</body>') !== false) $bodyHtml = preg_replace('/<\/body>/i', $pixel . '</body>', $bodyHtml, 1);
            else $bodyHtml .= $pixel;
        }
        return array('body_html' => $bodyHtml, 'body_text' => $bodyText);
    }
}

if (!function_exists('communication_tracking_find_link')) {
    function communication_tracking_find_link($pdo, $token, $kind)
    {
        communication_tracking_ensure_schema($pdo);
        $token = preg_replace('/[^a-f0-9]/i', '', (string)$token);
        if (strlen($token) < 32 || strlen($token) > 96) return null;
        $st = $pdo->prepare('SELECT * FROM communication_tracking_links WHERE token=:token AND kind=:kind LIMIT 1');
        $st->execute(array(':token' => $token, ':kind' => (string)$kind));
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ? $row : null;
    }
}

if (!function_exists('communication_tracking_record')) {
    function communication_tracking_record($pdo, $link, $eventType, $server)
    {
        if (!$link || !is_array($link)) return false;
        $server = is_array($server) ? $server : array();
        $ip = isset($server['REMOTE_ADDR']) ? (string)$server['REMOTE_ADDR'] : '';
        $ua = isset($server['HTTP_USER_AGENT']) ? substr((string)$server['HTTP_USER_AGENT'], 0, 500) : '';
        $referer = isset($server['HTTP_REFERER']) ? substr((string)$server['HTTP_REFERER'], 0, 1000) : '';
        $ipHash = hash('sha256', 'tickex-engagement|' . (string)$link['token'] . '|' . $ip);
        $eventKey = sha1((int)$link['id'] . '|' . (string)$eventType . '|' . $ipHash . '|' . sha1($ua) . '|' . gmdate('YmdH'));
        $st = $pdo->prepare('INSERT OR IGNORE INTO communication_tracking_events (tracking_link_id,event_type,event_key,ip_hash,user_agent,referer,occurred_at) VALUES (:link,:type,:key,:ip,:ua,:ref,CURRENT_TIMESTAMP)');
        $st->execute(array(':link' => (int)$link['id'], ':type' => (string)$eventType, ':key' => $eventKey, ':ip' => $ipHash, ':ua' => $ua, ':ref' => $referer));
        if ($st->rowCount() > 0) {
            $up = $pdo->prepare('UPDATE communication_tracking_links SET event_count=event_count+1,first_event_at=COALESCE(first_event_at,CURRENT_TIMESTAMP),last_event_at=CURRENT_TIMESTAMP WHERE id=:id');
            $up->execute(array(':id' => (int)$link['id']));
            return true;
        }
        return false;
    }
}

if (!function_exists('communication_tracking_append_attribution')) {
    function communication_tracking_append_attribution($url, $token)
    {
        $url = (string)$url;
        $fragment = '';
        $hashPos = strpos($url, '#');
        if ($hashPos !== false) {
            $fragment = substr($url, $hashPos);
            $url = substr($url, 0, $hashPos);
        }
        $separator = (strpos($url, '?') === false) ? '?' : '&';
        return $url . $separator . 'ct=' . rawurlencode((string)$token) . $fragment;
    }
}

if (!function_exists('communication_tracking_attribution_for_event')) {
    function communication_tracking_attribution_for_event($pdo, $token, $eventId)
    {
        $link = communication_tracking_find_link($pdo, $token, 'click');
        if (!$link) return null;
        $linkedEventId = isset($link['event_id']) ? (int)$link['event_id'] : 0;
        if ($linkedEventId > 0 && (int)$eventId > 0 && $linkedEventId !== (int)$eventId) return null;
        return array(
            'campaign_id' => (int)$link['campaign_id'],
            'run_id' => (int)$link['run_id'],
            'recipient_fingerprint' => (string)$link['recipient_fingerprint'],
            'tracking_token' => (string)$link['token'],
        );
    }
}

if (!function_exists('communication_tracking_run_metrics')) {
    function communication_tracking_run_metrics($pdo, $runId)
    {
        communication_tracking_ensure_schema($pdo);
        $runId = (int)$runId;
        $metrics = array('unique_opens' => 0, 'total_opens' => 0, 'unique_clicks' => 0, 'total_clicks' => 0, 'confirmed_orders' => 0, 'revenue' => 0.0);
        $st = $pdo->prepare("SELECT kind,COUNT(DISTINCT CASE WHEN event_count>0 THEN recipient_id END) AS unique_count,COALESCE(SUM(event_count),0) AS total_count FROM communication_tracking_links WHERE run_id=:run GROUP BY kind");
        $st->execute(array(':run' => $runId));
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            if ($row['kind'] === 'open') {
                $metrics['unique_opens'] = (int)$row['unique_count'];
                $metrics['total_opens'] = (int)$row['total_count'];
            } elseif ($row['kind'] === 'click') {
                $metrics['unique_clicks'] = (int)$row['unique_count'];
                $metrics['total_clicks'] = (int)$row['total_count'];
            }
        }
        if (communication_tracking_table_exists($pdo, 'tc_orders') && communication_tracking_table_has_column($pdo, 'tc_orders', 'communication_run_id')) {
            $paymentCondition = communication_tracking_table_has_column($pdo, 'tc_orders', 'payment_status') ? "payment_status='confirmed'" : "state='success'";
            $orders = $pdo->prepare('SELECT COUNT(*) AS total,COALESCE(SUM(amount),0) AS revenue FROM tc_orders WHERE communication_run_id=:run AND ' . $paymentCondition);
            $orders->execute(array(':run' => $runId));
            $row = $orders->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $metrics['confirmed_orders'] = (int)$row['total'];
                $metrics['revenue'] = (float)$row['revenue'];
            }
        }
        return $metrics;
    }
}
