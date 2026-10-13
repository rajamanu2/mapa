// Execute production directory/render/intent code in an isolated DOM facade. No HTTP or accounts.
'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),vm=require('node:vm'),crypto=require('node:crypto');
let checks=0,networkCalls=0,document;
const check=(value,label)=>{assert.ok(value,label);checks++;};
class Element {
    constructor(tag){this.tagName=tag.toUpperCase();this.children=[];this.parentElement=null;this.dataset={};this.attributes={};this.listeners={};this.disabled=false;this.checked=false;this.hidden=false;this._text='';this.value='';this.className='';this.classList={toggle(){},add(){},remove(){}};}
    get textContent(){return this._text+this.children.map(node=>node.textContent).join('');}
    set textContent(value){this.replaceChildren();this._text=String(value??'');}
    set innerHTML(_){throw new Error('Candidate strings must never enter an HTML parser.');}
    append(...items){for(const node of items){if(node.parentElement)node.remove();node.parentElement=this;this.children.push(node);}}
    replaceChildren(...items){for(const node of this.children)node.parentElement=null;this.children=[];this._text='';this.append(...items);}
    insertBefore(node,reference){if(node.parentElement)node.remove();const index=this.children.indexOf(reference);assert.ok(index>=0,'insertBefore reference must be a direct child');node.parentElement=this;this.children.splice(index,0,node);}
    remove(){if(this.parentElement){this.parentElement.children=this.parentElement.children.filter(node=>node!==this);this.parentElement=null;}}
    setAttribute(key,value){this.attributes[key]=String(value);}
    getAttribute(key){return this.attributes[key]??null;}
    removeAttribute(key){delete this.attributes[key];}
    addEventListener(type,callback){(this.listeners[type]??=[]).push(callback);}
    dispatchEvent(event){for(const callback of this.listeners[event.type]||[])callback({...event,target:event.target||this});return true;}
    click(force=false){if(!force&&this.disabled)return;this.dispatchEvent({type:'click',preventDefault(){}});}
    focus(){document.activeElement=this;}
    querySelectorAll(selector){const tags=selector.split(',').map(s=>s.trim().replace(/:.*/,'').toUpperCase());return walk(this).filter(node=>node!==this&&tags.includes(node.tagName));}
    querySelector(selector){return this.querySelectorAll(selector)[0]||null;}
}
function walk(node){return[node,...node.children.flatMap(walk)];}
document={documentElement:new Element('html'),body:new Element('body'),readyState:'loading',visibilityState:'visible',createElement:tag=>new Element(tag),querySelector:()=>null,getElementById:()=>null,addEventListener(){}};
const window={addEventListener(){},dispatchEvent(){},confirm:()=>true};
const sandbox={document,window,location:{pathname:'/fleet-ops',assign(){throw new Error('No navigation in isolated QA.');}},crypto,console,Blob,AbortController,TextEncoder,URL,
    MutationObserver:class{observe(){}},requestAnimationFrame(){},setTimeout,clearTimeout,CustomEvent:class{},fetch(){networkCalls++;throw new Error('No network in isolated QA.');}};
