'use strict';
// Contract/lifecycle test with a stub Google SDK. No Google account or network use.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const core = require('../public/mapa-voice.js');
const source = fs.readFileSync(require.resolve('../public/mapa-voice.js'), 'utf8');
const start = source.indexOf('    function pickupMap('), end = source.indexOf('    function openCrewAdmin(', start);
let checks=0;
const check=(condition,message)=>{checks++;assert.ok(condition,message);};
for(const query of ['+91 919999999999','person@example.com','https://example.com', 'a'.repeat(161),'ab']) check(core.locationQuery(query)==='', 'Reject contacts, URLs and invalid queries: '+query.slice(0,20));
check(core.googleSearchUrl('')==='https://www.google.com/maps?authuser=0', 'Empty search preserves the requested Google Maps link.');
const broadSearch = new URL(core.googleSearchUrl('Araku railway station'));
check(broadSearch.origin==='https://www.google.com'&&broadSearch.searchParams.get('query')==='Araku railway station'&&broadSearch.searchParams.get('authuser')==='0', 'Browser search uses the place query without a district filter.');
check(core.googleSearchUrl('person@example.com')===core.googleSearchUrl(''), 'Do not forward a rejected query to Google.');
check(core.pastedPickupPoint(' -33.8688, 151.2093 ').latitude===-33.8688, 'Coordinates outside the district remain selectable.');
check(core.pastedPickupPoint('0, 0').longitude===0, 'Accept valid zero coordinates.');
for (const value of ['91, 82','18, 181','18,','NaN, 82','18, 82, 3','18e2,82','https://www.google.com/maps/@18.0,82.0,14z','https://maps.app.goo.gl/example']) check(core.pastedPickupPoint(value)===null, 'Reject invalid or ambiguous pasted pin: '+value);
check(core.locationQuery('పాడేరు')==='పాడేరు','Telugu query remains intact.');
check(core.googlePlaceCandidate({location:{lat:()=>91,lng:()=>82}})===null,'Reject invalid provider coordinates.');
const nodes=[],frames=[],searches=[]; let selected=0, mapCleared=0, clickRemoved=0;
class Element {
 constructor(tag,cls='',text=''){Object.assign(this,{tag,className:cls,text,children:[],events:{},isConnected:true,value:'',classList:{add(){}}});nodes.push(this);}
 append(...items){this.children.push(...items);} replaceChildren(...items){this.children=items;}
 addEventListener(name,fn){this.events[name]=fn;} setAttribute(){} removeAttribute(){}
}
const el=(...args)=>new Element(...args);
class MapStub {
 constructor(){this.zoom=10;} fitBounds(bounds){this.bounds=bounds;} setZoom(value){this.zoom=value;} getZoom(){return this.zoom;} setCenter(point){this.center=point;}
 addListener(name,fn){this.click=fn;return {remove(){clickRemoved++;}};}
}
class CircleStub { constructor(args){this.args=args;} setMap(){} setCenter(){} addListener(){return {remove(){}};} }
const maps={importLibrary:async name=>name==='maps'?{Map:MapStub}:{Place:{searchByText:request=>new Promise((resolve,reject)=>searches.push({request,resolve,reject}))}},Circle:CircleStub,event:{clearInstanceListeners(){mapCleared++;}}};
const context=vm.createContext({el,field(parent,label,name,value){const n=el('input');n.name=name;n.value=value;parent.append(n);return n;},button(text,fn){const n=el('button','',text);n.events.click=fn;return n;},busy:false,pending:null,recommendations:null,deskSnapshot:null,deskLoadedAt:0,pickupMapConfig:{google_maps_configured:true,google_maps_api_key:'AIza'+'x'.repeat(35)},ASR_BOUNDS:core.ASR_BOUNDS,locationQuery:core.locationQuery,googleSearchUrl:core.googleSearchUrl,pastedPickupPoint:core.pastedPickupPoint,googlePlaceCandidate:core.googlePlaceCandidate,pickupCandidate:core.pickupCandidate,googleFailureListeners:new Set(),googleAuthFailed:false,loadGoogleMaps:async()=>maps,window:{google:{maps}},string:value=>typeof value==='string'?value:'',request(){throw new Error('Google mode must not silently call another provider');},requestAnimationFrame:fn=>frames.push(fn),setTimeout(){return 1;},clearTimeout(){},removePickupMap:null});
const inputs={latitude:el('input'),longitude:el('input'),pickup_address:el('input')};
const pickupMap=vm.runInContext('('+source.slice(start,end).trim()+')',context);
const settle=()=>new Promise(resolve=>setImmediate(resolve));
(async()=>{
 pickupMap(el('section'),inputs,()=>selected++);frames.shift()();await settle();
 const query=nodes.find(n=>n.name==='location_search'), search=nodes.find(n=>n.tag==='button'&&n.text==='Search map'),results=nodes.find(n=>n.className==='mapa-voice-search-results');
 check(search.textContent==='Search Google Maps','Google map enables Places search in the same form.');
 query.value='ITDA';const operation=search.events.click();await settle();
 check(searches.length===1,'One explicit query calls Places once.');
 const request=searches[0].request;
 check(request.region==='in'&&request.maxResultCount===8,'India region and bounded results.');
 check(request.fields.join(',')==='displayName,formattedAddress,location','Request only the place fields needed by dispatch.');
 check(!JSON.stringify(request).includes('callback_number'),'No call details are in Google search requests.');
 searches[0].resolve({places:[{displayName:'TEST place',formattedAddress:'TEST address',location:{lat:()=>18.1,lng:()=>82.6}}]});await operation;
 check(selected===0&&inputs.latitude.value==='','Suggestions never select or confirm a pickup automatically.');
 inputs.pickup_address.value='Previous pickup';
 results.children.find(n=>n.tag==='button').events.click();
 check(inputs.latitude.value==='18.100000'&&inputs.longitude.value==='82.600000'&&selected===1,'Explicit place selection transfers a candidate pin.');
 check(inputs.pickup_address.value==='TEST place, TEST address','Google candidate uses the same explicit address replacement as key-free search.');
 query.value='Old place';const old=search.events.click();await settle();query.value='New place';query.events.input({isComposing:false});searches[1].resolve({places:[]});await old;
 check(results.children.length===0,'Stale Google responses cannot overwrite a changed query.');
 const googleLink=nodes.find(n=>n.tag==='a'&&n.text==='Open Google Maps ↗'),paste=nodes.find(n=>n.name==='pickup_pasted_coordinates'),apply=nodes.find(n=>n.text==='Use these coordinates');
 check(googleLink.target==='_blank'&&googleLink.rel==='noopener noreferrer'&&new URL(googleLink.href).searchParams.get('query')==='New place','Google opens separately without sharing the operator page or its referrer.');
 const failed=search.events.click();await settle();searches[2].reject(new Error('Unavailable catalog'));await failed;
 check(results.children[0].text.includes('still click or drag a pin'),'Failed search explains available pin selection.');
 inputs.pickup_address.value='Caller-confirmed entrance';paste.value='18.25, 82.75';let prevented=false;
 paste.events.keydown({key:'Enter',preventDefault(){prevented=true;}});
 check(prevented,'Enter applies the pin without submitting the operational call form.');
 check(inputs.latitude.value==='18.250000'&&inputs.longitude.value==='82.750000'&&selected===2,'Pasted pin still works after search failure.');
 check(inputs.pickup_address.value==='Caller-confirmed entrance','Pasting coordinates preserves the manually entered address for review.');
 paste.value='https://www.google.com/maps/@20,80,14z';apply.events.click();
 check(inputs.latitude.value==='18.250000'&&selected===2,'An ambiguous Maps camera URL never changes the pickup.');
 context.pending={};paste.value='17, 82';apply.events.click();check(selected===2,'Pending mutation still protects the draft.');context.pending=null;
 context.removePickupMap();
 check(mapCleared===1&&clickRemoved===1&&context.googleFailureListeners.size===0,'Unmount detaches Google map listeners and marker lifecycle.');
 await search.events.click();apply.events.click();check(searches.length===3&&selected===2,'An unmounted form cannot search or apply coordinates.');
 console.log(`MAPA Google pickup: ${checks} checks passed (stub SDK; live Google activation remains separate).`);
})().catch(error=>{console.error(error);process.exitCode=1;});
