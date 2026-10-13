// Execute the production review UI in an isolated DOM facade; no server, accounts or network.
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const crypto = require('node:crypto');
let checks = 0;
function check(condition, label) { assert.ok(condition, label); checks++; }
const revoked = [], created = [];
let document;
class Element {
    constructor(tag) { this.tagName=tag.toUpperCase();this.children=[];this.parentElement=null;this.dataset={};this.attributes={};this.listeners={};this.disabled=false;this.hidden=false;this.open=false;this._text='';this.className='';this.value='';this.isRoot=false;
        this.classList={toggle(){},add(){},remove(){}}; }
    get textContent(){return this._text+this.children.map(child=>child.textContent||'').join('');}
    set textContent(value){this.replaceChildren();this._text=String(value??'');}
    get isConnected(){return this.isRoot||!!this.parentElement?.isConnected;}
    append(...items){for(const item of items){const node=typeof item==='string'?Object.assign(new Element('span'),{textContent:item}):item;if(node.parentElement)node.remove();node.parentElement=this;this.children.push(node);}}
    replaceChildren(...items){for(const child of this.children)child.parentElement=null;this.children=[];this._text='';this.append(...items);}
    remove(){if(this.parentElement){this.parentElement.children=this.parentElement.children.filter(child=>child!==this);this.parentElement=null;}}
    setAttribute(key,value){this.attributes[key]=String(value);}
    getAttribute(key){return this.attributes[key]??null;}
    removeAttribute(key){delete this.attributes[key];}
    addEventListener(type,callback){(this.listeners[type]??=[]).push(callback);}
    dispatchEvent(event){for(const callback of this.listeners[event.type]||[])callback(event);return true;}
    async click(){await Promise.all((this.listeners.click||[]).map(callback=>callback({preventDefault(){}})));}
    showModal(){this.open=true;}
    close(){this.open=false;this.dispatchEvent({type:'close'});}
    focus(){document.activeElement=this;}
    contains(target){return this===target||this.children.some(child=>child.contains?.(target));}
    querySelectorAll(selector){const tags=selector.split(',').map(part=>part.trim().replace(/:.*/,'').toUpperCase());const found=[];const visit=node=>{for(const child of node.children){if(tags.includes(child.tagName)&&(!selector.includes(':not(:disabled)')||!child.disabled))found.push(child);visit(child);}};visit(this);return found;}
    querySelector(selector){return this.querySelectorAll(selector)[0]||null;}
}
document={documentElement:new Element('html'),body:new Element('body'),readyState:'loading',visibilityState:'visible',listeners:{},createElement:tag=>new Element(tag),querySelector:()=>null,getElementById(id){const visit=node=>{if(node.id===id||node.attributes?.id===id)return node;for(const child of node.children||[]){const found=visit(child);if(found)return found;}return null;};return visit(this.body)||visit(this.documentElement);},
    addEventListener(type,callback){(this.listeners[type]??=[]).push(callback);}};
document.documentElement.isRoot=true;document.body.isRoot=true;document.activeElement=document.body;
const window={listeners:{},addEventListener(type,callback){(this.listeners[type]??=[]).push(callback);},dispatchEvent(event){for(const callback of this.listeners[event.type]||[])callback(event);},confirm:()=>true};
const sandbox={document,window,location:{pathname:'/fleet-ops',assign(){throw new Error('No navigation permitted in isolated QA.');}},crypto,Blob,AbortController,TextEncoder,console,
    URL:{revokeObjectURL:url=>revoked.push(url),createObjectURL(){const url='blob:DEMO-qa-'+created.length;created.push(url);return url;}},
    MutationObserver:class{observe(){}},requestAnimationFrame(){},setTimeout,clearTimeout,CustomEvent:class{constructor(type,init={}){this.type=type;this.detail=init.detail;}},
    fetch:async()=>{throw new Error('No network is permitted in isolated QA.');}};
