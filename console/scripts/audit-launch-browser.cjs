// Local browser QA only; block all external network traffic and operational writes.
const {chromium}=require('C:/Users/roysh/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const out=path.resolve(process.env.MAPA_AUDIT_OUTPUT||path.join(__dirname,'../audit'));fs.mkdirSync(out,{recursive:true});
const origin=process.env.MAPA_AUDIT_ORIGIN||'http://127.0.0.1:8878';
assert.equal(new URL(origin).hostname,'127.0.0.1','QA must target the isolated loopback preview');
(async()=>{
 const browser=await chromium.launch({headless:true,channel:'chrome'});const results=[];
 try{
  for(const width of [360,390,768,1440]){
   const context=await browser.newContext({viewport:{width,height:900}});
   await context.route('**/*',r=>new URL(r.request().url()).origin===origin?r.continue():r.abort());
   const page=await context.newPage();
   for(const route of ['/privacy/','/terms/','/data-rights/','/missing-page','/track/','/call-intake/','/auth']){
    const errors=[];const handler=e=>errors.push(e.message);page.on('pageerror',handler);
    const response=await page.goto(origin+route,{waitUntil:'networkidle',timeout:60000});
    if(route==='/auth')await page.locator('input[type=email]').waitFor({timeout:30000});
    if(route==='/call-intake/')await page.getByText('Sign in', {exact:false}).first().waitFor({timeout:10000}).catch(()=>{});
    const info=await page.evaluate(()=>({title:document.title,description:document.querySelector('meta[name=description]')?.content,
      overflow:document.documentElement.scrollWidth>innerWidth+1,viewport:document.querySelector('meta[name=viewport]')?.content,
      loadMs:Math.round(performance.getEntriesByType('navigation')[0]?.loadEventEnd||0),
      bytes:performance.getEntriesByType('resource').reduce((n,r)=>n+r.transferSize,0)}));
    assert.equal(response.status(),route==='/missing-page'?404:200,route);
    assert.ok(info.description,route+' description');assert.ok(!info.viewport.includes('maximum-scale'),route+' zoom');
    assert.ok(!info.overflow,route+' overflows '+width);assert.deepEqual(errors,[],route+' browser errors');
    if(route==='/auth'){
      await page.locator('input[type=email]').fill('invalid-email');
      assert.equal(await page.locator('input[type=email]').evaluate(e=>e.checkValidity()),false);
      const legal=page.getByRole('button',{name:'Legal',exact:true});
      await legal.click();await page.getByRole('link',{name:'Privacy policy',exact:true}).waitFor();
      await page.getByRole('button',{name:'Done',exact:true}).click();
      assert.equal(await page.locator('dialog[open]').count(),0);
    }
    if(route==='/track/')assert.ok((await page.locator('#status').innerText()).includes('invalid'));
    results.push({width,route,status:response.status(),...info});
    if(width===390 && ['/privacy/','/auth','/call-intake/','/missing-page'].includes(route))
      await page.screenshot({path:path.join(out,route.replaceAll('/','_')+'-mobile.png'),fullPage:true});
    page.off('pageerror',handler);
   }
   await context.close();
  }
  const context=await browser.newContext();const page=await context.newPage();
  for(const route of ['/privacy/','/terms/','/data-rights/','/track/','/404.html']){
   await page.goto(origin+route);const links=await page.locator('a[href],link[rel=stylesheet],link[rel=icon],script[src]').evaluateAll(es=>es.map(e=>e.getAttribute('href')||e.getAttribute('src')).filter(u=>u.startsWith('/')&&!u.startsWith('//')));
   for(const link of links){const response=await context.request.get(origin+link);assert.equal(response.status(),200,route+' broken '+link);}
  }
  for(const route of ['/assets/not-found.js','/.env','/mapa-source.zip'])assert.equal((await context.request.get(origin+route)).status(),404,route);
  await context.close();fs.writeFileSync(path.join(out,'browser-results.json'),JSON.stringify(results,null,2));
  console.log('PASS: '+results.length+' page/viewport checks, metadata/zoom/overflow, login validity, legal dialog, caller-link failure, local links/assets and private-file denials.');
 }finally{await browser.close()}
})().catch(e=>{console.error(e);process.exitCode=1});
