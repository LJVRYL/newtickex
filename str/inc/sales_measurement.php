<?php
if (!class_exists('GESalesStore',false)) require_once __DIR__.'/sales_store.php';
function tickex_sales_capture_order($orderId) {
    if (strtolower($_SERVER['HTTP_HOST']??'')!=='www.tickex.com.ar'||!empty($_SESSION['admin_id'])) return;
    try {(new GESalesStore('/opt/ferozo3/web/str/.ge-sales-context/queue.sqlite'))->capture((int)$orderId,$_COOKIE);}catch(Throwable $e) {/* Measurement must not interrupt ordering. */}
}
