// Execute the production operator pickup flow with synthetic DOM and transport boundaries.
'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),vm=require('node:vm'),crypto=require('node:crypto');
let checks=0,networkCalls=0,document,confirmAnswer=true;
const check=(value,label)=>{assert.ok(value,label);checks++;};
function walk(node){return[node,...node.children.flatMap(walk)];}
function matches(node,selector){
    const parts=selector.trim().split(/\s+/);let current=node;
    function simple(n,s){const attr=s.match(/\[([\w-]+)="([^"]*)"\]/);if(attr&&String(n[attr[1]]??n.attributes[attr[1]]??'')!==attr[2])return false;s=s.replace(/\[[^\]]+\]/g,'');if(s.includes(':not(:disabled)')&&n.disabled)return false;s=s.replace(/:not\(:disabled\)/g,'');const tag=s.match(/^[a-z]+/i)?.[0];if(tag&&n.tagName!==tag.toUpperCase())return false;const cls=s.match(/\.([\w-]+)/)?.[1];return !cls||n.className.split(/\s+/).includes(cls);}
    if(!simple(current,parts.pop()))return false;
    while(parts.length){const part=parts.pop();current=current.parentElement;while(current&&!simple(current,part))current=current.parentElement;if(!current)return false;}
    return true;
}
class Element {
    constructor(tag){this.tagName=tag.toUpperCase();this.children=[];this.parentElement=null;this.dataset={};this.attributes={};this.listeners={};this.disabled=false;this.checked=false;this.hidden=false;this._text='';this.value='';this.className='';this.classList={toggle(){},add(){},remove(){}};}
    get isConnected(){return this===document.body||!!this.parentElement?.isConnected;}
    get textContent(){return this._text+this.children.map(node=>node.textContent).join('');}
    set textContent(value){this.replaceChildren();this._text=String(value??'');}
    set innerHTML(_){throw new Error('No HTML parsing in isolated QA.');}
    append(...items){for(const node of items){if(node.parentElement)node.remove();node.parentElement=this;this.children.push(node);}}
    replaceChildren(...items){for(const node of this.children)node.parentElement=null;this.children=[];this._text='';this.append(...items);}
    insertBefore(node,reference){if(node.parentElement)node.remove();const index=this.children.indexOf(reference);assert.ok(index>=0);node.parentElement=this;this.children.splice(index,0,node);}
    remove(){if(this.parentElement){this.parentElement.children=this.parentElement.children.filter(node=>node!==this);this.parentElement=null;}}
    contains(node){return !!node&&walk(this).includes(node);}
    setAttribute(key,value){this.attributes[key]=String(value);}
    getAttribute(key){return this.attributes[key]??null;}
    removeAttribute(key){delete this.attributes[key];}
    addEventListener(type,callback){(this.listeners[type]??=[]).push(callback);}
    removeEventListener(type,callback){this.listeners[type]=(this.listeners[type]||[]).filter(fn=>fn!==callback);}
    dispatchEvent(event){if(!event.target)event.target=this;for(const callback of this.listeners[event.type]||[])callback(event);if(event.bubbles)this.parentElement?.dispatchEvent(event);return true;}
    async click(force=false){if(!force&&this.disabled)return;for(const callback of this.listeners.click||[])await callback({type:'click',target:this,preventDefault(){}});}
    focus(){document.activeElement=this;}
    querySelectorAll(selector){return walk(this).filter(node=>node!==this&&selector.split(',').some(s=>matches(node,s)));}
    querySelector(selector){return this.querySelectorAll(selector)[0]||null;}
}
document={documentElement:new Element('html'),body:new Element('body'),readyState:'loading',hidden:false,createElement:tag=>new Element(tag),querySelector:()=>null,getElementById:id=>walk(document.body).find(node=>node.id===id)||null,addEventListener(){}};
const broadcasts=[];
const window={addEventListener(){},dispatchEvent(){},confirm:()=>confirmAnswer,BroadcastChannel:class{constructor(name){this.name=name;}postMessage(value){broadcasts.push(value);}close(){}}};
let timer=0;
const sandbox={document,window,location:{pathname:'/fleet-ops',assign(){throw new Error('No navigation in isolated QA.');}},crypto,console,Blob,AbortController,TextEncoder,URL,URLSearchParams,
    Event:class{constructor(type,options={}){this.type=type;Object.assign(this,options);}},MutationObserver:class{observe(){}},requestAnimationFrame(){},setTimeout(){return ++timer;},clearTimeout(){},CustomEvent:class{},fetch(){networkCalls++;throw new Error('No network in isolated QA.');}};
const hooks=`
let __intent;
mutate=(path,body)=>{__intent=makeIntent(path,body,'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');};
const __trackerRequests=[];let __trackerConflict=true;
request=async(path,options={})=>{if(path.endsWith('/locations'))return {locations:[]};if(!path.endsWith('/whatsapp'))throw Error('Unexpected isolated request '+path);__trackerRequests.push({path,body:JSON.parse(options.body)});if(__trackerConflict)throw Object.assign(Error('An active link exists.'),{status:409});return {status:'accepted',tracker_link:{id:'link_DEMO',token:'a'.repeat(64),expires_at:Date.now()+3600000}};};
window.__deskQa={seed:(call=null,options={})=>{selected=call;manualEdit=options.draft||null;dirty=false;pending=null;busy=false;conflict=false;capabilities={automatic_dispatch:true,caller_tracking:false,demo:options.demo===true};places=[];sourceDirectory={hospitals:[{id:'phc_DEMO',name:'DEMO PHC'}],hierarchy_available:true,secretariats:[{id:'sec_DEMO',phc_id:'phc_DEMO',name:'DEMO secretariat'}],villages:[{id:'village_DEMO',secretariat_id:'sec_DEMO',name:'DEMO village'}]};session={role:'admin'};dialog=el('dialog');dialog.open=true;content=el('div');status=el('div');dialog.append(status,content);document.body.replaceChildren(dialog);__intent=null;if(call)renderCall(content);else renderManual(content);return content;},intent:()=>__intent,
 saved:(call)=>{selected=call;newCall=true;capabilities={caller_tracking:false};const parent=el('section');document.body.replaceChildren(parent);renderManual(parent);return parent;},
 trackerSeed:()=>{const call={id:'call_DEMO_tracker',review:{callback_number:'+918000000000'}};selected=call;newCall=true;capabilities={caller_tracking:true};issuedCallerLinks.clear();__trackerRequests.length=0;const parent=el('section');document.body.replaceChildren(parent);renderSavedTrackerCard(parent,call,()=>{});return parent;},trackerRequests:()=>__trackerRequests,setTrackerConflict:value=>{__trackerConflict=value;}};
`;
const source=fs.readFileSync(path.join(__dirname,'../public/mapa-voice.js'),'utf8');
vm.createContext(sandbox);vm.runInContext(source.replace(/\}\)\(\);\s*$/,hooks+'})();'),sandbox);
const pureSandbox={module:{exports:{}}}; vm.runInNewContext(source,pureSandbox); const core=pureSandbox.module.exports;
for(const action of ['start','stop']){
 const route='/calls/call_DEMO_desk/automatic/'+action;
 check(core.validRequestPath(route),'bounded automatic '+action+' route accepted');
 check(!core.validRequestPath(route,true),'automatic '+action+' is not a mobile transport route');
 check(core.makeIntent(route,{expected_revision:2},'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee').path===route,'automatic '+action+' intent accepted');
}
check(!core.validRequestPath('/calls/call_DEMO_desk/automatic/delete'),'unknown automatic action rejected');
check(!core.validRequestPath('/calls/call_DEMO_desk/automatic/start?admin=1'),'unlisted query rejected');
let stages=core.dispatchPipeline({id:'call_DEMO',state:'dispatched',order_id:'order_DEMO',acknowledged:false,trip_progress:{},review:{coordinates_confirmed:true,latitude:18,longitude:82}});
check(stages.length===8&&stages.map(stage=>stage.label).join(',')==='CALL,LOCATE,DISPATCH,ACCEPTED,ARRIVED,ONBOARD,HOSPITAL,COMPLETE','reference eight-step pipeline uses exact reference stages');
check(!stages[3].complete&&!stages[4].complete&&!stages[5].complete&&!stages[6].complete,'dispatch receipt does not manufacture acceptance, arrival, onboard or hospital evidence');
stages=core.dispatchPipeline({id:'call_DEMO',order_status:'completed',trip_progress:{accepted:true,pickup_arrived:true,patient_onboard:true,hospital_arrived:true,completed:true}});
check(stages.slice(3).every(stage=>stage.complete),'recorded trip evidence advances actual operational stages');
const qa=window.__deskQa;
const find=(root,name)=>walk(root).find(n=>n.name===name), btn=(root,label)=>walk(root).find(n=>n.tagName==='BUTTON'&&n.textContent===label);
const call={id:'call_DEMO_desk',revision:2,state:'ready',demo:false,source:'manual',automatic_dispatch_available:true,review:{caller_name:'DEMO caller',reported_problem:'DEMO report',pickup_address:'DEMO pickup',priority:'urgent',vehicle_requirement:'any',coordinates_confirmed:true,latitude:17.9,longitude:82.9}};
(async()=>{
let root=qa.seed();
check(!find(root,'demo_mode'),'live-only creation does not render a call-mode chooser');
root=qa.seed(null,{demo:true});
check(root.querySelector('.mapa-command-training').open===false,'authorized training settings stay collapsed for live calls');
check(root.querySelector('.mapa-command-training').contains(find(root,'demo_mode')),'call-mode chooser belongs only to training settings');
check(root.querySelector('form').children.indexOf(root.querySelector('.mapa-command-quick-fields'))<root.querySelector('form').children.indexOf(root.querySelector('.mapa-command-training')),'primary caller form appears before training settings');
root=qa.seed(null,{demo:true,draft:{demo:true}});check(root.querySelector('.mapa-command-training').open===true&&find(root,'demo_mode').value==='training','saved DEMO draft explicitly exposes its training mode');
root=qa.seed();
check(root.querySelector('.mapa-command-create-form')&&root.querySelector('.mapa-command-quick-fields'),'reference compact creation form rendered');
check(root.querySelector('.mapa-command-create-slides')?.attributes.role==='tablist','new call uses compact section navigation');
const slides=root.querySelectorAll('.mapa-command-create-slide');
check(slides.length===2&&!slides[0].hidden&&slides[1].hidden,'caller section opens first and pickup section stays collapsed');
await btn(root,'Next: Pickup & incident').click();
check(slides[0].hidden&&!slides[1].hidden,'Next reveals pickup without rebuilding the form');
await btn(root,'Back: Caller & priority').click();
check(!slides[0].hidden&&slides[1].hidden,'Back retains the caller section');
walk(root).find(n=>n.tagName==='BUTTON'&&n.textContent==='Caller & priority').dispatchEvent({type:'keydown',key:'ArrowRight',preventDefault(){}});
check(slides[0].hidden&&!slides[1].hidden,'arrow navigation reaches the next accessible tab');
root.querySelector('form').dispatchEvent({type:'submit',preventDefault(){}});
check(!slides[1].hidden&&find(root,'reported_problem').attributes['aria-invalid']==='true','required-field error reveals the slide containing that field');
check(!!btn(root,'Create & Pin Emergency'),'reference create action retained');
check(root.querySelectorAll('.mapa-command-create-options').every(node=>!node.open),'ASR and patient extras start collapsed');
const verified=find(root,'pickup_confirmed_at_creation');check(verified&&!verified.checked,'pickup verification is explicit and unchecked');
check(find(root,'latitude').value===''&&find(root,'longitude').value==='','reference default coordinates are not copied into live calls');
check(find(root,'vehicle_requirement').children.every(option=>['','any','bls','als'].includes(option.value)),'only server-supported capabilities offered');
find(root,'reported_problem').value='DEMO incident';find(root,'pickup_address').value='DEMO exact entrance';find(root,'latitude').value='18';find(root,'longitude').value='82';find(root,'priority').value='emergency';find(root,'vehicle_requirement').value='als';verified.checked=true;
root.querySelector('form').dispatchEvent({type:'submit',preventDefault(){}});
let created=JSON.parse(qa.intent().body);check(created.priority==='emergency'&&created.vehicle_requirement==='als','creation priority and capability serialized');
check(created.pickup_confirmed_at_creation===true&&created.coordinates_confirmed===false,'creation verification records evidence without bypassing dispatch review');
find(root,'pickup_address').dispatchEvent(new sandbox.Event('input',{bubbles:true}));check(!verified.checked,'editing pickup invalidates prior creation acknowledgement');
check(root.querySelectorAll('.mapa-call-workbench').length===1,'one native call workbench');
check(root.querySelectorAll('fieldset').length===1,'one compact primary intake section');
for(const name of ['caller_name','callback_number','priority','vehicle_requirement','pickup_address','reported_problem','latitude','longitude'])check(walk(root).filter(n=>n.name===name).length===1,name+' preserved once');
check(root.querySelector('.mapa-call-rail').textContent.includes('private tracker appears'),'tracker guidance alongside draft');
find(root,'caller_name').value='DEMO updated';find(root,'caller_name').dispatchEvent(new sandbox.Event('input',{bubbles:true}));
check(root.querySelector('.mapa-intake-summary').textContent.includes('DEMO updated'),'summary follows unsaved input');
root=qa.saved({...call,state:'needs_review'});
check(root.querySelector('.mapa-command-workflow-tabs')&&root.querySelector('.mapa-command-inline-tracker').hidden===false,'saved call opens the tracker section first');
check(root.querySelector('.mapa-command-saved-call .mapa-voice-form-grid')?.hidden===true,'saved details remain available without filling the page');
await btn(root,'Saved call details').click();
check(root.querySelector('.mapa-command-inline-tracker').hidden&&root.querySelector('.mapa-command-saved-call .mapa-voice-form-grid')?.hidden===false,'saved call details and tracker switch without losing data');
root=qa.seed(call);
check(root.querySelector('.mapa-command-workflow-tabs')&&root.querySelector('.mapa-voice-candidates').hidden===false&&root.querySelector('.mapa-call-tracking').hidden===true,'reviewed call opens dispatch while tracker stays compact');
await btn(root,'Caller tracker').click();check(root.querySelector('.mapa-call-tracking').hidden===false&&root.querySelector('.mapa-voice-candidates').hidden===true,'reviewed call tracker slide preserves dispatch panel');
await btn(root,'Review / edit').click();check(root.querySelector('.mapa-call-rail').hidden===false&&root.querySelector('.mapa-command-review-editor').open===true,'review slide opens editable form explicitly');
check(!!btn(root,'Dispatch fastest · start automatic offers')&&!!find(root,'ambulance_choice'),'automatic and manual options both retained');
const confirm=walk(root).find(n=>n.type==='checkbox'&&n.parentElement.textContent.startsWith('I confirm the reviewed'));
check(btn(root,'Dispatch fastest · start automatic offers').disabled,'automatic offers require confirmation');
confirm.checked=true;confirm.dispatchEvent(new sandbox.Event('change'));
await btn(root,'Dispatch fastest · start automatic offers').click();
check(qa.intent().path==='/calls/'+call.id+'/automatic/start','automatic confirmation reaches bounded mutation intent');
check(JSON.parse(qa.intent().body).expected_revision===2,'offer start binds reviewed revision');
check(root.textContent.includes('Caller location & ambulance link'),'saved caller tracker remains available with the selected call');
root=qa.seed({...call,automatic_dispatch:{state:'offering',offers:[]}});
check(!!btn(root,'Stop automatic dispatch')&&!find(root,'ambulance_choice'),'active offers prevent competing manual assignment');
check(root.querySelector('.mapa-command-selected-summary').textContent.includes('Automatic crew offers are active')&&!btn(root,'Review / edit'),'offer status stays visible and edit slide is unavailable while offers run');
check(root.querySelector('form').hidden,'active offer intake cannot be edited');
root=qa.seed({...call,automatic_dispatch:{state:'offering',cascade_active:true,first_acceptance_wins:true,outstanding_count:2,overdue_count:1,offers:[{driver_name:'DEMO Outstanding',driver_phone:'+910000000000',vehicle_registration:'DEMO-OVERDUE',state:'overdue',road_seconds:300,timeout_seconds:120,remaining_seconds:0,no_response:true,can_respond:true,expires_at:new Date().toISOString()},{driver_name:'DEMO Next',vehicle_registration:'DEMO-OFFERED',state:'offered',road_seconds:360,timeout_seconds:120,remaining_seconds:120,can_respond:true,expires_at:new Date(Date.now()+120000).toISOString()}]}});
check(root.textContent.includes('AUTOMATIC DISPATCH ACTIVE')&&root.textContent.includes('FIRST ACCEPTANCE WINS ASSIGNMENT'),'actual cascade contract displays first-acceptance policy');
check(root.textContent.includes('OVERDUE (ACTIONABLE)')&&root.textContent.includes('This offer remains actionable'),'overdue and expired semantics remain distinct');
check(root.textContent.includes('+910000000000')&&root.textContent.includes('ETA: 5 min by road'),'actual crew phone and road ETA displayed');
check(root.querySelectorAll('.mapa-command-offer-timer').length===2&&root.textContent.includes('OVERDUE · ACTIONABLE'),'each outstanding offer has its own countdown/actionable status');
check(!find(root,'ambulance_choice')&&!btn(root,'Retry automatic offers'),'multiple outstanding offers do not unlock competing manual or restart writes');
root=qa.seed({...call,automatic_dispatch:{state:'offering',replacement_routing_status:'refresh_selected_call',offers:[{driver_name:'DEMO Crew',vehicle_registration:'DEMO-AMB',state:'declined',timeout_seconds:120,road_seconds:60,response_seconds:15,responded_at:new Date().toISOString(),expires_at:new Date().toISOString()},{driver_name:'DEMO Crew 2',vehicle_registration:'DEMO-AMB2',state:'expired',timeout_seconds:120,road_seconds:90,no_response:true,expires_at:new Date().toISOString()}]}});
check(root.textContent.includes('Driver response: 15 s')&&root.textContent.includes('Crew declined'),'actual offer response and decline shown');
check(root.textContent.includes('expired offer cannot assign')&&root.textContent.includes('replacement candidates'),'actual timeout and replacement guidance shown');
root=qa.seed({...call,automatic_dispatch:{state:'needs_operator',offers:[]}});
check(btn(root,'Retry automatic offers').disabled,'retry requires renewed explicit confirmation');
const retryConfirmed=walk(root).find(n=>n.type==='checkbox'&&n.parentElement.textContent.startsWith('I confirm the reviewed pickup and destination and authorize offers to the remaining'));
retryConfirmed.checked=true;retryConfirmed.dispatchEvent(new sandbox.Event('change'));await btn(root,'Retry automatic offers').click();
check(qa.intent().path==='/calls/'+call.id+'/automatic/start'&&JSON.parse(qa.intent().body).expected_revision===call.revision,'retry binds current reviewed call without resetting history');
root=qa.seed({...call,automatic_dispatch:{state:'offering',offers:[]}});check(!btn(root,'Retry automatic offers'),'active offer cannot be restarted through retry control');
root=qa.seed({...call,state:'needs_review',review:null,draft:{...call.review,priority:'emergency',vehicle_requirement:'als',pickup_confirmed_at_creation:true,coordinates_confirmed:false}});
check(find(root,'priority').value==='emergency'&&find(root,'vehicle_requirement').value==='als','selected review prefills saved creation priority and capability');
check(!!btn(root,'Review & pickup')&&!!btn(root,'Caller tracker')&&!root.querySelector('form').hidden,'unreviewed call has review and tracker slides');
await btn(root,'Caller tracker').click();check(root.querySelector('form').hidden,'tracker slide keeps the review form compact');
find(root,'latitude').dispatchEvent(new sandbox.Event('input',{bubbles:true}));check(!root.querySelector('form').hidden,'caller pin copied to coordinates reopens review for verification');
const readBack=walk(root).find(n=>n.type==='checkbox'&&n.parentElement.textContent.startsWith('I have read back'));
check(!readBack.checked&&!btn(root,'Dispatch fastest · start automatic offers'),'creation verification does not auto-confirm dispatch review or crew offers');
root=qa.seed({...call,state:'dispatched',order_id:'order_DEMO_trip',order_status:'started',trip_progress:{accepted:true}});
check(root.querySelector('.mapa-command-trip-panel').hidden===false&&root.querySelector('.mapa-call-tracking').hidden===true,'active trip keeps its status visible while tracker stays compact');
await btn(root,'Caller tracker').click();check(root.querySelector('.mapa-command-trip-panel').hidden&&root.querySelector('.mapa-call-tracking').hidden===false,'active trip can switch to caller tracker without changing trip data');
root=qa.seed({...call,state:'dispatched',order_id:'order_DEMO_trip',order_status:'started',trip_progress:{accepted:true}});
check(root.querySelector('.mapa-call-tracking').hidden===false,'same-call refresh preserves the operator’s active tracker slide');
root=qa.seed({...call,source:'asrhealth'});
check(!btn(root,'Dispatch fastest · start automatic offers')&&!root.querySelector('form'),'ASR remains display and tracking only');
root=qa.trackerSeed();
const trackerConsent=walk(root).find(n=>n.type==='checkbox'&&n.parentElement.textContent.startsWith('Caller agreed to receive the private tracking'));
trackerConsent.checked=true;
await btn(root,'Send tracker & get location').click();
check(qa.trackerRequests().length===1&&qa.trackerRequests()[0].body.issue_new===true&&qa.trackerRequests()[0].body.replace_existing===undefined,'ordinary send issues a new link without rotating an active one');
check(root.textContent.includes('active tracker link')&&!btn(root,'Confirm replacement and send').hidden,'409 offers an explicit replacement choice');
confirmAnswer=false;await btn(root,'Confirm replacement and send').click();
check(qa.trackerRequests().length===1,'declined replacement leaves original caller link active');
confirmAnswer=true;qa.setTrackerConflict(false);await btn(root,'Confirm replacement and send').click();
check(qa.trackerRequests().length===2&&qa.trackerRequests()[1].body.replace_existing===true,'only confirmed replacement sends replace_existing true');
check(walk(root).some(n=>n.getAttribute('aria-label')==='Private WhatsApp tracker link')&&!btn(root,'Create private tracker link'),'successful replacement returns the sent link without a separate issue step');
check(networkCalls===0,'isolated test never contacts source/server');
console.log(checks+' New Call workbench checks passed; DEMO fixtures, zero network.');
})().catch(error=>{console.error(error);process.exitCode=1;});
