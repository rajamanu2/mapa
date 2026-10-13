const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const {makeTransport}=require('./mapa-source-native.js');
(async()=>{
let count=0;const check=(v,message)=>{assert.ok(v,message);count++;};
const reject=async(fn,predicate)=>{await assert.rejects(fn,predicate);count++;};
const actor={user:'DEMO-user',company:'DEMO-company',token:'DEMO-console-token'};
const uuid='00000000-0000-4000-8000-000000000001',event='a'.repeat(64);
const link={dataset:'ambulances',source_id:'DEMO-source',native_uuid:uuid,source_revision:'b'.repeat(64),expected_link_revision:0,confirmed:true,reason:'DEMO verified match'};
const cases=[
 ['connections',{dataset:'ambulances',options:'1',search:'DEMO / & <script>',page:'2'},'/api/int/v1/mapa/source-connections','GET','mapa-asr-connections-v1'],
 ['connection-save',link,'/api/int/v1/mapa/source-connections','POST','mapa-asr-connections-v1'],
 ['projection',{dataset:'drivers',id:uuid},'/api/int/v1/mapa/source-projections/drivers/'+uuid,'GET','mapa-asr-connections-v1'],
 ['reconciliation',{status:'pending',page:'2'},'/api/int/v1/mapa/source-reconciliation','GET','mapa-asr-reconciliation-v1'],
 ['reconciliation-ack',{id:event},'/api/int/v1/mapa/source-reconciliation/'+event+'/acknowledge','POST','mapa-asr-reconciliation-v1'],
];
function harness(contract,reply){
 const env={identity:{...actor},headers:{Authorization:'Bearer '+actor.token,'Access-Console-Sandbox':'true','Access-Console-Sandbox-Key':'DEMO-sandbox',Cookie:'DEMO-forbidden','X-Crew-Token':'DEMO-crew'},calls:[]};
 env.request=makeTransport(()=>env.identity,()=>env.headers,async(url,options)=>{env.calls.push({url,options});return reply?reply(env):{ok:true,status:200,json:async()=>({contract,company_id:actor.company,rows:[]})};});return env;
}
for(const [action,params,url,method,contract] of cases){
 const h=harness(contract);const body=await h.request(action,params);const sent=h.calls[0];
 check(sent.url.split('?')[0]===url,action+' uses fixed API route');check(sent.options.method===method,action+' method');
 check(body.company_id===actor.company&&body.contract===contract,action+' response identity');
 check(sent.options.credentials==='omit'&&sent.options.cache==='no-store'&&sent.options.redirect==='error',action+' isolation options');
 check(sent.options.headers.Authorization==='Bearer '+actor.token,action+' uses current native console bearer');
 check(!('Cookie' in sent.options.headers)&&!('X-Crew-Token' in sent.options.headers),action+' excludes unrelated credentials');
 check(sent.options.headers['Access-Console-Sandbox-Key']==='DEMO-sandbox',action+' preserves approved native sandbox scope');
 check(!sent.url.includes(actor.token)&&!sent.url.includes('DEMO-sandbox'),action+' never puts credentials in URL');
 if(method==='GET')check(!sent.options.body,action+' no GET body');
 else check(sent.options.headers['Content-Type']==='application/json',action+' JSON body');
 if(action==='connection-save')check(JSON.stringify(JSON.parse(sent.options.body))===JSON.stringify(link),'link command preserves exact confirmed revision body');
 if(action==='reconciliation-ack')check(sent.options.body==='{"reviewed":true}','acknowledgement only sends explicit review action');
 for(const field of ['user','company','token']){
   const changed=harness(contract);changed.identity={...actor,[field]:'DEMO-other'};
   await reject(()=>changed.request(action,params));check(changed.calls.length===0,action+' '+field+' change blocks before network');
 }
 const anonymous=harness(contract);anonymous.identity=null;await reject(()=>anonymous.request(action,params));check(anonymous.calls.length===0,action+' anonymous blocked');
 const wrongBearer=harness(contract);wrongBearer.headers.Authorization='Bearer DEMO-crew-token';await reject(()=>wrongBearer.request(action,params));check(wrongBearer.calls.length===0,action+' rejects header session mismatch');
 const duringFetch=harness(contract,env=>{env.identity={...actor,company:'DEMO-other'};return {ok:true,json:async()=>({contract,company_id:actor.company})};});
 await reject(()=>duringFetch.request(action,params));
 const duringJson=harness(contract,env=>({ok:true,json:async()=>{env.identity={...actor,token:'DEMO-new-token'};return {contract,company_id:actor.company};}}));
 await reject(()=>duringJson.request(action,params));
 for(const response of [{contract,company_id:'DEMO-other'},{contract},{company_id:actor.company},{contract:'mapa-asr-source-v1',company_id:actor.company}]){
   const mismatch=harness(contract,()=>({ok:true,json:async()=>response}));await reject(()=>mismatch.request(action,params));
 }
 for(const status of [401,403,409,422,503]){
   const denied=harness(contract,()=>({ok:false,status,json:async()=>{throw new Error('must not parse unsuccessful response');}}));
   await reject(()=>denied.request(action,params),e=>e.status===status);check(denied.calls.length===1,action+' rejection never retries mutation or fetch');
 }
 const malformed=harness(contract,()=>({ok:true,json:async()=>{throw new SyntaxError('DEMO malformed JSON');}}));await reject(()=>malformed.request(action,params));
}
for(const bad of ['',null,'../users','not-a-uuid',uuid+'/..','https://elsewhere.invalid',{}]){
 const h=harness('mapa-asr-connections-v1');await reject(()=>h.request('projection',{dataset:'drivers',id:bad}));check(h.calls.length===0,'invalid projection UUID blocked');
}
for(const dataset of ['users','../drivers','drivers/../../users',null]){
 const h=harness('mapa-asr-connections-v1');await reject(()=>h.request('projection',{dataset,id:uuid}));check(h.calls.length===0,'invalid projection dataset blocked');
}
for(const bad of ['',null,'A'.repeat(64),'a'.repeat(63),event+'/acknowledge','../state.json',{}]){
 const h=harness('mapa-asr-reconciliation-v1');await reject(()=>h.request('reconciliation-ack',{id:bad}));check(h.calls.length===0,'invalid event path blocked');
}
const query=harness('mapa-asr-connections-v1');await query.request('connections',cases[0][1]);
check(new URL(query.calls[0].url,'https://example.invalid').searchParams.get('search')==='DEMO / & <script>','search encoded exactly as data');
const unlink=harness('mapa-asr-connections-v1');const unlinkBody={action:'unlink',dataset:'drivers',source_id:'DEMO-driver',expected_link_revision:3,confirmed:true,reason:'DEMO incorrect match'};
await unlink.request('connection-save',unlinkBody);check(unlink.calls[0].options.body===JSON.stringify(unlinkBody),'unlink body not replaced by link defaults');
// Validation authority remains the API: transport must surface its invalid-body rejection unchanged.
for(const badBody of [{...link,native_uuid:'invalid'},{...link,confirmed:false},{...link,expected_link_revision:-1},{...link,unexpected:'DEMO'}]){
 const h=harness('mapa-asr-connections-v1',()=>({ok:false,status:422}));await reject(()=>h.request('connection-save',badBody),e=>e.status===422);check(h.calls.length===1,'invalid command rejected by API without automatic retry');
}
const local=fs.readFileSync(path.join(__dirname,'mapa-source-native.js'));
check(local.equals(fs.readFileSync(path.join(__dirname,'../../console/public/mapa-source-native.js'))),'source and console transports are byte-identical');
console.log('SOURCE CONNECTION TRANSPORT PASS '+count+' checks');
})().catch(error=>{console.error(error);process.exitCode=1;});
