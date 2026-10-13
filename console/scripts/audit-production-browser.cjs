'use strict';
const {chromium}=require('C:/Users/roysh/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const origin=process.env.MAPA_AUDIT_ORIGIN||'http://127.0.0.1:8881';
assert.equal(new URL(origin).hostname,'127.0.0.1');
const output=path.resolve(process.env.MAPA_AUDIT_OUTPUT||'private/production-review-20261004/final-browser');
(async()=>{
 fs.mkdirSync(output,{recursive:true});
 const browser=await chromium.launch({headless:true,channel:'chrome'});
 try {
  const context=await browser.newContext({viewport:{width:390,height:844}});
  await context.route('**/*',r=>new URL(r.request().url()).origin===origin?r.continue():r.abort());
  const page=await context.newPage(),errors=[],missing=[];
  page.on('pageerror',e=>errors.push(e.message));
  page.on('response',r=>{if(r.status()===404)missing.push(new URL(r.url()).pathname)});
  await page.goto(origin+'/auth',{waitUntil:'networkidle'});
  await page.locator('input[type=email]').waitFor();
  assert.equal(await page.getByRole('button',{name:/create account/i}).count(),0,'Closed registration must survive the source build');
  await page.goto(origin+'/onboard',{waitUntil:'networkidle'});
  await page.locator('input[type=email]').waitFor();
  assert.ok(new URL(page.url()).pathname.startsWith('/auth'),'Onboarding redirects to sign-in');
  // Single slugs are existing protected virtual routes; use an unmatched nested path.
  await page.goto(origin+'/__mapa-route-missing/nested',{waitUntil:'networkidle'});
  await page.getByRole('heading',{name:'Page not found',exact:true}).waitFor();
  assert.equal(await page.locator('#boot-loader').count(),0,'The 404 must not remain under the boot overlay');
  const contrast=await page.locator('#mapa-not-found').evaluate(e=>{
   const luminance=c=>{const rgb=c.match(/[\d.]+/g).slice(0,3).map(Number).map(v=>{v/=255;return v<=0.04045?v/12.92:((v+0.055)/1.055)**2.4});return rgb[0]*0.2126+rgb[1]*0.7152+rgb[2]*0.0722};
   const fg=luminance(getComputedStyle(e).color),bg=luminance(getComputedStyle(e.closest('main')).backgroundColor);
   return (Math.max(fg,bg)+0.05)/(Math.min(fg,bg)+0.05);
  });
  assert.ok(contrast>=4.5,'404 text contrast must be readable');
  await page.screenshot({path:path.join(output,'ember-404-mobile.png'),fullPage:true});
  assert.deepEqual(errors,[]);assert.deepEqual(missing,[]);
  await context.close();
  if(process.env.MAPA_AUDIT_ROUTES_ONLY==='1'){console.log(JSON.stringify({guardedRoutesPassed:true,contrast}));return;}
  const slow=await browser.newContext({viewport:{width:390,height:844}});
  await slow.route('**/*',r=>new URL(r.request().url()).origin===origin?r.continue():r.abort());
  const mobile=await slow.newPage(),cdp=await slow.newCDPSession(mobile);
  await cdp.send('Network.enable');await cdp.send('Network.setCacheDisabled',{cacheDisabled:true});
  await cdp.send('Network.emulateNetworkConditions',{offline:false,latency:150,downloadThroughput:1600000/8,uploadThroughput:750000/8,connectionType:'cellular4g'});
  await cdp.send('Emulation.setCPUThrottlingRate',{rate:4});
  const start=Date.now();await mobile.goto(origin+'/auth',{waitUntil:'domcontentloaded',timeout:120000});
  await mobile.locator('input[type=email]').waitFor({timeout:120000});
  await mobile.locator('input[type=email]').fill('DEMO-readiness@example.invalid');
  const interactiveMs=Date.now()-start;
  await mobile.waitForLoadState('networkidle',{timeout:120000});
  const resources=await mobile.evaluate(()=>performance.getEntriesByType('resource').map(r=>({path:new URL(r.name).pathname,bytes:r.transferSize,duration:Math.round(r.duration)})).sort((a,b)=>b.bytes-a.bytes));
  const report={simulation:'390px, cold cache, 1.6 Mbps down, 150ms latency, 4x CPU; loopback server; not field metrics or Lighthouse',
    loginInteractiveMs:interactiveMs,resourceBytes:resources.reduce((n,r)=>n+r.bytes,0),resources,
    guardedRoutesPassed:true,performanceSignedOff:false};
  fs.writeFileSync(path.join(output,'production-browser.json'),JSON.stringify(report,null,2));
  await mobile.screenshot({path:path.join(output,'auth-simulated-mobile.png'),fullPage:true});
  console.log(JSON.stringify({guardedRoutesPassed:true,loginInteractiveMs:interactiveMs,resourceBytes:report.resourceBytes,performanceSignedOff:false}));
  await slow.close();
 }finally{await browser.close()}
})().catch(e=>{console.error(e);process.exitCode=1});
