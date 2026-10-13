'use strict';
const {chromium}=require('C:/Users/roysh/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const {createPreview}=require('./preview-caller-tracking.cjs');
const assert=require('node:assert/strict');
(async()=>{
 const preview=await createPreview(0),browser=await chromium.launch({headless:true,channel:'chrome'});let checks=0;
 const check=(v,m)=>{assert.ok(v,m);checks++;};
 try{
  for(const mode of ['rounded','timestamp','nearby','retry']){
   const context=await browser.newContext({viewport:{width:390,height:844}}),page=await context.newPage(),posts=[],errors=[];let failures=mode==='retry'?1:0;
   const fixture={stage:'to_pickup',leg:'pickup',revision:'DEMO-precision',caller_location_allowed:true,expires_at:Date.now()+3600000,ambulance:null,pickup:{latitude:17.91,longitude:82.91,label:'DEMO operator pickup'},caller_pickup_confirmation:null,planned_road_route:null,meta:{mapa_demo:true}};
   preview.setFixture(fixture);page.on('pageerror',e=>errors.push(e.message));
   await context.route('**/*',async route=>{
    const req=route.request();if(new URL(req.url()).origin!==preview.url)return route.abort();
    if(req.method()==='POST'&&req.url().endsWith('/tracking/location')){
     const b=req.postDataJSON();posts.push(b);
     if(b.confirm_pickup&&failures-->0)return route.fulfill({status:503,contentType:'application/json',body:'{"error":"DEMO uncertain"}'});
     let evidence=null;
     if(b.confirm_pickup){evidence={latitude:17.9041235,longitude:82.9039877,accuracy_m:12.345,recorded_at:b.recorded_at,confirmed_at:Date.now(),source:'caller_confirmed',stale:false};if(mode==='timestamp')evidence.recorded_at++;if(mode==='nearby')evidence.latitude=17.9041236;if(['rounded','retry'].includes(mode))fixture.caller_pickup_confirmation=evidence;}
     return route.fulfill({status:200,contentType:'application/json',body:JSON.stringify({ok:true,pickup_requires_operator_confirmation:true,caller_pickup_confirmation:evidence})});
    }
    return route.continue();
   });
   await page.goto(preview.url+'/track/#'+preview.token);await page.waitForFunction(()=>!document.querySelector('#share').disabled);
   await page.evaluate(()=>{navigator.geolocation.watchPosition=fn=>{window.precisionGPS=fn;return 999;};navigator.geolocation.clearWatch=()=>{};});
   await page.locator('#consent').check();await page.locator('#share').click();
   await page.evaluate(()=>window.precisionGPS({coords:{latitude:17.90412345678,longitude:82.90398765432,accuracy:12.3454321},timestamp:Date.now()}));
   await page.waitForFunction(()=>document.querySelector('#location-status').textContent.includes('operator confirmation'));
   await page.locator('#confirm-pickup').click();
   if(mode==='retry'){
    await page.waitForFunction(()=>document.querySelector('#location-status').textContent.includes('uncertain'));
    await page.evaluate(()=>window.precisionGPS({coords:{latitude:18.1,longitude:83.1,accuracy:9},timestamp:Date.now()}));
   }
   if(['rounded','retry'].includes(mode)){
    await page.waitForFunction(()=>document.querySelector('#pickup-confirmed').textContent.startsWith('You confirmed'),null,{timeout:20000});
    check((await page.locator('#location-status').innerText()).includes('delivered'),'Chrome accepts rounded receipt '+mode);
    if(mode==='rounded'){check(await page.locator('#confirm-pickup').isDisabled(),'rounded fix cannot duplicate confirmation');check(await page.locator('#caller-legend').isHidden(),'rounding creates no extra phone marker');}
    if(mode==='retry'){const confirmations=posts.filter(b=>b.confirm_pickup);check(confirmations.length===2,'one explicit confirmation plus one exact retry');check(JSON.stringify(confirmations[0])===JSON.stringify(confirmations[1]),'Chrome retry frozen body survives later phone move');}
   }else{
    await page.waitForFunction(()=>document.querySelector('#location-status').textContent.includes('uncertain'),null,{timeout:15000});
    check(!(await page.locator('#pickup-confirmed').innerText()).startsWith('You confirmed'),'Chrome rejects distinct '+mode);
   }
   check(posts.find(b=>b.confirm_pickup).latitude===17.90412345678,'wire request exact high precision '+mode);check(errors.length===0,'no browser errors '+mode);
   await context.close();
  }
  console.log('PASS caller precision Chrome: '+checks+' checks, synthetic loopback only');
 }finally{await browser.close();preview.server.closeAllConnections();await new Promise(resolve=>preview.server.close(resolve));}
})().catch(e=>{console.error(e);process.exitCode=1;});
