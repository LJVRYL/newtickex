<?php
require_once __DIR__ . '/communication_templates.php';

if (!function_exists('communication_campaigns_allowed_statuses')) {
    function communication_campaigns_allowed_statuses()
    {
        return array('draft', 'scheduled', 'sending', 'sent', 'cancelled', 'failed', 'archived');
    }
}

if (!function_exists('communication_campaigns_editable_statuses')) {
    function communication_campaigns_editable_statuses()
    {
        return array('draft', 'archived');
    }
}

if (!function_exists('communication_campaigns_normalize_status')) {
    function communication_campaigns_normalize_status($status)
    {
        $status = strtolower(trim((string)$status));
        $allowed = communication_campaigns_allowed_statuses();
        if (!in_array($status, $allowed, true)) {
            return 'draft';
        }
        return $status;
    }
}

if (!function_exists('communication_campaigns_slugify')) {
    function communication_campaigns_slugify($text)
    {
        $text = strtolower(trim((string)$text));
        $text = preg_replace('/[^a-z0-9]+/', '-', $text);
        $text = trim((string)$text, '-');
        if ($text === '') $text = 'campana';
        return $text;
    }
}

if (!function_exists('communication_campaigns_unique_slug')) {
    function communication_campaigns_unique_slug($pdo, $organizationId, $slugBase, $excludeId)
    {
        $slug = communication_campaigns_slugify($slugBase);
        $base = $slug;
        $n = 2;

        while (true) {
            $sql = 'SELECT id FROM communication_campaigns WHERE organization_id = :org AND slug = :slug';
            if ((int)$excludeId > 0) {
                $sql .= ' AND id <> :id';
            }
            $sql .= ' LIMIT 1';
            $st = $pdo->prepare($sql);
            $params = array(':org' => (int)$organizationId, ':slug' => $slug);
            if ((int)$excludeId > 0) $params[':id'] = (int)$excludeId;
            $st->execute($params);
            if (!$st->fetch(PDO::FETCH_ASSOC)) {
                return $slug;
            }
            $slug = $base . '-' . $n;
            $n++;
        }
    }
}

if (!function_exists('communication_campaigns_scope_sql')) {
    function communication_campaigns_scope_sql($isSuper)
    {
        $sql = 'organization_id = :org';
        if (!$isSuper) {
            $sql .= ' AND created_by_admin_id = :aid';
        }
        return $sql;
    }
}

if (!function_exists('communication_campaigns_scope_params')) {
    function communication_campaigns_scope_params($organizationId, $adminId, $isSuper)
    {
        $params = array(':org' => (int)$organizationId);
        if (!$isSuper) {
            $params[':aid'] = (int)$adminId;
        }
        return $params;
    }
}

if (!function_exists('communication_campaigns_audience_scope_sql')) {
    function communication_campaigns_audience_scope_sql($isSuper)
    {
        $sql = 'organization_id = :org';
        if (!$isSuper) {
            $sql .= ' AND created_by_admin_id = :aid';
        }
        return $sql;
    }
}

