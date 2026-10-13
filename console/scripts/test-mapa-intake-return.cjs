const fs=require('fs'),vm=require('vm'),assert=require('assert'),path=require('path');
const source=fs.readFileSync(path.join(__dirname,'../public/mapa-voice.js'),'utf8');
const line=source.split('\n').find(s=>s.includes("const close = button(standalone"));
const callback=line.slice(line.indexOf('() =>'),line.indexOf('; close.setAttribute')-1);
let checks=0;
function run({embedded=false,guard=true,parentMethod=true,standalone=true}={}) {
 let exits=0,assigns=0,dialogs=0,guards=0;
 const parent=parentMethod?{mapaCloseIntake:()=>{exits++;return guard;}}:{};
 const w={mapaIntakeCanLeave:()=>{guards++;return guard;},top:{location:{assign:()=>assigns++}}};w.parent=embedded?parent:w;
 const fn=vm.runInNewContext(callback,{standalone,window:w,location:{assign:()=>assigns++},dialog:{close:()=>dialogs++}});fn();return {exits,assigns,dialogs,guards};
}
assert.deepStrictEqual(run({embedded:true}),{exits:1,assigns:0,dialogs:0,guards:0});checks++;
assert.equal(run({embedded:true,guard:false}).assigns,0);checks++;
assert.equal(run({embedded:true,guard:false}).exits,1);checks++;
assert.equal(run({}).assigns,1);checks++;
assert.equal(run({guard:false}).assigns,0);checks++;
assert.equal(run({standalone:false}).dialogs,1);checks++;
assert.equal(run({embedded:true,parentMethod:false}).assigns,1);checks++;
console.log(`PASS ${checks} intake return navigation checks; no browser, calls or operational writes.`);

