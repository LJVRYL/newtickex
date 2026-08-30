<?php

putenv('TICKEX_MAIL_TRANSPORT=fake');
putenv('TICKEX_SITE_URL=https://str.tickex.test');

$GLOBALS['communication_test_pdo'] = new PDO('sqlite::memory:');
$GLOBALS['communication_test_pdo']->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function db()
{
    return $GLOBALS['communication_test_pdo'];
}

require_once dirname(__DIR__) . '/inc/communication_execution_engine.php';

function communication_delivery_test_assert($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }
    echo 'PASS: ' . $message . PHP_EOL;
}

$pdo = db();
communication_templates_ensure_schema($pdo);
communication_campaigns_ensure_schema($pdo);
communication_execution_ensure_schema($pdo);
communication_contacts_imports_ensure_schema($pdo);

$pdo->exec('CREATE TABLE communication_audiences (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    organization_id INTEGER NOT NULL,
    created_by_admin_id INTEGER,
    name TEXT NOT NULL,
    slug TEXT NOT NULL,
    filters_json TEXT,
    status TEXT NOT NULL DEFAULT "active",
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)');
$pdo->exec('CREATE TABLE eventos (id INTEGER PRIMARY KEY, creado_por_admin_id INTEGER)');
$pdo->exec('CREATE TABLE entradas (id INTEGER PRIMARY KEY, evento_id INTEGER, email TEXT, nombre TEXT, fecha_registro TEXT)');
$pdo->exec('CREATE TABLE usuarios (email TEXT, nombre TEXT, apellido TEXT, rol TEXT)');
$pdo->exec('CREATE TABLE registro_pendientes (email TEXT, nombre TEXT, apellido TEXT)');
$pdo->exec('CREATE TABLE user_blocks (email TEXT, active INTEGER, blocked_by_admin_id INTEGER)');

$stContact = $pdo->prepare('INSERT INTO communication_contacts_imports (created_by_admin_id,email,nombre,source,import_batch,import_file,imported_at,created_at) VALUES (7,:email,:nombre,"import_csv","delivery-test","delivery.csv",CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)');
for ($i = 1; $i <= 502; $i++) {
    $name = ($i === 1) ? '<b>Nombre sin escapar</b>' : ('Persona ' . $i);
    $stContact->execute(array(':email' => sprintf('persona%04d@example.com', $i), ':nombre' => $name));
}
$stContact->execute(array(':email' => 'correo-invalido', ':nombre' => 'Invalido'));
$stContact->execute(array(':email' => 'bloqueado@example.com', ':nombre' => 'Bloqueado'));
$pdo->exec("INSERT INTO user_blocks (email,active,blocked_by_admin_id) VALUES ('bloqueado@example.com',1,7)");

$suppressedToken = communication_suppressions_token_for($pdo, 1, 7, 'persona0502@example.com');
communication_delivery_test_assert($suppressedToken !== '', 'unsubscribe token is generated');
communication_delivery_test_assert(communication_suppressions_unsubscribe_token($pdo, $suppressedToken), 'contact can unsubscribe per administrator');

$pdo->exec("INSERT INTO communication_audiences (organization_id,created_by_admin_id,name,slug,filters_json,status) VALUES (1,7,'Todos','todos','{}','active')");
$audienceId = (int)$pdo->lastInsertId();
$stTemplate = $pdo->prepare('INSERT INTO communication_templates (organization_id,created_by_admin_id,source_type,is_system_locked,template_type,name,slug,subject_template,body_html_template,body_text_template,status) VALUES (1,7,"custom",0,"newsletter","Prueba","prueba-entrega","Hola {{nombre}}","<p>Hola {{nombre}}</p>","Hola {{nombre}}","active")');
$stTemplate->execute();
$templateId = (int)$pdo->lastInsertId();
$stCampaign = $pdo->prepare('INSERT INTO communication_campaigns (organization_id,created_by_admin_id,name,slug,status,audience_id,template_id) VALUES (1,7,"Campana 501","campana-501","draft",:aud,:tpl)');
$stCampaign->execute(array(':aud' => $audienceId, ':tpl' => $templateId));
$campaignId = (int)$pdo->lastInsertId();

$enqueue = communication_execution_enqueue_campaign($pdo, 1, $campaignId, 7, false, array('request_key' => 'delivery-engine-501'));
communication_delivery_test_assert(!empty($enqueue['ok']), 'campaign is enqueued');

$result = communication_execution_process_queue($pdo, 10, 'test-worker', 200);
communication_delivery_test_assert((int)$result['picked'] === 3, 'large campaign continues across three batches');
communication_delivery_test_assert((int)$result['done'] === 1, 'campaign command completes only after the final batch');

$run = $pdo->query('SELECT * FROM communication_campaign_runs WHERE campaign_id=' . $campaignId . ' ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
$campaign = $pdo->query('SELECT * FROM communication_campaigns WHERE id=' . $campaignId)->fetch(PDO::FETCH_ASSOC);
communication_delivery_test_assert((int)$run['resolved_recipients'] === 501, 'invalid, blocked and unsubscribed contacts are excluded');
communication_delivery_test_assert((int)$run['accepted_count'] === 501, 'all deliverable recipients are accepted');
communication_delivery_test_assert((int)$run['processed_count'] === 501, 'all materialized recipients are processed');
communication_delivery_test_assert($run['status'] === 'completed' && $campaign['status'] === 'sent', 'run is finalized after pending reaches zero');
communication_delivery_test_assert((int)$pdo->query("SELECT COUNT(*) FROM communication_campaign_run_recipients WHERE status='queued'")->fetchColumn() === 0, 'no queued recipients remain after completion');
communication_delivery_test_assert((int)$pdo->query("SELECT COUNT(*) FROM email_logs WHERE context='communication_campaign' AND mail_ok=1")->fetchColumn() === 501, 'fake transport records exactly one email per recipient');

$firstBody = (string)$pdo->query("SELECT body FROM email_logs WHERE to_email='persona0001@example.com' ORDER BY id DESC LIMIT 1")->fetchColumn();
communication_delivery_test_assert(strpos($firstBody, '&lt;b&gt;Nombre sin escapar&lt;/b&gt;') !== false, 'recipient name is escaped in HTML');
communication_delivery_test_assert(strpos($firstBody, 'unsubscribe.php?token=') !== false, 'campaign email contains an unsubscribe link');

$secondPass = communication_execution_process_queue($pdo, 10, 'test-worker-second-pass', 200);
communication_delivery_test_assert((int)$secondPass['picked'] === 0, 'completed campaign is not picked again');
communication_delivery_test_assert((int)$pdo->query("SELECT COUNT(*) FROM email_logs WHERE context='communication_campaign' AND mail_ok=1")->fetchColumn() === 501, 'reprocessing does not duplicate accepted emails');

echo 'ALL COMMUNICATION DELIVERY TESTS PASSED' . PHP_EOL;
