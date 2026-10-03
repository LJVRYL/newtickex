<?php
require_once __DIR__ . '/communication_templates.php';

function tickex_mobile_communication_template_save($pdo, $admin, $body, $templateId)
{
    if (!tickex_mobile_communication_allowed($admin)) return array('error' => 'forbidden');
    if (!is_array($body)) return array('error' => 'invalid_template');
    $fields = array('name', 'description', 'slug', 'templateType', 'status', 'subjectTemplate', 'bodyHtmlTemplate', 'bodyTextTemplate', 'sampleDataJson');
    foreach ($fields as $field) if (isset($body[$field]) && !is_string($body[$field])) return array('error' => 'invalid_template');
    $name = isset($body['name']) ? trim($body['name']) : '';
    $description = isset($body['description']) ? trim($body['description']) : '';
    $slugInput = isset($body['slug']) ? trim($body['slug']) : '';
    $type = isset($body['templateType']) ? strtolower(trim($body['templateType'])) : 'general';
    $status = isset($body['status']) ? strtolower(trim($body['status'])) : 'draft';
    $subject = isset($body['subjectTemplate']) ? trim($body['subjectTemplate']) : '';
    $html = isset($body['bodyHtmlTemplate']) ? $body['bodyHtmlTemplate'] : '';
    $plain = isset($body['bodyTextTemplate']) ? $body['bodyTextTemplate'] : '';
    $sample = isset($body['sampleDataJson']) ? $body['sampleDataJson'] : '';
    if ($name === '' || strlen($name) > 120 || strlen($description) > 1000 || strlen($slugInput) > 120 || $subject === '' || strlen($subject) > 250 || strlen($html) > 6000 || strlen($plain) > 6000 || strlen($sample) > 2000 || !isset(communication_templates_allowed_types()[$type]) || !in_array($status, communication_templates_allowed_status(), true)) return array('error' => 'invalid_template');
    $sampleJson = communication_templates_parse_sample_json($sample);
    if ($sampleJson === false) return array('error' => 'invalid_sample');
    $variables = communication_variables_extract_from_template_parts($subject, $html, $plain);
    if (communication_variables_unknown_keys($variables)) return array('error' => 'unknown_variables');
    $schema = communication_variables_schema_json_from_keys($variables);
    $adminId = (int)$admin['id'];
    $isSuper = tickex_mobile_communication_super($admin);
    $templateId = (int)$templateId;
    if ($templateId > 0) {
        $scope = communication_templates_scope_sql($isSuper);
        $params = communication_templates_scope_params(1, $adminId, $isSuper);
        $read = $pdo->prepare('SELECT id, organization_id, is_system_locked FROM communication_templates WHERE id=:id AND status<>"deleted" AND ' . $scope . ' LIMIT 1');
        $read->execute(array(':id' => $templateId) + $params);
        $existing = $read->fetch(PDO::FETCH_ASSOC);
        if (!$existing) return array('error' => 'not_found');
        if ((int)$existing['is_system_locked'] === 1 || (int)$existing['organization_id'] !== 1) return array('error' => 'system_locked');
    }
    $slug = communication_templates_unique_slug($pdo, 1, $slugInput !== '' ? $slugInput : $name, $templateId);
    if ($templateId > 0) {
        $update = $pdo->prepare('UPDATE communication_templates SET name=:name, slug=:slug, template_type=:type, status=:status, description=:description, subject_template=:subject, body_html_template=:html, body_text_template=:plain, variables_schema_json=:schema, sample_data_json=:sample, updated_at=CURRENT_TIMESTAMP WHERE id=:id');
        $update->execute(array(':name' => $name, ':slug' => $slug, ':type' => $type, ':status' => $status, ':description' => $description, ':subject' => $subject, ':html' => $html, ':plain' => $plain, ':schema' => $schema, ':sample' => $sampleJson, ':id' => $templateId));
    } else {
        $insert = $pdo->prepare('INSERT INTO communication_templates (organization_id, created_by_admin_id, source_type, parent_template_id, is_system_locked, template_type, name, slug, description, subject_template, body_html_template, body_text_template, variables_schema_json, sample_data_json, status, created_at, updated_at) VALUES (1,:admin,"custom",NULL,0,:type,:name,:slug,:description,:subject,:html,:plain,:schema,:sample,:status,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)');
        $insert->execute(array(':admin' => $adminId, ':type' => $type, ':name' => $name, ':slug' => $slug, ':description' => $description, ':subject' => $subject, ':html' => $html, ':plain' => $plain, ':schema' => $schema, ':sample' => $sampleJson, ':status' => $status));
        $templateId = (int)$pdo->lastInsertId();
    }
    return array('id' => (string)$templateId, 'name' => $name, 'slug' => $slug, 'status' => $status, 'templateType' => $type);
}
