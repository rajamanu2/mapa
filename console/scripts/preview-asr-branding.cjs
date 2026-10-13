'use strict';
// Isolated appearance fixture. It serves a fixed asset allowlist, never a console/API directory.
const http=require('node:http'),fs=require('node:fs'),path=require('node:path');
const root=path.resolve(__dirname,'..'),publicRoot=path.join(root,'public');
const source=fs.readFileSync(path.join(root,'app/index.html'),'utf8');
const begin=source.indexOf('<div id="boot-loader"'),finish=source.indexOf('    <script src=',begin);
if(begin<0||finish<0)throw Error('Expected reviewed startup markup');
const splash=source.slice(begin,finish);
const logo=fs.readFileSync(path.join(root,'app/components/logo.hbs'),'utf8').replace(' ...attributes','');
const icon=fs.readFileSync(path.join(root,'app/components/logo-icon.hbs'),'utf8').replace(' ...attributes','');
const files={'/mapa-loading.css':['mapa-loading.css','text/css'],'/images/asr-health-icon.svg':['images/asr-health-icon.svg','image/svg+xml'],'/images/asr-health-logo.svg':['images/asr-health-logo.svg','image/svg+xml']};
const banner='<nav class="fixture-banner">DEMO visual fixture · no accounts or operational data · <a href="/loading">Loading screen</a> · <a href="/app">App logo and preserved credit</a></nav>';
function createPreview(port=8893){
 return new Promise(resolve=>{
  const server=http.createServer((req,res)=>{
   const pathname=new URL(req.url,'http://127.0.0.1').pathname;
   res.setHeader('Cache-Control','no-store');res.setHeader('Referrer-Policy','no-referrer');res.setHeader('X-Content-Type-Options','nosniff');
   res.setHeader('Content-Security-Policy',"default-src 'none'; style-src 'self' 'unsafe-inline'; img-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");
   if(req.method!=='GET'){res.writeHead(405);res.end();return;}
   if(files[pathname]){res.setHeader('Content-Type',files[pathname][1]);res.end(fs.readFileSync(path.join(publicRoot,files[pathname][0])));return;}
   if(!['/','/loading','/app'].includes(pathname)){res.writeHead(404);res.end('DEMO fixture: file not available.');return;}
   res.setHeader('Content-Type','text/html; charset=utf-8');
   const app='<div class="fixture-shell"><aside><div class="fixture-logo">'+logo+'</div><p class="fixture-muted">MAPA operations software</p><div class="fixture-menu">Operations<br>Calls<br>Crew<br>Hospitals</div><div class="mapa-attribution"><strong>MAPA.DEV</strong><span aria-hidden="true"> · </span><button disabled>Legal</button></div></aside><main><p class="fixture-muted">DEMO appearance preview</p><h1>ASR Health command centre</h1><p>The logo below is the actual prepared native icon markup.</p><div class="fixture-icon">'+icon+'</div><p>MAPA remains the software; the bottom-left MAPA.DEV attribution is retained.</p></main></div>';
   res.end('<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>DEMO ASR Health branding preview</title><link rel="stylesheet" href="/mapa-loading.css"><style>body{margin:0;background:#101827;color:#e5eff4;font:16px/1.5 system-ui}.fixture-banner{position:relative;z-index:30;padding:12px 16px;background:#653b11;color:white;font-size:13px}.fixture-banner a{color:#fff1bd}.fixture-shell{display:flex;min-height:calc(100vh - 48px)}aside{position:relative;width:264px;flex-shrink:0;padding:24px;background:#142031;border-right:1px solid #304154}.mapa-wordmark{display:inline-flex;align-items:center;gap:12px;font-size:22px}.fixture-muted{font-size:13px;color:#94a8b8}.fixture-menu{line-height:3;color:#c6d9e3;margin:28px 0}.mapa-attribution{position:absolute;bottom:20px;left:20px;font-size:12px;color:#9badc1}.mapa-attribution button{color:#93c5fd;background:none;border:0}main{padding:40px;max-width:800px}h1{font-size:30px}.fixture-icon{padding:24px;background:#1b2d3d;border-radius:16px;width:max-content}@media(max-width:560px){aside{width:160px;padding:20px 12px}.mapa-wordmark{font-size:13px;gap:6px}.mapa-wordmark img{width:30px;height:30px}main{padding:24px 16px}h1{font-size:24px}}</style></head><body>'+banner+(pathname==='/app'?app:splash)+'</body></html>');
  });
  server.listen(port,'127.0.0.1',()=>resolve({server,url:'http://127.0.0.1:'+server.address().port}));
 });
}
module.exports={createPreview};
if(require.main===module)createPreview(Number(process.env.PORT||8893)).then(p=>console.log('Isolated DEMO branding preview: '+p.url+'/loading and '+p.url+'/app'));
