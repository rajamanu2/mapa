'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),vm=require('node:vm');
const source=fs.readFileSync(path.join(__dirname,'../public/mapa-voice.js'),'utf8');
const core=require('../public/mapa-voice.js');
const start=source.indexOf('    function deskNavigationAllowed('),end=source.indexOf('    async function loadCommandDesk(',start);
const buttonStart=source.indexOf('    function button('),buttonEnd=source.indexOf('    function announce(',buttonStart);
assert.ok(start>=0&&end>start&&buttonStart>=0&&buttonEnd>buttonStart);
const production=source.slice(buttonStart,buttonEnd)+source.slice(start,end);
let checks=0;
const check=(value,label)=>{assert.ok(value,label);checks++;};
class Element {
    constructor(tag){this.tagName=tag.toUpperCase();this.children=[];this.dataset={};this.attributes={};this.listeners={};this.className='';this.disabled=false;this._text='';this.focused=false;this.scrolled=false;}
    append(...nodes){this.children.push(...nodes);}
    set textContent(value){this._text=String(value);this.children=[];}
    get textContent(){return this._text+this.children.map(node=>node.textContent).join('');}
    setAttribute(name,value){this.attributes[name]=String(value);}
    getAttribute(name){return this.attributes[name];}
    addEventListener(type,fn){this.listeners[type]=fn;}
    click(force=false){if(!this.disabled||force)this.listeners.click?.();}
    focus(){this.focused=true;}
    scrollIntoView(){this.scrolled=true;}
}
const readyCall={id:'call_DEMO_navigation',state:'ready',demo:false,review:{pickup_address:'DEMO pickup'}};
const candidate={id:'driver_DEMO_navigation',vehicle_id:'vehicle_DEMO_navigation',simulated:false};
const keys=['dispatchable_now','total_fleet','on_trip','gps_stale_offline','active_calls'];
function harness({state={},embedded=false,parentOrigin='http://localhost:4200',confirm=true}={}){
    const paths=[],parentPaths=[],messages=[],prompts=[],regions=new Map();let renders=0,network=0;
    const location={origin:'http://localhost:4200',assign:path=>paths.push(path)};
    const window={location,confirm:message=>{prompts.push(message);return confirm;}};
    window.parent=embedded?{location:{origin:parentOrigin,assign:path=>parentPaths.push(path)}}:window;
    const context={
        busy:false,pending:null,dirty:false,editDraft:null,manualEdit:null,resourceEditor:null,selected:null,candidates:[],
        newCall:true,asrSelected:{id:'DEMO_source'},activeView:'calls',queueFilter:'urgent',secondaryTab:'drivers',workflowChoice:{key:'review'},
        deskSnapshot:{summary:{total_fleet:9,on_trip:2,active_calls:3},fleet:[{gps_stale:true},{gps_stale:false},{gps_stale:null}]},deskError:'',deskLoadedAt:1,
        ...state,window,location,eligible:core.eligible,gpsCoverage:core.gpsCoverage,
        el:(tag,className,text)=>{const node=new Element(tag);node.className=className||'';if(text!==undefined)node.textContent=text;return node;},
        announce:(message,error=false)=>messages.push({message,error}),
        content:{querySelector:selector=>regions.get(selector)||null},
        render:()=>{renders++;regions.clear();for(const name of ['.mapa-command-queue','.mapa-command-secondary-content','.mapa-command-hero'])regions.set(name,new Element('section'));
            if(context.selected?.state==='ready'&&context.selected.review){regions.set('.mapa-voice-candidates',new Element('section'));if(!context.selected.supervised_pilot)regions.set('[name="ambulance_choice"]',new Element('select'));}},
        fetch:()=>{network++;throw Error('No network in synthetic navigation tests');}
    };
    vm.createContext(context);vm.runInContext(production,context,{filename:'mapa-voice.navigation.js'});
    const parent=new Element('div');context.renderDeskMetrics(parent);
    const cards=parent.children[0].children.filter(node=>node.tagName==='BUTTON');
    return {context,cards,paths,parentPaths,messages,prompts,regions,stats:()=>({renders,network}),card:key=>cards.find(node=>node.dataset.voiceFocus==='metric:'+key)};
}

const basic=harness();
check(basic.cards.length===5,'All five metrics are semantic action buttons');
for(const card of basic.cards){
    check(card.type==='button'&&!!card.listeners.click&&!!card.title&&!!card.getAttribute('aria-label'),'Metric is keyboard-activatable without form submission and has an accessible destination');
}
check(basic.card('dispatchable_now').textContent.startsWith('Select call'),'Unselected eligibility remains unknown, never a fabricated zero');
check(basic.card('on_trip').title==='Open orders map','On-trip action describes its actual unfiltered orders-map destination');
check(basic.card('gps_stale_offline').getAttribute('aria-label').includes('1 stale · 1 without verified fix · 1 recent'),'GPS evidence remains available in the accessible metric description');

