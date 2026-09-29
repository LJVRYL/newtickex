(function(){
'use strict';
var form=document.querySelector('.event-create form'), sections=Array.from(form.querySelectorAll('.create-section'));
if(sections.length!==4)return;
var identity=sections[0],planning=sections[1],location=sections[2],presentation=sections[3];
planning.after(presentation);var steps=[identity,planning,presentation,location];
var footer=form.querySelector('.create-actions'),submit=footer.querySelector('[type=submit]');
var review=document.createElement('section');review.className='card create-section';review.innerHTML='<h2>Revisá tu evento</h2><p>Se guarda sin publicar. Después configurás las entradas.</p><div id="wizard-review"></div>';
footer.before(review);steps.push(review);
var titles=['Identidad','Planificación','Presentación','Lugar','Revisar'];
steps.forEach(function(s,i){var badge=s.querySelector('.section-number');if(badge)badge.textContent=i+1;});
location.querySelector('h2').textContent='Lugar';
location.querySelector('.section-head p').textContent='Buscá la dirección en el mapa o completala después.';
var fields=location.querySelector('.field-grid'),extra=document.createElement('details');extra.className='wizard-extra';extra.innerHTML='<summary>Información adicional para el público (opcional)</summary>';
Array.from(fields.children).slice(2).forEach(function(field){extra.appendChild(field);});location.appendChild(extra);
var max=form.querySelector('[name=max_entradas_compra]');if(max)max.closest('.field').hidden=true;
var end=form.querySelector('[name=fecha_hasta]'),endField=end.closest('.field'),start=form.querySelector('[name=fecha_desde]');
var toggle=document.createElement('label');toggle.className='wizard-toggle';toggle.innerHTML='<input type="checkbox" id="wizard-multiday"> Termina otro día';endField.before(toggle);
var multi=toggle.querySelector('input');multi.checked=!!end.value&&end.value!==start.value;
function endToggle(){endField.hidden=!multi.checked;if(!multi.checked)end.value=start.value;}
multi.addEventListener('change',endToggle);start.addEventListener('change',endToggle);endToggle();
var mapButton=document.createElement('button');mapButton.type='button';mapButton.className='btn secondary';mapButton.textContent='Verificar dirección en Google Maps';
var map=document.createElement('iframe');map.title='Vista del lugar en Google Maps';map.hidden=true;map.loading='lazy';map.referrerPolicy='no-referrer';map.className='wizard-map';
fields.after(mapButton,map);mapButton.addEventListener('click',function(){var address=form.elements.direccion.value.trim();if(!address){form.elements.direccion.focus();return;}map.src='https://www.google.com/maps?q='+encodeURIComponent(address)+'&output=embed';map.hidden=false;});
var preview=document.createElement('img');preview.alt='Vista previa del flyer';preview.className='wizard-flyer';preview.hidden=true;form.elements.flyer.after(preview);var blob=null;
form.elements.flyer.addEventListener('change',function(){if(blob)URL.revokeObjectURL(blob);var file=this.files[0];preview.hidden=true;if(file&&['image/jpeg','image/png'].includes(file.type)&&file.size<=2097152){blob=URL.createObjectURL(file);preview.src=blob;preview.hidden=false;}});
var toolbar=document.createElement('div');toolbar.className='wizard-nav';toolbar.innerHTML='<button type="button" class="btn secondary" id="wizard-back">← Atrás</button><button type="button" class="btn secondary" id="wizard-skip">Completar después</button><button type="button" class="btn" id="wizard-next">Continuar →</button>';
footer.prepend(toolbar);var save=document.createElement('button');save.type='button';save.className='btn secondary';save.textContent='Guardar borrador y salir';footer.appendChild(save);
var status=document.createElement('p');status.id='wizard-save-status';status.setAttribute('role','status');footer.appendChild(status);
var error=document.createElement('p');error.className='flash err';error.setAttribute('role','alert');error.hidden=true;toolbar.before(error);
var progress=document.querySelector('.create-progress');progress.innerHTML='';progress.setAttribute('aria-label','Progreso de creación');
var label=document.createElement('p');label.className='wizard-progress';progress.appendChild(label);
document.querySelector('.create-hero h1').textContent='Tu evento, paso a paso';
document.querySelector('.create-hero>p').textContent='Empezá por el nombre. Podés guardar y continuar más tarde.';
if(/^event_flyers\/wizard_[a-f0-9]{32}\.(png|jpg)$/.test(form.dataset.savedFlyer||'')){preview.src=form.dataset.savedFlyer;preview.hidden=false;}
var index=Number(form.dataset.wizardStep)||0,busy=false,savedFile=null;
function summary(){var box=document.getElementById('wizard-review');box.textContent='';[['Nombre','nombre',0],['Fecha','fecha_desde',1],['Apertura','hora_puertas',1],['Cupo','capacidad_total',1],['Descripción','descripcion',2],['Lugar','lugar',3],['Dirección','direccion',3]].forEach(function(row){var line=document.createElement('p'),b=document.createElement('button');line.appendChild(document.createTextNode(row[0]+': '+(form.elements[row[1]].value||'Pendiente')+' '));b.type='button';b.textContent='Editar';b.className='wizard-edit';b.onclick=function(){show(row[2]);};line.appendChild(b);box.appendChild(line);});}
function show(i){index=Math.max(0,Math.min(4,i));steps.forEach(function(s,n){s.hidden=n!==index;});label.textContent='Paso '+(index+1)+' de 5 · '+titles[index];document.getElementById('wizard-back').hidden=index===0;document.getElementById('wizard-skip').hidden=index===0||index===4;document.getElementById('wizard-next').hidden=index===4;submit.hidden=index!==4;error.hidden=true;if(index===4)summary();var h=steps[index].querySelector('h2');if(h){h.tabIndex=-1;h.focus({preventScroll:true});} }
function validateStep(){var inputs=steps[index].querySelectorAll('input,textarea');for(var input of inputs){if(input.type==='file')continue;if(!input.checkValidity()){input.reportValidity();return false;}}if(index===1&&end.value<start.value){error.textContent='La finalización no puede ser anterior al inicio.';error.hidden=false;return false;}return true;}
async function persist(){if(busy)return false;busy=true;status.textContent='Guardando…';var data=new FormData(form);if(form.elements.flyer.files[0]===savedFile)data.delete('flyer');data.set('wizard_action','save');data.set('wizard_step',index);try{var response=await fetch(form.action||location.href,{method:'POST',body:data,credentials:'same-origin'});var result=await response.json();if(!response.ok||!result.ok)throw Error(result.error||'No se pudo guardar.');savedFile=form.elements.flyer.files[0]||null;status.textContent='Borrador guardado. Podés retomarlo desde Crear evento.';return true;}catch(e){status.textContent='No se guardó: '+e.message;return false;}finally{busy=false;}}
document.getElementById('wizard-next').onclick=async function(){if(busy||!validateStep())return;if(await persist())show(index+1);};
document.getElementById('wizard-skip').onclick=async function(){if(busy)return;if(await persist())show(index+1);};
document.getElementById('wizard-back').onclick=function(){if(!busy)show(index-1);};
save.onclick=async function(){if(await persist())window.location.href='panel_admin.php';};
form.addEventListener('submit',function(e){if(busy){e.preventDefault();return;}if(index!==4){e.preventDefault();document.getElementById('wizard-next').click();return;}for(var i=0;i<4;i++){var invalid=steps[i].querySelector(':invalid');if(invalid){e.preventDefault();show(i);invalid.reportValidity();return;}}});
var style=document.createElement('style');style.textContent='.event-create [hidden]{display:none!important}.create-layout{grid-template-columns:minmax(0,760px);justify-content:center}.create-aside{display:none}.create-hero{padding:24px}.create-progress{display:block;margin-top:16px}.wizard-progress{margin:0!important;font-weight:700}.create-actions{display:flex;flex-direction:column;align-items:stretch}.wizard-nav{display:flex;gap:10px;flex-wrap:wrap;justify-content:space-between}.wizard-nav .btn{min-width:0}.wizard-toggle{display:flex;align-items:center;gap:8px}.wizard-toggle input{width:auto}.wizard-extra{margin-top:22px}.wizard-extra summary{cursor:pointer;padding:12px 0}.wizard-extra .field{margin-top:16px}.wizard-map{width:100%;height:260px;border:0;border-radius:14px;margin-top:16px}.wizard-flyer{max-width:100%;max-height:230px;border-radius:12px;margin-top:12px}.wizard-edit{color:#baa6ff;border:0;background:none;cursor:pointer}#wizard-save-status{font-size:13px;line-height:1.5}.create-section h2:focus{outline:none}';document.head.appendChild(style);
show(index);
})();
