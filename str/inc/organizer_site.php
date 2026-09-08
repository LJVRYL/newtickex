<?php

if (!function_exists('tickex_organizer_site_ensure_schema')) {
    function tickex_organizer_site_ensure_schema($pdo)
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS clientes_sites (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            admin_id INTEGER NOT NULL,
            slug_publico TEXT NOT NULL,
            nombre_publico TEXT NOT NULL,
            texto_hero TEXT,
            texto_intro TEXT,
            whatsapp TEXT,
            instagram_url TEXT,
            tiktok_url TEXT,
            facebook_url TEXT,
            youtube_url TEXT,
            visible INTEGER NOT NULL DEFAULT 0,
            primary_color TEXT NOT NULL DEFAULT '#7c5cff',
            accent_color TEXT NOT NULL DEFAULT '#47d7ea',
            background_color TEXT NOT NULL DEFAULT '#070914',
            logo_url TEXT,
            custom_domain TEXT,
            custom_domain_status TEXT NOT NULL DEFAULT 'not_configured',
            white_label_enabled INTEGER NOT NULL DEFAULT 0,
            created_at TEXT,
            updated_at TEXT
        )");
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_clientes_sites_admin ON clientes_sites(admin_id)');
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_clientes_sites_slug ON clientes_sites(slug_publico)');

        $wanted = array(
            'whatsapp' => 'TEXT', 'instagram_url' => 'TEXT', 'tiktok_url' => 'TEXT',
            'facebook_url' => 'TEXT', 'youtube_url' => 'TEXT',
            'primary_color' => "TEXT NOT NULL DEFAULT '#7c5cff'",
            'accent_color' => "TEXT NOT NULL DEFAULT '#47d7ea'",
            'background_color' => "TEXT NOT NULL DEFAULT '#070914'",
            'logo_url' => 'TEXT', 'custom_domain' => 'TEXT',
            'custom_domain_status' => "TEXT NOT NULL DEFAULT 'not_configured'",
            'white_label_enabled' => 'INTEGER NOT NULL DEFAULT 0',
        );
        $columns = $pdo->query("PRAGMA table_info('clientes_sites')")->fetchAll(PDO::FETCH_ASSOC);
        $present = array();
        foreach ($columns as $column) $present[(string)$column['name']] = true;
        foreach ($wanted as $name => $definition) {
            if (!isset($present[$name])) $pdo->exec('ALTER TABLE clientes_sites ADD COLUMN ' . $name . ' ' . $definition);
        }
        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_clientes_sites_custom_domain ON clientes_sites(custom_domain) WHERE custom_domain IS NOT NULL AND custom_domain <> ''");

        $eventTable = (int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='eventos'")->fetchColumn() > 0;
        if ($eventTable) {
            $eventColumns = $pdo->query("PRAGMA table_info('eventos')")->fetchAll(PDO::FETCH_ASSOC);
            $hasPublished = false;
            foreach ($eventColumns as $column) if ($column['name'] === 'publicado_site') $hasPublished = true;
            if (!$hasPublished) $pdo->exec('ALTER TABLE eventos ADD COLUMN publicado_site INTEGER NOT NULL DEFAULT 0');
        }
    }
}

if (!function_exists('tickex_organizer_site_defaults')) {
    function tickex_organizer_site_defaults()
    {
        return array(
            'slug_publico'=>'', 'nombre_publico'=>'', 'texto_hero'=>'', 'texto_intro'=>'',
            'whatsapp'=>'', 'instagram_url'=>'', 'tiktok_url'=>'', 'facebook_url'=>'', 'youtube_url'=>'',
            'visible'=>0, 'primary_color'=>'#7c5cff', 'accent_color'=>'#47d7ea',
            'background_color'=>'#070914', 'logo_url'=>'', 'custom_domain'=>'',
            'custom_domain_status'=>'not_configured', 'white_label_enabled'=>0,
        );
    }
}

if (!function_exists('tickex_organizer_site_slug')) {
    function tickex_organizer_site_slug($value)
    {
        $value = strtolower(trim((string)$value));
        $value = preg_replace('/[^a-z0-9-]+/', '-', $value);
        return trim($value, '-');
    }
}

