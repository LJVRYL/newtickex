<?php
require __DIR__.'/store.php';
$path=tempnam(sys_get_temp_dir(),'ga4-sale-test-');$now=time();$tests=0;
function verify($value,$name){global $tests;if(!$value)throw new RuntimeException($name);$tests++;}
try {
    $store=new GESalesStore($path);$token=str_repeat('a',64);
    $cookies=array('ge_sales_browser'=>$token,'ge_growth_consent'=>'granted','_ga'=>'GA1.1.12345.67890','ge_growth_session'=>(string)$now);
    verify(!$store->capture(1,$cookies),'old visit consent cannot grant sales');
    $store->permission($token,true);
    verify($store->capture(1,$cookies),'explicit grant captures future order');
    verify(!$store->capture(1,$cookies),'repeat order cannot replace attribution');
    verify(count($store->pending())===1,'pending order present');
    $store->permission($token,false);
    verify(count($store->pending())===0,'withdrawal removes pending order');
    verify(!$store->capture(2,$cookies),'withdrawn browser cannot create new context');
    $called=false;
    verify(!$store->guardedSend(1,function()use(&$called){$called=true;return array('state'=>'sent');}),'revoked send refused');
    verify(!$called,'sender not called after withdrawal');
    $store->permission($token,true);
    verify(!$store->capture(1,$cookies),'grant again cannot backfill revoked order');
    verify($store->capture(2,$cookies),'new permission can cover new order');
    verify($store->guardedSend(2,function($context){verify($context['scope']==='sales-v1','explicit scope');return array('state'=>'sent','result'=>'fixture_only');}),'isolated send acknowledgement');
    verify(count($store->pending())===0,'sent cannot repeat');
    $pdo=new PDO('sqlite:'.$path);
    verify($pdo->query('SELECT context FROM contexts WHERE order_id=2')->fetchColumn()==='','sent pseudonym erased');
    $expired=str_repeat('b',64);$store->permission($expired,true,$now-181*86400);$cookies['ge_sales_browser']=$expired;
    verify(!$store->capture(3,$cookies),'expired browser permission refused');
    echo json_encode(array('passed'=>$tests,'real_orders_created'=>0,'network_requests'=>0))."\n";
}finally{unlink($path);}
