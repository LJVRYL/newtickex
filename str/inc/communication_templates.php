<?php
require_once __DIR__ . '/communication_variables.php';

if (!function_exists('communication_templates_allowed_types')) {
    function communication_templates_allowed_types()
    {
        return array(
            'general' => 'General',
            'bienvenida' => 'Bienvenida',
            'recordatorio' => 'Recordatorio',
            'confirmacion' => 'Confirmacion',
            'promocion' => 'Promocion',
            'post_evento' => 'Post-evento',
        );
    }
}

if (!function_exists('communication_templates_allowed_status')) {
    function communication_templates_allowed_status()
    {
        return array('draft', 'active', 'archived');
    }
}

if (!function_exists('communication_templates_slugify')) {
    function communication_templates_slugify($text)
    {
        $text = strtolower(trim((string)$text));
        $text = preg_replace('/[^a-z0-9]+/', '-', $text);
        $text = trim((string)$text, '-');
        if ($text === '') $text = 'plantilla';
        return $text;
    }
}

if (!function_exists('communication_templates_ensure_schema')) {
    function communication_templates_ensure_schema($pdo)
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS communication_templates (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            organization_id INTEGER NOT NULL DEFAULT 1,
            created_by_admin_id INTEGER,
            source_type TEXT NOT NULL DEFAULT "custom",
            parent_template_id INTEGER,
            is_system_locked INTEGER NOT NULL DEFAULT 0,
            template_type TEXT NOT NULL DEFAULT "general",
            name TEXT NOT NULL,
            slug TEXT NOT NULL,
            description TEXT,
            subject_template TEXT NOT NULL,
            body_html_template TEXT,
            body_text_template TEXT,
            variables_schema_json TEXT,
            sample_data_json TEXT,
            status TEXT NOT NULL DEFAULT "active",
            last_used_at TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_comm_tpl_org_slug ON communication_templates(organization_id, slug)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_comm_tpl_org_status ON communication_templates(organization_id, status)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_comm_tpl_type ON communication_templates(template_type)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_comm_tpl_source ON communication_templates(source_type)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_comm_tpl_created_by ON communication_templates(created_by_admin_id)');

        // Plantillas base del sistema (globales: organization_id = 0)
        $st = $pdo->prepare('INSERT OR IGNORE INTO communication_templates
            (organization_id, created_by_admin_id, source_type, parent_template_id, is_system_locked, template_type, name, slug, description, subject_template, body_html_template, body_text_template, variables_schema_json, sample_data_json, status)
            VALUES
            (:org, :aid, :src, :parent, :locked, :tt, :n, :s, :d, :subj, :html, :text, :vars, :sample, :st)');

        $defaultSample = json_encode(communication_variables_default_sample(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $seedRows = array(
            array(
                'template_type' => 'confirmacion',
                'name' => 'Sistema - Confirmacion de tickex',
                'slug' => 'sistema-confirmacion-tickex',
                'description' => 'Plantilla base para confirmar asignacion de tickex.',
                'subject_template' => 'Tu tickex para {{evento}} ya esta listo',
                'body_html_template' => '<h2>Hola {{nombre}}</h2><p>Tu codigo es <strong>{{codigo}}</strong>.</p><p>Evento: {{evento}}</p><p>Fecha: {{fecha}}</p><p><a href="{{ticket_url}}">Ver ticket</a></p>',
                'body_text_template' => "Hola {{nombre}}\n\nTu codigo es {{codigo}}.\nEvento: {{evento}}\nFecha: {{fecha}}\nTicket: {{ticket_url}}",
            ),
            array(
                'template_type' => 'recordatorio',
                'name' => 'Sistema - Recordatorio de evento',
                'slug' => 'sistema-recordatorio-evento',
                'description' => 'Plantilla base para recordar asistencia al evento.',
                'subject_template' => 'Recordatorio: {{evento}} es el {{fecha}}',
                'body_html_template' => '<h2>Te esperamos, {{nombre}}</h2><p>Recordatorio para <strong>{{evento}}</strong>.</p><p>Fecha: {{fecha}}</p><p>Codigo: {{codigo}}</p><p><a href="{{checkin_url}}">Ir a check-in</a></p>',
                'body_text_template' => "Hola {{nombre}}\n\nRecordatorio de {{evento}}\nFecha: {{fecha}}\nCodigo: {{codigo}}\nCheck-in: {{checkin_url}}",
            ),
        );

        foreach ($seedRows as $r) {
            $vars = communication_variables_extract_from_template_parts($r['subject_template'], $r['body_html_template'], $r['body_text_template']);
            $varsJson = communication_variables_schema_json_from_keys($vars);

            $st->execute(array(
                ':org' => 0,
                ':aid' => null,
                ':src' => 'system',
                ':parent' => null,
                ':locked' => 1,
                ':tt' => $r['template_type'],
                ':n' => $r['name'],
                ':s' => $r['slug'],
                ':d' => $r['description'],
                ':subj' => $r['subject_template'],
                ':html' => $r['body_html_template'],
                ':text' => $r['body_text_template'],
                ':vars' => $varsJson,
                ':sample' => $defaultSample,
                ':st' => 'active',
            ));
        }
    }
}

if (!function_exists('communication_templates_unique_slug')) {
    function communication_templates_unique_slug($pdo, $organizationId, $slugBase, $excludeId)
    {
        $slug = communication_templates_slugify($slugBase);
        $base = $slug;
        $n = 2;

        while (true) {
            $sql = 'SELECT id FROM communication_templates WHERE organization_id = :org AND slug = :slug';
            if ((int)$excludeId > 0) {
                $sql .= ' AND id <> :id';
            }
            $sql .= ' LIMIT 1';
            $st = $pdo->prepare($sql);
            $params = array(':org' => (int)$organizationId, ':slug' => $slug);
            if ((int)$excludeId > 0) {
                $params[':id'] = (int)$excludeId;
            }
            $st->execute($params);
            if (!$st->fetch(PDO::FETCH_ASSOC)) {
                return $slug;
            }
            $slug = $base . '-' . $n;
            $n++;
        }
    }
}

if (!function_exists('communication_templates_scope_sql')) {
    function communication_templates_scope_sql($isSuper)
    {
        if ($isSuper) {
            return '(organization_id = :org OR organization_id = 0)';
        }
        return '((organization_id = 0 AND source_type = "system") OR (organization_id = :org AND created_by_admin_id = :aid))';
    }
}

if (!function_exists('communication_templates_scope_params')) {
    function communication_templates_scope_params($organizationId, $adminId, $isSuper)
    {
        $params = array(':org' => (int)$organizationId);
        if (!$isSuper) {
            $params[':aid'] = (int)$adminId;
        }
        return $params;
    }
}

if (!function_exists('communication_templates_normalize_type')) {
    function communication_templates_normalize_type($templateType)
    {
        $templateType = strtolower(trim((string)$templateType));
        $allowed = communication_templates_allowed_types();
        if (!isset($allowed[$templateType])) return 'general';
        return $templateType;
    }
}

if (!function_exists('communication_templates_normalize_status')) {
    function communication_templates_normalize_status($status)
    {
        $status = strtolower(trim((string)$status));
        $allowed = communication_templates_allowed_status();
        if (!in_array($status, $allowed, true)) return 'active';
        return $status;
    }
}

if (!function_exists('communication_templates_parse_sample_json')) {
    function communication_templates_parse_sample_json($raw)
    {
        $raw = trim((string)$raw);
        if ($raw === '') return null;

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) return false;

        $normalized = array();
        foreach ($decoded as $k => $v) {
            $kk = strtolower(trim((string)$k));
            if ($kk === '') continue;
            if (is_array($v) || is_object($v)) continue;
            $normalized[$kk] = (string)$v;
        }

        if (empty($normalized)) return null;
        return json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