if (!function_exists('tickex_organizer_site_color')) {
    function tickex_organizer_site_color($value, $fallback)
    {
        $value = strtolower(trim((string)$value));
        return preg_match('/^#[0-9a-f]{6}$/', $value) ? $value : $fallback;
    }
}

if (!function_exists('tickex_organizer_site_asset_url')) {
    function tickex_organizer_site_asset_url($value)
    {
        $value = trim((string)$value);
        if ($value === '') return '';
        if (strpos($value, '/') === 0 && strpos($value, '//') !== 0) return $value;
        if (preg_match('~^https://[^\s]+$~i', $value)) return $value;
        throw new InvalidArgumentException('El logo debe usar HTTPS o una ruta local que empiece con /.');
    }
}

if (!function_exists('tickex_organizer_site_domain')) {
    function tickex_organizer_site_domain($value)
    {
        $value = strtolower(trim((string)$value));
        $value = preg_replace('~^https?://~', '', $value);
        $value = rtrim($value, '/');
        if (strpos($value, '/') !== false || strpos($value, ':') !== false) throw new InvalidArgumentException('Ingresá solamente el dominio, sin ruta ni puerto.');
        if ($value === '') return '';
        if (strlen($value) > 253 || !preg_match('/^(?=.{4,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $value)) {
            throw new InvalidArgumentException('El dominio ingresado no es válido.');
        }
        if ($value === 'tickex.com.ar' || substr($value, -14) === '.tickex.com.ar') {
            throw new InvalidArgumentException('Los subdominios Tickex se administran desde el identificador público.');
        }
        return $value;
    }
}

if (!function_exists('tickex_organizer_site_by_admin')) {
    function tickex_organizer_site_by_admin($pdo, $adminId)
    {
        tickex_organizer_site_ensure_schema($pdo);
        $st = $pdo->prepare('SELECT * FROM clientes_sites WHERE admin_id=:admin LIMIT 1');
        $st->execute(array(':admin'=>(int)$adminId));
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return array_merge(tickex_organizer_site_defaults(), $row ?: array());
    }
}

if (!function_exists('tickex_organizer_site_by_slug')) {
    function tickex_organizer_site_by_slug($pdo, $slug)
    {
        $st = $pdo->prepare('SELECT * FROM clientes_sites WHERE slug_publico=:slug LIMIT 1');
        $st->execute(array(':slug'=>tickex_organizer_site_slug($slug)));
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}

if (!function_exists('tickex_organizer_site_slug_from_host')) {
    function tickex_organizer_site_slug_from_host($pdo, $host)
    {
        $host = strtolower(trim((string)$host));
        $host = preg_replace('/:\d+$/', '', $host);
        if ($host === '' || $host === 'localhost' || $host === '127.0.0.1') return '';
        if (preg_match('/^([a-z0-9-]+)\.tickex\.com\.ar$/', $host, $match) && $match[1] !== 'www' && $match[1] !== 'str') {
            return tickex_organizer_site_slug($match[1]);
        }
        $st = $pdo->prepare("SELECT slug_publico FROM clientes_sites WHERE custom_domain=:host AND custom_domain_status='verified' LIMIT 1");
        $st->execute(array(':host'=>$host));
        return (string)($st->fetchColumn() ?: '');
    }
}

if (!function_exists('tickex_organizer_site_public_url')) {
    function tickex_organizer_site_public_url($site, $production)
    {
        $slug = tickex_organizer_site_slug(isset($site['slug_publico']) ? $site['slug_publico'] : '');
        if (!$production) return 'site.php?slug=' . rawurlencode($slug);
        if (!empty($site['custom_domain']) && isset($site['custom_domain_status']) && $site['custom_domain_status'] === 'verified') {
            return 'https://' . $site['custom_domain'] . '/';
        }
        if ($production && $slug !== '') return 'https://str.tickex.com.ar/site.php?slug=' . rawurlencode($slug);
        return '';
    }
}

if (!function_exists('tickex_organizer_site_internal_link')) {
    function tickex_organizer_site_internal_link($site, $eventSlug)
    {
        $url = tickex_organizer_site_public_url($site, false);
        if ($eventSlug === '') return $url;
        return $url . '&event=' . rawurlencode((string)$eventSlug);
    }
}
