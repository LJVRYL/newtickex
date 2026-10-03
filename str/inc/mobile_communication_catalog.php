<?php

function tickex_mobile_communication_allowed($admin)
{
    return isset($admin['tipo_global']) && in_array($admin['tipo_global'], array('admin_evento', 'super_admin', 'superadmin'), true);
}

function tickex_mobile_communication_super($admin)
{
    return isset($admin['tipo_global']) && in_array($admin['tipo_global'], array('super_admin', 'superadmin'), true);
}

function tickex_mobile_communication_table_exists($pdo, $table)
{
    $stmt = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=:table LIMIT 1");
    $stmt->execute(array(':table' => $table));
    return (bool)$stmt->fetchColumn();
}

function tickex_mobile_communication_catalog($pdo, $admin, $kind)
{
    if (!tickex_mobile_communication_allowed($admin)) return array('error' => 'forbidden');
    $tables = array('audiences' => 'communication_audiences', 'templates' => 'communication_templates', 'campaigns' => 'communication_campaigns');
    if (!isset($tables[$kind])) return array('error' => 'invalid_catalog');
    if (!tickex_mobile_communication_table_exists($pdo, $tables[$kind])) return array('items' => array());
    $isSuper = tickex_mobile_communication_super($admin);
    $params = array(':org' => 1);
    if (!$isSuper) $params[':admin'] = (int)$admin['id'];
    if ($kind === 'audiences') {
        $scope = $isSuper ? 'organization_id = :org' : 'organization_id = :org AND created_by_admin_id = :admin';
        $sql = 'SELECT id, name, slug, status, filters_json, created_at, updated_at FROM communication_audiences WHERE ' . $scope . ' AND status <> "deleted" ORDER BY updated_at DESC, id DESC LIMIT 100';
    } elseif ($kind === 'templates') {
        $scope = $isSuper ? '(organization_id = :org OR organization_id = 0)' : '((organization_id = 0 AND source_type = "system") OR (organization_id = :org AND created_by_admin_id = :admin))';
        $sql = 'SELECT id, name, slug, template_type, status, source_type, is_system_locked, subject_template, created_at, updated_at FROM communication_templates WHERE ' . $scope . ' AND status <> "deleted" ORDER BY is_system_locked DESC, updated_at DESC, id DESC LIMIT 100';
    } else {
        $scope = $isSuper ? 'organization_id = :org' : 'organization_id = :org AND created_by_admin_id = :admin';
        $sql = 'SELECT id, name, slug, description, status, audience_id, template_id, subject_override, scheduled_for, sent_at, is_favorite, created_at, updated_at FROM communication_campaigns WHERE ' . $scope . ' AND removed_at IS NULL ORDER BY updated_at DESC, id DESC LIMIT 100';
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return array('items' => $stmt->fetchAll(PDO::FETCH_ASSOC));
}