const hooks=`
const __attempts=[];
// Stop at the HTTP boundary only; production mutate/intent guards remain exercised.
sendPending=()=>{__attempts.push({path:pending.intent.path,body:pending.intent.body});};
window.__directoryQa={
 seed:(data)=>{session={user:'user_DEMO_dispatcher',company:'company_DEMO',role:'admin'};selected=data.call;candidates=data.candidates||[];unavailable=data.unavailable||[];dirty=!!data.dirty;pending=data.pending||null;busy=!!data.busy;__attempts.length=0;const parent=el('section');document.body.replaceChildren(parent);renderCandidates(parent,data.call);return parent;},
 patch:(data)=>{if('selected'in data)selected=data.selected;if('candidates'in data)candidates=data.candidates;if('dirty'in data)dirty=data.dirty;if('pending'in data)pending=data.pending;if('busy'in data)busy=data.busy;if('session'in data)session=data.session;},
 mount:()=>mount(),entry:()=>entry,render:(parent,call)=>renderCandidates(parent,call),attempts:()=>__attempts,state:()=>({pending,busy,selected}),directory:ambulanceDirectory
};
`;
const source=fs.readFileSync(path.join(__dirname,'../public/mapa-voice.js'),'utf8');
check(/\}\)\(\);\s*$/.test(source),'recognize production closure before VM-only instrumentation');
vm.createContext(sandbox);vm.runInContext(source.replace(/\}\)\(\);\s*$/,hooks+'})();'),sandbox,{filename:'mapa-voice.production.js'});
const qa=window.__directoryQa;
const call={id:'call_DEMO_directory',state:'ready',revision:7,demo:false,order_id:null,review:{pickup_address:'DEMO caller-confirmed pickup',priority:'urgent',vehicle_requirement:'any'}};
function ready(n,area='Paderu'){return{id:'driver_DEMO_'+n,vehicle_id:'vehicle_DEMO_'+n,vehicle_name:'DEMO ambulance '+n,vehicle_registration:'AP31AB'+String(n).padStart(4,'0'),vehicle_type:'Special',name:'DEMO crew '+n,hospital_name:'PHC DEMO '+area,base_name:'DEMO '+area,mandal:area,secretariat:'DEMO Secretariat',simulated:false,distance_km:3.2,fix_recorded_at:'2026-10-01T00:00:00Z'};}
const a={...ready(1),'vehicle_registration':'AP 31-AB.0001',hospital_name:'PHC Madagada'},b=ready(2,'Araku');
const blocked={vehicle_id:'vehicle_DEMO_blocked',vehicle_name:'DEMO bike ambulance',vehicle_registration:'AP31AB0003',vehicle_type:'Feeder/Bike',base_name:'PHC Madagada',mandal:'Paderu',simulated:false,message:'DEMO crew has not started duty.',code:'off_duty'};
function seed(extra={}){return qa.seed({call:{...call},candidates:[a,b],unavailable:[blocked],...extra});}
function ui(root){const all=walk(root);return{search:all.find(n=>n.name==='ambulance_search'),chooser:all.find(n=>n.name==='ambulance_choice'),readyOnly:all.find(n=>n.type==='checkbox'&&n.parentElement.textContent==='Show ready to assign only'),
    confirm:all.find(n=>n.type==='checkbox'&&n.parentElement.textContent.startsWith('I confirm dispatch')),dispatch:all.find(n=>n.tagName==='BUTTON'&&n.textContent==='Confirm dispatch'),count:all.find(n=>n.id==='mapa-ambulance-match-count')};}
function choose(u,value){u.chooser.value=value;u.chooser.dispatchEvent({type:'change'});}
function confirm(u,value=true){u.confirm.checked=value;u.confirm.dispatchEvent({type:'change'});}
function search(u,value){u.search.value=value;u.search.dispatchEvent({type:'input'});}
function options(u){return walk(u.chooser).filter(n=>n.tagName==='OPTION'&&n.value);}
function armed(){const root=seed(),u=ui(root);choose(u,a.vehicle_id);confirm(u);return{root,u};}

