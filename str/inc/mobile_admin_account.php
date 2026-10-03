<?php

function tickex_mobile_admin_account($pdo, $admin)
{
    if (!isset($admin['id']) || (int)$admin['id'] <= 0) return array('error'=>'forbidden');
    $columns = array(); foreach ($pdo->query("PRAGMA table_info('usuarios_admin')") as $column) $columns[(string)$column['name']] = true;
    $select = array('id','nombre','email','dni','cbu');
    foreach (array('apellido','apodo') as $optional) if (isset($columns[$optional])) $select[] = $optional;
    $read = $pdo->prepare('SELECT '.implode(',', $select).' FROM usuarios_admin WHERE id=:id LIMIT 1');
    $read->execute(array(':id'=>(int)$admin['id']));
    $row = $read->fetch(PDO::FETCH_ASSOC);
    if (!$row) return array('error'=>'not_found');
    return array('id'=>(string)$row['id'], 'firstName'=>(string)$row['nombre'], 'lastName'=>isset($row['apellido']) ? (string)$row['apellido'] : '', 'email'=>(string)$row['email'], 'dni'=>(string)$row['dni'], 'cbu'=>(string)$row['cbu'], 'tickexId'=>isset($row['apodo']) ? (string)$row['apodo'] : '', 'version'=>hash('sha256', json_encode($row)));
}

function tickex_mobile_admin_account_save($pdo, $admin, $input)
{
    if (!is_array($input) || !isset($input['expectedVersion']) || !is_string($input['expectedVersion'])) return array('error'=>'expected_version_required');
    $fields = array('firstName'=>'nombre','lastName'=>'apellido','email'=>'email','dni'=>'dni','cbu'=>'cbu','tickexId'=>'apodo');
    foreach ($input as $key=>$value) if ($key !== 'expectedVersion' && !isset($fields[$key])) return array('error'=>'invalid_field');
    $changes = array();
    foreach ($fields as $key=>$column) {
        if (!array_key_exists($key, $input)) continue;
        if (!is_string($input[$key])) return array('error'=>'invalid_field');
        $value = trim($input[$key]);
        if (strlen($value) > ($key === 'email' ? 254 : 120)) return array('error'=>'value_too_long');
        if ($key === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) return array('error'=>'invalid_email');
        if ($key === 'tickexId' && $value !== '' && !preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $value)) return array('error'=>'invalid_tickex_id');
        if ($key === 'dni' && ($value === '' || !preg_match('/^[0-9]{6,12}$/', $value))) return array('error'=>'invalid_dni');
        if ($key === 'cbu' && $value !== '' && !preg_match('/^[0-9]{22}$/', $value)) return array('error'=>'invalid_cbu');
        $changes[$column] = $value;
    }
    if (!$changes) return array('error'=>'empty_update');
    try {
        $pdo->beginTransaction();
        $current = tickex_mobile_admin_account($pdo, $admin);
        if (isset($current['error'])) { $pdo->rollBack(); return $current; }
        if (!hash_equals($current['version'], $input['expectedVersion'])) { $pdo->rollBack(); return array('error'=>'conflict'); }
        $columns = array(); foreach ($pdo->query("PRAGMA table_info('usuarios_admin')") as $column) $columns[(string)$column['name']] = true;
        foreach ($changes as $column=>$value) if (!isset($columns[$column])) { $pdo->rollBack(); return array('error'=>'unsupported_field'); }
        if (isset($changes['email'])) {
            $st=$pdo->prepare('SELECT 1 FROM usuarios_admin WHERE lower(email)=lower(:email) AND id<>:id LIMIT 1');
            $st->execute(array(':email'=>$changes['email'],':id'=>(int)$admin['id']));
            if ($st->fetchColumn()) { $pdo->rollBack(); return array('error'=>'email_taken'); }
        }
        if (isset($changes['apodo']) && $changes['apodo'] !== '') {
            $st=$pdo->prepare('SELECT 1 FROM usuarios_admin WHERE lower(apodo)=lower(:apodo) AND id<>:id LIMIT 1');
            $st->execute(array(':apodo'=>$changes['apodo'],':id'=>(int)$admin['id']));
            if ($st->fetchColumn()) { $pdo->rollBack(); return array('error'=>'tickex_id_taken'); }
            $clientCols = array(); foreach ($pdo->query("PRAGMA table_info('registro_pendientes')") as $column) $clientCols[(string)$column['name']] = true;
            if (isset($clientCols['apodo'])) {
                $st=$pdo->prepare('SELECT 1 FROM registro_pendientes WHERE lower(apodo)=lower(:apodo) LIMIT 1');
                $st->execute(array(':apodo'=>$changes['apodo']));
                if ($st->fetchColumn()) { $pdo->rollBack(); return array('error'=>'tickex_id_taken'); }
            }
        }
        $sets=array(); $params=array(':id'=>(int)$admin['id']);
        foreach ($changes as $column=>$value) { $sets[]=$column.'=:'.$column; $params[':'.$column]=$value === '' ? null : $value; }
        $st=$pdo->prepare('UPDATE usuarios_admin SET '.implode(',', $sets).' WHERE id=:id');
        $st->execute($params);
        $pdo->commit();
    } catch (Exception $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
    return tickex_mobile_admin_account($pdo, $admin);
}

