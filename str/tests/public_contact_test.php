<?php
require_once __DIR__ . '/../inc/public_contact.php';
function contact_test($condition,$message){if(!$condition){fwrite(STDERR,'FAIL: '.$message.PHP_EOL);exit(1);}echo 'PASS: '.$message.PHP_EOL;}
$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
tickex_public_contact_ensure_schema($pdo);
contact_test((int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='public_contact_requests'")->fetchColumn()===1,'public contact inbox schema is created');
list($ok,$message,$reference)=tickex_public_contact_create($pdo,array('name'=>'Ana Productora','email'=>'ANA@example.com','phone'=>'5491123456789','organization'=>'Club Norte','event_type'=>'Fiesta','message'=>'Quiero organizar un evento para quinientas personas.'));
contact_test($ok&&strpos($reference,'CON-')===0,'valid commercial inquiry is registered');
$row=$pdo->query('SELECT * FROM public_contact_requests')->fetch(PDO::FETCH_ASSOC);
contact_test($row['email']==='ana@example.com'&&$row['status']==='new','contact is normalized and enters the new queue');
list($invalid)=tickex_public_contact_create($pdo,array('name'=>'X','email'=>'no-email','message'=>'corto'));
contact_test(!$invalid&&(int)$pdo->query('SELECT COUNT(*) FROM public_contact_requests')->fetchColumn()===1,'invalid public inquiry is rejected');
$landing=file_get_contents(__DIR__.'/../conocer_tickex.php');$form=file_get_contents(__DIR__.'/../contacto.php');$inbox=file_get_contents(__DIR__.'/../superadmin_contactos.php');
contact_test(strpos($landing,'contacto.php')!==false&&strpos($form,'tickex_public_contact_create')!==false,'landing opens the internal contact form');
contact_test(strpos($form,'website')!==false&&strpos($form,'tickex_csrf_verify')!==false&&strpos($form,'tickex_turnstile_verify_post')!==false,'public form includes bot and request protections');
contact_test(strpos($inbox,'tickex_is_super_admin')!==false&&strpos($inbox,'public_contact_requests')!==false,'commercial inbox is restricted to superadministrators');
echo 'ALL PUBLIC CONTACT TESTS PASSED'.PHP_EOL;

