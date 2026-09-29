<?php
require_once __DIR__.'/inc/bootstrap.php';
require_once __DIR__.'/inc/google_identity.php';
require_once __DIR__.'/inc/producer_self_service.php';
require_once __DIR__.'/inc/mercadopago_marketplace.php';
header('Cache-Control: no-store');
$title='Empezá a usar Tickex';
$pdo=db(); $error=''; $organization='';
$context=isset($_SESSION['auth_context'])?$_SESSION['auth_context']:'';
$userId=$context==='user'&&!empty($_SESSION['usuario_id'])?(int)$_SESSION['usuario_id']:0;
$cu=current_user();
$adminId=$context==='admin'&&isset($cu['tipo_global'])&&in_array($cu['tipo_global'],array('admin_evento','super_admin','superadmin'),true)?(int)$cu['id']:0;
$user=null; $requestStatus='';
if ($userId>0) {
    $st=$pdo->prepare('SELECT * FROM registro_pendientes WHERE id=?');
    $st->execute(array($userId)); $user=$st->fetch(PDO::FETCH_ASSOC);
    if ($user && tickex_producer_requests_ready($pdo)) {
        $st=$pdo->prepare('SELECT status FROM producer_onboarding_requests WHERE user_id=?');
        $st->execute(array($userId)); $requestStatus=(string)$st->fetchColumn();
    }
}
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $organization=isset($_POST['organization'])?trim((string)$_POST['organization']):'';
    if (!tickex_csrf_verify(isset($_POST['_csrf'])?$_POST['_csrf']:'')) $error='La sesión venció. Actualizá la página.';
    elseif (!$user) $error='Ingresá a tu cuenta para continuar.';
    else {
        try {
            $result=tickex_self_service_create($pdo,$userId,$user['email'],$organization);
            if ($result['status']==='approved') tickex_google_establish_session($pdo,'admin',$result['account']);
            header('Location: empezar.php',true,303); exit;
        } catch (RuntimeException $e) {
            // SQL/server details must never reach the public form.
            if ($e instanceof PDOException) $error='No pudimos crear tu espacio. Intentá nuevamente.';
            else $error=$e->getMessage();
        } catch (Exception $e) { $error='No pudimos crear tu espacio. Intentá nuevamente.'; }
    }
}
$connected=false; $configured=false;
if ($adminId>0) {
    $account=tickex_mp_account($pdo,$adminId,false);
    $connected=$account&&$account['status']==='connected';
    $configured=tickex_mp_configured();
}
$flashError=isset($_SESSION['mp_flash_error'])?$_SESSION['mp_flash_error']:'';
if (isset($_GET['mp_error']) && $_GET['mp_error']==='1') $flashError='connection_failed';
unset($_SESSION['mp_flash_error'],$_SESSION['mp_flash_ok']);
include __DIR__.'/inc/layout_top.php';
?>
<style>
.tx-start{max-width:660px;margin:36px auto;padding:0 16px}.tx-start h1{font-size:clamp(28px,5vw,42px);line-height:1.12;margin:12px 0}.tx-start .card{padding:28px}.tx-start-steps{display:flex;gap:14px;list-style:none;padding:0;font-size:13px;flex-wrap:wrap;color:var(--muted)}.tx-start-steps strong{color:var(--text)}.tx-start label{display:block;margin:16px 0 8px}.tx-start .btn{display:block;text-align:center;margin-top:16px;width:100%;box-sizing:border-box}.tx-start small{display:block;margin-top:16px;color:var(--muted)}
</style>
<main class="tx-start">
<ol class="tx-start-steps" aria-label="Tu progreso"><li><strong>1. Tu cuenta</strong></li><li>2. Mercado Pago</li><li>3. Tu primer evento</li></ol>
<section class="card">
<?php if($error!==''):?><p class="flash err" role="alert"><?php echo e($error);?></p><?php endif;?>
<?php if($flashError!==''):?><p class="flash err" role="alert">No se completó la conexión con Mercado Pago. Podés volver a intentarlo.</p><?php endif;?>
<?php if($adminId>0):?>
<h1><?php echo $connected?'Todo listo para tu primer evento':'Conectá tu Mercado Pago';?></h1>
<p><?php echo $connected?'Tu cuenta está vinculada. Ahora prepará tu evento, sus entradas y su fecha.':'Vinculá la cuenta donde vas a recibir tus ventas. La autorización se realiza en Mercado Pago.';?></p>
<?php if($connected):?>
<a class="btn" href="crear_evento.php">Crear mi primer evento</a>
<?php elseif($configured):?>
<form method="post" action="mercadopago_connect.php">
<input type="hidden" name="_csrf" value="<?php echo e(tickex_csrf_token());?>">
<input type="hidden" name="return_to" value="empezar">
<button class="btn" type="submit">Conectar Mercado Pago</button></form>
<a class="btn secondary" href="crear_evento.php">Preparar mi evento mientras tanto</a>
<?php else:?>
<p>La conexión de cobros todavía está en preparación. Podés avanzar con tu evento.</p>
<a class="btn" href="crear_evento.php">Preparar mi primer evento</a>
<?php endif;?>
<a class="btn secondary" href="<?php echo e(tickex_route('admin_home',array()));?>">Ir a mi panel</a>
<?php elseif($user && $requestStatus==='pending'):?>
<h1>Recibimos tu solicitud</h1>
<p>Tu cuenta de organizador está pendiente de revisión. Cuando se apruebe, podrás ingresar al panel para crear eventos y conectar Mercado Pago.</p>
<p>Podés volver a esta página para consultar el estado.</p>
<?php elseif($user && $requestStatus==='rejected'):?>
<h1>No pudimos habilitar tu espacio</h1>
<p>Contactá a soporte para revisar la solicitud.</p>
<?php elseif($user && in_array($requestStatus,array('manual_approved','auto_approved','imported_auto'),true)):?>
<h1>Tu espacio está habilitado</h1>
<p>Ingresá con tu cuenta de organizador para continuar.</p>
<a class="btn" href="login.php?next=empezar.php">Ingresar al panel</a>
<?php elseif($user):?>
<h1>¿Cómo se llama tu organización?</h1><p>Este será tu espacio para administrar tus eventos.</p>
<form method="post"><input type="hidden" name="_csrf" value="<?php echo e(tickex_csrf_token());?>">
<label for="organization">Nombre de tu productora, ciclo o proyecto</label>
<input id="organization" name="organization" maxlength="160" required autocomplete="organization" value="<?php echo e($organization);?>">
<button class="btn" type="submit">Crear mi espacio y continuar</button></form>
<?php elseif($context==='admin'):?>
<h1>Ya tenés un acceso de equipo</h1><p>Ingresá con una cuenta propia para crear tu organización.</p><a class="btn" href="<?php echo e(tickex_route('admin_home',array()));?>">Volver a mi panel</a>
<?php else:?>
<h1>Tu próximo evento empieza acá.</h1><p>Creá tu cuenta, conectá Mercado Pago y empezá a organizar.</p>
<a class="btn" href="google_login.php?context=unified&amp;next=empezar.php">Continuar con Google</a>
<a class="btn secondary" href="registro_usuario.php?next=empezar.php">Registrarme con email</a>
<p>¿Ya tenés cuenta? <a href="login.php?next=empezar.php">Ingresar y continuar</a></p>
<?php endif;?>
<small><a href="legal.php?doc=organizers">Condiciones para organizadores</a> · <a href="legal.php?doc=privacy">Privacidad</a></small>
</section></main>
<?php include __DIR__.'/inc/layout_bottom.php';?>
