<?php
require_once __DIR__.'/../inc/legal_center.php';

function legal_test($condition,$message){if(!$condition){fwrite(STDERR,'FAIL: '.$message.PHP_EOL);exit(1);}echo 'PASS: '.$message.PHP_EOL;}

$pdo=new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
tickex_legal_ensure_schema($pdo);

legal_test((int)$pdo->query('SELECT COUNT(*) FROM legal_documents')->fetchColumn()===4,'four legal drafts are seeded');
legal_test(count(tickex_legal_documents($pdo,false))===0,'drafts are never exposed as active legal text');

foreach(array('terms','privacy','refunds') as $type){
 tickex_legal_save_document($pdo,$type,ucfirst($type),'Published summary','Published '.$type,true,1);
}
$published=tickex_legal_documents($pdo,false);
legal_test(isset($published['terms'],$published['privacy'],$published['refunds']),'checkout legal documents can be published');
legal_test((int)$published['terms']['version']===2,'published document creates a new immutable version');

tickex_legal_record_acceptance($pdo,'buyer',55,'Buyer@Example.com','checkout:ORDER-1',array('REMOTE_ADDR'=>'127.0.0.1','HTTP_USER_AGENT'=>'Test Agent'),array('terms','privacy','refunds'));
tickex_legal_record_acceptance($pdo,'buyer',55,'Buyer@Example.com','checkout:ORDER-1',array('REMOTE_ADDR'=>'127.0.0.1','HTTP_USER_AGENT'=>'Test Agent'),array('terms','privacy','refunds'));
$acceptance=$pdo->query('SELECT * FROM legal_acceptances')->fetch(PDO::FETCH_ASSOC);
$accepted=json_decode($acceptance['documents_json'],true);
legal_test((int)$pdo->query('SELECT COUNT(*) FROM legal_acceptances')->fetchColumn()===1,'checkout acceptance is idempotent per order');
legal_test(count($accepted)===3&&!isset($accepted['organizers']),'buyer accepts only applicable document versions');
legal_test($acceptance['ip_hash']===hash('sha256','127.0.0.1')&&strpos($acceptance['ip_hash'],'127.')===false,'acceptance stores technical evidence without the raw IP');

$withdrawal=tickex_legal_create_withdrawal($pdo,array('email'=>'buyer@example.com','full_name'=>'Buyer Test','purchase_reference'=>'ORDER-1','event_name'=>'Evento'));
legal_test(strpos($withdrawal,'ARR-')===0,'withdrawal request receives an immediate public identifier');
tickex_legal_update_withdrawal($pdo,1,'reviewing','Validando compra',1);
legal_test($pdo->query("SELECT status FROM withdrawal_requests WHERE id=1")->fetchColumn()==='reviewing','withdrawal workflow is traceable');

$privacy=tickex_legal_create_privacy_request($pdo,array('email'=>'buyer@example.com','full_name'=>'Buyer Test','request_type'=>'access','details'=>'Quiero una copia'));
legal_test(strpos($privacy,'DAT-')===0,'privacy request receives an immediate public identifier');
tickex_legal_update_privacy_request($pdo,1,'completed','Identidad verificada',1);
legal_test($pdo->query("SELECT status FROM privacy_requests WHERE id=1")->fetchColumn()==='completed','privacy workflow is traceable');
legal_test($pdo->query('PRAGMA integrity_check')->fetchColumn()==='ok','legal database remains consistent');
echo 'ALL LEGAL CENTER TESTS PASSED'.PHP_EOL;
