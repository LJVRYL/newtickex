<?php
require_once __DIR__ . '/../inc/security.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/google_identity.php';

function google_test($condition, $message) { if (!$condition) { echo 'FAIL: '.$message.PHP_EOL; exit(1); } echo 'PASS: '.$message.PHP_EOL; }

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE registro_pendientes(id INTEGER PRIMARY KEY AUTOINCREMENT,email TEXT NOT NULL,token TEXT NOT NULL,nombre TEXT,apellido TEXT,apodo TEXT,dni TEXT,genero TEXT,foto_path TEXT,next_url TEXT,creado_en TEXT,completado_en TEXT,password_hash TEXT)');
$pdo->exec('CREATE TABLE usuarios_admin(id INTEGER PRIMARY KEY,username TEXT,email TEXT,password TEXT,rol TEXT,tipo_global TEXT,rol_evento TEXT,activo INTEGER,evento_id INTEGER)');
$pdo->exec('CREATE TABLE staff_eventos(staff_id INTEGER,evento_id INTEGER)');
$pdo->exec("INSERT INTO usuarios_admin VALUES(10,'agus','agus@example.com','x','admin','admin_evento',NULL,1,NULL)");
$pdo->exec("INSERT INTO usuarios_admin VALUES(11,'puerta','door@example.com','x','staff','staff_evento','puerta',1,NULL)");
$pdo->exec('INSERT INTO staff_eventos VALUES(11,77)');

tickex_google_ensure_schema($pdo);
google_test((int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='auth_identities'")->fetchColumn() === 1, 'federated identity schema is created');

putenv('TICKEX_GOOGLE_CLIENT_ID=tickex-test.apps.googleusercontent.com');
putenv('TICKEX_GOOGLE_CLIENT_SECRET=test-secret-value');
putenv('TICKEX_GOOGLE_REDIRECT_URI=https://local.test/google_oauth_callback.php');
$_SESSION = array();
$authUrl = tickex_google_begin('buyer', '/panel_usuario.php');
parse_str((string)parse_url($authUrl, PHP_URL_QUERY), $authQuery);
google_test(parse_url($authUrl, PHP_URL_HOST) === 'accounts.google.com' && $authQuery['scope'] === 'openid email profile', 'Google authorization requests only identity scopes');
google_test(!empty($authQuery['state']) && !empty($authQuery['code_challenge']) && $authQuery['code_challenge_method'] === 'S256', 'Google authorization uses state and PKCE');
$stateData = tickex_google_consume_state($authQuery['state']);
google_test($stateData['context'] === 'buyer' && $stateData['next'] === '/panel_usuario.php', 'Google state preserves login context and safe destination');
$stateRejected = false;
try { tickex_google_consume_state($authQuery['state']); } catch (RuntimeException $e) { $stateRejected = true; }
google_test($stateRejected, 'Google authorization state can be used only once');

$buyerProfile = array('sub'=>'google-buyer-1','email'=>'buyer@example.com','email_verified'=>true,'given_name'=>'Ada','family_name'=>'Lovelace');
list($buyerType,$buyer) = tickex_google_find_or_create_account($pdo,$buyerProfile,'buyer');
google_test($buyerType === 'buyer' && (int)$buyer['id'] > 0, 'verified Google buyer is created');
list($buyerTypeAgain,$buyerAgain) = tickex_google_find_or_create_account($pdo,$buyerProfile,'buyer');
google_test((int)$buyerAgain['id'] === (int)$buyer['id'], 'returning Google buyer reuses the linked account');
google_test((int)$pdo->query("SELECT COUNT(*) FROM auth_identities WHERE account_type='buyer'")->fetchColumn() === 1, 'buyer identity is linked only once');

$adminProfile = array('sub'=>'google-admin-1','email'=>'agus@example.com','email_verified'=>true);
list($adminType,$admin) = tickex_google_find_or_create_account($pdo,$adminProfile,'admin');
google_test($adminType === 'admin' && (int)$admin['id'] === 10, 'Google can link an existing administrator');
list($unifiedAdminType,$unifiedAdmin) = tickex_google_find_or_create_account($pdo,$adminProfile,'unified');
google_test($unifiedAdminType === 'admin' && (int)$unifiedAdmin['id'] === 10, 'unified Google access routes an existing administrator to its admin account');
$unifiedBuyerProfile = array('sub'=>'google-unified-buyer','email'=>'newbuyer@example.com','email_verified'=>true,'given_name'=>'New','family_name'=>'Buyer');
list($unifiedBuyerType,$unifiedBuyer) = tickex_google_find_or_create_account($pdo,$unifiedBuyerProfile,'unified');
google_test($unifiedBuyerType === 'buyer' && (int)$unifiedBuyer['id'] > 0, 'unified Google access creates a regular buyer when no administrator exists');
$unknownAdminRejected = false;
try { tickex_google_find_or_create_account($pdo,array('sub'=>'unknown','email'=>'unknown@example.com','email_verified'=>true),'admin'); } catch (RuntimeException $e) { $unknownAdminRejected = true; }
google_test($unknownAdminRejected, 'Google never creates administrator privileges');

$_SESSION = array();
$destination = tickex_google_establish_session($pdo,'buyer',$buyer);
google_test($destination === '/mi-cuenta' && $_SESSION['auth_context'] === 'user' && empty($_SESSION['es_admin']), 'buyer login cannot inherit administrator privileges');
$_SESSION = array();
$destination = tickex_google_establish_session($pdo,'admin',$admin);
google_test($destination === '/administrar' && $_SESSION['auth_context'] === 'admin' && (int)$_SESSION['admin_id'] === 10, 'administrator Google login preserves its tenant identity');

$doorProfile = array('sub'=>'google-door-1','email'=>'door@example.com','email_verified'=>true);
list($doorType,$door) = tickex_google_find_or_create_account($pdo,$doorProfile,'admin');
$_SESSION = array();
$destination = tickex_google_establish_session($pdo,$doorType,$door);
google_test($destination === 'puerta.php?evento_id=77' && $_SESSION['rol_evento'] === 'puerta', 'staff login keeps the assigned door role and flow');

google_test(tickex_google_safe_next('https://evil.example') === '' && tickex_google_safe_next('/panel_usuario.php') === '/panel_usuario.php', 'post-login redirect cannot leave Tickex');
$unverifiedRejected = false;
try { tickex_google_find_or_create_account($pdo,array('sub'=>'bad','email'=>'bad@example.com','email_verified'=>false),'buyer'); } catch (RuntimeException $e) { $unverifiedRejected = true; }
google_test($unverifiedRejected, 'unverified Google emails are rejected');

$buyerLogin = file_get_contents(__DIR__ . '/../login.php');
$adminLogin = file_get_contents(__DIR__ . '/../login_admin.php');
google_test(strpos($buyerLogin, "require_once __DIR__ . '/inc/routes.php';") !== false, 'the unified login loads clean routes before redirecting');
google_test(strpos($buyerLogin, 'rol_evento,activo,evento_id FROM usuarios_admin') !== false, 'the unified password login loads the assigned staff role');
google_test(strpos($buyerLogin, 'google_login.php?context=unified') !== false, 'the public login uses one Google entry point for every role');
google_test(strpos($adminLogin, "header('Location: login.php'") !== false, 'the historical administrator login redirects to the unified login');

echo 'ALL GOOGLE IDENTITY TESTS PASSED'.PHP_EOL;
