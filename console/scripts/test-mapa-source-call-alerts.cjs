const assert=require('assert');const {sourceCallUnread}=require('../public/mapa-intake-native.js');const {validRequestPath}=require('../public/mapa-voice.js');let checks=0;
const a={id:'1',source_revision:'a'.repeat(64)},b={id:'2',source_revision:'b'.repeat(64)};
function eq(a,b){assert.deepStrictEqual(a,b);checks++;}function rejects(fn){assert.throws(fn);checks++;}
eq(sourceCallUnread([a,b],new Map()),2);eq(sourceCallUnread([a,b],new Map([['1',a.source_revision]])),1);eq(sourceCallUnread([a],new Map([['1',a.source_revision]])),0);eq(sourceCallUnread([{...a,source_revision:b.source_revision}],new Map([['1',a.source_revision]])),1);eq(sourceCallUnread([],new Map()),0);
rejects(()=>sourceCallUnread([a,a],new Map()));rejects(()=>sourceCallUnread([{id:'../1',source_revision:a.source_revision}],new Map()));rejects(()=>sourceCallUnread([{...a,source_revision:'bad'}],new Map()));rejects(()=>sourceCallUnread(Array(501).fill(a),new Map()));
eq(validRequestPath('/source-calls'),true);eq(validRequestPath('/source-calls/12/open'),true);eq(validRequestPath('/source-calls/12/dispatch'),false);eq(validRequestPath('/source-calls/12/open',true),false);eq(validRequestPath('/source-calls/12/open?token=secret'),false);eq(validRequestPath('/source-calls/../../open'),false);
console.log(`PASS ${checks} source caller alert and route isolation checks; synthetic records only.`);
