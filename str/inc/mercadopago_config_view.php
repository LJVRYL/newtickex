<?php
$isInternalAccount = isset($policy['account_type']) && $policy['account_type'] === 'str_owner';
$connectionLabel = $accountConnected ? ($accountExpired ? 'Vinculación vencida' : 'Cuenta conectada') : 'Cuenta sin conectar';
$environmentLabel = !$configured ? 'Sin configurar' : ($isSandbox ? 'Pruebas' : 'Producción');
$platformFeeGeneral = tickex_mp_effective_platform_fee_percent($settings, array('platform_fee_override_percent' => null));
$formatMpDate = function ($value) {
    $ts = $value ? strtotime((string)$value . ' UTC') : false;
    return $ts ? date('d/m/Y H:i', $ts) : 'Sin fecha';
};
$mpOrderLabels = array(
    'confirmed' => 'Aprobado',
    'pending' => 'Pendiente',
    'created' => 'Creado',
    'failed' => 'Fallido',
    'rejected' => 'Rechazado',
    'cancelled' => 'Cancelado',
);
?>
<style>
.mp-center{max-width:1240px;margin:0 auto;padding:20px 18px 36px}.mp-hero{padding:29px;background:linear-gradient(135deg,rgba(33,72,155,.94),rgba(34,27,91,.96));border:1px solid rgba(92,154,255,.22);position:relative;overflow:hidden}.mp-hero:after{content:"";position:absolute;width:360px;height:360px;border-radius:50%;border:1px solid rgba(82,177,255,.2);right:-125px;bottom:-265px}.mp-hero-row{display:flex;align-items:flex-start;justify-content:space-between;gap:20px;position:relative;z-index:1}.mp-kicker{display:block;color:#64dff0;font-size:10px;font-weight:900;letter-spacing:.14em;text-transform:uppercase}.mp-hero h1{font-size:40px;line-height:1.04;margin:10px 0}.mp-hero p{max-width:690px;color:#c2c8dc;line-height:1.55;margin:0}.mp-ready{display:flex;align-items:center;gap:8px;padding:8px 11px;border-radius:999px;font-size:10px;font-weight:900;letter-spacing:.07em;text-transform:uppercase;border:1px solid rgba(255,255,255,.12);background:rgba(7,12,30,.3);white-space:nowrap}.mp-ready:before{content:"";width:8px;height:8px;border-radius:50%;background:#ffbd5c;box-shadow:0 0 12px rgba(255,189,92,.65)}.mp-ready.ok:before{background:#50dfb4;box-shadow:0 0 12px rgba(80,223,180,.65)}.mp-flow{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-top:25px;position:relative;z-index:1}.mp-step{padding:12px 13px;border:1px solid rgba(255,255,255,.08);background:rgba(5,10,27,.28);border-radius:11px}.mp-step small,.mp-step strong{display:block}.mp-step small{color:#8f98b3;font-size:9px;text-transform:uppercase;letter-spacing:.09em}.mp-step strong{font-size:12px;margin-top:4px}.mp-step.done{border-color:rgba(73,216,177,.22);background:rgba(44,166,136,.08)}.mp-step.done small{color:#71dabe}.mp-step.warn{border-color:rgba(255,188,82,.2)}.mp-alerts{margin-top:14px}.mp-overview{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-top:14px}.mp-stat{padding:17px 18px}.mp-stat span{display:block;color:#8991a9;font-size:9px;font-weight:900;letter-spacing:.1em;text-transform:uppercase}.mp-stat strong{display:block;font-size:17px;margin:8px 0 3px}.mp-stat small{display:block;color:#777f98;font-size:10px;line-height:1.45}.mp-grid{display:grid;grid-template-columns:minmax(0,1.15fr) minmax(330px,.85fr);gap:14px;margin-top:14px}.mp-section{padding:21px}.mp-section-head{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;margin-bottom:17px}.mp-section-head h2,.mp-section-head h3{margin:0;font-size:21px}.mp-section-head p{margin:5px 0 0;color:#828aa3;font-size:11px;line-height:1.45}.mp-icon{width:42px;height:42px;border-radius:12px;display:grid;place-items:center;background:linear-gradient(135deg,rgba(63,142,239,.2),rgba(111,73,229,.18));border:1px solid rgba(104,151,244,.2);font-weight:900;color:#81d6ee}.mp-account-state{padding:16px;border-radius:14px;border:1px solid rgba(255,255,255,.08);background:rgba(6,10,23,.38)}.mp-account-state strong{font-size:16px}.mp-account-state p{font-size:11px;color:#8f96ad;line-height:1.5;margin:6px 0 0}.mp-account-meta{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:12px}.mp-account-meta div{padding:10px 11px;border:1px solid rgba(255,255,255,.06);border-radius:10px}.mp-account-meta small,.mp-account-meta strong{display:block}.mp-account-meta small{font-size:8px;color:#747c95;text-transform:uppercase;letter-spacing:.08em}.mp-account-meta strong{font-size:11px;margin-top:4px}.mp-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:15px}.mp-actions form{margin:0}.mp-danger-note{color:#c38b95;font-size:10px;margin-top:9px}.mp-policy-number{font-size:38px;line-height:1;font-weight:900;margin:11px 0 5px}.mp-policy-number small{font-size:16px;color:#9ca4bb}.mp-breakdown{display:grid;gap:7px;margin-top:14px}.mp-breakdown div{display:flex;justify-content:space-between;gap:14px;padding:9px 0;border-bottom:1px solid rgba(255,255,255,.06);font-size:11px;color:#8e96ad}.mp-breakdown strong{color:#d7dae6}.mp-callout{margin-top:13px;padding:11px 12px;border-radius:10px;background:rgba(64,196,171,.07);border:1px solid rgba(64,196,171,.15);color:#91cabd;font-size:10px;line-height:1.45}.mp-events{margin-top:14px;padding:21px}.mp-event-list{display:grid;gap:9px}.mp-event{display:grid;grid-template-columns:minmax(220px,1fr) 230px auto;gap:13px;align-items:end;padding:14px 15px;border-radius:12px;background:rgba(6,10,23,.35);border:1px solid rgba(255,255,255,.07);margin:0}.mp-event-name strong,.mp-event-name small{display:block}.mp-event-name strong{font-size:13px}.mp-event-name small{color:#747d97;font-size:9px;margin-top:5px}.mp-event label{font-size:10px;color:#9ba2b7}.mp-event select{display:block;width:100%;margin-top:5px}.mp-provider strong,.mp-provider small{display:block}.mp-provider strong{font-size:12px}.mp-provider small{font-size:9px;color:#798198;margin-top:4px;line-height:1.4}.mp-history{margin-top:12px}.mp-history summary,.mp-super summary{cursor:pointer;color:#aab0c2;font-size:11px;font-weight:800;padding:5px 0}.mp-history .mp-event-list{margin-top:10px}.mp-operations{margin-top:14px;padding:21px}.mp-operation-list{display:grid;gap:8px}.mp-operation{display:grid;grid-template-columns:minmax(190px,1fr) 110px 120px 110px;gap:12px;align-items:center;padding:12px 14px;border-radius:11px;background:rgba(6,10,23,.35);border:1px solid rgba(255,255,255,.065)}.mp-operation strong,.mp-operation small{display:block}.mp-operation small{color:#747c94;font-size:9px;margin-top:4px}.mp-money{text-align:right}.mp-status{display:inline-flex;padding:5px 8px;border-radius:999px;font-size:8px;font-weight:900;text-transform:uppercase;letter-spacing:.07em;color:#e5b96d;border:1px solid rgba(226,173,79,.2);background:rgba(226,173,79,.08)}.mp-status.ok{color:#67d8b6;border-color:rgba(68,203,164,.2);background:rgba(68,203,164,.08)}.mp-empty{padding:20px;text-align:center;color:#8189a1;border:1px dashed rgba(255,255,255,.1);border-radius:12px;font-size:11px}.mp-super{margin-top:14px;padding:4px 21px 16px}.mp-super>summary{padding:17px 0;font-size:14px;color:#d8dbe6}.mp-super-body{border-top:1px solid rgba(255,255,255,.07);padding-top:18px}.mp-super-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;align-items:end}.mp-super-grid label,.mp-admin-policy label{font-size:10px;color:#a4aabd}.mp-super-grid input,.mp-admin-policy input,.mp-admin-policy select{display:block;width:100%;margin-top:6px}.mp-toggle{display:flex!important;gap:9px;align-items:center;margin:14px 0!important;font-size:11px!important}.mp-toggle input{width:auto!important;margin:0!important}.mp-admin-list{display:grid;gap:8px;margin-top:13px}.mp-admin-policy{display:grid;grid-template-columns:minmax(230px,1fr) 210px 190px auto;gap:10px;align-items:end;padding:13px;border-radius:11px;background:rgba(5,9,21,.38);border:1px solid rgba(255,255,255,.06);margin:0}.mp-admin-policy strong,.mp-admin-policy small{display:block}.mp-admin-policy small{font-size:9px;color:#747c95;margin-top:4px}.mp-footer-links{display:flex;justify-content:flex-end;gap:10px;margin-top:12px}.mp-footer-links a{font-size:10px;color:#78819a;text-decoration:none}
@media(max-width:960px){.mp-overview{grid-template-columns:1fr 1fr}.mp-grid{grid-template-columns:1fr}.mp-admin-policy{grid-template-columns:1fr 1fr}.mp-admin-policy .btn{align-self:end}.mp-operation{grid-template-columns:1fr 100px 100px}.mp-operation-date{grid-column:1/-1}}
@media(max-width:720px){.mp-center{padding:12px}.mp-hero{padding:22px 18px}.mp-hero-row,.mp-section-head{flex-direction:column}.mp-hero h1{font-size:33px}.mp-ready{white-space:normal}.mp-flow{grid-template-columns:1fr 1fr}.mp-event,.mp-operation,.mp-super-grid,.mp-admin-policy{grid-template-columns:1fr}.mp-money{text-align:left}.mp-account-meta{grid-template-columns:1fr}.mp-actions,.mp-actions form,.mp-actions .btn,.mp-event .btn,.mp-admin-policy .btn{width:100%}}
@media(max-width:460px){.mp-overview,.mp-flow{grid-template-columns:1fr}}
</style>

<main class="mp-center">
  <section class="card mp-hero">
    <div class="mp-hero-row">
      <div><span class="mp-kicker">Cobros online</span><h1>Mercado Pago</h1><p>Conectá la cuenta que recibirá las ventas. Tickex prepara cada cobro, separa el costo de servicio y confirma las entradas automáticamente.</p></div>
      <span class="mp-ready<?php echo $integrationReady ? ' ok' : ''; ?>"><?php echo $integrationReady ? 'Integración lista' : 'Configuración pendiente'; ?></span>
    </div>
    <div class="mp-flow">
      <div class="mp-step<?php echo $configured ? ' done' : ' warn'; ?>"><small>Paso 1</small><strong>Aplicación Tickex</strong></div>
      <div class="mp-step<?php echo $accountConnected && !$accountExpired ? ' done' : ' warn'; ?>"><small>Paso 2</small><strong>Cuenta vinculada</strong></div>
      <div class="mp-step<?php echo ($isInternalAccount || $commercialEnabled) ? ' done' : ' warn'; ?>"><small>Paso 3</small><strong><?php echo $isInternalAccount ? 'Proveedor por evento' : 'Ventas habilitadas'; ?></strong></div>
      <div class="mp-step<?php echo $approvedMpOrders > 0 ? ' done' : ' warn'; ?>"><small>Paso 4</small><strong>Pago verificado</strong></div>
    </div>
  </section>

  <?php if ($error !== '' || $ok !== ''): ?><div class="mp-alerts"><?php if ($error !== ''): ?><div class="flash err"><?php echo e($error); ?></div><?php endif; ?><?php if ($ok !== ''): ?><div class="flash ok"><?php echo e($ok); ?></div><?php endif; ?></div><?php endif; ?>

  <section class="mp-overview">
    <div class="card mp-stat"><span>Cuenta receptora</span><strong><?php echo e($connectionLabel); ?></strong><small><?php echo $accountConnected ? 'Vendedor identificado por Mercado Pago' : 'Todavía no recibirá ventas por esta vía'; ?></small></div>
    <div class="card mp-stat"><span>Entorno</span><strong><?php echo e($environmentLabel); ?></strong><small><?php echo !$configured ? 'No puede iniciar pagos desde este entorno' : ($isSandbox ? 'No utiliza dinero real' : 'Los pagos utilizan dinero real'); ?></small></div>
    <div class="card mp-stat"><span>Costo al comprador</span><strong><?php echo e(number_format((float)$effectiveServiceCharge, 2, ',', '.')); ?>%</strong><small><?php echo $isInternalAccount ? 'Aplicable cuando el evento usa Mercado Pago' : 'Definido por la política de Tickex'; ?></small></div>
    <div class="card mp-stat"><span>Eventos con MP</span><strong><?php echo (int)$mercadoPagoEventCount; ?></strong><small>De <?php echo count($events); ?> evento<?php echo count($events) === 1 ? '' : 's'; ?> de esta cuenta</small></div>
  </section>

  <div class="mp-grid">
    <section class="card mp-section">
      <div class="mp-section-head"><div><h2>Cuenta que recibe el dinero</h2><p>La vinculación se realiza directamente con Mercado Pago. Tickex nunca solicita la contraseña del organizador.</p></div><span class="mp-icon">MP</span></div>
      <?php if (!$configured): ?>
        <div class="mp-account-state"><strong>Integración no disponible en este entorno</strong><p>Falta la configuración segura de la aplicación Marketplace. En producción debe resolverlo el equipo de Tickex; el organizador no tiene que cargar credenciales técnicas.</p></div>
      <?php elseif ($accountConnected): ?>
        <div class="mp-account-state">
          <strong><?php echo $accountExpired ? 'La vinculación necesita renovarse' : 'Cuenta conectada correctamente'; ?></strong>
          <p><?php echo $accountExpired ? 'El acceso venció. Volvé a conectar la cuenta antes de recibir nuevas ventas.' : 'Mercado Pago acreditará las ventas en esta cuenta y separará la comisión de Tickex en cada operación.'; ?></p>
          <div class="mp-account-meta"><div><small>ID de vendedor</small><strong><?php echo e($account['mp_user_id']); ?></strong></div><div><small>Vinculación vigente hasta</small><strong><?php echo e($formatMpDate($account['expires_at'])); ?></strong></div></div>
          <?php if ($accountExpiresSoon): ?><div class="mp-callout">La vinculación vence pronto. Conviene renovarla antes del próximo evento.</div><?php endif; ?>
        </div>
        <div class="mp-actions">
          <?php if ($accountExpired): ?><form method="post" action="mercadopago_connect.php"><input type="hidden" name="_csrf" value="<?php echo e($csrf); ?>"><button class="btn" type="submit">Renovar vinculación</button></form><?php endif; ?>
          <form method="post" onsubmit="return confirm('¿Desconectar esta cuenta? Las ventas por Mercado Pago quedarán pausadas hasta volver a vincularla.');"><input type="hidden" name="_csrf" value="<?php echo e($csrf); ?>"><button class="btn secondary" type="submit" name="disconnect" value="1">Desconectar cuenta</button></form>
        </div>
        <div class="mp-danger-note">Desconectar no mueve eventos a TotalCoin ni elimina operaciones anteriores.</div>
      <?php else: ?>
        <div class="mp-account-state"><strong>Falta vincular una cuenta</strong><p>Ingresá a Mercado Pago, revisá los permisos solicitados y autorizá a Tickex. Después volverás automáticamente a esta pantalla.</p></div>
        <div class="mp-actions"><form method="post" action="mercadopago_connect.php"><input type="hidden" name="_csrf" value="<?php echo e($csrf); ?>"><button class="btn" type="submit">Conectar Mercado Pago</button></form></div>
      <?php endif; ?>
    </section>

    <section class="card mp-section">
      <div class="mp-section-head"><div><h2>Cómo se distribuye el cobro</h2><p>Resumen de la política aplicada a esta cuenta.</p></div><span class="mp-icon">%</span></div>
      <?php if ($isInternalAccount): ?>
        <div class="mp-policy-number">Flexible</div><p class="muted">SAVE THE RAVE puede seleccionar TotalCoin o Mercado Pago en cada evento.</p>
      <?php else: ?>
        <div class="mp-policy-number"><?php echo e(number_format((float)$effectiveServiceCharge, 2, ',', '.')); ?><small>% al comprador</small></div>
      <?php endif; ?>
      <div class="mp-breakdown">
        <div><span>Tipo de cuenta</span><strong><?php echo $isInternalAccount ? 'Cuenta interna' : 'Organizador cliente'; ?></strong></div>
        <?php if (!$isInternalAccount): ?><div><span>Costo estimado Mercado Pago</span><strong><?php echo e(number_format((float)$settings['mp_cost_estimate_percent'], 2, ',', '.')); ?>%</strong></div><div><span>Fee Tickex estimado</span><strong><?php echo e(number_format((float)$effectiveFee, 2, ',', '.')); ?>% del cobro</strong></div><?php endif; ?>
        <div><span>Estado comercial</span><strong><?php echo $isInternalAccount ? 'Por evento' : ($commercialEnabled ? 'Habilitado' : 'Pausado'); ?></strong></div>
      </div>
      <div class="mp-callout"><?php echo $isInternalAccount ? 'Esta excepción corresponde únicamente a la cuenta operativa de SAVE THE RAVE.' : 'El organizador conserva el precio nominal publicado; el comprador abona el costo de servicio adicional.'; ?></div>
    </section>
  </div>

  <section class="card mp-events">
    <div class="mp-section-head"><div><h2>Medio de pago por evento</h2><p><?php echo $isInternalAccount ? 'Elegí el proveedor solamente para eventos vigentes o próximos.' : 'Los eventos pagos de clientes utilizan Mercado Pago Split automáticamente.'; ?></p></div><span class="mp-icon">EV</span></div>
    <?php if (!$currentEvents): ?><div class="mp-empty">No hay eventos vigentes o próximos para configurar.</div><?php else: ?><div class="mp-event-list">
      <?php foreach ($currentEvents as $event): $eventId=(int)$event['id'];$eventConfig=$eventConfigs[$eventId];$provider=(string)$eventConfig['provider']; ?>
        <form method="post" class="mp-event"><input type="hidden" name="_csrf" value="<?php echo e($csrf); ?>"><input type="hidden" name="event_id" value="<?php echo $eventId; ?>"><div class="mp-event-name"><strong><?php echo e($event['nombre']); ?></strong><small><?php echo e($event['fecha_desde'] ? $formatMpDate($event['fecha_desde']) : 'Fecha no definida'); ?></small></div><?php if ($isInternalAccount): ?><label>Proveedor<select name="provider"><option value="totalcoin"<?php echo $provider==='totalcoin'?' selected':''; ?>>TotalCoin</option><option value="mercadopago"<?php echo $provider==='mercadopago'?' selected':''; ?>>Mercado Pago Split</option></select></label><button class="btn" type="submit" name="save_event" value="1">Guardar</button><?php else: ?><input type="hidden" name="provider" value="mercadopago"><div class="mp-provider"><strong>Mercado Pago Split</strong><small>Servicio <?php echo e(number_format((float)$eventConfig['service_charge_percent'],2,',','.')); ?>% · fee Tickex estimado <?php echo e(number_format((float)$eventConfig['marketplace_fee_percent'],2,',','.')); ?>%</small></div><span class="mp-status<?php echo $commercialEnabled&&$accountConnected?' ok':''; ?>"><?php echo $commercialEnabled&&$accountConnected?'Disponible':'Pausado'; ?></span><?php endif; ?></form>
      <?php endforeach; ?>
    </div><?php endif; ?>
    <?php if ($pastEvents): ?><details class="mp-history"><summary>Ver <?php echo count($pastEvents); ?> evento<?php echo count($pastEvents)===1?' terminado':'s terminados'; ?></summary><div class="mp-event-list"><?php foreach ($pastEvents as $event): $eventId=(int)$event['id'];$eventConfig=$eventConfigs[$eventId];$provider=(string)$eventConfig['provider']; ?><div class="mp-event"><div class="mp-event-name"><strong><?php echo e($event['nombre']); ?></strong><small><?php echo e($event['fecha_desde']?$formatMpDate($event['fecha_desde']):'Sin fecha válida'); ?></small></div><div class="mp-provider"><strong><?php echo $provider==='mercadopago'?'Mercado Pago Split':'TotalCoin'; ?></strong><small>Configuración histórica</small></div><span class="mp-status">Finalizado</span></div><?php endforeach; ?></div></details><?php endif; ?>
  </section>

  <section class="card mp-operations">
    <div class="mp-section-head"><div><h2>Actividad reciente de Mercado Pago</h2><p>Últimos intentos registrados para los eventos de esta cuenta.</p></div><a class="btn secondary" href="facturacion_admin.php">Ir a Facturación</a></div>
    <?php if (!$recentMpOrders): ?><div class="mp-empty">Todavía no hay operaciones de Mercado Pago registradas.</div><?php else: ?><div class="mp-operation-list"><?php foreach ($recentMpOrders as $mpOrder): $rawStatus=!empty($mpOrder['payment_status'])?(string)$mpOrder['payment_status']:(string)$mpOrder['state'];$statusLabel=isset($mpOrderLabels[$rawStatus])?$mpOrderLabels[$rawStatus]:ucfirst($rawStatus); ?><div class="mp-operation"><div><strong><?php echo e($mpOrder['evento_nombre']); ?></strong><small><?php echo e($mpOrder['ref']); ?></small></div><span class="mp-status<?php echo $rawStatus==='confirmed'?' ok':''; ?>"><?php echo e($statusLabel); ?></span><div class="mp-money"><strong>$<?php echo number_format((float)$mpOrder['amount'],2,',','.'); ?></strong><small>Total cobrado</small></div><div class="mp-operation-date"><strong><?php echo e($formatMpDate($mpOrder['updated_at'])); ?></strong><small>Último cambio</small></div></div><?php endforeach; ?></div><?php endif; ?>
  </section>

  <?php if ($isSuper): ?>
    <details class="card mp-super">
      <summary>Configuración de plataforma · sólo superadmin</summary>
      <div class="mp-super-body"><div class="mp-section-head"><div><h3>Política comercial general</h3><p>El costo al comprador es fijo. Los planes distribuyen su remanente sin modificar el precio final.</p></div></div><form method="post"><input type="hidden" name="_csrf" value="<?php echo e($csrf); ?>"><input type="hidden" name="total_cost_target_percent" value="15"><div class="mp-super-grid"><label>Costo de servicio al comprador<input value="15% fijo" readonly></label><label>Costo estimado Mercado Pago (%)<input type="number" name="mp_cost_estimate_percent" min="0" max="100" step="0.01" value="<?php echo e($settings['mp_cost_estimate_percent']); ?>"></label><div><small class="muted">Fee inicial Tickex estimado</small><strong><?php echo e(number_format((float)$platformFeeGeneral,2,',','.')); ?>%</strong></div></div><label class="mp-toggle"><input type="checkbox" name="enforcement_enabled" value="1"<?php echo $commercialEnabled?' checked':''; ?>> Habilitar ventas Mercado Pago para organizadores cliente</label><button class="btn" type="submit" name="save_platform" value="1">Guardar política general</button></form></div>
    </details>
    <details class="card mp-super">
      <summary>Cuentas y excepciones por administrador · sólo superadmin</summary>
      <div class="mp-super-body"><p class="muted">La cuenta interna debe reservarse para SAVE THE RAVE. Los organizadores cliente reciben las condiciones del plan asignado en Suscripciones.</p><div class="mp-admin-list"><?php foreach ($adminPolicies as $adminRow): $adminPolicy=$adminRow['mp_policy'];$adminAccount=$adminRow['mp_account']; ?><form method="post" class="mp-admin-policy"><input type="hidden" name="_csrf" value="<?php echo e($csrf); ?>"><input type="hidden" name="policy_admin_id" value="<?php echo (int)$adminRow['id']; ?>"><input type="hidden" name="fee_override" value=""><div><strong><?php echo e(!empty($adminRow['nombre'])?$adminRow['nombre']:'Administrador'); ?></strong><small><?php echo e($adminRow['email']); ?> · MP <?php echo $adminAccount&&$adminAccount['status']==='connected'?'conectado':'sin conectar'; ?></small></div><label>Tipo de cuenta<select name="account_type"><option value="client"<?php echo $adminPolicy['account_type']==='client'?' selected':''; ?>>Organizador cliente</option><option value="str_owner"<?php echo $adminPolicy['account_type']==='str_owner'?' selected':''; ?>>SAVE THE RAVE / interna</option></select></label><div><small class="muted">Condición comercial</small><strong><?php echo $adminPolicy['account_type']==='client'?'Según suscripción':'Por evento'; ?></strong></div><button class="btn secondary" type="submit" name="save_policy" value="1">Guardar</button></form><?php endforeach; ?></div></div>
    </details>
  <?php endif; ?>

  <nav class="mp-footer-links"><a href="facturacion_admin.php">Facturación</a><a href="panel_admin.php">Volver al panel</a></nav>
</main>
