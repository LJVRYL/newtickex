<?php
require_once __DIR__ . '/../inc/communication_templates.php';
require_once __DIR__ . '/../inc/communication_campaigns.php';
require_once __DIR__ . '/../inc/event_newsletters.php';
require_once __DIR__ . '/../inc/communication_template_management.php';

function tpl_test($ok, $message) { echo ($ok ? 'PASS: ' : 'FAIL: ') . $message . PHP_EOL; if (!$ok) exit(1); }

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
communication_templates_ensure_schema($pdo);
communication_campaigns_ensure_schema($pdo);
event_newsletters_ensure_schema($pdo);

$insert = $pdo->prepare('INSERT INTO communication_templates (organization_id,created_by_admin_id,source_type,is_system_locked,template_type,name,slug,subject_template,status) VALUES (1,7,"custom",0,"general",:name,:slug,"Hola", "active")');
$insert->execute(array(':name'=>'Sin uso', ':slug'=>'sin-uso'));
$unusedId = (int)$pdo->lastInsertId();
$result = communication_template_delete($pdo, $unusedId, 'organization_id = :org AND created_by_admin_id = :aid', array(':org'=>1, ':aid'=>7));
tpl_test(!empty($result['ok']) && $result['mode']==='deleted', 'unused custom template is deleted permanently');
tpl_test((int)$pdo->query('SELECT COUNT(*) FROM communication_templates WHERE id='.$unusedId)->fetchColumn()===0, 'unused template row no longer exists');

$insert->execute(array(':name'=>'Usada', ':slug'=>'usada'));
$usedId = (int)$pdo->lastInsertId();
$pdo->exec('INSERT INTO communication_campaigns (organization_id,created_by_admin_id,name,slug,status,template_id) VALUES (1,7,"Campana","campana","draft",'.$usedId.')');
$result = communication_template_delete($pdo, $usedId, 'organization_id = :org AND created_by_admin_id = :aid', array(':org'=>1, ':aid'=>7));
tpl_test(!empty($result['ok']) && $result['mode']==='retired', 'used template is retired without breaking history');
tpl_test($pdo->query('SELECT status FROM communication_templates WHERE id='.$usedId)->fetchColumn()==='deleted', 'retired template keeps its historical row');
tpl_test((int)$pdo->query('SELECT template_id FROM communication_campaigns LIMIT 1')->fetchColumn()===$usedId, 'campaign retains its template reference');

$available = communication_campaigns_fetch_templates($pdo, 1, 7, false);
$availableIds = array_map(function($row){ return (int)$row['id']; }, $available);
tpl_test(!in_array($usedId, $availableIds, true), 'deleted template is not offered to new campaigns');
tpl_test(communication_campaigns_find_template($pdo, 1, 7, false, $usedId)===null, 'deleted template cannot start a new campaign');

$systemId = (int)$pdo->query('SELECT id FROM communication_templates WHERE is_system_locked=1 LIMIT 1')->fetchColumn();
$result = communication_template_delete($pdo, $systemId, '(organization_id = :org OR organization_id = 0)', array(':org'=>1));
tpl_test(empty($result['ok']), 'system template cannot be deleted');

$insert->execute(array(':name'=>'Ajena', ':slug'=>'ajena'));
$foreignId = (int)$pdo->lastInsertId();
$result = communication_template_delete($pdo, $foreignId, 'organization_id = :org AND created_by_admin_id = :aid', array(':org'=>1, ':aid'=>99));
tpl_test(empty($result['ok']), 'another administrator cannot delete the template');
echo "ALL COMMUNICATION TEMPLATE MANAGEMENT TESTS PASSED\n";
