<?php
require_once __DIR__ . '/communication_campaigns.php';
require_once __DIR__ . '/communication_template_renderer.php';

function tickex_mobile_communication_draft($pdo, $admin, $body, $campaignId)
{
    if (!tickex_mobile_communication_allowed($admin)) return array('error' => 'forbidden');
    foreach (array('name', 'description', 'slug', 'subjectOverride', 'notesInternal') as $key) if (isset($body[$key]) && !is_string($body[$key])) return array('error' => 'invalid_draft');
    $name = isset($body['name']) ? trim($body['name']) : '';
    $description = isset($body['description']) ? trim($body['description']) : '';
    $slugInput = isset($body['slug']) ? trim($body['slug']) : '';
    $subject = isset($body['subjectOverride']) ? trim($body['subjectOverride']) : '';
    $notes = isset($body['notesInternal']) ? trim($body['notesInternal']) : '';
    $audienceId = isset($body['audienceId']) ? filter_var($body['audienceId'], FILTER_VALIDATE_INT) : false;
    $templateId = isset($body['templateId']) ? filter_var($body['templateId'], FILTER_VALIDATE_INT) : false;
    if ($name === '' || strlen($name) > 120 || strlen($description) > 1000 || strlen($slugInput) > 120 || strlen($subject) > 250 || strlen($notes) > 1000 || !$audienceId || !$templateId) return array('error' => 'invalid_draft');
    $adminId = (int)$admin['id'];
    $isSuper = tickex_mobile_communication_super($admin);
    $audience = communication_campaigns_find_audience($pdo, 1, $adminId, $isSuper, $audienceId);
    $template = communication_campaigns_find_template($pdo, 1, $adminId, $isSuper, $templateId);
    if (!$audience || !$template) return array('error' => 'invalid_selection');
    communication_campaigns_ensure_schema($pdo);
    $campaignId = (int)$campaignId;
    if ($campaignId > 0) {
        $scope = communication_campaigns_scope_sql($isSuper);
        $params = communication_campaigns_scope_params(1, $adminId, $isSuper);
        $read = $pdo->prepare('SELECT id, status FROM communication_campaigns WHERE id=:id AND removed_at IS NULL AND ' . $scope . ' LIMIT 1');
        $read->execute(array(':id' => $campaignId) + $params);
        $existing = $read->fetch(PDO::FETCH_ASSOC);
        if (!$existing) return array('error' => 'not_found');
        if (!in_array((string)$existing['status'], array('draft', 'archived'), true)) return array('error' => 'campaign_locked');
    }
    $slug = communication_campaigns_unique_slug($pdo, 1, $slugInput !== '' ? $slugInput : $name, $campaignId);
    if ($campaignId > 0) {
        $update = $pdo->prepare("UPDATE communication_campaigns SET name=:name, slug=:slug, description=:description, status='draft', audience_id=:audience, template_id=:template, subject_override=:subject, notes_internal=:notes, updated_at=CURRENT_TIMESTAMP WHERE id=:id");
        $update->execute(array(':name' => $name, ':slug' => $slug, ':description' => $description, ':audience' => $audienceId, ':template' => $templateId, ':subject' => $subject !== '' ? $subject : null, ':notes' => $notes !== '' ? $notes : null, ':id' => $campaignId));
    } else {
        $insert = $pdo->prepare("INSERT INTO communication_campaigns (organization_id, created_by_admin_id, name, slug, description, status, audience_id, template_id, subject_override, notes_internal, created_at, updated_at) VALUES (1,:admin,:name,:slug,:description,'draft',:audience,:template,:subject,:notes,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)");
        $insert->execute(array(':admin' => $adminId, ':name' => $name, ':slug' => $slug, ':description' => $description, ':audience' => $audienceId, ':template' => $templateId, ':subject' => $subject !== '' ? $subject : null, ':notes' => $notes !== '' ? $notes : null));
        $campaignId = (int)$pdo->lastInsertId();
    }
    return array('id' => (string)$campaignId, 'name' => $name, 'slug' => $slug, 'status' => 'draft', 'audienceId' => (string)$audienceId, 'templateId' => (string)$templateId);
}

function tickex_mobile_communication_preview($pdo, $admin, $campaignId)
{
    if (!tickex_mobile_communication_allowed($admin)) return array('error' => 'forbidden');
    $adminId = (int)$admin['id'];
    $isSuper = tickex_mobile_communication_super($admin);
    $scope = communication_campaigns_scope_sql($isSuper);
    $params = communication_campaigns_scope_params(1, $adminId, $isSuper);
    $read = $pdo->prepare('SELECT id, name, audience_id, template_id, subject_override FROM communication_campaigns WHERE id=:id AND removed_at IS NULL AND ' . $scope . ' LIMIT 1');
    $read->execute(array(':id' => (int)$campaignId) + $params);
    $campaign = $read->fetch(PDO::FETCH_ASSOC);
    if (!$campaign) return array('error' => 'not_found');
    $template = communication_campaigns_find_template($pdo, 1, $adminId, $isSuper, (int)$campaign['template_id']);
    if (!$template) return array('error' => 'template_not_found');
    $subject = trim((string)$campaign['subject_override']);
    if ($subject === '') $subject = (string)$template['subject_template'];
    $preview = communication_template_renderer_preview($subject, isset($template['body_html_template']) ? $template['body_html_template'] : '', isset($template['body_text_template']) ? $template['body_text_template'] : '', isset($template['sample_data_json']) ? $template['sample_data_json'] : '');
    return array('campaignId' => (string)$campaignId, 'campaignName' => (string)$campaign['name'], 'templateName' => (string)$template['name'], 'preview' => $preview);
}
