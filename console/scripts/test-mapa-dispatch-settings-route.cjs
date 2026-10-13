'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');
let checks=0;
const check=(condition,label)=>{assert.ok(condition,label);checks++;};
class Element {
  constructor(tag){this.tagName=tag.toUpperCase();this.children=[];this.parentElement=null;this.attributes={};this._text='';this.disabled=false;this.hidden=false;this.className='';this.classList={add:()=>{},remove:()=>{}};}
  get isConnected(){return !!this.parentElement;}
  get textContent(){return this._text+this.children.map(child=>child.textContent).join('');}
  set textContent(value){this.replaceChildren();this._text=String(value??'');}
  setAttribute(name,value){this.attributes[name]=String(value);if(name==='class')this.className=String(value);}
  removeAttribute(name){delete this.attributes[name];}
  append(...children){for(const child of children){child.remove();child.parentElement=this;this.children.push(child);}}
  replaceChildren(...children){for(const child of this.children)child.parentElement=null;this.children=[];this._text='';this.append(...children);}
  remove(){if(this.parentElement){this.parentElement.children=this.parentElement.children.filter(child=>child!==this);this.parentElement=null;}}
  contains(node){return this===node||this.children.some(child=>child.contains(node));}
  querySelector(selector){return selector==='[role="alert"]'?this.children.find(child=>child.attributes.role==='alert')||null:null;}
}
const navigation=new Element('nav'),section=new Element('section'),root=new Element('main');root.append(navigation,section);
const sidebarSelector='#sidebar-menu-items .next-sidebar-navigator-viewport.is-nested .next-sidebar-navigator-view-in';
const selectors=[],document={hidden:false,createElement:tag=>new Element(tag),querySelector:selector=>{selectors.push(selector);if(selector===sidebarSelector)return navigation;if(selector==='section.next-view-section')return section;return null;},addEventListener(){},removeEventListener(){}};
let pathname='/fleet-ops',initialize,destructor;const timers=[];const methods=[];
const session={isAuthenticated:true,data:{authenticated:{token:'DEMO-token',user:'DEMO-admin'}}},user={companyId:'DEMO-company'};
const response=()=>({ok:true,json:async()=>({contract:'mapa-dispatch-settings-v1',enabled:true,worker_fresh:true,server_ready:true,enrolled_crews:2,revision:1})});
let fetchResult=async()=>response();
let clock=Date.now();class TestDate extends Date {static now(){return clock;}}
const owner={lookup(name){if(name==='service:session')return session;if(name==='service:current-user')return user;if(name==='service:fetch')return{getHeaders:()=>({Authorization:'Bearer DEMO'})};if(name==='service:router')return{on(){},off(){}};return null;}};
const sandbox={window:{define(_name,_deps,register){const exports={};register(exports,{registerDestructor(_owner,fn){destructor=fn;}});initialize=exports.default.initialize;},confirm:()=>false},document,location:{get pathname(){return pathname;}},AbortController,Date:TestDate,JSON,Object,Error,Number,Math,
  setInterval(fn,delay){const timer={fn,delay,active:true};timers.push(timer);return timer;},clearInterval(timer){if(timer)timer.active=false;},setTimeout(){return 1;},clearTimeout(){},
  fetch:async(_url,options)=>{methods.push(options.method);return fetchResult();}};
vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../public/mapa-dispatch-settings.js'),'utf8'),sandbox,{filename:'mapa-dispatch-settings.js'});
initialize(owner);
const mount=timers.find(timer=>timer.delay===1500)?.fn;
check(typeof mount==='function'&&navigation.children.length===0,'unrelated operations route has no dispatch Settings item');
pathname='/fleet-ops/settings';mount();
check(navigation.children.length===1&&navigation.children[0].textContent==='Automatic Dispatch','real MAPA Settings route mounts one entry');
check(selectors.includes(sidebarSelector)&&navigation.children[0].className.includes('next-sidebar-navigator-item'),'entry uses the live Settings navigator structure');
const item=navigation.children[0];mount();check(navigation.children.length===1&&navigation.children[0]===item,'polling does not duplicate the entry');
(async()=>{
  await item.onclick();
  check(selectors.includes('section.next-view-section')&&section.children.some(child=>child.className==='mapa-dispatch-panel'),'entry opens inside the Settings main section');
  check(methods.length===1&&methods[0]==='GET','opening Settings reads server status without writing');
  const buttons=node=>node.children.flatMap(child=>[...(child.tagName==='BUTTON'?[child]:[]),...buttons(child)]);
  const refresh=()=>buttons(section).find(button=>button.textContent==='Refresh status').onclick();
  check(section.textContent.includes('Live')&&section.textContent.includes('ON'),'successful read reports current enabled worker');
  fetchResult=async()=>{throw Error('Network unavailable');}; await refresh();
  check(!section.textContent.includes('Live')&&!section.textContent.includes('Automatic offersON')&&section.textContent.includes('Network unavailable'),'failed refresh clears previous healthy claims');
  check(!buttons(section).some(button=>button.textContent.startsWith('Turn ')),'unverified status cannot issue revision writes');
  fetchResult=async()=>response(); await refresh();
  check(section.textContent.includes('Live'),'recovery restores verified server state');
  let resolveOld;fetchResult=()=>new Promise(resolve=>{resolveOld=resolve;}); const oldRefresh=refresh();
  pathname='/fleet-ops';mount();pathname='/fleet-ops/settings';mount();
  let resolveNew;fetchResult=()=>new Promise(resolve=>{resolveNew=resolve;});const reopened=navigation.children[0].onclick();
  check(!section.textContent.includes('Live'),'reopening clears cached settings before fresh response');
  resolveOld(response());await oldRefresh;
  check(!section.textContent.includes('Live'),'old response cannot populate reopened panel for same account');
  resolveNew(response());await reopened;
  let resolveBody;fetchResult=async()=>({ok:true,json:()=>new Promise(resolve=>{resolveBody=resolve;})});const parsing=refresh();await new Promise(resolve=>setImmediate(resolve));
  user.companyId='DEMO-other-company';mount();fetchResult=async()=>response();await navigation.children[0].onclick();
  resolveBody({contract:'mapa-dispatch-settings-v1',enabled:false,server_ready:false,enrolled_crews:999,revision:99});await parsing;
  check(!section.textContent.includes('999')&&section.textContent.includes('Live'),'response body completing after company switch is discarded');
  const staleToggle=buttons(section).find(button=>button.textContent.startsWith('Turn off'));
  clock+=30001;sandbox.window.confirm=()=>true;await staleToggle.onclick();mount();
  check(methods.every(method=>method==='GET'),'expired action captured before refresh cannot POST a stale revision');
  check(!section.textContent.includes('Live')&&section.textContent.includes('no longer current'),'clock expiry invalidates health even when refresh is delayed');
  check(!buttons(section).some(button=>button.textContent.startsWith('Turn ')),'aged settings have no stale revision toggle');
  await refresh();check(section.textContent.includes('Live'),'manual refresh recovers expired status');
  pathname='/fleet-ops/settings/two-fa';mount();check(navigation.children.length===1,'nested Settings route retains the entry');
  pathname='/fleet-ops/settings-other';mount();check(navigation.children.length===0&&section.children.length===0,'similarly named unrelated route removes item and panel');
  pathname='/settings';mount();check(navigation.children.length===0,'legacy root Settings path is outside MAPA fleet settings');
  pathname='/fleet-ops/settings';mount();check(navigation.children.length===1,'return to real Settings mounts the entry again');
  destructor();check(navigation.children.length===0,'owner teardown cleans up the entry');
  check(methods.every(method=>method==='GET'),'failure and session tests made zero writes');
  console.log(checks+' MAPA dispatch Settings route checks passed; isolated DOM, zero writes.');
})().catch(error=>{console.error(error);process.exitCode=1;});

