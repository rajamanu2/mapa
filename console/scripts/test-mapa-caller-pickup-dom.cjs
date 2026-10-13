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
const __requests=[];let __transport;
request=async(path,options={})=>{__requests.push({path,...options});return __transport(path,options);};
window.__pickupQa={
 seed:(call,transport)=>{if(removeCallerSync)removeCallerSync();if(removePickupMap)removePickupMap();session={user:'user_DEMO_dispatcher',company:'company_DEMO',role:'admin',expires:Date.now()+3600000};selected=call;calls=[call];capabilities={caller_tracking:true};candidates=[];unavailable=[];places=[];dirty=false;editDraft=null;pending=null;busy=false;conflict=false;newCall=false;activeView='calls';pendingPickupConfirmations.clear();__requests.length=0;__transport=transport;dialog=el('dialog');dialog.open=true;content=el('div');status=el('div');dialog.append(status,content);document.body.replaceChildren(dialog);render();return dialog;},
 state:()=>({selected,dirty,editDraft,pending,pickupPending:pendingPickupConfirmations.size,message:lastMessage}),requests:()=>__requests,
 patch:data=>{if('busy'in data)busy=data.busy;if('pending'in data)pending=data.pending;},transport:fn=>{__transport=fn;}
};
`;
const source=fs.readFileSync(path.join(__dirname,'../public/mapa-voice.js'),'utf8');
check(/\}\)\(\);\s*$/.test(source),'production closure recognized');
vm.createContext(sandbox);vm.runInContext(source.replace(/\}\)\(\);\s*$/,hooks+'})();'),sandbox,{filename:'mapa-voice.production.js'});
const qa=window.__pickupQa;
const now=Date.now(),point={latitude:17.9001234,longitude:82.9005678,accuracy_m:12,recorded_at:now-1000,confirmed_at:now,source:'caller_confirmed',stale:false};
const review={caller_name:'DEMO caller',callback_number:'+910000000000',pickup_address:'DEMO manual entrance address',landmark:'DEMO landmark',access_directions:'DEMO entrance route',latitude:17.93,longitude:82.93,coordinates_confirmed:true,priority:'urgent',vehicle_requirement:'any'};
const call={id:'call_DEMO_pickup',revision:7,state:'ready',demo:false,source:'manual',meta:{mapa_demo:true},review,order_id:null};
const snapshot={can_confirm_live_pickup:true,order_revision:'a'.repeat(64),locations:[{link_id:'11111111-1111-4111-8111-111111111111',location:{...point,latitude:18,recorded_at:now},caller_pickup_confirmation:point}]};
const clone=value=>JSON.parse(JSON.stringify(value));
function button(text){return walk(document.body).find(node=>node.tagName==='BUTTON'&&node.textContent===text);}
function input(name){return walk(document.body).find(node=>node.name===name);}
function acknowledgement(){return walk(document.body).find(node=>node.type==='checkbox'&&node.parentElement.textContent.startsWith('I have read back'));}
function fixture(data=call,snap=snapshot){return qa.seed(clone(data),async(path)=>{if(path.endsWith('/locations'))return clone(snap);throw new Error('Unexpected fixture request '+path);});}
async function checkLocation(){await button('Check caller location').click();}

(async()=>{
    fixture();check(button('Use caller-confirmed pickup pin').disabled,'candidate action starts disabled');
    await checkLocation();check(!button('Use caller-confirmed pickup pin').disabled,'explicit fresh caller confirmation enables review action');
    check(Number(input('latitude').value)===review.latitude&&qa.state().dirty===false,'poll never changes operator coordinates or dirty state');
    check(acknowledgement().checked,'existing read-back remains unchanged on poll');
    const prior=clone(qa.state().selected);await button('Use caller-confirmed pickup pin').click();
    check(Math.abs(Number(input('latitude').value)-point.latitude)<0.000001&&Math.abs(Number(input('longitude').value)-point.longitude)<0.000001,'apply uses confirmed snapshot instead of later moving GPS');
    check(input('pickup_address').value===review.pickup_address&&input('landmark').value===review.landmark&&input('access_directions').value===review.access_directions,'apply preserves caller-described address, landmark and entrance for operator calibration');
    check(!acknowledgement().checked&&qa.state().dirty&&qa.state().editDraft.data.coordinates_confirmed===false,'apply clears prior read-back acknowledgement and records unsaved draft');
    check(JSON.stringify(qa.state().selected)===JSON.stringify(prior),'apply leaves saved call immutable');
    check(qa.requests().length===1&&qa.requests().every(row=>row.body===undefined),'apply does not save, dispatch or submit a pickup mutation');
    check(document.body.textContent.includes('Pickup pin: 17.900123, 82.900568'),'map coordinate summary follows the candidate');
    check(qa.state().message.includes('Verify the address and entrance'),'operator told to verify address before saving');

    fixture();await checkLocation();confirmAnswer=false;await button('Use caller-confirmed pickup pin').click();confirmAnswer=true;
    check(Number(input('latitude').value)===review.latitude&&!qa.state().dirty,'cancel leaves draft unchanged');
    fixture(call,{...snapshot,locations:[{link_id:snapshot.locations[0].link_id,location:point}]});await checkLocation();
    check(button('Use caller-confirmed pickup pin').disabled,'passive GPS never enables explicit caller confirmation action');
    fixture(call,{...snapshot,locations:[{...snapshot.locations[0],caller_pickup_confirmation:{...point,stale:true}}]});await checkLocation();
    check(button('Use caller-confirmed pickup pin').disabled,'stale frozen candidate cannot borrow fresh passive GPS');
    fixture();await checkLocation();input('callback_number').value='+910000000001';input('callback_number').dispatchEvent(new sandbox.Event('input',{bubbles:true}));
    check(button('Use caller-confirmed pickup pin').disabled,'changing callback disables evidence from the previous caller');
    await button('Use caller-confirmed pickup pin').click(true);check(Number(input('latitude').value)===review.latitude,'forced stale callback handler cannot apply previous caller GPS');
    for(const flag of ['busy','pending']){fixture();await checkLocation();qa.patch({[flag]:flag==='busy'?true:{intent:{body:'DEMO pending'}}});await button('Use caller-confirmed pickup pin').click(true);check(Number(input('latitude').value)===review.latitude,flag+' prevents candidate apply');}

    const dispatched={...call,state:'dispatched',order_id:'order_DEMO_pickup'},updated={...dispatched,revision:8,review:{...review,latitude:point.latitude,longitude:point.longitude}};
    const receipt={ok:true,order_id:dispatched.order_id,confirmation_id:'confirmation_DEMO_pickup',order_revision:'b'.repeat(64),crew_must_review:true,pickup:point};
    fixture(dispatched);qa.transport(async(path,options)=>{if(path.endsWith('/locations'))return clone(snapshot);if(path.endsWith('/pickup'))return clone(receipt);if(path==='/calls/'+call.id)return{call:clone(updated),candidates:[],unavailable:[],places:[]};throw new Error('Unexpected fixture request '+path);});
    await checkLocation();await button('Accept caller-confirmed pickup for this trip').click();
    const mutation=qa.requests().find(row=>row.body!==undefined),body=JSON.parse(mutation.body);
    check(mutation.path==='/tracking/calls/'+call.id+'/pickup'&&mutation.native===true,'accept uses bounded native pickup endpoint');
    check(body.recorded_at===point.recorded_at&&body.link_id===snapshot.locations[0].link_id&&body.expected_revision===snapshot.order_revision,'native acceptance binds frozen timestamp, link and current native order revision');
    check(body.confirmed===true&&/^[a-f0-9-]{36}$/i.test(body.action_id),'acceptance uses explicit confirmation and generated action ID');
    check(qa.state().selected.revision===8&&qa.state().selected.review.latitude===point.latitude,'success reloads authoritative call and revised pickup');
    check(document.body.textContent.includes('17.900123, 82.900568'),'dispatched result renders refreshed native pickup');
    check(qa.state().pickupPending===0&&qa.requests().filter(row=>row.body!==undefined).length===1,'success clears pending action with one mutation');
    check(broadcasts.length===1&&JSON.stringify(broadcasts[0])==='{"type":"intake-updated","version":1}','successful acceptance invalidates Operations without contact or GPS payload');

    fixture(dispatched);qa.transport(async(path)=>{if(path.endsWith('/locations'))return clone(snapshot);if(path.endsWith('/pickup'))return clone(receipt);throw new Error('DEMO refresh offline');});
    await checkLocation();await button('Accept caller-confirmed pickup for this trip').click();
    check(qa.state().pickupPending===0&&qa.requests().filter(row=>row.body!==undefined).length===1,'accepted receipt stays final when subsequent read fails');
    check(qa.state().message.includes('Pickup accepted')&&qa.state().message.includes('displayed pickup may be old'),'failed refresh clearly labels prior displayed point');
    check(!qa.state().message.includes('outcome is unknown')&&!button('Retry same pickup confirmation'),'failed read does not mislabel an accepted update as uncertain');

    fixture(dispatched);let attempt=0;qa.transport(async(path)=>{if(path.endsWith('/locations'))return clone(snapshot);if(path.endsWith('/pickup')){if(++attempt===1)throw Object.assign(new Error('DEMO lost response'),{status:502});return clone(receipt);}if(path==='/calls/'+call.id)return{call:clone(updated)};throw new Error(path);});
    await checkLocation();await button('Accept caller-confirmed pickup for this trip').click();
    check(qa.state().pickupPending===1&&!button('Retry same pickup confirmation').disabled,'uncertain receipt retains recoverable action');
    await button('Retry same pickup confirmation').click();const retries=qa.requests().filter(row=>row.body!==undefined);
    check(retries.length===2&&retries[0].body===retries[1].body,'uncertain retry preserves exact body and idempotency key');
    check(qa.state().selected.revision===8&&qa.state().pickupPending===0,'retry success refreshes accepted revision');

    fixture(dispatched);qa.transport(async(path)=>{if(path.endsWith('/locations'))return clone(snapshot);throw Object.assign(new Error('DEMO stale revision'),{status:409});});
    await checkLocation();await button('Accept caller-confirmed pickup for this trip').click();
    check(qa.state().pickupPending===0&&button('Accept caller-confirmed pickup for this trip').disabled,'definite revision conflict clears action and requires refresh');
    check(qa.state().selected.revision===7,'rejected request does not fake revised call');
    check(networkCalls===0,'all production DOM checks used zero network or account access');
    console.log(checks+' caller pickup production DOM/intent checks passed; isolated DEMO fixtures, zero network.');
})().catch(error=>{console.error(error);process.exitCode=1;});
