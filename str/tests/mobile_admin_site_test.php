<?php
require_once __DIR__ . '/../inc/mobile_admin_site.php';
function mobile_site_ok($condition, $message) { if (!$condition) { fwrite(STDERR, 'FAIL: '.$message.PHP_EOL); exit(1); } echo 'PASS: '.$message.PHP_EOL; }
$file = tempnam(sys_get_temp_dir(), 'tickex-mobile-site-');
$pdo = new PDO('sqlite:'.$file);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE eventos(id INTEGER PRIMARY KEY, publicado_site INTEGER)');
$owner = array('id'=>3,'tipo_global'=>'admin_evento');
$other = array('id'=>4,'tipo_global'=>'admin_evento');
$staff = array('id'=>5,'tipo_global'=>'staff');
$empty = tickex_mobile_admin_site($pdo, $owner);
mobile_site_ok($empty['slug'] === '' && $empty['updatedAt'] === null, 'read without site is read-only');
mobile_site_ok((int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE name='clientes_sites'")->fetchColumn() === 0, 'read did not create schema');
mobile_site_ok(tickex_mobile_admin_site_save($pdo, $staff, array('section'=>'identity','expectedUpdatedAt'=>null,'slug'=>'qa','name'=>'QA'))['error'] === 'forbidden', 'staff cannot create organizer site');
mobile_site_ok(tickex_mobile_admin_site_save($pdo, $owner, array('section'=>'identity','expectedUpdatedAt'=>null,'slug'=>'qa','name'=>'QA','customDomain'=>'example.com'))['error'] === 'invalid_field', 'custom domain cannot be mutated here');
$created = tickex_mobile_admin_site_save($pdo, $owner, array('section'=>'identity','expectedUpdatedAt'=>null,'slug'=>'Fiesta QA','name'=>'Fiesta QA','visible'=>true));
mobile_site_ok($created['slug'] === 'fiesta-qa' && $created['visible'] === true && $created['updatedAt'] !== null, 'identity is saved and normalized');
mobile_site_ok(tickex_mobile_admin_site_save($pdo, $other, array('section'=>'identity','expectedUpdatedAt'=>null,'slug'=>'fiesta-qa','name'=>'Ajena'))['error'] === 'slug_taken', 'slug uniqueness enforced');
mobile_site_ok(tickex_mobile_admin_site_save($pdo, $owner, array('section'=>'identity','expectedUpdatedAt'=>null,'name'=>'Otro'))['error'] === 'conflict', 'stale version rejected');
mobile_site_ok(tickex_mobile_admin_site_save($pdo, $owner, array('section'=>'brand','expectedUpdatedAt'=>$created['updatedAt'],'logoUrl'=>'javascript:alert(1)'))['error'] === 'invalid_logo_url', 'unsafe logo URL rejected');
$brand = tickex_mobile_admin_site_save($pdo, $owner, array('section'=>'brand','expectedUpdatedAt'=>$created['updatedAt'],'primaryColor'=>'#AABBCC','logoUrl'=>'/uploads/qa.svg'));
mobile_site_ok($brand['primaryColor'] === '#aabbcc' && $brand['logoUrl'] === '/uploads/qa.svg', 'brand saved without changing identity');
mobile_site_ok(tickex_mobile_admin_site($pdo, $other)['slug'] === '', 'owner isolation');
mobile_site_ok(tickex_mobile_admin_site_save($pdo, $owner, array('section'=>'social','expectedUpdatedAt'=>$brand['updatedAt'],'instagramUrl'=>'http://bad.example'))['error'] === 'invalid_social_url', 'social links require HTTPS');
$social = tickex_mobile_admin_site_save($pdo, $owner, array('section'=>'social','expectedUpdatedAt'=>$brand['updatedAt'],'instagramUrl'=>'https://instagram.com/qa'));
mobile_site_ok($social['instagramUrl'] === 'https://instagram.com/qa' && $social['updatedAt'] !== $brand['updatedAt'], 'social links save with version advance');
mobile_site_ok((int)$pdo->query('SELECT COUNT(*) FROM clientes_sites')->fetchColumn() === 1, 'rejected changes created no other sites');
@unlink($file);
echo 'ALL MOBILE SITE TESTS PASSED'.PHP_EOL;
