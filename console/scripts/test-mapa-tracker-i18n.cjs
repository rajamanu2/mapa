'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');

class TextNode {constructor(data){this.nodeType=3;this.data=data;}}
class Element {
 constructor(tag='div',children=[]){this.nodeType=1;this.tagName=tag.toUpperCase();this.childNodes=children;this.attributes={};this.listeners={};}
 getAttribute(name){return this.attributes[name]??null;}
 setAttribute(name,value){this.attributes[name]=String(value);}
 addEventListener(name,callback){this.listeners[name]=callback;}
 click(){let prevented=false;this.listeners.click?.({preventDefault(){prevented=true;}});return prevented;}
}
const heading=new TextNode('Your ambulance journey');
const consent=new TextNode('I am at the pickup and agree to share this phone’s location with ASR Health for this trip.');
const status=new TextNode('Checking your private trip link…');
const attribution=new TextNode('Road map data © ');
const inactive=new TextNode('This tracker link is no longer active');
const aria=new Element('section',[heading,consent,status,attribution,inactive]);aria.setAttribute('aria-label','Trip map');
const en=new Element('button'),te=new Element('button');
const body=new Element('body',[aria,en,te]);
const html=new Element('html');
const document={body,documentElement:html,title:'',getElementById:id=>({'language-en':en,'language-te':te})[id]};
let observe;
class MutationObserver {constructor(callback){observe=callback;}observe(){}}
const source=fs.readFileSync(path.join(__dirname,'../public/track/tracking-i18n.js'),'utf8');
const scrolls=[],viewport={scrollY:620,scrollTo(x,y){scrolls.push([x,y]);}};
vm.runInNewContext(source,{document,navigator:{language:'te-IN'},MutationObserver,WeakMap,console,window:viewport});
assert.equal(html.lang,'te');
assert.equal(heading.data,'మీ అంబులెన్స్ ప్రయాణం');
assert.match(consent.data,/అంగీకరిస్తున్నాను/);
assert.equal(aria.getAttribute('aria-label'),'ప్రయాణ మ్యాప్');
assert.equal(te.getAttribute('aria-pressed'),'true');
assert.equal(attribution.data,'రహదారి మ్యాప్ సమాచారం © ');
assert.equal(inactive.data,'ఈ ట్రాకర్ లింక్ ఇకపై పనిచేయదు');

status.data='Your ambulance is assigned. Follow its reported location below.';
observe([{type:'characterData',target:status}]);
assert.match(status.data,/అంబులెన్స్ కేటాయించారు/);
assert.equal(en.click(),true,'language click cancels default navigation');
assert.deepEqual(scrolls.at(-1),[0,620],'language switch keeps the caller at the same page position');
assert.equal(html.lang,'en');
assert.equal(heading.data,'Your ambulance journey');
assert.equal(status.data,'Your ambulance is assigned. Follow its reported location below.');
assert.equal(aria.getAttribute('aria-label'),'Trip map');
assert.equal(attribution.data,'Road map data © ');
assert.equal(te.click(),true,'Telugu switch cancels default navigation');
assert.deepEqual(scrolls.at(-1),[0,620]);
assert.equal(heading.data,'మీ అంబులెన్స్ ప్రయాణం');
status.data='Phone position: 18.338324, 82.846919 · accuracy about 20 m.';
observe([{type:'characterData',target:status}]);
assert.match(status.data,/18\.338324, 82\.846919/);
assert.match(status.data,/ఖచ్చితత్వం/);
status.data='Location sharing could not be verified. The tracker remains open; refresh its trip update and ask the operator for help if sharing still fails.';
observe([{type:'characterData',target:status}]);
assert.match(status.data,/ట్రాకర్ తెరిచే ఉంది/,'GPS upload failure is not translated as expired');
status.data='This trip link has ended, expired or was replaced. Ask your operator for a new link.';
observe([{type:'characterData',target:status}]);
assert.match(status.data,/కొత్త లింక్/,'actual terminal link state has Telugu recovery text');
assert(!source.includes('localStorage')&&!source.includes('sessionStorage')&&!source.includes('fetch('),'language switch does not store tracker tokens or call another service');
console.log('MAPA tracker Telugu/English language switch: 16 checks passed.');
