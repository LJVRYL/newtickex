<?php
require_once __DIR__ . '/../inc/mobile_admin_support.php';
function mobile_support_ok($value, $label) { if (!$value) { fwrite(STDERR, 'FAIL: '.$label.PHP_EOL); exit(1); } echo 'PASS: '.$label.PHP_EOL; }
$pdo = new PDO('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE usuarios_admin(id INTEGER PRIMARY KEY,email TEXT,apodo TEXT,tipo_global TEXT,activo INTEGER)');
$pdo->exec('CREATE TABLE eventos(id INTEGER PRIMARY KEY,nombre TEXT,creado_por_admin_id INTEGER,borrado_en TEXT)');
$pdo->exec("INSERT INTO usuarios_admin VALUES(3,'owner@example.invalid','Owner','admin_evento',1),(4,'other@example.invalid','Other','admin_evento',1),(9,'support@example.invalid','Support','super_admin',1); INSERT INTO eventos VALUES(1,'QA',3,NULL),(2,'Ajeno',4,NULL)");
$owner = array('id'=>3,'tipo_global'=>'admin_evento'); $other = array('id'=>4,'tipo_global'=>'admin_evento'); $staff = array('id'=>8,'tipo_global'=>'staff');
mobile_support_ok(tickex_mobile_support_list($pdo,$staff,array())['error']==='forbidden','staff cannot use producer support');
mobile_support_ok(tickex_mobile_support_list($pdo,array('id'=>9,'tipo_global'=>'super_admin'),array())['error']==='forbidden','superadministrator uses the separate web support workflow');
mobile_support_ok(tickex_mobile_support_list($pdo,$owner,array())['items']===array(),'empty list is valid');
mobile_support_ok(tickex_mobile_support_create($pdo,$owner,array('eventId'=>2,'category'=>'events','subject'=>'Evento ajeno','body'=>'Esto es una consulta sintética.','priority'=>'normal'))['error']==='invalid_ticket','foreign event rejected');
mobile_support_ok(tickex_mobile_support_create($pdo,$owner,array('eventId'=>1,'category'=>'events','subject'=>'Consulta válida','body'=>'Esto es una consulta sintética.','priority'=>'extreme'))['error']==='invalid_priority','priority is validated');
$ticket = tickex_mobile_support_create($pdo,$owner,array('eventId'=>1,'category'=>'events','subject'=>'Consulta válida','body'=>'Esto es una consulta sintética.','priority'=>'normal'));
mobile_support_ok(!isset($ticket['error']) && $ticket['eventId']==='1' && count($ticket['messages'])===1,'owner creates scoped ticket');
mobile_support_ok((int)$pdo->query("SELECT COUNT(*) FROM notificaciones WHERE user_id=9 AND tipo='support'")->fetchColumn()===1,'support dashboard receives the same notification as web');
$id=(int)$ticket['id'];
mobile_support_ok(tickex_mobile_support_detail($pdo,$other,$id)['error']==='not_found','foreign ticket hidden');
mobile_support_ok(tickex_mobile_support_reply($pdo,$other,$id,array('body'=>'Ajeno'))['error']==='not_found','foreign reply denied');
$reply=tickex_mobile_support_reply($pdo,$owner,$id,array('body'=>'Amplío detalles.'));
mobile_support_ok(count($reply['messages'])===2 && $reply['status']==='open','owner can reply');
mobile_support_ok(tickex_mobile_support_list($pdo,$owner,array('status'=>'INVALID'))['error']==='invalid_filter','filter is validated');
list($ok) = tickex_support_update_ticket($pdo,$id,3,false,'closed');
mobile_support_ok($ok && tickex_mobile_support_reply($pdo,$owner,$id,array('body'=>'No debería'))['error']==='invalid_reply','closed ticket refuses reply');
$reopened=tickex_mobile_support_reopen($pdo,$owner,$id);
mobile_support_ok($reopened['status']==='open','owner reopens ticket');
mobile_support_ok(tickex_mobile_support_list($pdo,$other,array())['items']===array(),'list is owner scoped');
echo 'ALL MOBILE SUPPORT TESTS PASSED'.PHP_EOL;
