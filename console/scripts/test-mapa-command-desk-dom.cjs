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
let __transport;
request=async(path,options={})=>__transport(path,options);
window.__commandQa={seed:(data={})=>{expire();session={name:'DEMO Operator',role:'admin',expires:Date.now()+3600000};capabilities={manual_intake:true,automatic_dispatch:true,command_desk:true,caller_tracking:data.tracker===true,supervised_pilot:data.pilotEnabled===true,extraction:data.extraction===true};selected=data.selected||null;calls=data.calls||[];asrCalls=data.asrCalls||[];asrSelected=null;newCall=false;pending=null;busy=false;dirty=false;if(Array.isArray(data.places))places=data.places;sourceDirectory={hierarchy_available:true,hospitals:[],secretariats:[],villages:[]};recommendations=data.recommendations||null;candidates=data.candidates||[];unavailable=[];deskSnapshot=data.desk||null;deskLoadedAt=data.desk?Date.now():0;dialog=el('dialog');dialog.open=true;content=el('div');status=el('div');dialog.append(status,content);document.body.replaceChildren(dialog);render();return content;},state:()=>({newCall,dirty,deskSnapshot,deskError}),intent:()=>pending?.intent,transport:fn=>{__transport=fn;},load:loadCommandDesk,refreshLive:refreshLiveDesk,refreshAsr:refreshAsrCalls,render,saved:()=>{newCall=true;render();},submitReview:async()=>{pending={intent:makeIntent('/calls/'+selected.id+'/review',{expected_revision:selected.revision},'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee'),message:'DEMO reviewed'};await sendPending();},patch:data=>{if('dirty'in data)dirty=data.dirty;}};
`;
const source=fs.readFileSync(path.join(__dirname,'../public/mapa-voice.js'),'utf8');vm.createContext(sandbox);vm.runInContext(source.replace(/\}\)\(\);\s*$/,hooks+'})();'),sandbox);
const qa=window.__commandQa,btn=(label)=>walk(document.body).find(n=>n.tagName==='BUTTON'&&n.textContent===label);
const review={caller_name:'DEMO Caller',callback_number:'+910000000000',pickup_address:'DEMO pickup',reported_problem:'DEMO report',priority:'urgent',vehicle_requirement:'bls',coordinates_confirmed:true,latitude:17.9,longitude:82.9};
const call={id:'call_DEMO_command',revision:2,state:'ready',demo:false,source:'manual',review};
const desk={contract:'mapa-command-desk-v1',server_time:Date.now(),summary:{total_fleet:9,on_trip:2,active_calls:3,dispatchable_now:null,gps_stale_offline:null},driver_statistics:[{driver_name:'DEMO Driver',completed_trips:12,accepted_offers:7,acceptance_rate:70,average_response_seconds:18}],trip_monitoring:[{call_id:call.id,vehicle_registration:'DEMO-AMB',driver_name:'DEMO Driver',stage:'en_route',alerts:[{message:'Movement delayed'}]}]};
(async()=>{
let root=qa.seed({calls:[call],desk});
check(root.querySelectorAll('.mapa-command-grid').length===1,'one integrated command grid');
check(root.querySelector('.mapa-command-header').textContent.includes('Integrated Ambulance Command Centre'),'uploaded reference command header replaces legacy desk chrome');
check(root.querySelector('.mapa-command-tools').contains(root.querySelector('.mapa-voice-toolbar')),'legacy support actions moved into compact tools menu');
check(!root.querySelector('.mapa-command-tools').open,'tools menu is closed by default');
const grid=root.querySelector('.mapa-command-grid');check(grid.children.length===3,'queue, emergency and map/secondary operations are three sibling columns');
check(grid.children[0].textContent.includes('Active Queue')&&grid.children[1].textContent.includes('Selected Emergency')&&grid.children[2].textContent.includes('Pickup & ambulance map'),'reference command column order');
check(root.querySelector('.mapa-command-kpis').textContent.includes('9Total fleet'),'measured fleet counts displayed');
check(root.querySelector('.mapa-command-kpis').textContent.includes('Select callEligible for selected call'),'eligibility requires a selected reviewed call');
const initialMap=root.querySelector('.mapa-voice-map'),initialHero=root.querySelector('.mapa-command-hero'),initialQueue=root.querySelector('.mapa-command-queue');
qa.transport(async requestPath=>requestPath==='/command-desk'?{...desk,summary:{...desk.summary,total_fleet:10}}:{contract:'mapa-asr-caller-feed-v1',calls:[]});
await qa.load();qa.refreshLive();await qa.refreshAsr(true);
check(document.body.querySelector('.mapa-voice-map')===initialMap&&document.body.querySelector('.mapa-command-hero')===initialHero,'background metrics and caller refresh keep the map and incident form mounted');
check(document.body.querySelector('.mapa-command-queue')===initialQueue,'caller refresh updates queue contents without replacing the desk');
check(document.body.querySelector('.mapa-command-kpis').textContent.includes('10Total fleet'),'live metrics update without a full desk render');
await btn('Driver Performance').click();check(root.querySelector('.mapa-command-secondary-content').textContent.includes('Completed trips: 12'),'real driver performance metrics displayed');
await btn('Active Exceptions').click();check(root.querySelector('.mapa-command-secondary-content').textContent.includes('Movement delayed'),'active trip alerts displayed');
await btn('Dispatch Activity').click();check(root.querySelector('.mapa-command-secondary-content').textContent.includes('Select a MAPA call'),'activity tab explains when offer history is empty');
await btn('Ambulance Fleet').click();check(root.querySelector('.mapa-command-secondary-content').textContent.includes('Select a reviewed call'),'fleet tab explains call-scoped eligibility');
await btn('Refresh desk').click();check(document.body.querySelector('.mapa-voice-map')===initialMap,'manual desk refresh preserves the active map');
await btn('+ New Call').click();check(qa.state().newCall,'New Call opens within same native desk');check(root.isConnected===false||document.body.querySelectorAll('.mapa-command-grid').length===1,'create does not nest application shells');
check(document.body.querySelector('.mapa-command-create-dialog').getAttribute('aria-label')==='Create Emergency Call','New Call form is labeled inside the command desk');
check(document.body.querySelector('.mapa-command-grid').contains(document.body.querySelector('.mapa-command-create-dialog')),'New Call form occupies the selected-emergency column');
check(document.body.querySelector('.mapa-command-queue')&&document.body.querySelector('.mapa-command-hero')&&document.body.querySelector('.mapa-command-secondary'),'queue and secondary desk retained while creating call');
check(document.body.querySelector('.mapa-command-secondary .mapa-voice-pickup-map'),'pickup map moved to right command column');
for(const name of ['caller_name','callback_number','priority','vehicle_requirement','pickup_address','reported_problem','latitude','longitude'])check(walk(document.body).filter(n=>n.name===name).length===1,name+' appears exactly once in the compact call form');
qa.patch({dirty:true});confirmAnswer=false;await btn('Back to call queue').click();check(qa.state().newCall&&qa.state().dirty,'cancel discard preserves unsaved new call');confirmAnswer=true;
qa.seed({selected:call,calls:[call],desk});check(document.body.querySelector('.mapa-command-selected-summary').textContent.includes('DEMO Caller'),'selected emergency summary shown before dispatch');check(document.body.querySelector('.mapa-command-review-editor').open===false,'reviewed call form compact and explicitly expandable');check(document.body.querySelector('.mapa-command-secondary .mapa-voice-pickup-map'),'review map stays in right pane');
qa.transport(async()=>{throw new Error('DEMO offline');});await qa.load();qa.render();check(document.body.textContent.includes('current refresh unavailable'),'failure retains values but explicitly marks stale metrics');
qa.transport(async()=>({...desk,contract:'unsupported'}));await qa.load();check(qa.state().deskError.includes('Unsupported command desk data'),'unrecognized desk contract rejected');
qa.transport(async()=>({...desk,fleet:{}}));await qa.load();check(qa.state().deskError.includes('Unsupported command desk data'),'malformed fleet rejected without breaking retained metrics');
qa.transport(async()=>desk);await qa.load();qa.render();check(!qa.state().deskError,'successful refresh clears stale state');
qa.seed({desk:{...desk,fleet:[{vehicle_id:'vehicle_DEMO_evidence',registration:'DEMO-GPS',driver_name:'DEMO GPS Driver',driver_phone:'+910000000000',gps_source:'hardware_gps',gps_recorded_at:Date.now(),gps_stale:false,driver_mapping_ambiguous:true}],driver_statistics:[{driver_name:'DEMO GPS Driver',no_response_offers:3,expired_offers:1,overdue_offers:2}]}});
check(document.body.querySelector('.mapa-command-secondary-content').textContent.includes('Ambulance device GPS'),'fleet labels actual hardware GPS source');
check(document.body.querySelector('.mapa-command-secondary-content').textContent.includes('+910000000000'),'fleet shows actual mapped driver phone');
check(document.body.querySelector('.mapa-command-secondary-content').textContent.includes('review'),'ambiguous driver mapping requires review');
await btn('Driver Performance').click();check(document.body.querySelector('.mapa-command-secondary-content').textContent.includes('No response: 3')&&document.body.querySelector('.mapa-command-secondary-content').textContent.includes('Actionable overdue: 2'),'statistics use actual no-response and actionable overdue counts');
qa.seed({calls:[call,{...call,id:'call_DEMO_routine',review:{...review,priority:'routine'}}]});await btn('Critical / High').click();check(document.body.querySelectorAll('.mapa-voice-call').length===1,'urgent filter excludes routine native call');await btn('Unconfirmed').click();check(document.body.querySelectorAll('.mapa-voice-call').length===0,'unconfirmed filter excludes reviewed calls');
const candidate={id:'driver_DEMO_rank',vehicle_id:'vehicle_DEMO_rank',vehicle_registration:'DEMO-ROAD',vehicle_name:'DEMO ambulance',vehicle_type:'BLS',name:'DEMO Driver',simulated:false,distance_km:3,fix_recorded_at:new Date().toISOString(),road_seconds:420,road_minutes:7,rank:1};
const ranked={routing_status:'ready',ranked:[candidate]};
qa.seed({selected:call,calls:[call],desk,candidates:[candidate],recommendations:ranked});
check(document.body.querySelector('.mapa-command-kpis').textContent.includes('1Eligible for selected call'),'only the selected call shows measured eligible candidates');
check(document.body.querySelector('.mapa-command-recommendations').textContent.includes('7 min by road'),'road recommendation renders genuine server ETA');
await btn('Choose this ambulance').click();const chooser=walk(document.body).find(n=>n.name==='ambulance_choice');check(chooser.value===candidate.vehicle_id,'recommendation selection binds existing manual chooser');check(btn('Confirm dispatch').disabled,'recommended selection still requires manual dispatch confirmation');
qa.transport(async(path,options)=>options.body?{ok:true,call:{...call,revision:3}}:{call:{...call,revision:3},candidates:[candidate],unavailable:[],places:[],recommendations:ranked});
await qa.submitReview();check(!!btn('Choose this ambulance'),'review receipt detail refresh restores authoritative road recommendations');
await btn('+ New Call').click();check(!document.body.querySelector('.mapa-command-recommendations'),'new call never reuses prior recommendation cards');
qa.seed({selected:{...call,source:'asrhealth'},calls:[{...call,source:'asrhealth'}],desk,recommendations:ranked});check(!btn('Choose this ambulance')&&!btn('Dispatch fastest · start automatic offers'),'ASR-owned call cannot use native recommendation or automatic writes');
qa.seed({selected:{...call,automatic_dispatch_available:true},calls:[call],desk});
check(!!btn('Dispatch fastest · start automatic offers'),'approved pilot call can show explicitly confirmed automatic start');
const nonPilot={...call,automatic_dispatch_available:false,automatic_dispatch_unavailable_reason:'pilot_scope'};
qa.seed({selected:nonPilot,calls:[nonPilot],desk});
check(!btn('Dispatch fastest · start automatic offers')&&!!btn('Save reviewed intake & find ambulances'),'non-pilot call retains manual review without an automatic start control');
check(document.body.textContent.includes('limited to approved pilot calls'),'pilot-scope reason is visible to the operator');
qa.seed({selected:{...nonPilot,automatic_dispatch:{state:'offering',offer_count:1,offers:[]}},calls:[nonPilot],desk});
check(!!btn('Stop automatic dispatch')&&!btn('Retry automatic offers'),'a run already in progress remains visible and stoppable when pilot scope changes');
const staleWorker={...call,automatic_dispatch_available:false,automatic_dispatch_unavailable_reason:'worker_stale',automatic_dispatch:{state:'needs_operator',reason:'worker_stale',offer_count:0,offers:[]}};
qa.seed({selected:staleWorker,calls:[staleWorker],desk});
check(document.body.textContent.includes('Automatic dispatch worker is unavailable; operator action required'),'stale worker shows explicit operator handoff instead of no-crew explanation');
qa.seed({pilotEnabled:true,desk});await btn('+ New Call').click();
const pilotOption=walk(document.body).find(node=>node.name==='supervised_pilot');
check(pilotOption&&!pilotOption.checked,'supervised no-patient pilot must be chosen explicitly');
pilotOption.checked=true;pilotOption.dispatchEvent(new sandbox.Event('change',{bubbles:true}));
const pilotForm=document.body.querySelector('.mapa-command-create-form');
const pilotField=name=>walk(pilotForm).find(node=>node.name===name);
check(pilotField('caller_name').value==='MAPA PILOT — NO PATIENT'&&pilotField('caller_name').readOnly,'pilot has immutable no-patient call label');
check(pilotField('callback_number').disabled&&pilotField('callback_number').value===''&&pilotField('tracker_consent')?.checked!==true,'pilot has no caller phone or WhatsApp consent path');
check(pilotField('pickup_confirmed_at_creation').disabled&&!pilotField('pickup_confirmed_at_creation').checked,'pilot cannot check caller-confirmed pickup');
check(!!btn('Save no-patient pilot call')&&pilotForm.textContent.includes('Saving does not send an offer'),'pilot creation explains its bounded effect');
pilotField('pickup_address').value='Araku Railway Station test point';pilotField('latitude').value='18.332922';pilotField('longitude').value='82.865258';
qa.transport(()=>new Promise(()=>{}));pilotForm.dispatchEvent({type:'submit',preventDefault(){}});
const pilotIntent=qa.intent(),pilotPayload=pilotIntent&&JSON.parse(pilotIntent.body);
check(pilotIntent?.path==='/calls/manual'&&pilotPayload.supervised_pilot===true&&pilotPayload.caller_name==='MAPA PILOT — NO PATIENT','explicit pilot creation sends the distinct marked manual-intake payload');
check(pilotPayload.callback_number===''&&pilotPayload.coordinates_confirmed===false&&pilotPayload.pickup_confirmed_at_creation===false&&!('patient_gender'in pilotPayload),'pilot create sends no caller, no false confirmation and no clinical defaults');
const pilotCall={...call,id:'call_DEMO_pilot',state:'needs_review',review:null,supervised_pilot:true,automatic_dispatch_available:false,transcript:'DEMO text must not become a patient report',draft:{...review,caller_name:'MAPA PILOT — NO PATIENT',callback_number:'',reported_problem:'SUPERVISED MAPA PILOT — NO PATIENT. Two-crew drill.',coordinates_confirmed:false,supervised_pilot:true}};
qa.seed({selected:{...call,state:'needs_review',review:null,draft:review,transcript:'DEMO caller transcript'},calls:[call],desk,extraction:true});
check(!!btn('Suggest draft from transcript'),'ordinary calls retain transcript suggestions when extraction is enabled');
qa.seed({selected:pilotCall,calls:[pilotCall],desk,pilotEnabled:true,extraction:true});
check(!btn('Suggest draft from transcript'),'no-patient pilot cannot turn transcript text into a purported caller report');
const pilotReviewForm=document.body.querySelector('.mapa-command-dispatch-review');
check(pilotReviewForm.textContent.includes('no-patient test point')&&!pilotReviewForm.textContent.includes('read back the exact pickup'),'pilot review uses test-point evidence, not a caller read-back');
check(!pilotReviewForm.textContent.includes('SMS')&&!!btn('Pilot awaiting server scope')&&btn('Pilot awaiting server scope').disabled,'pilot review cannot message or dispatch before server scope');
check(!btn('Confirm dispatch')&&!walk(document.body).some(node=>node.name==='ambulance_choice'),'pilot has no manual-dispatch bypass');
qa.seed({selected:{...pilotCall,automatic_dispatch_available:true,draft:{...pilotCall.draft,dropoff_id:'place_DEMO_destination'}},calls:[pilotCall],desk,pilotEnabled:true,places:[{id:'place_DEMO_destination',name:'DEMO test destination'}]});
check(!!btn('Confirm test point & start scoped crew offers'),'scoped pilot exposes explicit operator-confirmed offer action');
const scopedPilotForm=document.body.querySelector('.mapa-command-dispatch-review'),pilotAck=walk(scopedPilotForm).find(node=>node.type==='checkbox'&&node.parentElement.textContent.startsWith('I confirm this exact no-patient'));
pilotAck.checked=true;qa.transport(()=>new Promise(()=>{}));scopedPilotForm.dispatchEvent({type:'submit',preventDefault(){}});
const pilotReviewIntent=qa.intent(),pilotReviewPayload=pilotReviewIntent&&JSON.parse(pilotReviewIntent.body);
check(pilotReviewIntent?.path==='/calls/'+pilotCall.id+'/review'&&pilotReviewPayload.supervised_pilot===true&&pilotReviewPayload.pilot_pickup_confirmed_by_operator===true&&pilotReviewPayload.coordinates_confirmed===false,'scoped review sends operator test-point evidence without caller confirmation');
check(pilotReviewPayload.callback_number===''&&!('patient_gender'in pilotReviewPayload)&&!('consciousness'in pilotReviewPayload),'pilot review sends no caller phone or clinical defaults');
qa.seed({selected:{...pilotCall,state:'dispatched',order_id:'order_DEMO_pilot',order_status:'dispatched',acknowledged:true},calls:[pilotCall],desk,pilotEnabled:true});
check(document.body.textContent.includes('Cancel this test order')&&!document.body.textContent.includes('Patient onboard'),'assigned pilot directs native cancellation without fake patient stages');
const trackerRequests=[];
qa.seed({selected:call,calls:[call],desk,tracker:true});qa.saved();
check(!!btn('Send tracker & get location'),'saved live call offers explicit tracker send');
check(trackerRequests.length===0,'opening saved call does not issue a private link');
const trackerConsent=walk(document.body).find(n=>n.tagName==='INPUT'&&n.type==='checkbox'&&n.parentElement?.textContent.includes('Caller agreed to receive the private tracking link'));
check(trackerConsent&&!trackerConsent.checked,'caller consent must be checked before sending');
trackerConsent.checked=true;
qa.transport(async(path,options={})=>{trackerRequests.push({path,body:options.body?JSON.parse(options.body):null});if(path.endsWith('/whatsapp'))return {status:'accepted',tracker_link:{id:'link_DEMO_oneclick',token:'a'.repeat(64),expires_at:Date.now()+3600000,url:'https://mapa.example.invalid/track/#'+'a'.repeat(64),scope:'full_journey'}};return {locations:[]};});
await btn('Send tracker & get location').click();
check(trackerRequests[0].path==='/tracking/calls/'+call.id+'/whatsapp'&&trackerRequests[0].body.issue_new===true&&!('token'in trackerRequests[0].body),'one consented action issues and sends, without a separate link request');
check(!!btn('Check WhatsApp delivery')&&btn('Send tracker & get location').hidden,'sent link remains available without a second send button');
qa.seed({selected:call,calls:[call],desk,tracker:true});qa.saved();
check(!!btn('Send tracker & get location')&&trackerRequests.every(r=>!r.path.endsWith('/links')),'new operator session does not silently recreate or revoke the sent link');
const reloadedConsent=walk(document.body).find(n=>n.tagName==='INPUT'&&n.type==='checkbox'&&n.parentElement?.textContent.includes('Caller agreed to receive the private tracking link'));reloadedConsent.checked=true;
qa.transport(async(path,options={})=>{trackerRequests.push({path,body:options.body?JSON.parse(options.body):null});throw Object.assign(new Error('An active tracker link already exists.'),{status:409});});
await btn('Send tracker & get location').click();check(!btn('Confirm replacement and send').hidden&&btn('Send tracker & get location').hidden,'legacy active link requires visible deliberate replacement');
qa.transport(async(path,options={})=>{trackerRequests.push({path,body:options.body?JSON.parse(options.body):null});return {status:'accepted',tracker_link:{id:'link_DEMO_replacement',token:'b'.repeat(64),expires_at:Date.now()+3600000,url:'https://mapa.example.invalid/track/#'+'b'.repeat(64),scope:'full_journey'}};});
await btn('Confirm replacement and send').click();check(trackerRequests.filter(r=>r.path.endsWith('/whatsapp')).at(-1).body.replace_existing===true,'replacement is sent only after explicit confirmation');
qa.seed({selected:call,calls:[call],desk,tracker:true});
check(!!btn('Send via WhatsApp'),'reviewed call allows direct send without a separately created link');
check(!btn('Create private trip link'),'reviewed call cannot strand an unsent standalone tracker link');
const reviewedConsent=walk(document.body).find(n=>n.tagName==='INPUT'&&n.type==='checkbox'&&n.parentElement?.textContent.includes('Caller agreed to receive a private link'));reviewedConsent.checked=true;
qa.transport(async(path,options={})=>{trackerRequests.push({path,body:options.body?JSON.parse(options.body):null});if(path.endsWith('/whatsapp'))return {status:'accepted',tracker_link:{id:'link_DEMO_reviewed',token:'c'.repeat(64),expires_at:Date.now()+3600000,url:'https://mapa.example.invalid/track/#'+'c'.repeat(64),scope:'full_journey'}};return {locations:[]};});
await btn('Send via WhatsApp').click();
check(trackerRequests.filter(r=>r.path.endsWith('/whatsapp')).at(-1).body.issue_new===true,'reviewed call also issues and sends in one consented action');
check(btn('Send via WhatsApp').hidden&&!!btn('Check WhatsApp delivery'),'reviewed call keeps sent link while preventing a second send');
qa.seed({selected:call,calls:[call],desk,tracker:true});
const orphanConsent=walk(document.body).find(n=>n.tagName==='INPUT'&&n.type==='checkbox'&&n.parentElement?.textContent.includes('Caller agreed to receive a private link'));orphanConsent.checked=true;
qa.transport(async()=>{throw Object.assign(new Error('An active tracker link already exists.'),{status:409});});
await btn('Send via WhatsApp').click();
check(!btn('Confirm replacement and send').hidden&&btn('Send via WhatsApp').hidden,'reviewed call after reload requires explicit recovery from a legacy active link');
check(networkCalls===0,'production DOM fixtures make no network calls');console.log(checks+' command desk production DOM checks passed; isolated DEMO data, zero network.');
})().catch(error=>{console.error(error);process.exitCode=1;});
