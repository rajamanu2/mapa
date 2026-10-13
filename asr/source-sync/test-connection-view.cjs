const assert=require('node:assert/strict');
const fs=require('node:fs'),path=require('node:path'),vm=require('node:vm');
class Element {
 constructor(tag){this.tagName=tag;this.children=[];this.attributes={};this._text='';this.value='';this.disabled=false;this.parent=null;}
 set textContent(value){this._text=String(value);this.children=[];}get textContent(){return this._text+this.children.map(c=>c.textContent).join('');}
 set innerHTML(_){throw new Error('Unsafe HTML rendering');}
 append(...children){for(const child of children){child.parent=this;this.children.push(child);}}
 replaceChildren(...children){for(const old of this.children)old.parent=null;this.children=[];this._text='';this.append(...children);}
 setAttribute(key,value){this.attributes[key]=value;}
 remove(){if(this.parent)this.parent.children=this.parent.children.filter(x=>x!==this);this.parent=null;}
}
const code=fs.readFileSync(path.join(__dirname,'../../console/public/mapa-connection-view.js'),'utf8');
const flush=()=>new Promise(resolve=>setImmediate(resolve));
const all=root=>[root,...root.children.flatMap(all)];
const find=(root,fn)=>{const el=all(root).find(fn);assert.ok(el,'Expected DOM node');return el;};
const button=(root,text)=>find(root,e=>e.tagName==='button'&&e.textContent===text);
const labelled=(root,text)=>find(root,e=>e.attributes['aria-label']===text);
let count=0;const check=(ok,label)=>{assert.ok(ok,label);count++;};
function fixture(kind){
 const document={hidden:false,createElement:tag=>new Element(tag),createTextNode:text=>{const el=new Element('#text');el.textContent=text;return el;}};
 const window={dispatchEvent(){}},requests=[],mount=new Element('main');
 vm.runInNewContext(code,{document,window,Event:class{constructor(type){this.type=type;}},console});
 const request=(action,params)=>new Promise((resolve,reject)=>requests.push({action,params,resolve,reject}));
 const view=window.MapaConnectionView.view({mount,request,kind});return {mount,requests,view};
}
const sourceRow={label:'DEMO <img onerror=bad()>',source_id:'DEMO-source',connection:'not_linked',source_revision:'a'.repeat(64),link_revision:0};
const choices=(page,has_more=true)=>({rows:[{label:'DEMO native '+page,public_id:'DEMO-public-'+page,uuid:'DEMO-uuid-'+page}],has_more});
(async()=>{
const f=fixture('connections');f.requests[0].resolve({rows:[sourceRow],total:1,has_more:false,stale:false});await flush();
check(f.mount.textContent.includes(sourceRow.label)&&!all(f.mount).some(el=>el.tagName==='img'),'source labels remain literal text');
const opened=button(f.mount,'Review identities and link').onclick();
const save=button(f.mount,'Save reviewed link'),search=button(f.mount,'Search native records'),more=button(f.mount,'Load more native records'),query=labelled(f.mount,'Search native records');
check(f.requests.at(-1).params.page==='1','initial options page1');check(save.disabled&&search.disabled&&more.disabled,'loading disables save/search/more');
f.requests.at(-1).reject(new Error('DEMO initial network failure'));await opened;
check(save.disabled&&!search.disabled&&more.disabled,'failed initial load keeps save disabled and enables retry');
const initialRetry=search.onclick();check(f.requests.at(-1).params.page==='1','initial retry stays on page1');
f.requests.at(-1).resolve(choices(1));await initialRetry;
check(!save.disabled&&!more.disabled,'successful options enable review and pagination');
const select=labelled(f.mount,'Select native identity');check(select.children[0].value===''&&select.value==='','native identity never automatically chosen');
query.value='DEMO typed but not searched';
const page2=more.onclick();const requestCount=f.requests.length;
await more.onclick();await search.onclick();
check(f.requests.length===requestCount,'double click and programmatic search while busy do not create requests');
check(f.requests.at(-1).params.page==='2'&&f.requests.at(-1).params.search==='','load more retains last successful search');
check(search.disabled&&more.disabled&&save.disabled,'pagination in flight blocks conflicting review actions');
f.requests.at(-1).reject(new Error('DEMO page2 failure'));await page2;
check(!more.disabled,'failed page2 remains retryable');
const page2retry=more.onclick();check(f.requests.at(-1).params.page==='2','failed page2 retry does not skip to page3');
f.requests.at(-1).resolve(choices(2));await page2retry;
check(select.children.length===3&&select.children[2].value==='DEMO-uuid-2','retry appends page2 exactly once');
const page3=more.onclick();check(f.requests.at(-1).params.page==='3','successful page2 advances next request to3');
f.requests.at(-1).resolve(choices(3,false));await page3;check(more.disabled,'last options page disables more');
query.value='  DEMO changed search  ';const newSearch=search.onclick();
check(f.requests.at(-1).params.page==='1'&&f.requests.at(-1).params.search==='DEMO changed search','explicit search resets page1 and captures trimmed query');
f.requests.at(-1).resolve(choices('new1'));await newSearch;
check(select.children.length===2&&select.children[1].value==='DEMO-uuid-new1','new search replaces previous options');
query.value='DEMO edited while paging';const nextSearchPage=more.onclick();
check(f.requests.at(-1).params.page==='2'&&f.requests.at(-1).params.search==='DEMO changed search','subsequent page uses committed filter despite input edits');
const pending=f.requests.at(-1);f.view.close();pending.resolve(choices('late'));await nextSearchPage;
check(f.mount.children.length===0,'closed view ignores late options response');
const unavailable=fixture('reconciliation');unavailable.requests[0].resolve({rows:[],pending_count:0,total:0,has_more:false,stale:false,journal_available:false});await flush();
check(unavailable.mount.textContent.includes('Source change history is not active yet'),'inactive journal is clearly identified');
check(!unavailable.mount.textContent.includes('Verified source snapshot'),'inactive journal never claims verified active queue');
const active=fixture('reconciliation');active.requests[0].resolve({rows:[],pending_count:0,total:0,has_more:false,stale:false,journal_available:true});await flush();
check(active.mount.textContent.includes('Verified source snapshot'),'active journal retains verified status');
const stale=fixture('reconciliation');stale.requests[0].resolve({rows:[],pending_count:0,total:0,has_more:false,stale:true,journal_available:true});await flush();
check(stale.mount.textContent.includes('Updates delayed'),'stale active journal clearly marked');
console.log('SOURCE CONNECTION VIEW PASS '+count+' checks');
})().catch(error=>{console.error(error);process.exitCode=1;});
