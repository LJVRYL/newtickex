<?php
// Include only on public pages. Account, checkout, ticket and admin views excluded.
function tickex_public_measurement() {
    if (strtolower(isset($_SERVER['HTTP_HOST'])?$_SERVER['HTTP_HOST']:'') !== 'www.tickex.com.ar') return;
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') return;
    if (!empty($_SESSION['admin_id']) || !empty($_SESSION['usuario_id']) || !empty($_SESSION['user_id'])) return;
    foreach(array('token','key','password','email','access_token','auth','order_key','code','qr') as $key) if(isset($_GET[$key]))return;
    $config=array('measurementId'=>'G-T8Z2V2J5B7','measurementReady'=>true,'privacyUrl'=>'/legal.php?doc=privacy','analyticsConsent'=>isset($_COOKIE['ge_growth_consent'])&&$_COOKIE['ge_growth_consent']==='granted');
    echo '<script>window.geGrowthConfig='.json_encode($config,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT).';</script><script src="/assets/public-measurement.js?v=20261009sales1" defer></script>';
}