if (!function_exists('communication_campaigns_ensure_schema')) {
    function communication_campaigns_ensure_schema($pdo)
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS communication_campaigns (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            organization_id INTEGER NOT NULL DEFAULT 1,
            created_by_admin_id INTEGER,
            name TEXT NOT NULL,
            slug TEXT NOT NULL,
            description TEXT,
            status TEXT NOT NULL DEFAULT "draft",
            audience_id INTEGER,
            template_id INTEGER,
            subject_override TEXT,
            notes_internal TEXT,
            scheduled_for TEXT,
            scheduled_timezone TEXT,
            sending_started_at TEXT,
            sent_at TEXT,
            cancelled_at TEXT,
            failed_at TEXT,
            snapshot_subject TEXT,
            snapshot_body_html TEXT,
            snapshot_body_text TEXT,
            snapshot_taken_at TEXT,
            is_favorite INTEGER NOT NULL DEFAULT 0,
            removed_at TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');

        $campaignColumns = $pdo->query('PRAGMA table_info(communication_campaigns)')->fetchAll(PDO::FETCH_ASSOC);
        $existingCampaignColumns = array();
        foreach ($campaignColumns as $campaignColumn) {
            if (isset($campaignColumn['name'])) $existingCampaignColumns[(string)$campaignColumn['name']] = true;
        }
        if (!isset($existingCampaignColumns['is_favorite'])) {
            $pdo->exec('ALTER TABLE communication_campaigns ADD COLUMN is_favorite INTEGER NOT NULL DEFAULT 0');
        }
        if (!isset($existingCampaignColumns['removed_at'])) {
            $pdo->exec('ALTER TABLE communication_campaigns ADD COLUMN removed_at TEXT');
        }

        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_comm_campaign_org_slug ON communication_campaigns(organization_id, slug)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_comm_campaign_org_status ON communication_campaigns(organization_id, status)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_comm_campaign_audience ON communication_campaigns(audience_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_comm_campaign_template ON communication_campaigns(template_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_comm_campaign_created_by ON communication_campaigns(created_by_admin_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_comm_campaign_scheduled_for ON communication_campaigns(scheduled_for)');
    }
}

if (!function_exists('communication_campaigns_toggle_favorite')) {
    function communication_campaigns_toggle_favorite($pdo, $organizationId, $adminId, $isSuper, $campaignId)
    {
        communication_campaigns_ensure_schema($pdo);
        $scopeSql = communication_campaigns_scope_sql($isSuper);
        $params = communication_campaigns_scope_params($organizationId, $adminId, $isSuper);
        $st = $pdo->prepare('UPDATE communication_campaigns SET is_favorite = CASE WHEN COALESCE(is_favorite,0) = 1 THEN 0 ELSE 1 END, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND removed_at IS NULL AND ' . $scopeSql);
        $st->execute(array(':id' => (int)$campaignId) + $params);
        if ($st->rowCount() < 1) return array('ok' => false, 'error' => 'No se encontro la campana.');
        $stState = $pdo->prepare('SELECT is_favorite FROM communication_campaigns WHERE id = :id LIMIT 1');
        $stState->execute(array(':id' => (int)$campaignId));
        return array('ok' => true, 'is_favorite' => ((int)$stState->fetchColumn() === 1));
    }
}

if (!function_exists('communication_campaigns_remove')) {
    function communication_campaigns_remove($pdo, $organizationId, $adminId, $isSuper, $campaignId)
    {
        communication_campaigns_ensure_schema($pdo);
        $scopeSql = communication_campaigns_scope_sql($isSuper);
        $params = communication_campaigns_scope_params($organizationId, $adminId, $isSuper);
        $stCampaign = $pdo->prepare('SELECT id, status FROM communication_campaigns WHERE id = :id AND removed_at IS NULL AND ' . $scopeSql . ' LIMIT 1');
        $stCampaign->execute(array(':id' => (int)$campaignId) + $params);
        $campaign = $stCampaign->fetch(PDO::FETCH_ASSOC);
        if (!$campaign) return array('ok' => false, 'error' => 'No se encontro la campana.');
        if ((string)$campaign['status'] === 'sending') return array('ok' => false, 'error' => 'No se puede eliminar una campana que se esta enviando.');

        $stActiveCommands = $pdo->prepare("SELECT COUNT(*) FROM communication_execution_commands WHERE campaign_id = :id AND status IN ('queued','processing')");
        $stActiveCommands->execute(array(':id' => (int)$campaignId));
        $stActiveRuns = $pdo->prepare("SELECT COUNT(*) FROM communication_campaign_runs WHERE campaign_id = :id AND status IN ('requested','preparing','running','finalizing')");
        $stActiveRuns->execute(array(':id' => (int)$campaignId));
        if ((int)$stActiveCommands->fetchColumn() > 0 || (int)$stActiveRuns->fetchColumn() > 0) {
            return array('ok' => false, 'error' => 'La campana tiene una ejecucion activa y no puede eliminarse.');
        }

        $stRemove = $pdo->prepare('UPDATE communication_campaigns SET removed_at = CURRENT_TIMESTAMP, is_favorite = 0, status = :status, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND removed_at IS NULL AND ' . $scopeSql);
        $stRemove->execute(array(':status' => 'archived', ':id' => (int)$campaignId) + $params);
        return $stRemove->rowCount() > 0 ? array('ok' => true) : array('ok' => false, 'error' => 'No se pudo eliminar la campana.');
    }
}

if (!function_exists('communication_campaigns_fetch_audiences')) {
    function communication_campaigns_fetch_audiences($pdo, $organizationId, $adminId, $isSuper)
    {
        $scopeSql = communication_campaigns_audience_scope_sql($isSuper);
        $params = communication_campaigns_scope_params($organizationId, $adminId, $isSuper);
        $sql = 'SELECT id, name, slug, status, filters_json FROM communication_audiences WHERE ' . $scopeSql . ' AND status <> "deleted" ORDER BY updated_at DESC, id DESC';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('communication_campaigns_find_audience')) {
    function communication_campaigns_find_audience($pdo, $organizationId, $adminId, $isSuper, $audienceId)
    {
        $audienceId = (int)$audienceId;
        if ($audienceId <= 0) return null;

        $scopeSql = communication_campaigns_audience_scope_sql($isSuper);
        $params = communication_campaigns_scope_params($organizationId, $adminId, $isSuper);
        $sql = 'SELECT * FROM communication_audiences WHERE id = :id AND ' . $scopeSql . ' AND status <> "deleted" LIMIT 1';
        $st = $pdo->prepare($sql);
        $st->execute(array(':id' => $audienceId) + $params);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ? $row : null;
    }
}

if (!function_exists('communication_campaigns_fetch_templates')) {
    function communication_campaigns_fetch_templates($pdo, $organizationId, $adminId, $isSuper)
    {
        $scopeSql = communication_templates_scope_sql($isSuper);
        $scopeParams = communication_templates_scope_params($organizationId, $adminId, $isSuper);
        $sql = 'SELECT id, name, slug, template_type, status, source_type, is_system_locked, subject_template, body_html_template, body_text_template, sample_data_json FROM communication_templates WHERE ' . $scopeSql . ' AND status <> "deleted" ORDER BY is_system_locked DESC, updated_at DESC, id DESC';
        $st = $pdo->prepare($sql);
        $st->execute($scopeParams);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('communication_campaigns_find_template')) {
    function communication_campaigns_find_template($pdo, $organizationId, $adminId, $isSuper, $templateId)
    {
        $templateId = (int)$templateId;
        if ($templateId <= 0) return null;

        $scopeSql = communication_templates_scope_sql($isSuper);
        $scopeParams = communication_templates_scope_params($organizationId, $adminId, $isSuper);
        $sql = 'SELECT * FROM communication_templates WHERE id = :id AND ' . $scopeSql . ' AND status <> "deleted" LIMIT 1';
        $st = $pdo->prepare($sql);
        $st->execute(array(':id' => $templateId) + $scopeParams);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ? $row : null;
    }
}