for(const [key,path]of [['total_fleet','/fleet-ops/manage/vehicles'],['on_trip','/fleet-ops?layout=map']]){
    const standalone=harness();standalone.card(key).click();
    assert.deepEqual(standalone.paths,[path]);checks++;check(standalone.parentPaths.length===0,'Standalone native route stays in its own window');
    const embedded=harness({embedded:true});embedded.card(key).click();
    assert.deepEqual(embedded.parentPaths,[path]);checks++;check(embedded.paths.length===0,'Embedded desk navigates native same-origin parent, never nests another console');
    const foreign=harness({embedded:true,parentOrigin:'https://example.invalid'});foreign.card(key).click();
    check(foreign.parentPaths.length===0&&foreign.paths[0]===path,'Different-origin parent is never navigated');
}
const deniedParent=harness({embedded:true});Object.defineProperty(deniedParent.context.window.parent,'location',{get(){throw Error('Cross-origin location denied');}});
deniedParent.card('total_fleet').click();check(deniedParent.paths[0]==='/fleet-ops/manage/vehicles','Unreadable parent falls back to same-window native route');

const queue=harness({state:{selected:readyCall}});queue.card('active_calls').click();
check(queue.context.queueFilter==='all'&&queue.context.selected===null&&!queue.context.newCall&&queue.context.asrSelected===null,'Active calls opens All queue and leaves current selection');
check(queue.regions.get('.mapa-command-queue').focused&&queue.regions.get('.mapa-command-queue').scrolled,'Queue action moves keyboard focus and scroll to its destination');

const gps=harness({state:{selected:readyCall}});gps.card('gps_stale_offline').click();
check(gps.context.secondaryTab==='fleet'&&gps.context.selected===null&&gps.regions.get('.mapa-command-secondary-content').focused,'GPS action opens whole-fleet evidence without retaining call-specific eligibility');
check(gps.messages.at(-1).message.includes('including stale and missing fixes'),'GPS action explains the existing evidence view without inventing a filtered dataset');

const eligible=harness({state:{selected:readyCall,candidates:[candidate]}});eligible.card('dispatchable_now').click();
check(eligible.context.selected===readyCall&&eligible.regions.get('[name="ambulance_choice"]').focused,'Eligible crews retains the reviewed call and focuses the existing chooser');
check(eligible.context.workflowChoice===null&&eligible.context.secondaryTab==='fleet','Eligibility action reveals the existing dispatch section');
check(eligible.paths.length===0&&eligible.parentPaths.length===0&&eligible.stats().network===0,'Eligibility navigation does not dispatch, save, request or leave the desk');
const pilot=harness({state:{selected:{...readyCall,supervised_pilot:true}}});pilot.card('dispatchable_now').click();
check(pilot.regions.get('.mapa-voice-candidates').focused,'Pilot without manual chooser focuses its existing crew-offer panel');
for(const selected of [null,{...readyCall,state:'needs_review',review:null},{...readyCall,state:'dispatched'}]){
    const unavailable=harness({state:{selected}});unavailable.card('dispatchable_now').click();
    check(unavailable.regions.get('.mapa-command-queue').focused&&unavailable.messages.at(-1).message.includes('Select a call and review'),'Unreviewed or completed eligibility points to call selection with a clear instruction');
}

for(const key of keys){
    for(const blockedState of [{busy:true},{pending:{id:'DEMO_pending'}},{dirty:true,manualEdit:{caller_name:'DEMO draft'}},{resourceEditor:{type:'ambulance'}}]){
        const guarded=harness({state:blockedState,confirm:false});const before={...guarded.context};guarded.card(key).click(true);
        check(guarded.paths.length===0&&guarded.parentPaths.length===0&&guarded.stats().renders===0,'Every metric honors update and unsaved-work guards even if invoked while disabled');
        for(const [field,value]of Object.entries(blockedState))assert.equal(guarded.context[field],value);
        check(guarded.context.selected===before.selected&&guarded.context.queueFilter===before.queueFilter,'Cancelled navigation preserves selection and filters');
    }
}
const discarded=harness({embedded:true,state:{dirty:true,manualEdit:{caller_name:'DEMO draft'},editDraft:{id:'DEMO draft'},resourceEditor:{type:'ambulance'}},confirm:true});
discarded.card('total_fleet').click();
check(discarded.prompts.length===1&&!discarded.context.dirty&&discarded.context.manualEdit===null&&discarded.context.editDraft===null&&discarded.context.resourceEditor===null,'Confirmed discard clears only local edit state before leaving and avoids a duplicate unload prompt');
check(discarded.parentPaths[0]==='/fleet-ops/manage/vehicles','Confirmed discard continues to the requested native screen');
const invalid=harness({state:{dirty:true}});invalid.context.navigateDeskMetric('https://example.invalid');
check(invalid.context.dirty&&invalid.prompts.length===0&&invalid.paths.length===0,'Unknown navigation key cannot clear edits or become an arbitrary destination');
console.log(`PASS ${checks} desk navigation checks; synthetic DOM only, no browser or network.`);