let root=seed(),u=ui(root);
check(u.count.textContent==='3 ambulances found · 2 ready to assign · 1 not ready','ready and unavailable inventory counts visible');
check(options(u).length===2&&!u.dispatch.disabled===false,'render does not auto-select or enable dispatch');
check(u.confirm.disabled&&qa.attempts().length===0,'explicit selection and confirmation required, no automatic dispatch');
check(!options(u).some(n=>n.value===blocked.vehicle_id),'unavailable ambulance cannot appear in assignment chooser');
check(root.textContent.includes(blocked.message),'unavailable reason is visible as text');
root=seed({unavailable:[{...blocked,vehicle_online:false,crew_link_status:'linked',crew_on_duty:false,crew_gps_status:'stale'}]});u=ui(root);
check(root.textContent.includes('Vehicle marked offline')&&root.textContent.includes('Crew off duty')&&root.textContent.includes('Crew phone GPS stale'),'separate native vehicle, duty and GPS evidence shown without claiming eligibility');
search(u,'offline');check(u.count.textContent.startsWith('1 ambulance found')&&options(u).length===0,'offline inventory is searchable but cannot be assigned');
root=seed();u=ui(root);
search(u,'paderu');check(options(u).length===1&&options(u).every(n=>n.value!==b.vehicle_id),'area filter retains ready matches and blocked reasons');
search(u,'Madagada');check(options(u).length===1,'hospital/base filter');
search(u,'AP31AB0001');check(options(u).length===1&&options(u)[0].value===a.vehicle_id,'compact normalized plate filter');
search(u,'paderu ap31ab0001');check(options(u).length===1,'area and plate terms require all matches');
search(u,'araku ap31ab0001');check(options(u).length===0,'incompatible area/plate terms give no false row');
check(u.chooser.disabled&&u.confirm.disabled&&u.dispatch.disabled&&root.textContent.includes('No matching ambulance records'),'empty filter closes dispatch controls');
search(u,'');u.readyOnly.checked=true;u.readyOnly.dispatchEvent({type:'change'});check(options(u).length===2&&options(u).every(n=>!n.disabled),'ready-only hides unavailable options');
check(qa.attempts().length===0,'all filter interactions perform no dispatch or network request');

const bulk=Array.from({length:260},(_,i)=>ready(1000+i,'DEMO Bulk Area'));
const manyBlocked=Array.from({length:80},(_,i)=>({...blocked,vehicle_id:'vehicle_DEMO_blocked_'+i,vehicle_registration:'AP99QA'+String(i).padStart(4,'0')}));
root=seed({candidates:bulk,unavailable:manyBlocked});u=ui(root);
check(options(u).length===8,'production dropdown remains bounded even with hundreds of fleet records');
check(!options(u).some(n=>n.value===bulk.at(-1).vehicle_id),'distant candidate requires search rather than oversized popup');
check(u.count.textContent==='340 ambulances found · 260 ready to assign · 80 not ready','large-directory count includes all scoped rows');
search(u,bulk.at(-1).vehicle_registration);check(options(u).length===1&&options(u)[0].value===bulk.at(-1).vehicle_id,'last large-directory row is searchable');
choose(u,bulk.at(-1).vehicle_id);search(u,'');check(options(u).length===9&&u.chooser.value===bulk.at(-1).vehicle_id,'searched selection stays visible when the search is cleared');

({root,u}=armed());check(!u.dispatch.disabled,'only explicit eligible driver selection plus confirmation enables dispatch');
check(qa.attempts().length===0,'confirming checkbox alone performs no dispatch');
search(u,'Paderu');check(!u.confirm.checked&&u.dispatch.disabled&&u.chooser.value===a.vehicle_id,'filter clears confirmation even when selected ready row remains');
confirm(u);choose(u,b.vehicle_id);check(!u.confirm.checked&&u.dispatch.disabled,'changing selection clears confirmation');
search(u,'');choose(u,b.vehicle_id);confirm(u);search(u,'Paderu');check(u.chooser.value===''&&!u.confirm.checked&&u.dispatch.disabled,'filter excluding selection clears selection and confirmation');
({root,u}=armed());u.readyOnly.checked=true;u.readyOnly.dispatchEvent({type:'change'});check(!u.confirm.checked&&u.dispatch.disabled,'ready-only change also clears confirmation');
({root,u}=armed());choose(u,blocked.vehicle_id);confirm(u);u.dispatch.click(true);check(u.confirm.disabled&&u.dispatch.disabled&&qa.attempts().length===0,'forged unavailable selection cannot dispatch');
({root,u}=armed());choose(u,'vehicle_DEMO_missing');confirm(u);u.dispatch.click(true);check(qa.attempts().length===0,'unknown dropdown value cannot dispatch');

