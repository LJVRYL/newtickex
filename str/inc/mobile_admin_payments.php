<?php
require_once __DIR__ . '/mercadopago_marketplace.php';

function tickex_mobile_admin_payment_settings($pdo, $admin)
{
    if (!isset($admin['id'], $admin['tipo_global']) || (int)$admin['id'] <= 0 || !in_array($admin['tipo_global'], array('admin_evento','super_admin'), true)) return array('error'=>'forbidden');
    $adminId=(int)$admin['id'];
    $accountRead=$pdo->prepare('SELECT status,expires_at,connected_at FROM mercadopago_marketplace_accounts WHERE admin_id=:admin LIMIT 1');
    $accountRead->execute(array(':admin'=>$adminId));
    $account=$accountRead->fetch(PDO::FETCH_ASSOC);
    $policyRead=$pdo->prepare('SELECT account_type FROM mercadopago_admin_policies WHERE admin_id=:admin LIMIT 1');
    $policyRead->execute(array(':admin'=>$adminId));
    $accountType=$policyRead->fetchColumn(); if ($accountType !== 'str_owner') $accountType='client';
    $settings=$pdo->query('SELECT enforcement_enabled FROM mercadopago_platform_settings WHERE id=1 LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    $commercialEnabled=$settings && (int)$settings['enforcement_enabled']===1;
    $config=tickex_mp_config();
    $configured=tickex_mp_configured($config);
    $connected=$account && (string)$account['status']==='connected';
    $expiry=$connected && !empty($account['expires_at']) ? strtotime((string)$account['expires_at'].' UTC') : false;
    $expired=$connected && $expiry!==false && $expiry<time();
    $expiresSoon=$connected && !$expired && $expiry!==false && $expiry<time()+30*86400;
    $eventRead=$pdo->prepare("SELECT e.id,e.nombre,e.fecha_desde,COALESCE(c.provider,'totalcoin') AS configured_provider FROM eventos e LEFT JOIN mercadopago_event_configs c ON c.event_id=e.id WHERE e.creado_por_admin_id=:admin AND e.borrado_en IS NULL ORDER BY e.id DESC LIMIT 100");
    $eventRead->execute(array(':admin'=>$adminId));
    $events=array();
    foreach ($eventRead->fetchAll(PDO::FETCH_ASSOC) as $row) $events[]=array('eventId'=>(string)$row['id'],'name'=>(string)$row['nombre'],'startsAt'=>$row['fecha_desde'] ?: null,'provider'=>$accountType==='str_owner' && $row['configured_provider']!=='mercadopago' ? 'totalcoin' : 'mercadopago');
    return array('connection'=>$connected ? ($expired ? 'expired' : 'connected') : 'disconnected',
        'connectedAt'=>$account && $account['connected_at'] ? (string)$account['connected_at'] : null,
        'expiresAt'=>$account && $account['expires_at'] ? (string)$account['expires_at'] : null,
        'expiresSoon'=>$expiresSoon,'environment'=>$configured ? (!empty($config['sandbox']) ? 'sandbox' : 'production') : 'unconfigured',
        'accountType'=>$accountType,'commercialEnabled'=>$commercialEnabled,
        'ready'=>$configured && $connected && !$expired && ($accountType==='str_owner' || $commercialEnabled),
        'events'=>$events);
}
