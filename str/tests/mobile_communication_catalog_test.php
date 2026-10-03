<?php
require_once __DIR__ . '/../inc/mobile_communication_catalog.php';
function catalog_assert($value, $message) { if (!$value) { fwrite(STDERR, $message . "\n"); exit(1); } }
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$owner = array('id' => 7, 'tipo_global' => 'admin_evento');
$other = array('id' => 8, 'tipo_global' => 'admin_evento');
$super = array('id' => 1, 'tipo_global' => 'super_admin');
catalog_assert(tickex_mobile_communication_catalog($pdo, $owner, 'campaigns')['items'] === array(), 'missing table returns empty catalog');
$pdo->exec('CREATE TABLE communication_audiences (id INTEGER, organization_id INTEGER, created_by_admin_id INTEGER, name TEXT, slug TEXT, status TEXT, filters_json TEXT, created_at TEXT, updated_at TEXT)');
$pdo->exec('CREATE TABLE communication_templates (id INTEGER, organization_id INTEGER, created_by_admin_id INTEGER, name TEXT, slug TEXT, template_type TEXT, status TEXT, source_type TEXT, is_system_locked INTEGER, subject_template TEXT, created_at TEXT, updated_at TEXT)');
$pdo->exec('CREATE TABLE communication_campaigns (id INTEGER, organization_id INTEGER, created_by_admin_id INTEGER, name TEXT, slug TEXT, description TEXT, status TEXT, audience_id INTEGER, template_id INTEGER, subject_override TEXT, scheduled_for TEXT, sent_at TEXT, is_favorite INTEGER, removed_at TEXT, created_at TEXT, updated_at TEXT)');
$pdo->exec("INSERT INTO communication_audiences VALUES (1,1,7,'Compradores','buyers','active','{}','',''), (2,1,8,'Ajenos','others','active','{}','','')");
$pdo->exec("INSERT INTO communication_templates VALUES (1,0,0,'Sistema','system','general','active','system',1,'Hola','',''), (2,1,7,'Propia','own','general','active','custom',0,'Hola','',''), (3,1,8,'Ajena','other','general','active','custom',0,'Hola','','')");
$pdo->exec("INSERT INTO communication_campaigns VALUES (1,1,7,'Campaña','campaign','', 'draft',1,2,'',NULL,NULL,0,NULL,'',''), (2,1,8,'Ajena','other','', 'draft',2,3,'',NULL,NULL,0,NULL,'','')");
catalog_assert(count(tickex_mobile_communication_catalog($pdo, $owner, 'audiences')['items']) === 1, 'audience scoped to owner');
catalog_assert(count(tickex_mobile_communication_catalog($pdo, $other, 'campaigns')['items']) === 1, 'campaign scoped to other owner');
catalog_assert(count(tickex_mobile_communication_catalog($pdo, $owner, 'templates')['items']) === 2, 'system template and owned template visible');
catalog_assert(count(tickex_mobile_communication_catalog($pdo, $super, 'campaigns')['items']) === 2, 'superadmin sees organization campaigns');
catalog_assert(tickex_mobile_communication_catalog($pdo, array('id' => 3, 'tipo_global' => 'cliente'), 'campaigns')['error'] === 'forbidden', 'buyer cannot view campaigns');
echo "mobile communication catalog PASS\n";
