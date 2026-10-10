<?php
if (PHP_SAPI!=='cli') exit(1);
require __DIR__.'/consented-purchase.php';
$results=array();
foreach(array('graphex','tickex') as $brand) {
    $settings=json_decode(file_get_contents('/root/ge-private/ga4-sales/'.$brand.'.json'),true);
    $context=array('client_id'=>'999999999.999999999','consented_at'=>time(),'scope'=>'sales-v1');
    $payment=array('confirmed'=>true,'order_id'=>999999999,'value'=>10,'currency'=>'ARS','items'=>array(array('id'=>999999999,'quantity'=>1,'price'=>10)));
    $payload=ConsentedPurchase::payload($brand,$context,$payment);$payload['validation_behavior']='ENFORCE_RECOMMENDATIONS';
    $url='https://www.google-analytics.com/debug/mp/collect?'.http_build_query(array('measurement_id'=>$settings['measurement_id'],'api_secret'=>$settings['api_secret']));
    $curl=curl_init($url);curl_setopt_array($curl,array(CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($payload),CURLOPT_HTTPHEADER=>array('Content-Type: application/json'),CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15,CURLOPT_FOLLOWLOCATION=>false));
    $body=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_HTTP_CODE);$error=curl_errno($curl);curl_close($curl);
    $response=json_decode((string)$body,true);
    $results[$brand]=array('http'=>$status,'curl_error'=>$error,'validation_messages'=>$response['validationMessages']??null,'reports_ingestion'=>false);
}
echo json_encode($results)."\n";
