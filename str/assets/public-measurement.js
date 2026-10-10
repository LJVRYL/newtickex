(function (w, d) {
  'use strict';
  var cfg = w.geGrowthConfig || {}, granted = false, loaded = false, pageViewed = false, seen = Object.create(null);
  var params=new URLSearchParams(w.location.search),sensitive=/^\/(wp-admin|gestion)(\/|$)|^\/wp-login\.php$/.test(w.location.pathname);
  ['token','key','password','resetpass','reset_password','email','access_token','auth','order_key'].forEach(function(k){if(params.has(k))sensitive=true;});
  var events = ['contact_click','landing_quote_click','landing_shop_click','registration_started','registration_completed','quote_request_started','quote_request_submitted','company_account_started','company_account_created','category_view','product_view','add_to_cart','begin_checkout'];
  function cookie(name, value, days) { d.cookie = name + '=' + encodeURIComponent(value) + '; Path=/; SameSite=Lax; Max-Age=' + (days * 86400) + (w.location.protocol === 'https:' ? '; Secure' : ''); }
  function read(name) { var p = d.cookie.split('; ').find(function(x) {return x.indexOf(name + '=') === 0;}); try {return p ? decodeURIComponent(p.slice(name.length+1)) : '';} catch(e) {return '';} }
  function clearGaCookies(){d.cookie.split('; ').forEach(function(pair){var name=pair.split('=')[0];if(!/^_ga(?:_[A-Z0-9]+)?$/.test(name))return;cookie(name,'',0);if(w.location.hostname){d.cookie=name+'=; Path=/; Max-Age=0; SameSite=Lax; Domain='+w.location.hostname;d.cookie=name+'=; Path=/; Max-Age=0; SameSite=Lax; Domain=.'+w.location.hostname;if(/(^|\.)tickex\.com\.ar$/.test(w.location.hostname))d.cookie=name+'=; Path=/; Max-Age=0; SameSite=Lax; Domain=.tickex.com.ar';}});}
  function touch() {
    var p = new URLSearchParams(w.location.search), out = {landing: /^\/[a-zA-Z0-9/_\-]{0,180}$/.test(w.location.pathname) ? w.location.pathname : '/'};
    ['utm_source','utm_medium','utm_campaign','utm_content','utm_term','gclid','gbraid','wbraid'].forEach(function(k){var v=p.get(k); if(v && /^[a-zA-Z0-9_.~+\-]{1,100}$/.test(v)) out[k]=v;});
    return out;
  }
  function persist() { var old;try{old=JSON.parse(read('ge_growth_touch'));}catch(e){}var current=touch(),now=Math.floor(Date.now()/1000),at=new Date().toISOString(); if(!old || !Number.isFinite(old.expires_at) || old.expires_at<=now)old={first:current,last:current,first_at:at,last_at:at,expires_at:now+30*86400};else if(current.utm_source || current.gclid || current.gbraid || current.wbraid){old.last=current;old.last_at=at;}cookie('ge_growth_touch',JSON.stringify(old),(old.expires_at-now)/86400); }
  function load() {
    if(loaded || sensitive || !/^G-[A-Z0-9]+$/.test(cfg.measurementId || ''))return;
    loaded=true; w.dataLayer=w.dataLayer||[];w.gtag=w.gtag||function(){w.dataLayer.push(arguments);};
    w.gtag('consent','default',{analytics_storage:'denied',ad_storage:'denied',ad_user_data:'denied',ad_personalization:'denied'});
    w.gtag('consent','update',{analytics_storage:'granted'});w.gtag('js',new Date());
    var config={send_page_view:false,page_location:w.location.origin+touch().landing,page_referrer:'',page_title:'',allow_google_signals:false,allow_ad_personalization_signals:false,cookie_expires:180*86400,cookie_update:false};
    var campaign=touch(),fields={utm_source:'campaign_source',utm_medium:'campaign_medium',utm_campaign:'campaign_name',utm_content:'campaign_content',utm_term:'campaign_term'};
    Object.keys(fields).forEach(function(k){if(campaign[k])config[fields[k]]=campaign[k];});
    w.gtag('config',cfg.measurementId,config);
    w.gtag('get',cfg.measurementId,'session_id',function(id){if(granted && /^\d{1,12}$/.test(String(id)))cookie('ge_growth_session',String(id),1);});
    var script=d.createElement('script');script.async=true;script.src='https://www.googletagmanager.com/gtag/js?id='+cfg.measurementId;d.head.appendChild(script);
  }
  var withdrawal = Promise.resolve(true);
  function permission(action) {
    return w.fetch('/measurement_permission.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action='+action,cache:'no-store'}).then(function(response){if(!response.ok)throw new Error('No se pudo guardar la preferencia');return response.json();}).then(function(result){if(!result.ok)throw new Error('No se pudo guardar la preferencia');cookie('ge_sales_scope_v1',action==='grant'?'granted':'denied',180);return true;});
  }
  function consent(value) {
    if(cfg.measurementReady!==true)return;
    granted=value === true;cookie('ge_growth_consent',granted?'granted':'denied',180);
    if(granted){w['ga-disable-'+cfg.measurementId]=false;persist();load();if(w.gtag&&loaded&&!pageViewed){pageViewed=true;w.gtag('event','page_view',{send_to:cfg.measurementId,page_location:w.location.origin+touch().landing,page_referrer:'',page_title:''});}if(cfg.pageEvent)emit({event:cfg.pageEvent,product_id:cfg.pageId});companyEvents();}else{if(read('ge_sales_scope_v1')==='granted'){withdrawal=permission('revoke');withdrawal.catch(function(){banner();});}w['ga-disable-'+cfg.measurementId]=true;cookie('ge_growth_touch','',0);cookie('ge_growth_session','',0);clearGaCookies();if(w.gtag)w.gtag('consent','update',{analytics_storage:'denied',ad_storage:'denied',ad_user_data:'denied',ad_personalization:'denied'});}
  }
  function emit(detail) {
    if(!granted || !detail || events.indexOf(detail.event)<0)return;
    // Existing funnel script already emits dataLayer. This adapter only exports
    // the documented event name and a path, never arbitrary event payloads.
    var key=detail.event+':'+(detail.request_id||detail.product_id||'');
    if(detail.event!=='add_to_cart' && seen[key])return;seen[key]=true;
    var payload={send_to:cfg.measurementId,page_location:w.location.origin+touch().landing,page_referrer:'',page_title:''};
    if(Number.isInteger(Number(detail.product_id)) && Number(detail.product_id)>0)payload.items=[{item_id:String(Number(detail.product_id))}];
    var names={product_view:'view_item',category_view:'view_item_list'};
    if(w.gtag && loaded)w.gtag('event',names[detail.event]||detail.event,payload);
  }
  w.geGrowth={setAnalyticsConsent:consent,emit:emit};
  // CMP is the authority: no banner and no implied acceptance is introduced.
  d.addEventListener('ge:analytics-consent',function(e){consent(e.detail === true);});
  d.addEventListener('graphex:funnel',function(e){emit(e.detail);});
  d.addEventListener('click',function(e){var a=e.target.closest && e.target.closest('a[data-funnel-event]');if(a)emit({event:a.dataset.funnelEvent});var contact=e.target.closest && e.target.closest('a[href]');if(contact && /^(https:\/\/wa\.me\/|tel:|mailto:)/.test(contact.getAttribute('href')||''))emit({event:'contact_click'});});
  // Woo translates classic jQuery additions to the public Blocks DOM event.
  // Coalesce the synchronous pair once; subsequent user actions get new tasks.
  var pendingAdd=null;
  function added(productId){if(pendingAdd){if(productId)pendingAdd.product_id=productId;return;}pendingAdd={event:'add_to_cart',product_id:productId};w.setTimeout(function(){var event=pendingAdd;pendingAdd=null;emit(event);},0);}
  d.addEventListener('wc-blocks_added_to_cart',function(){added();});
  if(w.jQuery)w.jQuery(d.body).on('added_to_cart',function(e,fragments,hash,button){added(button && button.data('product_id'));});
  function companyEvents(){var field=d.querySelector('.ge-quick-register input[name="cuenta"][value="empresa"]');if(field)emit({event:'company_account_started'});if(cfg.companyCompleted)emit({event:'company_account_created'});}
  if(cfg.analyticsConsent === true)consent(true);
  if(cfg.measurementReady!==true)return;
  function banner() {
    if(d.querySelector('section[aria-label="Preferencias de medición"]'))return;
    var box=d.createElement('section');box.setAttribute('aria-label','Preferencias de medición');
    box.style.cssText='position:fixed;bottom:16px;left:16px;right:16px;z-index:99999;background:#0E1424;color:#F7F8FC;padding:16px;border:1px solid #A8B0C3;border-radius:12px;box-shadow:0 4px 20px #0002;max-width:680px';
    var text=d.createElement('p');text.textContent='¿Nos permitís usar Google Analytics para medir visitas, interacciones y compras confirmadas? En las compras enviamos un identificador seudónimo, productos, moneda e importe de los productos; sin nombre, DNI ni correo. Es opcional. Recordamos la procedencia hasta 30 días y tu elección durante 180 días. Podés rechazar o retirar el permiso en Preferencias de medición. Retirarlo detiene los nuevos envíos y elimina las cookies de medición de este navegador; no borra información ya recibida por Google.';box.appendChild(text);
    if(cfg.privacyUrl){var link=d.createElement('a');link.href=cfg.privacyUrl;link.textContent='Política de privacidad';link.style.color='#90F3AF';box.appendChild(link);}
    function choice(label,value){var button=d.createElement('button');button.type='button';button.textContent=label;button.style.cssText='min-height:44px;padding:10px 14px;margin:4px;border:1px solid #A8B0C3;border-radius:12px;background:#0E1424;color:#F7F8FC;font:inherit';button.onclick=function(){Array.from(box.querySelectorAll('button')).forEach(function(b){b.disabled=true;});var hadSales=read('ge_sales_scope_v1')==='granted';consent(value);(value?permission('grant'):(hadSales?withdrawal:permission('revoke'))).then(function(){box.remove();}).catch(function(){Array.from(box.querySelectorAll('button')).forEach(function(b){b.disabled=false;});text.textContent='La medición del navegador se detuvo si rechazaste. No pudimos guardar la preferencia en el servidor; reintentá para detener también los envíos de compras pendientes.';});};box.appendChild(button);}
    choice('Aceptar medición',true);choice('Rechazar medición',false);d.body.appendChild(box);
  }
  var prefs=d.createElement('button');prefs.type='button';prefs.textContent='Preferencias de medición';prefs.style.cssText='position:fixed;bottom:4px;right:4px;z-index:99998;min-height:44px;padding:8px 12px;background:#0E1424;color:#F7F8FC;border:1px solid #A8B0C3;border-radius:12px;font:inherit';prefs.onclick=banner;d.body.appendChild(prefs);
  if(!read('ge_growth_consent') || !read('ge_sales_scope_v1'))banner();
})(window, document);


