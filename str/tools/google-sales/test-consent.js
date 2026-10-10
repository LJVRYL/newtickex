const fs=require('fs'),vm=require('vm'),assert=require('assert');
const source=fs.readFileSync(process.argv[2],'utf8');
function fixture(old=false,fail=false){
  const jar=old?{ge_growth_consent:'granted'}:{},nodes=[],requests=[],scripts=[];
  const node=(tag)=>({tag,style:{},children:[],attributes:{},setAttribute(k,v){this.attributes[k]=v;},appendChild(n){this.children.push(n);},querySelectorAll(){return this.children.filter(n=>n.tag==='button');},remove(){this.removed=true;}});
  const d={createElement:node,addEventListener(){},querySelector(selector){return selector.startsWith('section')?nodes.find(n=>n.tag==='section'&&!n.removed)||null:null;},head:{appendChild(n){scripts.push(n);}},body:{appendChild(n){nodes.push(n);}}};
  Object.defineProperty(d,'cookie',{get:()=>Object.entries(jar).map(([k,v])=>k+'='+v).join('; '),set(v){const part=v.split(';')[0],i=part.indexOf('=');jar[part.slice(0,i)]=part.slice(i+1);if(v.includes('Max-Age=0'))delete jar[part.slice(0,i)];}});
  const w={location:{protocol:'https:',origin:'https://graphex.ar',hostname:'graphex.ar',pathname:'/',search:''},geGrowthConfig:{measurementId:'G-TEST',measurementReady:true,analyticsConsent:old},fetch(url,opts){requests.push({url,opts});return Promise.resolve({ok:!fail,json:()=>Promise.resolve({ok:true})});}};
  vm.runInNewContext(source,{window:w,document:d,URLSearchParams});
  return {w,d,jar,nodes,requests,scripts,box:()=>nodes.find(n=>n.tag==='section'&&!n.removed)};
}
async function drain(){for(let i=0;i<10;i++)await Promise.resolve();}
(async()=>{
 let f=fixture(true);assert(f.box(),'new sales notice even with old consent');assert.equal(f.requests.length,0,'old visits must not authorize sales');
 f.box().children.find(n=>n.textContent==='Aceptar medición').onclick();await drain();assert.equal(f.requests.length,1);assert.equal(f.requests[0].opts.body,'action=grant');assert.equal(f.jar.ge_sales_scope_v1,'granted');assert(!f.box());
 f.jar._ga='GA1.1.123.456';f.w.geGrowth.setAnalyticsConsent(false);await drain();assert.equal(f.requests.at(-1).opts.body,'action=revoke');assert.equal(f.jar.ge_sales_scope_v1,'denied');assert.equal(f.jar._ga,undefined);
 f=fixture(false,true);f.box().children.find(n=>n.textContent==='Aceptar medición').onclick();await drain();assert(f.box(),'failed server consent stays visible');assert.equal(f.jar.ge_sales_scope_v1,undefined,'failure cannot authorize sales');
 f=fixture();assert.equal(f.scripts.length,0);f.box().children.find(n=>n.textContent==='Rechazar medición').onclick();await drain();assert.equal(f.scripts.length,0);assert.equal(f.jar.ge_sales_scope_v1,'denied');
 console.log('PASS: old consent not reused, explicit server acknowledgement, withdrawal, failure recovery, rejection prevents Google');
})().catch(e=>{console.error(e);process.exit(1);});
