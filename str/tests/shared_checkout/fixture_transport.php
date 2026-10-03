<?php
// Synthetic transport only. This file is never deployed.
function tc_config() { return array('callback_base'=>'https://fixture.invalid','use_prod'=>false); }
function fixture_gateway($provider,$ref,$payload) {
    $pdo=db();
    $st=$pdo->prepare('INSERT INTO fixture_gateway_calls(provider,ref,payload) VALUES(:p,:r,:v)');
    $st->execute(array(':p'=>$provider,':r'=>$ref,':v'=>json_encode($payload)));
    usleep(200000);
    if (isset($payload['email']) && $payload['email']==='failure@example.invalid') throw new RuntimeException('simulated response lost after dispatch');
    return 'fixture-'.substr(hash('sha256',$ref),0,24);
}
function tc_checkout($amount,$concept,$dni,$ref,$last,$first,$email,$logo=null,$callbacks=null) {
    $rid=fixture_gateway('totalcoin',$ref,array('amount'=>$amount,'email'=>$email,'callbacks'=>$callbacks));
    return 'https://provider.invalid/checkout?requestId='.$rid;
}
function tickex_mp_http_json($method,$url,array $headers,$body=null) {
    $payload=json_decode($body,true);
    if (!is_array($payload)) $payload=$body;
    $rid=fixture_gateway('mercadopago',$payload['external_reference'],$payload);
    return array('id'=>$rid,'init_point'=>'https://mp.invalid/checkout/'.$rid,'sandbox_init_point'=>'https://mp.invalid/checkout/'.$rid);
}
putenv('TICKEX_MAIL_TRANSPORT=fake');
putenv('TOTALCOIN_CALLBACK_KEY=fixture-signing-key');
putenv('TICKEX_MP_ENCRYPTION_KEY=fixture-encryption-key-long-enough');
putenv('TICKEX_MP_CLIENT_ID=fixture-client');
putenv('TICKEX_MP_CLIENT_SECRET=fixture-secret');
putenv('TICKEX_MP_SANDBOX=true');
require_once __DIR__.'/inc/bootstrap.php';
if (isset($_GET['fixture_login'])) {
    $_SESSION['usuario_id']=(int)$_GET['fixture_login'];
    $_SESSION['usuario_email']='login@example.invalid';
    $_SESSION['first_name']='Login'; $_SESSION['last_name']='Buyer'; $_SESSION['dni']='12345678';
}
require __DIR__.'/checkout_totalcoin.php';
