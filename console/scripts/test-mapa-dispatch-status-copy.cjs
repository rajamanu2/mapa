// Render the production dispatch status with isolated DEMO data and no network.
'use strict';
const fs=require('node:fs'),path=require('node:path');
const harness=fs.readFileSync(path.join(__dirname,'test-mapa-new-call-workbench.cjs'),'utf8');
const marker='(async()=>{';
const start=harness.indexOf(marker);
if(start<0)throw new Error('MAPA workbench DOM harness not found');
const checks=`
const active={...call,supervised_pilot:true,automatic_dispatch:{state:'offering',cascade_active:true,first_acceptance_wins:true,outstanding_count:2,offer_count:2,offers:[]}};
let root=qa.seed(active);
check(root.textContent.includes('Outstanding crews may still accept'),'active pilot with outstanding offers shows acceptance guidance');
const closed={...call,supervised_pilot:true,automatic_dispatch:{state:'needs_operator',reason:'no_eligible_crew',first_acceptance_wins:true,outstanding_count:0,offer_count:2,offers:[{driver_name:'DEMO Crew 1',vehicle_registration:'DEMO-AMB1',state:'cancelled',timeout_seconds:120,road_seconds:60,expires_at:new Date().toISOString()},{driver_name:'DEMO Crew 2',vehicle_registration:'DEMO-AMB2',state:'cancelled',timeout_seconds:120,road_seconds:90,expires_at:new Date().toISOString()}]}};
root=qa.seed(closed);
check(!root.textContent.includes('Outstanding crews may still accept')&&!root.textContent.includes('FIRST ACCEPTANCE WINS ASSIGNMENT'),'closed pilot with no outstanding offers hides active acceptance guidance');
check(root.textContent.includes('cancelled unanswered test offers')&&root.textContent.includes('cannot receive another offer for it')&&root.textContent.includes('new supervised no-patient call'),'closed pilot explains cancellation and how to repeat a two-crew drill');
check(networkCalls===0,'production DOM fixture makes no network calls');
console.log(checks+' dispatch status copy checks passed; isolated DEMO data, zero network.');
`;
new Function('require','__dirname','process','console',harness.slice(0,start)+checks)(require,__dirname,process,console);
