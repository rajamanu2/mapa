import assert from 'node:assert/strict';
import {createAccessHandler} from './access.mjs';
let time = 1000000, mode = 'admin', fetches = 0;
const company = 'asr-company';
const handler = createAccessHandler({apiOrigin:'http://native.invalid',company,administratorRoleId:'approved-admin-role',customerUserId:'approved-customer',now:()=>time,fetchImpl:async () => {
  fetches++;
  if (mode === 'failure') throw new Error('upstream unavailable');
  if (mode === 'revoked') return new Response('{}',{status:401});
  const user={is_admin:['admin','other','inactive','inactive-member','missing-member'].includes(mode), company_uuid:mode==='other'?'other-company':company,status:mode==='inactive'?'inactive':'active',session_status:mode==='inactive-member'?'suspended':mode==='missing-member'?undefined:'active'};
  if (mode.startsWith('company-')) user.role={id:mode==='company-wrong-id'?'unapproved-role':'approved-admin-role',name:mode==='company-wrong-name'?'Viewer':'Administrator',company_uuid:mode==='company-foreign-role'?'another-company':null};
  user.uuid=mode==='company-unapproved-customer'?'someone-else':'approved-customer';
  return Response.json({user});
}});
const auth = 'Bearer 123|' + 'a'.repeat(64);
async function call(headers={}) {
  const result={};
  await handler({url:'/authorize',method:'GET',headers},{writeHead(status,headers){result.status=status;result.headers=headers;},end(){}});
  return result;
}
assert.equal((await call()).status,401);
assert.equal(fetches,0);
const granted=await call({authorization:auth,'x-original-uri':'/api/int/v1/vehicles'});
assert.equal(granted.status,204);
assert.match(granted.headers['Set-Cookie'],/HttpOnly; Secure; SameSite=Strict/);
const cookie=granted.headers['Set-Cookie'].split(';')[0];
assert.equal((await call({cookie,'x-original-uri':'/api/int/v1/vehicles'})).status,401,'Cookie must never authorize HTTP data or writes');
assert.equal((await call({authorization:auth,'x-original-uri':'/socketcluster/'})).status,401,'WS requires its issued cookie');
assert.equal((await call({cookie,'x-original-uri':'/socketcluster/'})).status,204);
assert.equal((await call({cookie:cookie+'forged','x-original-uri':'/socketcluster/'})).status,401);
mode='revoked'; assert.equal((await call({cookie,'x-original-uri':'/socketcluster/'})).status,401);
for (mode of ['viewer','other','inactive','inactive-member','missing-member','company-wrong-id','company-wrong-name','company-foreign-role','company-unapproved-customer']) assert.equal((await call({authorization:auth})).status,403,mode);
mode='company-admin'; assert.equal((await call({authorization:auth})).status,204,'Exact company Administrator role permits native scoped access without global admin');
assert.equal((await call({authorization:auth,'x-original-method':'POST','x-original-uri':'/api/int/v1/orders'})).status,204,'Operational writes reach native permissions');
for (const path of ['/api/int/v1/users/change-password','/api/int/v1/users/owner/change-email','/api/int/v1/users/remove-from-company/owner','/api/int/v1/roles/administrator',
  '/api/int/v1/users%2Fchange-password','/api/int/v1//users/change-password','/api/int/v1/orders/../users/change-password','/api/int/v1/users%252fchange-password']) {
  assert.equal((await call({authorization:auth,'x-original-method':'POST','x-original-uri':path})).status,403,'Customer cannot change owner credentials or security access');
}
mode='admin'; assert.equal((await call({authorization:auth,'x-original-method':'POST','x-original-uri':'/api/int/v1/users/change-password'})).status,204,'Original owner retains native permissions');
mode='failure'; assert.equal((await call({authorization:auth})).status,503);
mode='admin'; time+=31*60*1000;
assert.equal((await call({cookie,'x-original-uri':'/socketcluster/'})).status,401,'Expired socket session rejected');
console.log('PASS: administrator/company/status boundaries, cookie scope, forged/expired/revoked socket sessions, and upstream failure.');
