'use strict';
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const{chromium}=require('C:/Users/roysh/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const publicDir=path.resolve(__dirname,'../public'),origin='http://127.0.0.1:8999';let checks=0;const check=(v,m)=>{assert.ok(v,m);checks++};
(async()=>{const browser=await chromium.launch({channel:'chrome',headless:true});const results=[];
try{for(const scenario of ['consent','no-consent','provider-disabled','provider-unknown','save-retry','training','pickup-confirm','pickup-retry','pickup-conflict']){
const ctx=await browser.newContext({viewport:{width:390,height:844}}),requests=[],errors=[];let call=null,manual=0,pickupAttempts=0;const live=scenario.startsWith('pickup-'),point={latitude:17.9,longitude:82.9,accuracy_m:12,recorded_at:Date.now()};if(live)call={id:'DEMO_call',revision:1,demo:false,state:'dispatched',order_id:'DEMO_order',caller:'+910000000000',draft:{caller_name:'DEMO Caller'},created_at:new Date().toISOString(),meta:{mapa_demo:true}};
await ctx.route('**/*',async route=>{const req=route.request(),url=new URL(req.url());if(url.origin!==origin)return route.abort();const p=url.pathname;const reply=(json,status=200)=>route.fulfill({status,contentType:'application/json',body:JSON.stringify(json)});
if(p==='/call-intake/')return route.fulfill({contentType:'text/html',body:'<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/mapa-map/leaflet.css"><link rel="stylesheet" href="/mapa-voice.css"><script defer src="/mapa-map/leaflet.js"></script><script defer src="/mapa-voice.js"></script></head><body></body></html>'});
if(['/mapa-voice.js','/mapa-voice.css','/mapa-map/leaflet.js','/mapa-map/leaflet.css'].includes(p))return route.fulfill({contentType:p.endsWith('.js')?'application/javascript':'text/css',body:fs.readFileSync(path.join(publicDir,p))});
if(!p.startsWith('/mapa/'))return route.abort();const body=req.method()==='POST'?JSON.parse(req.postData()||'{}'):null;requests.push({p,method:req.method(),body});
if(p==='/mapa/mobile/v1/login')return reply({contract:'mapa-native-mobile-v1',token:'DEMO-local-only',expires_at:Date.now()+3600000,user:{id:'DEMO_user',company_id:'DEMO_company',role:'admin',name:'DEMO Operator',meta:{mapa_demo:true}}});
if(p==='/mapa/voice/v1/calls')return reply({contract:'mapa-native-voice-v1',calls:call?[call]:[],capabilities:{manual_intake:true,caller_tracking:true,demo:true},pickup_map:{}});
if(p==='/mapa/mobile/v1/tracking/sms')return reply({configured:scenario!=='provider-disabled',delivery_receipts:false});
if(p==='/mapa/voice/v1/calls/manual'){manual++;if(scenario==='save-retry'&&manual===1)return reply({error:'DEMO uncertain receipt'},500);call={id:'DEMO_call',revision:0,demo:body.demo,state:'received',source:'manual',caller:body.callback_number,draft:body,created_at:new Date().toISOString(),meta:{mapa_demo:true}};return reply({ok:true,call});}
if(p==='/mapa/voice/v1/calls/DEMO_call')return reply({contract:'mapa-native-voice-v1',call,candidates:[],unavailable:[],places:[{id:'DEMO_hospital',name:'DEMO Hospital',meta:{mapa_demo:true}}]});
if(p==='/mapa/mobile/v1/tracking/calls/DEMO_call/sms/auto')return reply({action_id:body.action_id,status:scenario==='provider-unknown'?'unknown':'queued',queued:scenario!=='provider-unknown',delivered:false,delivery_confirmed:false});
if(p==='/mapa/mobile/v1/tracking/calls/DEMO_call/locations')return reply({can_confirm_live_pickup:true,order_revision:'a'.repeat(64),locations:[{link_id:'DEMO_link',location:point}]});
if(p==='/mapa/mobile/v1/tracking/calls/DEMO_call/pickup'){pickupAttempts++;if(scenario==='pickup-conflict')return reply({error:'DEMO changed revision'},409);if(scenario==='pickup-retry'&&pickupAttempts===1)return reply({error:'DEMO uncertain'},502);return reply({ok:true,replayed:pickupAttempts>1,confirmation_id:'DEMO_confirmation',order_id:'DEMO_order',order_revision:'b'.repeat(64),crew_must_review:true,pickup:point});}
if(req.method()==='GET')return reply({contract:'mapa-native-voice-v1',places:[],locations:[]});return reply({error:'Unexpected DEMO write'},400);
});
const page=await ctx.newPage();page.on('pageerror',e=>errors.push(e.message));await page.goto(origin+'/call-intake/');await page.getByLabel('Email',{exact:true}).fill('DEMO-operator@example.invalid');await page.getByLabel('Password',{exact:true}).fill('DEMO-only');await page.getByRole('button',{name:'Sign in to Call Intake',exact:true}).click();if(live){
await page.locator('[data-voice-focus="call:DEMO_call"]').click();
await page.getByRole('button',{name:'Check caller location',exact:true}).click();
const confirm=page.getByRole('button',{name:'Confirm caller pickup for this trip',exact:true});await confirm.waitFor();
check(requests.every(r=>!r.p.endsWith('/pickup')),'checking never changes pickup');
page.once('dialog',d=>d.accept());await confirm.click();
if(scenario==='pickup-retry'){
await page.getByRole('button',{name:'Retry same pickup confirmation',exact:true}).waitFor();
check(await page.getByRole('button',{name:'Check caller location',exact:true}).isDisabled(),'unknown locks replacement location');
await page.getByRole('button',{name:'Refresh crew acknowledgement',exact:true}).click();
await page.getByRole('button',{name:'Retry same pickup confirmation',exact:true}).click();
}
if(scenario==='pickup-conflict'){await page.getByText('Pickup was not confirmed. Refresh the caller location and trip before trying again.',{exact:true}).first().waitFor();check(await confirm.isDisabled(),'conflict requires fresh snapshot');}
else await page.getByText('Pickup confirmed for this trip. The crew must review the changed pickup in the crew app.',{exact:true}).first().waitFor();
const writes=requests.filter(r=>r.p.endsWith('/pickup'));check(writes.length===(scenario==='pickup-retry'?2:1),'expected confirmation count');check(writes[0].body.expected_revision==='a'.repeat(64)&&writes[0].body.confirmed===true,'confirmation binds observed revision');check(writes[0].body.recorded_at===point.recorded_at&&writes[0].body.link_id==='DEMO_link','confirmation binds exact source fix');
if(writes.length===2)check(JSON.stringify(writes[0].body)===JSON.stringify(writes[1].body),'unknown retains exact intent across rerender');
check(errors.length===0,'pickup browser no JS errors');results.push({scenario,passed:true,pickupRequests:writes.length});console.log('Passed '+scenario);await ctx.close();continue;
}
await page.getByLabel('Callback number (blank if unknown)',{exact:true}).waitFor();
const consent=page.locator('[name=auto_sms_consent]');check(!await consent.isChecked(),scenario+' consent defaults off');
await page.getByLabel('Callback number (blank if unknown)',{exact:true}).fill('+910000000000');await page.getByLabel('Caller name',{exact:true}).fill('DEMO Caller');await page.locator('[name=reported_problem]').fill('DEMO isolated test');
if(scenario==='training'){await page.locator('[name=demo_mode]').selectOption('training');check(await consent.isDisabled(),'training blocks SMS consent');}
else if(scenario==='provider-disabled')check(await consent.isDisabled(),'disabled provider blocks SMS consent');
else if(scenario!=='no-consent')await consent.check();
check(requests.every(r=>!r.p.endsWith('/sms/auto')),'editing never sends SMS');
await page.getByRole('button',{name:'Save call & continue to review',exact:true}).click();
if(scenario==='save-retry'){await page.getByRole('button',{name:'Retry the same update',exact:true}).click();}
await page.getByRole('button',{name:'Save reviewed intake & find ambulances',exact:true}).waitFor();
const sends=requests.filter(r=>r.p.endsWith('/sms/auto')),expected=['consent','provider-unknown','save-retry'].includes(scenario)?1:0;check(sends.length===expected,scenario+' expected SMS calls');
if(expected){check(sends[0].body.consent_to_message===true,'consent sent');check(sends[0].body.expected_callback_number==='+910000000000','binds confirmed saved callback');check(requests.findIndex(r=>r.p.endsWith('/calls/manual'))<requests.findIndex(r=>r.p.endsWith('/sms/auto')),'only after save');check(await page.getByText(scenario==='provider-unknown'?'SMS outcome unknown. Check with the caller before any further send.':'SMS queued by the gateway; sending and delivery are not confirmed.',{exact:true}).count()>0,'truthful gateway status');}
if(scenario==='save-retry'){const saves=requests.filter(r=>r.p.endsWith('/calls/manual'));check(saves.length===2&&saves[0].body.action_id===saves[1].body.action_id,'uncertain save retries same key');}
if(scenario==='consent'){const before=requests.filter(r=>r.method==='POST').length;await page.getByRole('button',{name:'Check caller location',exact:true}).click();await page.getByRole('button',{name:'Use caller pickup pin',exact:true}).waitFor();page.once('dialog',d=>d.accept());await page.getByRole('button',{name:'Use caller pickup pin',exact:true}).click();check(await page.locator('[name=latitude]').inputValue()==='17.9','explicit pin updates latitude');check(await page.locator('[name=longitude]').inputValue()==='82.9','explicit pin updates longitude');check(!await page.getByLabel(/I have read back the exact pickup/).isChecked(),'readback remains required');check(requests.filter(r=>r.method==='POST').length===before,'pin action never saves or dispatches');}
check(errors.length===0,scenario+' browser has no JS errors');check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),scenario+' mobile no horizontal overflow');results.push({scenario,passed:true,smsRequests:sends.length});console.log('Passed '+scenario);await ctx.close();
}
fs.mkdirSync('private/sms-ui-review-20261005',{recursive:true});fs.writeFileSync('private/sms-ui-review-20261005/browser.json',JSON.stringify(results,null,2));console.log(JSON.stringify({checks,scenarios:results,externalRequests:0}));
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1});


