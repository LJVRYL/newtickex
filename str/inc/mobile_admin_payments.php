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

function tickex_mobile_admin_payment_connect($pdo, $admin, $begin = null)
{
    if (!isset($admin['id'], $admin['tipo_global']) || (int)$admin['id'] <= 0 || !in_array($admin['tipo_global'], array('admin_evento', 'super_admin', 'superadmin'), true)) return array('error' => 'forbidden');
    $pdo->exec('CREATE TABLE IF NOT EXISTS mobile_mp_oauth_returns (state_hash TEXT PRIMARY KEY, admin_id INTEGER NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
    $pdo->beginTransaction();
    try {
        $url = $begin === null ? tickex_mp_begin_oauth($pdo, (int)$admin['id']) : call_user_func($begin, $pdo, (int)$admin['id']);
        $parts = parse_url((string)$url);
        $query = array();
        parse_str(isset($parts['query']) ? $parts['query'] : '', $query);
        if (!isset($parts['scheme'], $parts['host'], $query['state']) || $parts['scheme'] !== 'https' || $parts['host'] !== 'auth.mercadopago.com' || !is_string($query['state']) || strlen($query['state']) < 32) throw new RuntimeException('No se pudo preparar la autorización.');
        $pdo->prepare('DELETE FROM mobile_mp_oauth_returns WHERE admin_id=:admin')->execute(array(':admin' => (int)$admin['id']));
        $pdo->prepare('INSERT INTO mobile_mp_oauth_returns (state_hash,admin_id) VALUES (:hash,:admin)')->execute(array(':hash' => hash('sha256', $query['state']), ':admin' => (int)$admin['id']));
        $pdo->commit();
        return array('authorizationUrl' => $url);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return array('error' => 'connection_unavailable');
    }
}

function tickex_mobile_mp_return_admin($pdo, $state)
{
    if (!is_string($state) || $state === '') return 0;
    if (!$pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='mobile_mp_oauth_returns' LIMIT 1")->fetchColumn()) return 0;
    if (!$pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='mercadopago_oauth_states' LIMIT 1")->fetchColumn()) return 0;
    $st = $pdo->prepare('SELECT r.admin_id FROM mobile_mp_oauth_returns r JOIN mercadopago_oauth_states s ON s.state_hash=r.state_hash AND s.admin_id=r.admin_id WHERE r.state_hash=:hash AND s.used_at IS NULL AND s.expires_at>=CURRENT_TIMESTAMP LIMIT 1');
    $st->execute(array(':hash' => hash('sha256', $state)));
    return (int)$st->fetchColumn();
}

function tickex_mobile_mp_clear_return($pdo, $state)
{
    $pdo->prepare('DELETE FROM mobile_mp_oauth_returns WHERE state_hash=:hash')->execute(array(':hash' => hash('sha256', (string)$state)));
}

function tickex_mobile_mp_return_location($connected)
{
    return 'tickex://admin?payment=' . ($connected ? 'connected' : 'error');
}