function tickex_mobile_admin_plan($pdo, $admin)
{
    if (!isset($admin['id'], $admin['tipo_global']) || $admin['tipo_global'] !== 'admin_evento') return array('error'=>'forbidden');
    $read=$pdo->prepare('SELECT s.status,s.ends_at,s.limit_exempt,p.code,p.name,p.description,p.monthly_price,p.organizer_share_percent,p.qr_limit_monthly,p.features_json FROM admin_subscriptions s JOIN subscription_plans p ON p.id=s.plan_id WHERE s.admin_id=:admin LIMIT 1');
    $read->execute(array(':admin'=>(int)$admin['id']));
    $plan=$read->fetch(PDO::FETCH_ASSOC);
    $settings=$pdo->query('SELECT enforcement_enabled FROM subscription_settings WHERE id=1 LIMIT 1')->fetchColumn();
    $periodStart=date('Y-m-01 00:00:00'); $periodEnd=date('Y-m-t 23:59:59');
    $usage=$pdo->prepare("SELECT COUNT(*) FROM entradas en JOIN eventos ev ON ev.id=en.evento_id WHERE ev.creado_por_admin_id=:admin AND COALESCE(en.oculto,0)=0 AND en.fecha_registro>=:start AND en.fecha_registro<=:end");
    $usage->execute(array(':admin'=>(int)$admin['id'],':start'=>$periodStart,':end'=>$periodEnd));
    $used=(int)$usage->fetchColumn();
    if (!$plan) return array('assigned'=>false,'observing'=>(int)$settings !== 1,'used'=>$used,'periodStart'=>$periodStart,'periodEnd'=>$periodEnd);
    $features=json_decode((string)$plan['features_json'],true); if (!is_array($features)) $features=array();
    $limit=$plan['qr_limit_monthly'] === null || (int)$plan['qr_limit_monthly'] <= 0 ? null : (int)$plan['qr_limit_monthly'];
    return array('assigned'=>true,'code'=>(string)$plan['code'],'name'=>(string)$plan['name'],'description'=>(string)$plan['description'],'status'=>(string)$plan['status'],'endsAt'=>$plan['ends_at'] ?: null,'monthlyPrice'=>$plan['monthly_price'] === null ? null : (float)$plan['monthly_price'],'organizerSharePercent'=>(float)$plan['organizer_share_percent'],'features'=>$features,'limit'=>$limit,'used'=>$used,'remaining'=>$limit === null ? null : max(0,$limit-$used),'periodStart'=>$periodStart,'periodEnd'=>$periodEnd,'observing'=>(int)$settings !== 1,'limitExempt'=>(int)$plan['limit_exempt'] === 1);
}
