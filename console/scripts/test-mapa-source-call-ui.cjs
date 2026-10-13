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

const hooks=`const callerQaOriginalRequest=request;window.__callerQa={actual:path=>callerQaOriginalRequest(path),prepare:()=>{ensureDialog();dialog.showModal();session={token:'DEMO-token',expires:Date.now()+60000,user:'DEMO-admin',company:'DEMO-company',role:'admin',name:'DEMO operator'};}, request:fn=>{request=fn;},load:()=>loadCalls(),render:()=>render(),state:()=>({asrCalls,selected,asrSelected,content}),guard:()=>{dirty=true;},finish:()=>{dirty=false;}};`;
const source=fs.readFileSync(path.join(__dirname,'../public/mapa-voice.js'),'utf8');
vm.createContext(sandbox);vm.runInContext(source.replace(/\}\)\(\);\s*$/,hooks+'})();'),sandbox);
const qa=window.__callerQa;
const row={id:'701',incident_number:'DEMO-701',caller_name:'DEMO caller <img src=x>',caller_phone:'+12025550142',patient_name:'DEMO patient',pickup_address:'DEMO village',status:'pending',created_at:'2026-07-28 12:30:00',source_revision:'a'.repeat(64)};
const requests=[],read=[];window.parent={mapaSourceCallRead:(_child,id,revision)=>read.push({id,revision})};
const buttons=()=>qa.state().content.querySelectorAll('button');
qa.prepare();qa.request(async(target,options)=>{requests.push({target,options});if(target.startsWith('/calls?'))return{calls:[],capabilities:{manual_intake:true},source_locations:[]};if(target==='/source-calls')return{contract:'mapa-asr-caller-feed-v1',company_id:'DEMO-company',calls:[row],stale:false,truncated:false};throw new Error('Unexpected fixture request '+target);});
(async()=>{
 sandbox.fetch=async()=>({ok:true,status:200,json:async()=>({contract:'mapa-asr-caller-feed-v1',company_id:'DEMO-company',calls:[row]})});
 check((await qa.actual('/source-calls')).calls[0].id==='701','real transport accepts the dedicated caller-feed contract');
 await assert.rejects(()=>qa.actual('/calls'),/supported MAPA Call Intake contract/);checks++;
 await qa.load();check(requests.some(r=>r.target==='/source-calls'),'opening intake fetches ASR calls immediately');
 check(qa.state().content.textContent.includes('DEMO-701'),'ASR incident appears in incoming queue');
 check(qa.state().content.textContent.includes('ASR recorded 2026-07-28 12:30'),'source record date is visible so an old open row is not mistaken for a new call');
 const incoming=buttons().find(button=>button.textContent==='Incoming calls · 0');
 check(!!incoming&&buttons().indexOf(incoming)<buttons().findIndex(button=>button.textContent==='NEW CALL'),'old ASR record is not counted as a fresh unread call, and incoming precedes NEW CALL');
 check(incoming.className.includes('mapa-voice-incoming-tab'),'incoming action has red notification styling');
 check(document.body.textContent.includes('Incoming calls loaded with latest data'),'fresh refresh confirms latest data loaded');
 const choose=buttons().find(b=>b.textContent.includes('DEMO-701'));check(!!choose,'source card is actionable');await choose.click();
 check(qa.state().asrSelected.id==='701'&&qa.state().selected===null,'source selection retains source identity');
 check(qa.state().content.textContent.includes('+12025550142')&&qa.state().content.textContent.includes('DEMO patient'),'caller phone and patient details are visible');
 check(qa.state().content.textContent.includes('<img src=x>')&&qa.state().content.querySelectorAll('img').length===0,'source values render as text, never markup');
 check(buttons().some(button=>button.textContent==='Incoming calls · 0'),'opening details acknowledges local unread count');
 check(read.length===1&&read[0].revision===row.source_revision,'opening a source entry acknowledges the exact source revision');
 check(buttons().some(b=>b.textContent==='Open caller tracking & create link'),'source details expose private-link workflow');
 check(!requests.some(r=>r.options?.body),'opening details sends no operational writes or messages');
 qa.guard();window.confirm=()=>false;await choose.click();check(read.length===1,'declining discard does not acknowledge or replace unsaved work');qa.finish();
 console.log('PASS '+checks+' source caller UI behavioral checks; synthetic DOM, no network or records.');
})().catch(error=>{console.error(error);process.exitCode=1;});

