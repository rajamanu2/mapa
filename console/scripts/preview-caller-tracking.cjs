'use strict';

// Isolated synthetic caller preview. Never proxies to an operational API or tile provider.

const http=require('node:http'),fs=require('node:fs'),path=require('node:path');

const root=path.resolve(__dirname,'../public');

const TOKEN='d'.repeat(64);
const DEMO_CONTACTS={driver:{name:'Driver',phone:'+12025550111'},caller:{name:'Callback number',phone:'+12025550112'},hospital:{name:'DEMO Hospital',phone:'+12025550113'}};

function createPreview(port=8884){

 const scenario=stage=>({contacts:stage==='completed'?null:DEMO_CONTACTS,stage,leg:stage==='to_hospital'?'hospital':stage==='to_pickup'?'pickup':null,caller_location_allowed:stage==='to_pickup',pickup:stage==='to_pickup'?{label:'DEMO operator pickup',latitude:17.930,longitude:82.932}:null,destination:stage==='to_hospital'?{label:'DEMO District Hospital',latitude:17.94,longitude:82.95}:null,status:stage==='to_pickup'?'ambulance_assigned':'pickup_complete',expires_at:Date.now()+3600000,ambulance:stage==='completed'?null:{registration:'DEMO AMBULANCE',location:{latitude:17.9,longitude:82.9,recorded_at:Date.now(),source:'hardware_gps',stale:false}},revision:'DEMO-'+stage,caller_location:null,caller_pickup_confirmation:null,planned_road_route:null,eta:null,meta:{mapa_demo:true}});
 let fixture=scenario('to_pickup'),httpStatus=200,autoFixture=true;

 const received=[];let views=0;

 const server=http.createServer((req,res)=>{

  const pathname=new URL(req.url,'http://127.0.0.1').pathname;

  res.setHeader('Cache-Control','no-store');res.setHeader('Referrer-Policy','no-referrer');

  if(pathname.startsWith('/mapa/mobile/v1/tracking/')){

   res.setHeader('Content-Type','application/json');

   if(req.headers.authorization!=='Bearer '+TOKEN){res.writeHead(401);res.end('{"error":"DEMO capability required"}');return;}

   if(pathname.endsWith('/view')&&req.method==='GET'){
    views++;
    if(autoFixture&&fixture.ambulance){
     // This geometry is deliberately synthetic, served only by the labelled local DEMO.
     const at=Date.now(),point=fixture.ambulance.location,target=fixture.stage==='to_hospital'?fixture.destination:fixture.pickup;
     point.latitude=17.9+Math.min(views,100)*.00008;point.recorded_at=at;
     fixture.planned_road_route={source:'private_osrm',leg:fixture.leg,context_revision:fixture.revision,source_recorded_at:at,generated_at:at,expires_at:at+60000,coordinates:[[point.longitude,point.latitude],[82.91,point.latitude],[82.91,target.latitude],[target.longitude,target.latitude]],distance_m:6000,duration_s:600};
    }
    res.writeHead(httpStatus);res.end(JSON.stringify(fixture));return;
   }

   if(pathname.endsWith('/location')&&req.method==='POST'){let body='';req.on('data',chunk=>{body+=chunk;if(body.length>4096)req.destroy();});req.on('end',()=>{
    let parsed;try{parsed=JSON.parse(body);}catch(_){res.writeHead(400);res.end('{"error":"DEMO malformed JSON"}');return;}
    if(!fixture.caller_location_allowed){res.writeHead(409);res.end('{"error":"DEMO pickup sharing ended"}');return;}
    if(parsed.consent!==true||!['latitude','longitude','accuracy_m','recorded_at'].every(k=>typeof parsed[k]==='number'&&Number.isFinite(parsed[k]))){res.writeHead(422);res.end('{"error":"DEMO invalid fix"}');return;}
    received.push(parsed);if(received.length>100)received.shift();fixture.caller_location={latitude:parsed.latitude,longitude:parsed.longitude,accuracy_m:parsed.accuracy_m,recorded_at:parsed.recorded_at};
    if(parsed.confirm_pickup===true)fixture.caller_pickup_confirmation={...fixture.caller_location,confirmed_at:Date.now(),source:'caller_confirmed',stale:false};
    res.end(JSON.stringify({ok:true,pickup_requires_operator_confirmation:true,caller_pickup_confirmation:fixture.caller_pickup_confirmation,meta:{mapa_demo:true}}));
   });return;}

   res.writeHead(404);res.end('{"error":"DEMO route not available"}');return;

  }

  const demoStages={'/demo/pickup':'to_pickup','/demo/hospital':'to_hospital','/demo/completed':'completed'};
  if(req.method==='GET'&&demoStages[pathname]){fixture=scenario(demoStages[pathname]);autoFixture=true;httpStatus=200;views=0;res.writeHead(302,{Location:'/track/#'+TOKEN});res.end();return;}
  if(pathname.startsWith('/demo-tiles/')){res.setHeader('Content-Type','image/svg+xml');res.end('<svg xmlns="http://www.w3.org/2000/svg" width="256" height="256"><rect width="256" height="256" fill="#dce9e7"/><path d="M0 70H256M85 0V256M0 175H256M190 0V256" stroke="#fff" stroke-width="10"/><text x="105" y="140" fill="#547870" font-size="12">DEMO</text></svg>');return;}

  if(pathname==='/'){res.writeHead(302,{Location:'/track/#'+TOKEN});res.end();return;}

  const files={'/privacy/':'privacy/index.html','/terms/':'terms/index.html','/data-rights/':'data-rights/index.html','/legal/legal.css':'legal/legal.css','/track/':'track/index.html','/track/index.html':'track/index.html','/track/tracking.js':'track/tracking.js','/track/tracking.css':'track/tracking.css','/mapa-map/leaflet.js':'mapa-map/leaflet.js','/mapa-map/leaflet.css':'mapa-map/leaflet.css','/mapa-icon.svg':'mapa-icon.svg'};

  if(!files[pathname]){res.writeHead(404);res.end('DEMO preview: this page is not served here.');return;}

  const ext=path.extname(files[pathname]);res.setHeader('Content-Type',({'.html':'text/html; charset=utf-8','.js':'text/javascript; charset=utf-8','.css':'text/css; charset=utf-8','.svg':'image/svg+xml'})[ext]);

  let text=fs.readFileSync(path.join(root,files[pathname]),'utf8');

  if(ext==='.html')text=text.replace('<body>','<body><div style="background:#713f12;color:white;padding:10px;text-align:center;font:700 13px system-ui">DEMO · Synthetic ambulance and phone location · No real messages or trips</div><script>Object.defineProperty(navigator,"geolocation",{value:{watchPosition(fn){window.demoGPS=setInterval(()=>fn({coords:{latitude:17.904,longitude:82.903,accuracy:12},timestamp:Date.now()}),500);return window.demoGPS;},clearWatch(id){clearInterval(id);}}});</script>');

  if(pathname==='/track/'||pathname==='/track/index.html')text=text.replace('<main>','<nav aria-label="DEMO scenarios" style="padding:12px;text-align:center;background:#fff1d6"><a href="/demo/pickup">DEMO pickup</a> · <a href="/demo/hospital">DEMO hospital</a> · <a href="/demo/completed">DEMO completed</a></nav><main>');
  if(pathname==='/track/tracking.js')text=text.replace('https://tile.openstreetmap.org/{z}/{x}/{y}.png','/demo-tiles/{z}/{x}/{y}.svg');

  res.end(text);

 });

 return new Promise(resolve=>server.listen(port,'127.0.0.1',()=>resolve({server,received,get views(){return views;},url:'http://127.0.0.1:'+server.address().port,token:TOKEN,setFixture:(value,status=200)=>{fixture=value;httpStatus=status;autoFixture=false;}})));

}

module.exports={createPreview};

if(require.main===module)createPreview(Number(process.env.PORT||8884)).then(p=>console.log('DEMO caller preview: '+p.url+'/track/#'+TOKEN));

