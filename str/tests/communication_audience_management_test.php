<?php
require_once __DIR__ . '/../inc/communication_audience_management.php';
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE communication_audiences (id INTEGER PRIMARY KEY, organization_id INTEGER, created_by_admin_id INTEGER, name TEXT, slug TEXT, status TEXT, updated_at TEXT)');
$pdo->exec('CREATE TABLE communication_campaigns (id INTEGER PRIMARY KEY, audience_id INTEGER)');
$pdo->exec("INSERT INTO communication_audiences VALUES (1,1,10,'Libre','libre','active',CURRENT_TIMESTAMP),(2,1,10,'En uso','en-uso','active',CURRENT_TIMESTAMP),(3,1,11,'Ajena','ajena','active',CURRENT_TIMESTAMP)");
$pdo->exec('INSERT INTO communication_campaigns VALUES (1,2)');
function audience_management_assert($condition,$message){if(!$condition){fwrite(STDERR,"FAIL: $message\n");exit(1);}echo "PASS: $message\n";}
$counts=communication_audience_campaign_counts($pdo,array(array('id'=>1),array('id'=>2)));
audience_management_assert($counts[1]===0&&$counts[2]===1,'campaign usage is counted before deletion');
$result=communication_audience_delete($pdo,1,10,false,2);
audience_management_assert($result['status']==='deleted_preserved'&&$result['campaign_count']===1,'used audience is removed while preserving campaign history');
audience_management_assert($pdo->query('SELECT status FROM communication_audiences WHERE id=2')->fetchColumn()==='deleted','used audience remains only as a hidden historical reference');
$result=communication_audience_delete($pdo,1,10,false,3);
audience_management_assert($result['status']==='not_found','administrator cannot delete another account audience');
$result=communication_audience_delete($pdo,1,10,false,1);
audience_management_assert($result['status']==='deleted','unused owned audience can be deleted');
audience_management_assert((int)$pdo->query('SELECT COUNT(*) FROM communication_audiences WHERE id=1')->fetchColumn()===0,'deleted audience is removed permanently');
echo "ALL COMMUNICATION AUDIENCE MANAGEMENT TESTS PASSED\n";
