'use strict';

const {chromium}=require('C:/Users/roysh/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');

const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');

const {createPreview}=require('./preview-caller-tracking.cjs');

(async()=>{

 const preview=await createPreview(0),output=path.resolve('private/caller-full-journey-review-20261005');fs.mkdirSync(output,{recursive:true});

 const browser=await chromium.launch({headless:true,channel:'chrome'});let checks=0;const check=(v,m)=>{assert.ok(v,m);checks++;};

 try{

  for(const width of [360,390,768,1440]){

   const context=await browser.newContext({viewport:{width,height:900}}),page=await context.newPage(),errors=[],leaks=[];

   await context.route('**/*',r=>{const req=r.request();if(new URL(req.url()).origin!==preview.url){leaks.push(req.url());return r.abort();}if(!req.url().includes('/mapa/mobile/')){if(req.headers().authorization||req.url().includes(preview.token)||(req.headers().referer||'').includes(preview.token))leaks.push('capability leaked');}return r.continue();});

   page.on('pageerror',e=>errors.push(e.message));

   await page.goto(preview.url+'/track/#'+preview.token,{waitUntil:'networkidle'});

   check(await page.locator('#registration').innerText()==='DEMO AMBULANCE','assignment visible');

   check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'no horizontal overflow '+width);

   check(!page.url().includes(preview.token),'fragment removed');

   await page.locator('#consent').check();await page.locator('#share').click();await page.waitForFunction(()=>document.querySelector('#location-status').textContent.includes('operator confirmation'));

   check(await page.locator('.leaflet-interactive').count()===2,'both markers '+width);

   await page.locator('#recenter').click();await page.screenshot({path:path.join(output,'caller-'+width+'.png'),fullPage:true});

   await page.locator('#stop').click();check(await page.locator('#stop').isDisabled(),'stop disables '+width);

   check(await page.locator('.leaflet-interactive').count()===1,'stop removes pickup '+width);

   check(errors.length===0,'no JS errors '+width);check(leaks.length===0,'no external requests/token leak '+width);

   await context.close();

  }

  const navigationContext=await browser.newContext({viewport:{width:390,height:844}}),navigationPage=await navigationContext.newPage(),navigationLeaks=[];

  await navigationContext.route('**/*',r=>{const req=r.request();if(new URL(req.url()).origin!==preview.url){navigationLeaks.push(req.url());return r.abort();}if(!req.url().includes('/mapa/mobile/')&&(req.headers().authorization||(req.headers().referer||'').includes(preview.token)||req.url().includes(preview.token)))navigationLeaks.push('capability leaked');return r.continue();});

  await navigationPage.goto(preview.url+'/track/#'+preview.token,{waitUntil:'networkidle'});await navigationPage.locator('#consent').check();await navigationPage.locator('#share').click();await navigationPage.waitForFunction(()=>document.querySelector('#location-status').textContent.includes('operator confirmation'));

  const beforeNavigationPosts=preview.received.length,beforeNavigationViews=preview.views;

  const privacyResponse=navigationPage.waitForResponse(r=>new URL(r.url()).pathname==='/privacy/');await navigationPage.getByRole('link',{name:'Privacy',exact:true}).click();check((await privacyResponse).status()===200,'privacy draft available');await navigationPage.getByRole('heading',{name:'Privacy policy',exact:true}).waitFor();

  await navigationPage.goBack({waitUntil:'domcontentloaded'});await navigationPage.locator('#status').waitFor();await navigationPage.waitForTimeout(900);

  check(await navigationPage.locator('#stop').isDisabled(),'back from privacy never auto-shares');check(preview.received.length===beforeNavigationPosts,'no GPS posts after navigation');

  const returnedStatus=await navigationPage.locator('#status').innerText(),backNavigationMode=returnedStatus.includes('invalid')?'fresh document - reopen original private link':'BFCache - read-only updates resumed';

  if(returnedStatus.includes('invalid')){check(await navigationPage.locator('#share').isDisabled(),'fresh document without capability safely disabled');check(returnedStatus.includes('new link'),'original link recovery instruction shown');}else{check(preview.views>beforeNavigationViews,'BFCache returns to read-only refresh');check((await navigationPage.locator('#location-status').innerText()).includes('paused'),'BFCache explains explicit resume');}

  check(navigationLeaks.length===0,'privacy and back has no token leak or external traffic');

  for(const [url,heading] of [['/terms/','Terms and conditions'],['/data-rights/','Data rights and GDPR']]){const response=await navigationPage.goto(preview.url+url);check(response.status()===200,'legal page '+url);await navigationPage.getByRole('heading',{name:heading,exact:true}).waitFor();}

  check((await navigationPage.request.get(preview.url+'/legal/legal.css')).status()===200,'legal stylesheet served');

  const bad=await navigationPage.request.post(preview.url+'/mapa/mobile/v1/tracking/location',{headers:{Authorization:'Bearer '+preview.token,'Content-Type':'application/json'},data:Buffer.from('{broken')});check(bad.status()===400,'malformed JSON rejected');check((await navigationPage.request.get(preview.url+'/privacy/')).status()===200,'preview survives malformed JSON');await navigationContext.close();

  const context=await browser.newContext({viewport:{width:390,height:844}}),page=await context.newPage();await context.route('**/*',r=>new URL(r.request().url()).origin===preview.url?r.continue():r.abort());

  preview.setFixture({status:'ambulance_assigned',expires_at:Date.now()+3600000,ambulance:{registration:'DEMO TERMINAL',location:{latitude:17.9,longitude:82.9,recorded_at:Date.now(),source:'hardware_gps',stale:false}},eta:null,meta:{mapa_demo:true}});await page.goto(preview.url+'/track/#'+preview.token);await page.waitForSelector('.leaflet-pane',{state:'attached'});check(await page.locator('.leaflet-pane').count()>0,'map initially rendered before terminal test');preview.setFixture({status:'pickup_complete',ambulance:null,meta:{mapa_demo:true}});await page.locator('#refresh').click();await page.waitForFunction(()=>document.querySelector('#registration').textContent==='Tracking ended');check(await page.locator('.leaflet-pane').count()===0,'terminal pickup removes all prior map extent');check(await page.locator('#map').innerHTML()==='','terminal map container empty');
  await page.goto('about:blank');
  const demoContacts={driver:{name:'Driver',phone:'+12025550111'},caller:{name:'Callback number',phone:'+12025550112'},hospital:{name:'DEMO Hospital',phone:'+12025550113'}};
  const newJourney={contacts:demoContacts,stage:'to_pickup',leg:'pickup',caller_location_allowed:true,expires_at:Date.now()+3600000,status:'ambulance_assigned',ambulance:{registration:'DEMO FULL JOURNEY',location:{latitude:17.92,longitude:82.92,recorded_at:Date.now(),source:'hardware_gps',stale:false}},pickup:{label:'DEMO confirmed pickup',latitude:17.90,longitude:82.90},destination:null,eta:null,meta:{mapa_demo:true}};
  preview.setFixture(newJourney);await page.goto(preview.url+'/track/#'+preview.token);await page.waitForFunction(()=>document.querySelector('#registration').textContent==='DEMO FULL JOURNEY');await page.locator('#call-open').click();await page.locator('#contact-sheet').waitFor({state:'visible'});check(await page.locator('#contact-driver').getAttribute('href')==='tel:+12025550111','driver canonical tel contact');check(await page.locator('#contact-sheet a[href^="tel:"]').count()===3,'all current trip contact options');await page.screenshot({path:path.join(output,'caller-contacts-390.png'),fullPage:true});await page.locator('#call-close').click();check(!await page.locator('#contact-sheet').isVisible(),'contact sheet accessible close');await page.locator('#consent').check();await page.locator('#share').click();await page.waitForFunction(()=>document.querySelector('#location-status').textContent.includes('operator confirmation'));check(await page.locator('#pickup-legend-label').innerText()==='Confirmed pickup','operator-confirmed pickup distinguished in Chrome');
  const postsBeforeHospital=preview.received.length;preview.setFixture({...newJourney,stage:'to_hospital',leg:'hospital',status:'pickup_complete',caller_location_allowed:false,contacts:{...demoContacts,driver:{name:'Driver',phone:'+12025550114'},hospital:{name:'DEMO Hospital',phone:'+12025550115'}},destination:{label:'DEMO District Hospital',latitude:17.95,longitude:82.95}});await page.locator('#refresh').click();await page.waitForFunction(()=>document.querySelector('#destination-name').textContent==='DEMO District Hospital');check(await page.locator('#share').isDisabled()&&await page.locator('#stop').isDisabled(),'hospital disables GPS collection');check(await page.locator('#refresh').isEnabled(),'hospital tracking continues');check(await page.locator('#contact-driver').getAttribute('href')==='tel:+12025550114','driver reassignment updates caller phone');check(await page.locator('#contact-hospital').getAttribute('href')==='tel:+12025550115','hospital change updates caller phone');check(await page.locator('.leaflet-interactive').count()===2,'hospital and ambulance only');await page.screenshot({path:path.join(output,'caller-hospital-390.png'),fullPage:true});await page.waitForTimeout(1000);check(preview.received.length===postsBeforeHospital,'hospital sends no further phone GPS');
  preview.setFixture({...newJourney,stage:'completed',leg:null,status:'pickup_complete',caller_location_allowed:false,ambulance:null,destination:null});await page.locator('#refresh').click();await page.waitForFunction(()=>document.querySelector('#status').textContent.includes('Trip completed'));check(await page.locator('#map').innerHTML()==='','full journey terminal clears geography');check(await page.locator('#destination-name').innerText()==='','terminal clears destination label');check(await page.locator('#contact-sheet a[href^="tel:"]').count()===0,'terminal clears every contact target');await page.screenshot({path:path.join(output,'caller-completed-390.png'),fullPage:true});
  await page.goto('about:blank');preview.setFixture({status:'awaiting_assignment',expires_at:Date.now()+3600000,ambulance:null,meta:{mapa_demo:true}});await page.goto(preview.url+'/track/#'+preview.token);await page.waitForFunction(()=>document.querySelector('#registration').textContent==='Awaiting assignment');check(await page.locator('.leaflet-interactive').count()===0,'no phantom GPS');

  preview.setFixture({error:'DEMO expired'},410);await page.locator('#refresh').click();await page.waitForFunction(()=>document.querySelector('#status').textContent.includes('expired'));check(await page.locator('#share').isDisabled(),'expired cannot share');await page.screenshot({path:path.join(output,'caller-expired.png'),fullPage:true});

  fs.writeFileSync(path.join(output,'results.json'),JSON.stringify({demo:true,meta:{mapa_demo:true},checks,backNavigationMode,externalProviderTraffic:false,realApiTraffic:false,passed:true},null,2));console.log(`MAPA caller browser: ${checks} checks passed; screenshots: ${output}`);

 }finally{await browser.close();preview.server.closeAllConnections();await new Promise(r=>preview.server.close(r));}

})().catch(e=>{console.error(e);process.exitCode=1;});

