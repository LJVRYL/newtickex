<?php
// Private CLI only. No HTTP route, no backfill, no payment-provider API calls.
if (PHP_SAPI!=='cli') exit(1);
$brand=$argv[1]??'';
if (!in_array($brand,array('graphex','tickex'),true)) exit(2);
require __DIR__.'/store.php';require __DIR__.'/consented-purchase.php';
$lock=fopen(($brand==='graphex'?'/home/graphexpress/public_html/.ge-sales-context':'/opt/ferozo3/web/str/.ge-sales-context').'/dispatch.lock','c');
if (!$lock||!flock($lock,LOCK_EX|LOCK_NB)) exit;
$settings=json_decode(file_get_contents('/root/ge-private/ga4-sales/'.$brand.'.json'),true);
if (!preg_match('/^G-[A-Z0-9]+$/D',$settings['measurement_id']??'')||!preg_match('/^[A-Za-z0-9_-]{15,100}$/D',$settings['api_secret']??'')) exit(3);
$store=new GESalesStore(($brand==='graphex'?'/home/graphexpress/public_html/.ge-sales-context':'/opt/ferozo3/web/str/.ge-sales-context').'/queue.sqlite');
if ($brand==='graphex') {
    $_SERVER['HTTP_HOST']='graphex.ar';define('WP_USE_THEMES',false);
    require '/home/graphexpress/public_html/wp-load.php';
} else {
    $financial=new PDO('sqlite:/opt/ferozo3/web/str/save_the_rave.sqlite');
    $financial->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $financial->exec('PRAGMA query_only=ON');$financial->exec('PRAGMA busy_timeout=3000');
}
$counts=array('pending_checked'=>0,'sent'=>0,'skipped'=>0);
foreach ($store->pending() as $row) {
    $counts['pending_checked']++;$id=(int)$row['order_id'];$items=array();$value=0;$paidAt=0;
    if ($brand==='graphex') {
        $order=wc_get_order($id);
        if (!$order) {$store->finish($id,'invalid','order_missing');continue;}
        if (!$order->get_date_paid()||(!$order->is_paid()&&$order->get_meta('_ge_payment_state')!=='paid')) continue;
        if ($order->get_total_refunded()>0) {$store->finish($id,'unconfirmed','refund_before_export');continue;}
        $paidAt=$order->get_date_paid()->getTimestamp();
        foreach ($order->get_items('line_item') as $item) {
            $quantity=(int)$item->get_quantity();$pid=(int)$item->get_product_id();
            if ($quantity<=0||$pid<=0) {$items=array();break;}
            $net=(float)$item->get_total();$value+=$net;
            $items[]=array('id'=>$pid,'quantity'=>$quantity,'price'=>$net/$quantity);
        }
        $currency=$order->get_currency();
    } else {
        $st=$financial->prepare('SELECT payment_status,payment_confirmed_at,evento_id,amount,ticket_subtotal,service_fee_amount,selected_tickets_json FROM tc_orders WHERE id=?');$st->execute(array($id));$order=$st->fetch(PDO::FETCH_ASSOC);
        if (!$order) {$store->finish($id,'invalid','order_missing');continue;}
        if ($order['payment_status']!=='confirmed'||!$order['payment_confirmed_at']) continue;
        $paidAt=strtotime($order['payment_confirmed_at'].' UTC');
        $selected=json_decode($order['selected_tickets_json'],true);
        if (is_array($selected)) foreach ($selected as $item) {
            $quantity=(int)($item['qty']??0);$price=$item['price']??null;
            if ($quantity<=0||!is_numeric($price)||(float)$price<0) {$items=array();break;}
            $value+=$quantity*(float)$price;
            // The event is the public product; ticket/customer names are never exported.
            $items[]=array('id'=>(int)$order['evento_id'],'quantity'=>$quantity,'price'=>(float)$price);
        }
        if (!$items||abs($value-(float)$order['ticket_subtotal'])>0.02||abs($value+(float)$order['service_fee_amount']-(float)$order['amount'])>0.02) {$store->finish($id,'invalid','amount_reconciliation');continue;}
        $currency='ARS';
    }
    if (!$paidAt||$paidAt<((int)$row['created']-60)||$paidAt>time()+60||time()-$paidAt>71*3600) {$store->finish($id,'expired','confirmation_timestamp');continue;}
    $payment=array('confirmed'=>true,'order_id'=>$id,'currency'=>$currency,'value'=>$value,'items'=>$items);
    $store->guardedSend($id,function($context) use($brand,$payment,$paidAt,$settings,&$counts) {
        $payload=ConsentedPurchase::payload($brand,$context,$payment);
        if (!$payload) return array('state'=>'invalid','result'=>'payload_validation');
        $payload['timestamp_micros']=$paidAt*1000000;
        if (!empty($context['session_id'])&&$paidAt-$context['session_id']<86400) $payload['events'][0]['params']['session_id']=$context['session_id'];
        $url='https://www.google-analytics.com/mp/collect?'.http_build_query(array('measurement_id'=>$settings['measurement_id'],'api_secret'=>$settings['api_secret']));
        $curl=curl_init($url);curl_setopt_array($curl,array(CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($payload),CURLOPT_HTTPHEADER=>array('Content-Type: application/json'),CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>8,CURLOPT_FOLLOWLOCATION=>false));
        $body=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_HTTP_CODE);$error=curl_errno($curl);curl_close($curl);
        // A 2xx is transport acknowledgement, not proof of report ingestion.
        // Never retry ambiguous sends automatically: avoid duplicating transactions.
        if (!$error&&$status>=200&&$status<300) {$counts['sent']++;return array('state'=>'sent','result'=>'http_'.$status);}
        $counts['skipped']++;return array('state'=>'transport_error','result'=>'curl_'.$error.'_http_'.$status);
    });
}
echo json_encode($counts)."\n";
