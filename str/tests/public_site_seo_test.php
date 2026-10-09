<?php
require __DIR__.'/../inc/event_lifecycle.php';
require __DIR__.'/../inc/public_site_seo.php';
$count=0;
function check($actual,$expected,$name){global $count;if($actual!==$expected)throw new Exception($name);$count++;echo 'PASS '.$name."\n";}
$site=array('slug_publico'=>'str','custom_domain'=>'','custom_domain_status'=>'not_configured');
check(tickex_public_site_canonical($site),'https://www.tickex.com.ar/site.php?slug=str','reserved host uses actual public landing');
check(tickex_public_site_canonical($site,'public-event'),'https://www.tickex.com.ar/site.php?slug=str&event=public-event','event canonical unique');
$site['custom_domain']='events.example';$site['custom_domain_status']='pending';
check(tickex_public_site_canonical($site),'https://www.tickex.com.ar/site.php?slug=str','unverified custom ignored');
$site['custom_domain_status']='verified';
check(tickex_public_site_canonical($site,'live'),'https://events.example/?event=live','verified domain identity');
$site['custom_domain']='evil.example/path';
check(tickex_public_site_canonical($site),'https://www.tickex.com.ar/site.php?slug=str','invalid custom ignored');
$site['slug_publico']='invalid/slug';check(tickex_public_site_canonical($site),'','invalid public slug');
$now=strtotime('2026-10-09 12:00:00');$event=array('slug'=>'future','publicado_site'=>1,'fecha_hasta'=>'2026-10-31','borrado_en'=>null);
check(tickex_public_event_eligible($event,$now),true,'published current event');
$event['borrado_en']='2026-10-08';check(tickex_public_event_eligible($event,$now),false,'deleted excluded');
$event['borrado_en']=null;$event['publicado_site']=0;check(tickex_public_event_eligible($event,$now),false,'draft excluded');
$event['publicado_site']=1;$event['fecha_hasta']='2026-10-01';check(tickex_public_event_eligible($event,$now),false,'past excluded');
echo 'CHECKS='.$count."\n";
