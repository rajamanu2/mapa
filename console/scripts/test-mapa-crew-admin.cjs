/* Copyright (c) 2026 MAPA.DEV. SPDX-License-Identifier: AGPL-3.0-or-later */
'use strict';
const assert = require('node:assert/strict');
const core = require('../public/mapa-voice.js');
let checks = 0;
function check(value, label) { assert.ok(value, label); checks++; }
const pending = { id:'registration_one', revision:1, status:'pending', name:'Registered crew' };
const vehicle = { id:'vehicle_verified' }, base = { id:'place_verified' }, choices={ vehicles:[vehicle], places:[base] };
const input={ vehicle_id:vehicle.id, place_id:base.id, identity_confirmed:true };
check(core.crewApprovalDraft(pending,input,choices).ok, 'Reviewed actual native choices permit an approval intent.');
const matched={...pending,crew_matches:[{id:'driver_existing',name:'Existing crew',matched_on:['name']}]};
check(!core.crewApprovalDraft(matched,input,choices).ok,'A name match requires an explicit review decision.');
const link=core.crewApprovalDraft(matched,{...input,driver_id:'driver_existing'},choices);
check(link.ok && link.data.driver_id==='driver_existing','Reviewed existing crew is passed to approval.');
check(!core.crewApprovalDraft(matched,{...input,driver_id:'driver_foreign'},choices).ok,'Unlisted crew cannot be linked.');
check(core.crewApprovalDraft(matched,{...input,create_new_crew:true},choices).ok,'Staff can explicitly identify a different new person.');
check(!core.crewApprovalDraft(matched,{...input,create_new_crew:'true'},choices).ok,'Creating a new person requires a boolean confirmation.');
for (const altered of [null,{...pending,status:'approved'},{...pending,status:'rejected'},{...pending,status:'revoked'},{...pending,revision:'1'},{...pending,revision:-1},{...pending,id:'../other'}]) check(!core.crewApprovalDraft(altered,input,choices).ok,'Only a current pending registration can be approved.');
for (const changes of [{identity_confirmed:false},{identity_confirmed:'true'},{vehicle_id:'user_supplied'},{vehicle_id:'vehicle_verified/other'},{place_id:'foreign_place'},{place_id:''}]) check(!core.crewApprovalDraft(pending,{...input,...changes},choices).ok,'Unverified identity or unavailable native assignment is blocked.');
check(!core.crewApprovalDraft(pending,input,{vehicles:[],places:[base]}).ok,'No eligible ambulance fails closed.');
check(!core.crewApprovalDraft(pending,input,{vehicles:[vehicle],places:[]}).ok,'No native hospital/base fails closed.');
const body={expected_revision:1,vehicle_id:vehicle.id,place_id:base.id,identity_confirmed:true,nested:{reason:'original'}};
const key='11111111-2222-4333-8444-555555555555';
for (const operation of ['approve','reject','revoke']) {
  const intent=core.crewIntent(`/enrollments/registration_one/${operation}`,body,key); const first=intent.body;
  body.nested.reason='changed';
  check(JSON.parse(intent.body).action_id===key,'An exact retry keeps its secure receipt identifier.');
  check(Object.isFrozen(intent),'Crew intent envelope cannot be overwritten.');
  check(intent.body===first,'Changed form data cannot change a saved crew update.');
}
for(const path of ['/enrollments/../approve','/enrollments/registration_one/approve?as=admin','https://other.example/enrollments/x/approve','/enrollments/registration_one/delete','/enrollments/x%2fy/approve']) {
  assert.throws(()=>core.crewIntent(path,body,key)); checks++;
  check(!core.validRequestPath(path,true),'Unsupported mutation path never receives the bearer credential.');
}
assert.throws(()=>core.crewIntent('/enrollments/registration_one/approve',{expected_revision:'1'},key)); checks++;
assert.throws(()=>core.crewIntent('/enrollments/registration_one/approve',body,'invalid')); checks++;
for(const path of ['/enrollments','/enrollments/options','/enrollments/registration_one/approve','/enrollments/registration_one/reject','/enrollments/registration_one/revoke','/trip-reports','/media/private_photo']) check(core.validRequestPath(path,true),'Supported company-bound endpoint is allowlisted.');
for(const path of ['/enrollment','/connect','/media/private_photo?token=secret','/media/../secret','/media/https://evil','/trip-reports?company_id=other','/enrollments/registration_one/approve/extra']) check(!core.validRequestPath(path,true),'Staff interface cannot call connection endpoints or arbitrary paths.');
const filtered=core.enrollmentRows([pending,{...pending,id:'bad/id'},{...pending,revision:null},{...pending,status:'unknown'},{...pending,status:'approved'}]);
check(filtered.length===2,'Unsafe or incompatible registration records are not displayed.');
check(core.enrollmentRows(Array.from({length:200},()=>pending)).length===100,'Registration rendering is bounded.');
check(core.tripReportRows([{id:'report_1',order_id:'order_1'},{id:'../private',order_id:'order_1'},{id:'report_2',order_id:'other/tenant'}]).length===1,'Reports require safe native references.');
check(core.privateMediaPath('photo_1')==='/media/photo_1','Media references become only a same-origin private API path.');
for(const id of ['../secret','https://evil.example','photo?token=secret','photo#fragment','',null]) check(core.privateMediaPath(id)===null,'An arbitrary image URL is never accepted.');
const approvalIntent=core.crewIntent('/enrollments/registration_one/approve',{expected_revision:1},key);
const approvedRow={...pending,status:'approved',revision:2};
check(core.enrollmentReceipt(approvalIntent,{ok:true,enrollment:approvedRow})===approvedRow,'Only the saved registration and expected approval receipt resolves a pending update.');
for(const wrong of [{ok:false,enrollment:approvedRow},{ok:'true',enrollment:approvedRow},{ok:true,enrollment:{...approvedRow,id:'registration_other'}},{ok:true,enrollment:{...approvedRow,status:'pending'}},{ok:true,enrollment:{...approvedRow,status:'rejected'}},{ok:true,enrollment:{...approvedRow,revision:1}},{ok:true,enrollment:{...approvedRow,revision:3}}]) check(core.enrollmentReceipt(approvalIntent,wrong)===null,'Mismatched or incomplete receipt remains uncertain and cannot confirm the crew update.');
console.log(`MAPA crew administrator behavior: ${checks} checks passed; no accounts, approvals, photos or dispatches submitted.`);
