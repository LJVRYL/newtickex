<?php
require_once __DIR__.'/subscriptions.php';
require_once __DIR__.'/producer_onboarding_requests.php';

/** Explicit migration; never invoked by a public GET. */
function tickex_self_service_migrate($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS producer_self_service (
        user_id INTEGER PRIMARY KEY, admin_id INTEGER NOT NULL UNIQUE,
        organization_name TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    tickex_subscriptions_ensure_schema($pdo);
    $pdo->exec('CREATE TABLE IF NOT EXISTS producer_onboarding_oauth_returns (state_hash TEXT PRIMARY KEY, return_url TEXT NOT NULL)');
}
function tickex_self_service_ready($pdo) {
    return (bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='producer_self_service'")->fetchColumn();
}
function tickex_self_service_create($pdo, $userId, $email, $organization) {
    if (!tickex_self_service_ready($pdo)) throw new RuntimeException('El alta está en preparación. Intentá nuevamente en unos minutos.');
    if (!tickex_producer_requests_ready($pdo)) throw new RuntimeException('El alta está en preparación. Intentá nuevamente en unos minutos.');
    $email=strtolower(trim((string)$email)); $organization=trim((string)$organization);
    if ($organization==='' || strlen($organization)>160) throw new RuntimeException('Ingresá un nombre para tu organización (hasta 160 caracteres).');
    $pdo->beginTransaction();
    try {
        $st=$pdo->prepare('SELECT * FROM registro_pendientes WHERE id=?');
        $st->execute(array((int)$userId)); $user=$st->fetch(PDO::FETCH_ASSOC);
        if (!$user || strtolower(trim($user['email']))!==$email || trim((string)$user['completado_en'])==='') throw new RuntimeException('Confirmá tu email para continuar.');
        $st=$pdo->prepare('SELECT 1 FROM user_blocks WHERE lower(email)=? AND active=1');
        $st->execute(array($email));
        if ($st->fetchColumn()) throw new RuntimeException('Tu cuenta está suspendida. Contactá a soporte.');
        $st=$pdo->prepare('SELECT a.* FROM producer_self_service s JOIN usuarios_admin a ON a.id=s.admin_id WHERE s.user_id=?');
        $st->execute(array((int)$userId)); $existing=$st->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            if ((int)$existing['activo']!==1 || $existing['tipo_global']!=='admin_evento' || strtolower($existing['email'])!==$email) throw new RuntimeException('Ingresá con tu cuenta de organizador para continuar.');
            $pdo->commit(); return array('status'=>'approved','account'=>$existing);
        }
        $st=$pdo->prepare('SELECT * FROM producer_onboarding_requests WHERE user_id=?');
        $st->execute(array((int)$userId)); $request=$st->fetch(PDO::FETCH_ASSOC);
        if ($request && $request['status']==='rejected') throw new RuntimeException('Tu solicitud fue rechazada. Contactá a soporte.');
        if ($request && $request['status']==='pending') { $pdo->commit(); return array('status'=>'pending','account'=>null); }
        $reason=tickex_producer_request_policy($pdo,$user,$email,$organization);
        $status=$reason===''?'auto_approved':'pending';
        if (!$request) {
            $pdo->prepare('INSERT INTO producer_onboarding_requests(user_id,email,organization_name,status,decision_reason,decided_at) VALUES(?,?,?,?,?,CASE WHEN ?=\'auto_approved\' THEN CURRENT_TIMESTAMP ELSE NULL END)')
                ->execute(array((int)$userId,$email,$organization,$status,$reason===''?'Reglas objetivas de alta cumplidas':$reason,$status));
            $requestId=(int)$pdo->lastInsertId();
        } else {
            throw new RuntimeException('La solicitud ya fue procesada. Contactá a soporte.');
        }
        if ($status==='pending') { $pdo->commit(); return array('status'=>'pending','account'=>null); }
        $account=tickex_self_service_grant_in_transaction($pdo,$user,$email,$organization,$requestId);
        $pdo->commit(); return array('status'=>'approved','account'=>$account);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
function tickex_self_service_grant_in_transaction($pdo,$user,$email,$organization,$requestId) {
        // The caller owns an open transaction and has checked duplicate accounts.
        $hash=isset($user['password_hash'])?(string)$user['password_hash']:'';
        if ($hash==='' || !password_get_info($hash)['algo']) {
            $st=$pdo->prepare("SELECT 1 FROM auth_identities WHERE provider='google' AND account_type='buyer' AND account_id=? AND lower(email_at_link)=?");
            $st->execute(array((int)$user['id'],$email));
            if (!$st->fetchColumn()) throw new RuntimeException('Completá el registro para continuar.');
            $hash=password_hash(bin2hex(random_bytes(32)),PASSWORD_DEFAULT);
        }
        $st=$pdo->prepare("INSERT INTO usuarios_admin(username,email,password,rol,tipo_global,rol_evento,evento_id,creado_por_admin_id,activo) VALUES(?,?,?,'admin','admin_evento',NULL,NULL,NULL,1)");
        $st->execute(array($email,$email,$hash)); $adminId=(int)$pdo->lastInsertId();
        $st=$pdo->prepare("SELECT id FROM subscription_plans WHERE code='initial' AND status='active'");
        $st->execute(); $planId=(int)$st->fetchColumn();
        if ($planId<=0) throw new RuntimeException('El plan Inicial no está disponible. Intentá nuevamente más tarde.');
        tickex_subscription_assign($pdo,$adminId,$planId,'active','','Alta directa del organizador',$adminId,false);
        $pdo->prepare('INSERT INTO producer_self_service(user_id,admin_id,organization_name) VALUES(?,?,?)')->execute(array((int)$user['id'],$adminId,$organization));
        $pdo->prepare('UPDATE producer_onboarding_requests SET admin_id=? WHERE id=?')->execute(array($adminId,(int)$requestId));
        $st=$pdo->prepare('SELECT * FROM usuarios_admin WHERE id=?'); $st->execute(array($adminId)); $account=$st->fetch(PDO::FETCH_ASSOC);
        return $account;
}
function tickex_self_service_decide($pdo,$requestId,$decision,$superAdminId) {
    if (!in_array($decision,array('approve','reject'),true) || (int)$superAdminId<=0) throw new RuntimeException('Decisión inválida.');
    $pdo->beginTransaction();
    try {
        $st=$pdo->prepare("SELECT r.*,u.email AS registered_email,u.password_hash,u.completado_en,u.nombre,u.apellido FROM producer_onboarding_requests r JOIN registro_pendientes u ON u.id=r.user_id WHERE r.id=? AND r.status='pending'");
        $st->execute(array((int)$requestId)); $request=$st->fetch(PDO::FETCH_ASSOC);
        if (!$request) throw new RuntimeException('La solicitud ya fue procesada o no existe.');
        if ($decision==='reject') {
            $pdo->prepare("UPDATE producer_onboarding_requests SET status='rejected',decision_reason='Rechazada por superadministrador',decided_by_admin_id=?,decided_at=CURRENT_TIMESTAMP WHERE id=?")
                ->execute(array((int)$superAdminId,(int)$requestId));
            $pdo->commit(); return null;
        }
        $email=strtolower(trim($request['email']));
        if (strtolower(trim((string)$request['registered_email']))!==$email) throw new RuntimeException('El email del registro cambió. Revisá la solicitud antes de aprobar.');
        if (trim((string)$request['completado_en'])==='' || !filter_var($email,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('El registro no está completo.');
        $st=$pdo->prepare('SELECT 1 FROM user_blocks WHERE lower(email)=? AND active=1'); $st->execute(array($email));
        if ($st->fetchColumn()) throw new RuntimeException('La cuenta está suspendida.');
        $st=$pdo->prepare('SELECT 1 FROM usuarios_admin WHERE lower(email)=? OR lower(username)=?'); $st->execute(array($email,$email));
        if ($st->fetchColumn()) throw new RuntimeException('Ya existe un acceso administrativo con este email.');
        $user=array('id'=>(int)$request['user_id'],'password_hash'=>$request['password_hash']);
        $account=tickex_self_service_grant_in_transaction($pdo,$user,$email,$request['organization_name'],(int)$requestId);
        $pdo->prepare("UPDATE producer_onboarding_requests SET status='manual_approved',decision_reason='Aprobada por superadministrador',decided_by_admin_id=?,decided_at=CURRENT_TIMESTAMP WHERE id=?")
            ->execute(array((int)$superAdminId,(int)$requestId));
        $pdo->commit(); return $account;
    } catch (Exception $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