for(const [label,patch] of [['dirty',{dirty:true}],['revision',{selected:{...call,revision:8}}],['already dispatched',{selected:{...call,order_id:'order_DEMO_existing'}}],['wrong demo mode',{selected:{...call,demo:true}}],['candidate removed',{candidates:[b]}],['pending',{pending:{intent:{path:'/DEMO/original',body:'DEMO immutable earlier action'}}}],['busy',{busy:true}],['logged out',{session:null}]]){
    ({root,u}=armed());qa.patch(patch);u.dispatch.click(true);check(qa.attempts().length===0,label+' blocks stale rendered dispatch handler');
    if(label==='pending')check(qa.state().pending.intent.body==='DEMO immutable earlier action','new click cannot replace pending original body');
}
root=seed({dirty:true});check(root.children.length===0,'dirty caller review hides candidate controls');
root=seed({call:{...call,state:'needs_review'}});check(root.children.length===0,'unreviewed call cannot render dispatch');
root=seed({call:{...call,review:null}});check(root.children.length===0,'missing confirmed review cannot render dispatch');
root=seed({pending:{intent:{path:'/DEMO/original',body:'DEMO original'}}});u=ui(root);check(u.search.disabled&&u.readyOnly.disabled&&u.chooser.disabled&&u.confirm.disabled&&u.dispatch.disabled,'pending immutable action disables all dispatch input');

({root,u}=armed());u.dispatch.click();check(qa.attempts().length===1,'explicit valid button click creates exactly one production intent');
let intent=qa.attempts()[0],body=JSON.parse(intent.body);
check(intent.path==='/calls/'+call.id+'/dispatch'&&body.driver_id===a.id&&body.driver_id!==a.vehicle_id,'intent uses selected native driver, with correct call path');
check(body.expected_revision===7&&body.confirmed===true&&/^[a-f0-9-]{36}$/i.test(body.action_id),'intent includes reviewed revision, confirmation and immutable action key');
u.dispatch.click(true);check(qa.attempts().length===1&&qa.state().pending.intent.body===intent.body,'double click cannot create or replace a pending dispatch intent');

const payload='<img src=x onerror="throw DEMO">';
root=seed({candidates:[{...a,vehicle_name:payload,hospital_name:'<svg/onload=DEMO>',name:'<script>DEMO</script>'}],unavailable:[{...blocked,message:payload}]});u=ui(root);
check(root.textContent.includes(payload)&&walk(root).every(n=>!['SCRIPT','IMG','SVG'].includes(n.tagName)),'candidate and unavailable text never creates executable elements');
choose(u,a.vehicle_id);check(root.textContent.includes('<script>DEMO</script>')&&root.textContent.includes('<svg/onload=DEMO>'),'selected details remain literal safe text');
root=seed({candidates:[a,{...b,simulated:true}],unavailable:[blocked,{...blocked,vehicle_id:'vehicle_DEMO_training',simulated:true}]});u=ui(root);check(options(u).length===1&&u.count.textContent.startsWith('2 ambulances found'),'live UI excludes simulated ready and unavailable rows');
root=seed({candidates:[a],unavailable:[{...blocked,vehicle_id:a.vehicle_id}]});u=ui(root);check(options(u).length===0&&u.chooser.disabled,'conflicting availability rows fail closed');
check(networkCalls===0,'all assertions used zero network calls and no accounts');

// The shortcut must never intercept resource pagination when native New Call is present.
let nativeNewCall={};
document.querySelector=selector=>selector==='.mapa-attribution'?{}:(selector.includes('fleet-ops-sidebar-primary-action')?nativeNewCall:null);
qa.mount();check(!qa.entry(),'native New Call prevents an overlapping floating shortcut');
nativeNewCall=null;qa.mount();check(qa.entry()&&qa.entry().hidden===false,'fallback shortcut remains available without native create action');
nativeNewCall={};qa.mount();check(qa.entry().hidden===true,'native create action returning hides existing fallback');
nativeNewCall=null;qa.mount();check(qa.entry().hidden===false,'fallback can reappear on eligible native page');

console.log(checks+' ambulance-directory production DOM/intent checks passed; isolated fixtures, zero network.');
