<?php
if (!function_exists('communication_ui_status_label')) {
    function communication_ui_status_label($status)
    {
        $labels = array('draft'=>'Borrador','scheduled'=>'Programada','queued'=>'En cola','sending'=>'Enviando','processing'=>'Procesando','completed'=>'Completada','done'=>'Completado','sent'=>'Enviada','failed'=>'Fallida','cancelled'=>'Cancelada','archived'=>'Archivada','accepted'=>'Entregado','transient_error'=>'Error temporal','permanent_error'=>'Error permanente','skipped_duplicate'=>'Duplicado omitido');
        $key = strtolower(trim((string)$status));
        return isset($labels[$key]) ? $labels[$key] : ($key !== '' ? ucfirst(str_replace('_', ' ', $key)) : 'Sin estado');
    }
}
if (!function_exists('communication_ui_status_class')) {
    function communication_ui_status_class($status)
    {
        $key = strtolower(trim((string)$status));
        if (in_array($key, array('sent','completed','done','accepted','active'), true)) return 'is-good';
        if (in_array($key, array('failed','permanent_error','cancelled'), true)) return 'is-bad';
        if (in_array($key, array('sending','processing','queued','scheduled'), true)) return 'is-live';
        if (in_array($key, array('transient_error','draft'), true)) return 'is-warn';
        return 'is-muted';
    }
}
?>
<style>
.comm-ops{max-width:1180px;margin:0 auto;display:grid;gap:17px}.comm-ops *{box-sizing:border-box}.comm-hero{padding:30px;background:radial-gradient(circle at 88% 12%,rgba(54,207,234,.14),transparent 30%),linear-gradient(135deg,rgba(24,29,56,.98),rgba(45,29,88,.96));border-color:rgba(129,91,245,.28)}.comm-hero-top{display:flex;justify-content:space-between;align-items:flex-start;gap:18px}.comm-kicker{color:#55d7ea;font-size:12px;font-weight:900;text-transform:uppercase;letter-spacing:.13em}.comm-kicker:before{content:"";display:inline-block;width:8px;height:8px;margin-right:8px;border-radius:50%;background:#55d7ea;box-shadow:0 0 14px #55d7ea}.comm-hero h1{margin:8px 0;font-size:clamp(30px,4vw,46px);line-height:1.03;letter-spacing:-.04em}.comm-hero p{margin:0;max-width:700px;color:#b9bccf}.comm-flow{display:grid;grid-template-columns:repeat(4,1fr);gap:9px;margin-top:25px}.comm-flow a{display:block;padding:14px;border:1px solid rgba(255,255,255,.08);border-radius:14px;background:rgba(5,8,21,.3);text-decoration:none;color:#fff}.comm-flow a:hover,.comm-flow a.active{border-color:rgba(125,89,241,.48);background:rgba(91,61,187,.18)}.comm-flow small{display:block;color:#7f859d;font-size:9px;font-weight:900;text-transform:uppercase;letter-spacing:.08em}.comm-flow strong{display:block;margin-top:6px;font-size:14px}.comm-ops-nav{display:flex;gap:7px;flex-wrap:wrap}.comm-ops-nav a{padding:8px 11px;border:1px solid rgba(255,255,255,.07);border-radius:10px;color:#8d93aa;text-decoration:none;font-size:11px;font-weight:800}.comm-ops-nav a.active,.comm-ops-nav a:hover{color:#fff;border-color:rgba(119,83,237,.35);background:rgba(100,66,212,.1)}.comm-alerts{display:grid;gap:8px}.comm-alerts .flash{margin:0}.comm-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:10px}.comm-stats.six{grid-template-columns:repeat(6,1fr)}.comm-stat{padding:17px 18px}.comm-stat span{color:#858aa3;font-size:10px;font-weight:850;text-transform:uppercase;letter-spacing:.08em}.comm-stat strong{display:block;margin-top:5px;font-size:25px}.comm-stat small{display:block;margin-top:5px;color:#737990}.comm-section-head{display:flex;justify-content:space-between;align-items:flex-end;gap:12px}.comm-section-head h2{margin:0;font-size:21px}.comm-section-head p{margin:5px 0 0;color:#858ba2;font-size:12px}.comm-toolbar{padding:15px}.comm-field{display:grid;gap:6px}.comm-field label{font-size:10px;font-weight:850;color:#878ca3;text-transform:uppercase;letter-spacing:.06em}.comm-field input,.comm-field select,.comm-field textarea{width:100%;margin:0}.comm-status{display:inline-flex;width:max-content;padding:5px 8px;border-radius:999px;font-size:9px;font-weight:900;text-transform:uppercase;background:rgba(142,148,171,.1);color:#9ba0b5}.comm-status.is-good{background:rgba(51,196,127,.1);color:#64daa0}.comm-status.is-bad{background:rgba(221,81,104,.1);color:#ea8b9a}.comm-status.is-live{background:rgba(74,188,229,.1);color:#62d2eb}.comm-status.is-warn{background:rgba(236,172,65,.1);color:#efbd69}.comm-list{display:grid;gap:9px}.comm-item{padding:17px}.comm-item-title{font-size:15px;font-weight:850}.comm-item-sub{margin-top:4px;color:#7b8198;font-size:11px}.comm-actions{display:flex;gap:6px;flex-wrap:wrap}.comm-actions form{margin:0}.comm-actions .btn{padding:7px 9px;font-size:11px}.comm-panel{padding:0;overflow:hidden}.comm-panel-head{padding:20px;border-bottom:1px solid rgba(255,255,255,.07)}.comm-panel-head h2,.comm-panel-head h3{margin:0}.comm-panel-head p{margin:5px 0 0;color:#858ba2;font-size:12px}.comm-panel-body{padding:20px}.comm-grid-2{display:grid;grid-template-columns:1fr 1fr;gap:13px}.comm-grid-3{display:grid;grid-template-columns:repeat(3,1fr);gap:13px}.comm-table-wrap{overflow:auto}.comm-table{width:100%;font-size:12px;border-collapse:collapse}.comm-table th{text-align:left;color:#777e96;font-size:9px;text-transform:uppercase;letter-spacing:.07em;padding:10px;border-bottom:1px solid rgba(255,255,255,.08)}.comm-table td{padding:11px 10px;border-bottom:1px solid rgba(255,255,255,.055);vertical-align:top}.comm-table tr:last-child td{border-bottom:0}.comm-empty{padding:30px;text-align:center;color:#858ba2}.comm-progress{height:8px;background:rgba(255,255,255,.06);border-radius:99px;overflow:hidden}.comm-progress>span{display:block;height:100%;background:linear-gradient(90deg,#6542dd,#51cadf);border-radius:inherit}.comm-muted{color:#7d839a;font-size:11px}.comm-system-links{display:flex;gap:8px;justify-content:flex-end}.comm-system-links a{color:#747a92;font-size:11px;text-decoration:none}.comm-email-preview{border:1px solid rgba(255,255,255,.09);border-radius:14px;overflow:hidden}.comm-email-subject{padding:12px 14px;background:rgba(9,12,27,.8);display:flex;gap:8px}.comm-email-body{padding:22px;background:#fff;color:#151515;min-height:150px}@media(max-width:1000px){.comm-stats.six{grid-template-columns:repeat(3,1fr)}.comm-grid-3{grid-template-columns:1fr 1fr}}@media(max-width:700px){.comm-hero{padding:23px 20px}.comm-hero-top{flex-direction:column}.comm-flow,.comm-stats,.comm-stats.six,.comm-grid-2,.comm-grid-3{grid-template-columns:1fr}.comm-toolbar .btn{width:100%}.comm-section-head{align-items:flex-start;flex-direction:column}}
.comm-actions .comm-delete{color:#f2a0ad;border-color:rgba(221,81,104,.3);background:rgba(221,81,104,.07)}.comm-actions .comm-delete:hover{color:#fff;border-color:rgba(221,81,104,.58);background:rgba(221,81,104,.18)}
</style>
<?php
if (!function_exists('communication_ui_flow')) {
    function communication_ui_flow($active)
    {
        $items = array('contacts'=>array('Paso 1','Contactos','superadmin_emails_db.php'),'audiences'=>array('Paso 2','Audiencias','comunicacion_audiencias.php'),'templates'=>array('Paso 3','Plantillas','comunicacion_plantillas.php'),'campaigns'=>array('Paso 4','Campañas','comunicacion_campanas.php'));
        echo '<nav class="comm-flow">';
        foreach ($items as $key => $item) echo '<a'.($active===$key?' class="active"':'').' href="'.e($item[2]).'"><small>'.e($item[0]).'</small><strong>'.e($item[1]).'</strong></a>';
        echo '</nav>';
    }
}
if (!function_exists('communication_ui_ops_nav')) {
    function communication_ui_ops_nav($active)
    {
        $items = array('engine'=>array('Estado del motor','comunicacion_estado_motor.php'),'history'=>array('Historial y métricas','comunicacion_historial.php'),'health'=>array('Diagnóstico','comunicacion_healthcheck.php'));
        echo '<nav class="comm-ops-nav">';
        foreach ($items as $key => $item) echo '<a'.($active===$key?' class="active"':'').' href="'.e($item[1]).'">'.e($item[0]).'</a>';
        echo '</nav>';
    }
}
?>
