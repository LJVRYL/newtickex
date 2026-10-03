<?php
require_once __DIR__ . '/communication_contacts.php';
require_once __DIR__ . '/mobile_admin.php';

function tickex_mobile_communication_audience_slug($pdo, $name, $excludeId)
{
    $base = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', (string)$name), '-'));
    if ($base === '') $base = 'audiencia';
    $base = substr($base, 0, 100);
    for ($n = 1; $n < 1000; $n++) {
        $slug = $n === 1 ? $base : $base . '-' . $n;
        $query = $pdo->prepare('SELECT 1 FROM communication_audiences WHERE organization_id=1 AND slug=:slug AND id<>:id LIMIT 1');
        $query->execute(array(':slug' => $slug, ':id' => (int)$excludeId));
        if (!$query->fetchColumn()) return $slug;
    }
    return null;
}

function tickex_mobile_communication_audience_save($pdo, $admin, $body, $audienceId)
{
    if (!tickex_mobile_communication_allowed($admin)) return array('error' => 'forbidden');
    if (!is_array($body) || !isset($body['name']) || !is_string($body['name'])) return array('error' => 'invalid_audience');
    $name = trim($body['name']);
    $description = isset($body['description']) ? $body['description'] : '';
    $status = isset($body['status']) ? $body['status'] : 'active';
    $filters = isset($body['filters']) ? $body['filters'] : array();
    if ($name === '' || strlen($name) > 120 || !is_string($description) || strlen($description) > 1000 || !in_array($status, array('active', 'archived'), true) || !is_array($filters)) return array('error' => 'invalid_audience');
    $allowed = array('q', 'registered', 'blocked', 'source', 'role', 'buyer', 'contact_type', 'event_id');
    foreach ($filters as $key => $value) {
        if (!in_array($key, $allowed, true) || !(is_string($value) || ($key === 'event_id' && is_int($value))) || strlen((string)$value) > 120) return array('error' => 'invalid_filters');
    }
    $normalized = communication_contacts_normalize_filters($filters);
    foreach ($filters as $key => $value) if ($value !== '' && $value !== 0 && !isset($normalized[$key])) return array('error' => 'invalid_filters');
    $adminId = (int)$admin['id'];
    $isSuper = tickex_mobile_communication_super($admin);
    $audienceId = (int)$audienceId;
    if ($audienceId > 0) {
        $read = $pdo->prepare('SELECT id FROM communication_audiences WHERE id=:id AND organization_id=1 AND status<>"deleted" AND (CAST(:super AS INTEGER)=1 OR created_by_admin_id=:admin) LIMIT 1');
        $read->execute(array(':id' => $audienceId, ':super' => $isSuper ? 1 : 0, ':admin' => $adminId));
        if (!$read->fetchColumn()) return array('error' => 'not_found');
    }
    if (isset($normalized['event_id']) && !tickex_mobile_admin_can_event($pdo, $admin, (int)$normalized['event_id'])) return array('error' => 'event_forbidden');
    $slug = tickex_mobile_communication_audience_slug($pdo, $name, $audienceId);
    if ($slug === null) return array('error' => 'slug_unavailable');
    $json = communication_contacts_filters_to_json($normalized);
    if ($audienceId > 0) {
        $update = $pdo->prepare('UPDATE communication_audiences SET name=:name, slug=:slug, description=:description, filters_json=:filters, status=:status, updated_at=CURRENT_TIMESTAMP WHERE id=:id');
        $update->execute(array(':name' => $name, ':slug' => $slug, ':description' => $description, ':filters' => $json, ':status' => $status, ':id' => $audienceId));
    } else {
        $insert = $pdo->prepare('INSERT INTO communication_audiences (organization_id, created_by_admin_id, name, slug, description, filters_json, status, created_at, updated_at) VALUES (1,:admin,:name,:slug,:description,:filters,:status,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)');
        $insert->execute(array(':admin' => $adminId, ':name' => $name, ':slug' => $slug, ':description' => $description, ':filters' => $json, ':status' => $status));
        $audienceId = (int)$pdo->lastInsertId();
    }
    return array('id' => (string)$audienceId, 'name' => $name, 'slug' => $slug, 'status' => $status, 'filters' => $normalized);
}

function tickex_mobile_communication_audience_estimate($pdo, $admin, $audienceId, $counter = null)
{
    if (!tickex_mobile_communication_allowed($admin)) return array('error' => 'forbidden');
    $isSuper = tickex_mobile_communication_super($admin);
    $adminId = (int)$admin['id'];
    $read = $pdo->prepare('SELECT id, filters_json FROM communication_audiences WHERE id=:id AND organization_id=1 AND status<>"deleted" AND (CAST(:super AS INTEGER)=1 OR created_by_admin_id=:admin) LIMIT 1');
    $read->execute(array(':id' => (int)$audienceId, ':super' => $isSuper ? 1 : 0, ':admin' => $adminId));
    $audience = $read->fetch(PDO::FETCH_ASSOC);
    if (!$audience) return array('error' => 'not_found');
    $filters = communication_contacts_filters_from_json($audience['filters_json']);
    if (isset($filters['event_id']) && !tickex_mobile_admin_can_event($pdo, $admin, (int)$filters['event_id'])) return array('error' => 'event_forbidden');
    $scope = array('is_super' => $isSuper, 'admin_id' => $adminId);
    $count = $counter ? call_user_func($counter, $pdo, $filters, $scope) : communication_contacts_count($pdo, $filters, $scope);
    return array('audienceId' => (string)$audienceId, 'estimatedRecipients' => (int)$count);
}