// Expose only fixture controls in this VM copy. Production source is never changed.
const hooks=`
window.__lifecycleQa={
 state:()=>({session,pending,busy,epoch,dialog,content,status,transport:nativeCrewTransport,mediaEpoch,urls:mediaUrls.size}),
 prepare:()=>ensureDialog(),
 seedPending:intent=>{pending={intent,native:true,kind:'enrollment',owner:session.user,company:session.company,message:'DEMO approval received'};activeView='enrollments';},
 send:()=>sendPending(),photo:()=>{privatePhoto(content,'DEMO_media_selfie','DEMO private selfie');return content.children.at(-1).children[0];}
};
`;
const source=fs.readFileSync(path.join(__dirname,'../public/mapa-voice.js'),'utf8');
check(source.endsWith('})();\n')||source.endsWith('})();\r\n'),'production UI ending is recognized before VM-only instrumentation');
const instrumented=source.replace(/\}\)\(\);\s*$/,hooks+'})();');
vm.createContext(sandbox);vm.runInContext(instrumented,sandbox,{filename:'mapa-voice.production.js'});
const api=window.MapaCrewReview,qa=window.__lifecycleQa;
const id='DEMO_enrollment_qa',identity={user:'user_DEMO_reviewer',company:'DEMO_company',name:'DEMO reviewer',role:'admin'};
const intent=Object.freeze({path:'/enrollments/'+id+'/approve',body:JSON.stringify({expected_revision:2,vehicle_id:'vehicle_DEMO',place_id:'place_DEMO',identity_confirmed:true,action_id:crypto.randomUUID()})});
const calls=[];let failFirst=true,photoResolver=null;
const request=async (target,options)=>{calls.push({target,body:options.body});if(failFirst){failFirst=false;throw Object.assign(new Error('DEMO uncertain response'),{status:0});}return{contract:'mapa-native-mobile-v1',ok:true,enrollment:{id,status:'approved',revision:3}};};
let valid=true;
const media=async()=>new Blob(['DEMO synthetic camera bytes'],{type:'image/jpeg'});
const transport={request,media,identity:()=>identity,valid:()=>valid};
(async()=>{
    check(api.configure(transport),'scoped native reviewer configures');qa.prepare();
    check(qa.state().session.nativeReview===true&&!qa.state().session.token,'native review stores no secondary bearer token');
    qa.seedPending(intent);await qa.send();
    check(qa.state().pending.intent===intent&&calls.length===1,'uncertain receipt retains original immutable intent');
    check(qa.state().content.textContent.includes('Retry the same update'),'uncertain state offers same-action retry');
    check(api.open(),'pending review initially opens without refresh');
    const originalEpoch=qa.state().epoch,originalSession=qa.state().session;
    qa.state().dialog.close();check(!qa.state().dialog.open&&qa.state().pending.intent===intent,'close retains same reviewer pending action');
    const sameTransport={request,media,identity:()=>({...identity}),valid:()=>valid};
    check(api.configure(sameTransport),'same reviewer and exact callbacks can reopen pending review');
    check(qa.state().epoch===originalEpoch&&qa.state().session===originalSession&&qa.state().pending.intent===intent,'reconfigure preserves pending body/session/epoch');
    check(api.open({driverId:'driver_DEMO_other'}),'same reviewer reopens recovery dialog');
    check(qa.state().dialog.open&&calls.length===1,'reopen does not refresh or send a replacement operation');
    check(!api.configure({...sameTransport,identity:()=>({...identity,user:'user_DEMO_other'})}),'pending action cannot switch to another reviewer');
    check(!api.configure({...sameTransport,identity:()=>({...identity,company:'DEMO_other_company'})}),'pending action cannot switch organization');
    check(!api.configure({...sameTransport,request:async()=>({})}),'matching IDs cannot substitute another request callback while pending');
    check(!api.configure({...sameTransport,media:async()=>({})}),'matching IDs cannot substitute another media callback while pending');
    check(qa.state().pending.intent===intent,'failed configuration never mutates immutable original action');
    await qa.send();
    check(calls.length===2&&calls[0].target===calls[1].target&&calls[0].body===calls[1].body,'recovery retries exact byte-for-byte action key and details');
    check(qa.state().pending===null,'only valid confirmed receipt clears pending operation');
    const button=qa.photo();await button.click();
    check(created.length===1&&qa.state().urls===1,'private selfie display uses one tracked local blob URL');
    const privateText=new Element('p');privateText.textContent='DEMO private crew email/contact';qa.state().content.append(privateText);
    qa.seedPending(intent);api.invalidate();
    check(qa.state().session===null&&qa.state().pending===null,'invalidation removes prior native session and action from future identity');
    check(!qa.state().dialog.open&&qa.state().content.textContent===''&&qa.state().status.textContent==='','invalidation immediately clears private rendered DOM and status and closes review');
    check(qa.state().urls===0&&revoked.includes(created[0]),'invalidation revokes all private blob URLs');
    const deferredMedia=()=>new Promise(resolve=>{photoResolver=resolve;});
    check(api.configure({...transport,media:deferredMedia}),'same valid reviewer can reconnect after local invalidation');
    const delayedButton=qa.photo();const delayed=delayedButton.click();
    check(typeof photoResolver==='function','private photo request is in flight');
    api.invalidate();
    const nextIdentity={...identity,user:'user_DEMO_next',name:'DEMO next reviewer'};
    check(api.configure({...transport,identity:()=>nextIdentity}),'new reviewer configures after old identity state is removed');
    photoResolver(new Blob(['DEMO old photo bytes'],{type:'image/jpeg'}));await delayed;
    check(created.length===1&&qa.state().content.textContent==='','old in-flight private photo cannot populate new identity DOM or create a blob URL');
    valid=false;check(!api.open(),'invalid native transport cannot reopen review');
    check(calls.every(call=>call.target===intent.path),'lifecycle recovery sends only the intended scoped action and no queue/duty/location requests');
    console.log(checks+' native voice-review lifecycle checks passed; isolated DOM only, no network or accounts.');
})().catch(error=>{console.error(error);process.exitCode=1;});
