<?php
require_once __DIR__ . '/../inc/mobile_communication_catalog.php';
require_once __DIR__ . '/../inc/mobile_communication_templates.php';
function template_assert($ok, $message) { if (!$ok) { echo 'FAIL ' . $message . PHP_EOL; exit(1); } }
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE communication_templates (id INTEGER PRIMARY KEY AUTOINCREMENT, organization_id INTEGER, created_by_admin_id INTEGER, source_type TEXT, parent_template_id INTEGER, is_system_locked INTEGER, template_type TEXT, name TEXT, slug TEXT, description TEXT, subject_template TEXT, body_html_template TEXT, body_text_template TEXT, variables_schema_json TEXT, sample_data_json TEXT, status TEXT, created_at TEXT, updated_at TEXT)');
$owner = array('id' => 2, 'tipo_global' => 'admin_evento');
$other = array('id' => 3, 'tipo_global' => 'admin_evento');
$body = array('name' => 'Invitación', 'subjectTemplate' => 'Hola {{nombre}}', 'bodyTextTemplate' => 'Te esperamos', 'templateType' => 'general', 'status' => 'draft');
$created = tickex_mobile_communication_template_save($pdo, $owner, $body, 0);
template_assert(isset($created['id']) && $created['status'] === 'draft', 'owner creates draft');
$id = (int)$created['id'];
template_assert(tickex_mobile_communication_template_save($pdo, $other, $body, $id)['error'] === 'not_found', 'other producer cannot edit');
$invalid = $body; $invalid['subjectTemplate'] = 'Hola {{variable_desconocida}}';
template_assert(tickex_mobile_communication_template_save($pdo, $owner, $invalid, 0)['error'] === 'unknown_variables', 'unknown variable rejected');
$body['status'] = 'active';
template_assert(tickex_mobile_communication_template_save($pdo, $owner, $body, $id)['status'] === 'active', 'owned template activates');
$pdo->exec("INSERT INTO communication_templates (organization_id,created_by_admin_id,source_type,is_system_locked,template_type,name,slug,subject_template,status) VALUES (0,NULL,'system',1,'general','Base','base','Hola','active')");
$systemId = (int)$pdo->lastInsertId();
template_assert(tickex_mobile_communication_template_save($pdo, array('id' => 1, 'tipo_global' => 'super_admin'), $body, $systemId)['error'] === 'system_locked', 'system template protected');
template_assert((int)$pdo->query('SELECT COUNT(*) FROM communication_templates')->fetchColumn() === 2, 'invalid edits create no template');
echo 'mobile communication templates PASS' . PHP_EOL;
