<?php
require_once __DIR__ . '/../inc/mobile_communication_catalog.php';
require_once __DIR__ . '/../inc/mobile_communication_drafts.php';
function draft_assert($value, $message) { if (!$value) { fwrite(STDERR, $message . "\n"); exit(1); } }
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE communication_audiences (id INTEGER PRIMARY KEY, organization_id INTEGER, created_by_admin_id INTEGER, name TEXT, slug TEXT, status TEXT, filters_json TEXT)');
communication_templates_ensure_schema($pdo);
communication_campaigns_ensure_schema($pdo);
$pdo->exec("INSERT INTO communication_audiences VALUES (1,1,7,'Compradores','buyers','active','{}'), (2,1,8,'Ajenos','others','active','{}')");
$pdo->exec("INSERT INTO communication_templates (id,organization_id,created_by_admin_id,source_type,template_type,name,slug,subject_template,body_html_template,body_text_template,sample_data_json,status) VALUES (50,1,7,'custom','general','Propia','own','Hola {{nombre}}','<p>Hola {{nombre}}</p>','Hola {{nombre}}','{\"nombre\":\"QA\"}','active'), (51,1,8,'custom','general','Ajena','other','Hola','','','','active')");
$owner = array('id' => 7, 'tipo_global' => 'admin_evento');
$other = array('id' => 8, 'tipo_global' => 'admin_evento');
$body = array('name' => 'Prueba', 'audienceId' => 1, 'templateId' => 50, 'description' => 'Borrador');
draft_assert(tickex_mobile_communication_draft($pdo, $owner, array('name' => ''), 0)['error'] === 'invalid_draft', 'invalid draft rejected');
draft_assert(tickex_mobile_communication_draft($pdo, $owner, array('name' => 'No', 'audienceId' => 2, 'templateId' => 50), 0)['error'] === 'invalid_selection', 'other audience rejected');
$created = tickex_mobile_communication_draft($pdo, $owner, $body, 0);
draft_assert($created['status'] === 'draft' && (int)$created['id'] > 0, 'draft created');
draft_assert(tickex_mobile_communication_preview($pdo, $other, $created['id'])['error'] === 'not_found', 'other producer cannot preview');
$preview = tickex_mobile_communication_preview($pdo, $owner, $created['id']);
draft_assert($preview['preview']['subject'] === 'Hola QA', 'preview renders sample without sending');
$body['name'] = 'Prueba actualizada';
$updated = tickex_mobile_communication_draft($pdo, $owner, $body, $created['id']);
draft_assert($updated['name'] === 'Prueba actualizada', 'owner updates draft');
$pdo->exec("UPDATE communication_campaigns SET status='sending' WHERE id=" . (int)$created['id']);
draft_assert(tickex_mobile_communication_draft($pdo, $owner, $body, $created['id'])['error'] === 'campaign_locked', 'sending campaign cannot be edited');
draft_assert((int)$pdo->query('SELECT COUNT(*) FROM communication_campaigns')->fetchColumn() === 1, 'no extra campaigns created');
echo "mobile communication drafts PASS\n";
