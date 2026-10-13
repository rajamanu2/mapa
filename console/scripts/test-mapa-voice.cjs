/* Copyright (c) 2026 MAPA.DEV. SPDX-License-Identifier: AGPL-3.0-or-later */
'use strict';

// Run from any directory: node path/to/console/scripts/test-mapa-voice.cjs
// Exercises the shipped UI's pure rules without a DOM, credentials, API or phone call.
// This is not browser sign-in, native-server acceptance or provider commissioning.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const sourcePath = path.resolve(__dirname, '../public/mapa-voice.js');
const source = fs.readFileSync(sourcePath, 'utf8');
const sandbox = { module: { exports: {} } };
vm.runInNewContext(source, sandbox, { filename: sourcePath, timeout: 1000 });
const core = sandbox.module.exports;
let assertions = 0;
function check(condition, description) {
    assertions++;
    assert.ok(condition, description);
}
function rejects(run, description) {
    assertions++;
    assert.throws(run, undefined, description);
}

check(core.inAsrDispatchMap({ latitude: 18.332922, longitude: 82.865258 }), 'Araku pickup remains visible on the district dispatch map.');
check(!core.inAsrDispatchMap({ latitude: 13.214350, longitude: 77.668133 }), 'A Bengaluru provider fix cannot pull the district dispatch map away from Araku.');
check(!core.inAsrDispatchMap(null), 'A missing position cannot be plotted on the dispatch map.');
check(!core.showDispatchPosition({ latitude: 13.214350, longitude: 77.668133 }, true), 'The stale Bengaluru marker is omitted from the dispatch map.');
check(core.showDispatchPosition({ latitude: 13.214350, longitude: 77.668133 }, false), 'A genuinely recent out-of-area fix is preserved for the operator.');

const places = [{ id: 'place_demo_1', name: 'DEMO Hospital' }];
const draft = {
    callback_number: '', caller_name: 'DEMO Caller', reported_problem: 'Caller-reported problem',
    pickup_address: 'DEMO pickup', landmark: '', latitude: '17.9', longitude: '82.6',
    priority: 'routine', vehicle_requirement: 'any', dropoff_id: 'place_demo_1', coordinates_confirmed: true
};
const review = overrides => core.reviewDraft({ ...draft, ...overrides }, places);
const asrIntake = {patient_name: 'DEMO patient', patient_age: '0', patient_gender: 'Female', incident_type: 'Referral Transfer', intake_priority: 'urgent', origin_phc_name: 'DEMO PHC', origin_secretariat_name: 'DEMO secretariat', destination_facility_name: 'DEMO facility', dispatcher_notes: 'DEMO handover'};
const asrReview = review(asrIntake);
check(asrReview.ok && asrReview.data.patient_age === 0 && asrReview.data.incident_type === 'Referral Transfer' && asrReview.data.dispatcher_notes === 'DEMO handover', 'ASR intake survives review including neonatal age zero');
check(!review({...asrIntake, patient_age: '121'}).ok, 'invalid age rejected');
check(!review({...asrIntake, incident_type: 'unlisted'}).ok, 'unlisted incident rejected');
check(!review({...asrIntake, patient_gender: 'unlisted'}).ok, 'unlisted gender rejected');
check(asrReview.data.priority === draft.priority, 'intake priority does not silently change confirmed dispatch priority');

// Verified operator inputs; omitted coordinates must never silently become zero.
check(review({}).ok, 'A valid operator-reviewed draft is accepted.');
check(review({ callback_number: '+910000000000' }).ok, 'An optional leading + is accepted for a syntactically valid synthetic callback.');
check(review({ callback_number: '' }).ok, 'Unknown callback numbers may remain blank.');
for (const callback_number of ['abc', '+123', '123 456789', '+1234567890123456', '++12345678']) {
    check(!review({ callback_number }).ok, `Malformed callback rejected: ${callback_number}`);
}
for (const latitude of ['', ' ', null, undefined, 'NaN', 'Infinity', '91', '-91']) {
    check(!review({ latitude }).ok, `Missing/nonfinite/out-of-range latitude rejected: ${latitude}`);
}
for (const longitude of ['', ' ', '181', '-181', '-Infinity']) {
    check(!review({ longitude }).ok, `Invalid longitude rejected: ${longitude}`);
}
check(review({ latitude: '0', longitude: '0' }).ok, 'Actual numeric zero remains a valid explicit coordinate.');
check(review({ latitude: '-90', longitude: '180' }).ok, 'Legal coordinate boundary values are accepted.');
check(!review({ coordinates_confirmed: false }).ok, 'Operator coordinate confirmation is required.');
check(!review({ coordinates_confirmed: 'true' }).ok, 'A string does not grant operator confirmation.');
const pilotReview = review({ supervised_pilot: true, caller_name: 'MAPA PILOT — NO PATIENT', reported_problem: 'SUPERVISED MAPA PILOT — NO PATIENT. Two-crew drill.', callback_number: '', coordinates_confirmed: false, pilot_pickup_confirmed_by_operator: true });
check(pilotReview.ok && pilotReview.data.supervised_pilot === true && pilotReview.data.pilot_pickup_confirmed_by_operator === true && pilotReview.data.coordinates_confirmed === false, 'No-patient review records distinct operator test-point confirmation without caller read-back.');
check(!('patient_gender' in pilotReview.data)&&!('consciousness' in pilotReview.data)&&!('destination_facility_name' in pilotReview.data),'Pilot review omits even default clinical and hospital handover fields.');
check(!review({ supervised_pilot: true, caller_name: 'MAPA PILOT — NO PATIENT', coordinates_confirmed: false, pilot_pickup_confirmed_by_operator: false }).ok, 'Pilot cannot review without explicit operator test-point confirmation.');
check(!review({ supervised_pilot: true, caller_name: 'MAPA PILOT — NO PATIENT', coordinates_confirmed: true, pilot_pickup_confirmed_by_operator: true }).ok, 'Pilot cannot claim caller-confirmed coordinates.');
check(!review({ supervised_pilot: true, caller_name: 'MAPA PILOT — NO PATIENT', callback_number: '+910000000000', coordinates_confirmed: false, pilot_pickup_confirmed_by_operator: true }).ok, 'Pilot review rejects a caller phone.');
check(!review({ reported_problem: '' }).ok, 'Reported problem is required.');
check(!review({ pickup_address: ' ' }).ok, 'Verified pickup is required.');
check(!review({ priority: '' }).ok, 'Operator must choose a priority.');
check(!review({ priority: 'automatic_triage' }).ok, 'Unknown/automated priority is rejected.');
check(!review({ vehicle_requirement: 'helicopter' }).ok, 'Unadvertised vehicle capability is rejected.');
for (const priority of ['routine', 'urgent', 'emergency']) {
    check(review({ priority }).ok, `Documented operator priority accepted: ${priority}`);
}
for (const vehicle_requirement of ['any', 'bls', 'als']) {
    check(review({ vehicle_requirement }).ok, `Documented capability choice accepted: ${vehicle_requirement}`);
}
check(!review({ dropoff_id: 'place_other_company' }).ok, 'Destination must belong to the server-provided native place list.');
check(!core.reviewDraft(draft, []).ok, 'An empty destination list cannot authorize dispatch.');
check(!core.reviewDraft(draft, null).ok, 'A missing destination list fails closed.');
check(review({ reported_problem: 'x'.repeat(2000) }).ok, 'The server-supported 2000-character problem limit is accepted.');
check(!review({ reported_problem: 'x'.repeat(2001) }).ok, 'A 2001-character problem is rejected before sending.');
check(review({ reported_problem: `   ${'x'.repeat(2000)}   ` }).data.reported_problem.length === 2000, 'Problem whitespace is trimmed before validation.');
check(/inputs\.reported_problem\.maxLength = 2000/.test(source), 'The visible field shares the server-supported limit.');
const transcriptText = '<img src=x onerror=alert(1)> caller text';
check(review({ reported_problem: transcriptText }).data.reported_problem === transcriptText, 'Transcript-derived text is kept as literal data, not instructions.');

// These are UI scope barriers; authoritative tenant/identity checks remain on the server.
const call = { id: 'voice_demo', state: 'ready', revision: 4, review: {}, demo: false };
const liveDriver = { id: 'driver_live', vehicle_id: 'vehicle_live', simulated: false };
const demoDriver = { id: 'driver_demo', vehicle_id: 'vehicle_demo', simulated: true };
const candidates = [liveDriver, demoDriver];
check(core.eligible(call, candidates).length === 1 && core.eligible(call, candidates)[0].id === liveDriver.id, 'A live call cannot offer a DEMO ambulance.');
check(core.eligible({ ...call, demo: true }, candidates).length === 1 && core.eligible({ ...call, demo: true }, candidates)[0].id === demoDriver.id, 'A DEMO call offers only DEMO resources.');
check(core.eligible({ ...call, state: 'needs_review' }, candidates).length === 0, 'An unreviewed call has no selectable candidates.');
check(core.eligible({ ...call, review: null }, candidates).length === 0, 'Missing review fails closed.');
check(core.eligible({ ...call, demo: 'false' }, candidates).length === 0, 'Malformed DEMO identity fails closed.');
check(core.eligible(call, [{ ...liveDriver, simulated: undefined }]).length === 0, 'Missing resource simulation identity fails closed.');
check(core.eligible(call, [{ ...liveDriver, id: '../other' }]).length === 0, 'Candidate ID cannot escape an API path.');
check(core.eligible(call, [{ ...liveDriver, vehicle_id: '' }]).length === 0, 'A candidate must identify its native vehicle.');
check(core.eligible(call, null).length === 0, 'Missing eligibility results cannot enable dispatch.');
check(core.dispatchAllowed(call, candidates, liveDriver.id, false, false, 4), 'Current reviewed call and explicit eligible selection permit confirmation.');
check(!core.dispatchAllowed(call, candidates, '', false, false, 4), 'No automatic candidate selection.');
check(!core.dispatchAllowed(call, candidates, demoDriver.id, false, false, 4), 'A selected DEMO candidate cannot bypass live filtering.');
check(!core.dispatchAllowed(call, candidates, liveDriver.id, true, false, 4), 'Edited draft invalidates candidate approval.');
check(!core.dispatchAllowed(call, candidates, liveDriver.id, false, true, 4), 'Pending/uncertain update blocks a replacement dispatch.');
check(!core.dispatchAllowed(call, candidates, liveDriver.id, false, false, 3), 'Stale draft revision blocks dispatch.');
check(!core.dispatchAllowed({ ...call, revision: '4' }, candidates, liveDriver.id, false, false, '4'), 'String revision cannot satisfy the integer contract.');
check(!core.dispatchAllowed({ ...call, revision: -1 }, candidates, liveDriver.id, false, false, -1), 'Negative revision is rejected.');
check(!core.dispatchAllowed({ ...call, state: 'dispatched' }, candidates, liveDriver.id, false, false, 4), 'A dispatched call cannot be sent again.');
check(!core.dispatchAllowed({ ...call, order_id: 'order_existing' }, candidates, liveDriver.id, false, false, 4), 'An existing native order reference blocks a second dispatch.');

// Retry is an immutable saved path + serialized body, never a regenerated request.
const actionId = '11111111-2222-4333-8444-555555555555';
const payload = { expected_revision: 4, confirmed: true, driver_id: liveDriver.id, nested: { value: 'original' } };
const intent = core.makeIntent('/calls/voice_demo/dispatch', payload, actionId);
const firstBody = intent.body;
payload.driver_id = 'driver_changed'; payload.nested.value = 'changed';
check(intent.body === firstBody, 'An uncertain retry retains the identical serialized body.');
check(JSON.parse(intent.body).driver_id === liveDriver.id, 'Changing the form object cannot change the saved ambulance choice.');
check(JSON.parse(intent.body).nested.value === 'original', 'Nested input is copied before the retry envelope is saved.');
check(JSON.parse(intent.body).action_id === actionId, 'The immutable receipt key survives retry.');
check(Object.isFrozen(intent), 'Saved path/body envelope is frozen.');
rejects(() => { intent.body = '{}'; }, 'A saved retry body cannot be overwritten.');
check(core.makeIntent('/calls/voice_demo/extract', { expected_revision: 4 }, actionId).path.endsWith('/extract'), 'Draft extraction shares protected action-key handling.');
for (const invalidPath of ['https://evil.example', '/calls/../dispatch', '/calls/voice_demo/dispatch?x=1', '/calls/voice_demo/complete', '/calls/voice%2fother/dispatch']) {
    rejects(() => core.makeIntent(invalidPath, payload, actionId), `Unapproved mutation path rejected: ${invalidPath}`);
}
rejects(() => core.makeIntent('/demo', {}, 'new-random-key'), 'A malformed action UUID is rejected.');
check(core.failureWarning('failed') && core.failureWarning('audio_gap'), 'Interrupted stream states retain a visible warning.');
check(core.failureWarning('process_restarted'), 'Process interruption is not mistaken for a complete transcript.');
check(!core.failureWarning('ended'), 'Ordinary stream completion is not mislabeled as a failure.');
check(!/\.innerHTML\s*=|insertAdjacentHTML|\beval\s*\(/.test(source), 'Transcript UI never inserts dynamic HTML or executes it.');
check(!/\blocalStorage\b|\bsessionStorage\b|document\.cookie/.test(source), 'Call Intake does not scrape or persist browser credentials.');

// Manual calls always enter the existing review workflow, never confirmed by map search.
check(core.intakeNotice({ manual_intake: true, live_intake: false }) === 'Answer calls on your existing phone number. Select NEW CALL to enter the caller’s details, then review and confirm before dispatch.', 'The enabled manual desk explains the selected existing-phone workflow.');
check(core.intakeNotice({ manual_intake: true }) === core.intakeNotice({ manual_intake: true, live_intake: false }), 'Missing optional hotline capability still describes the enabled operator desk.');
check(core.intakeNotice({ manual_intake: false, live_intake: false }).startsWith('Call intake is disabled.'), 'Both disabled intake modes show a truthful disabled message.');
check(core.intakeNotice({ manual_intake: false, live_intake: true }) === '', 'Enabled provider intake is not mislabeled as disabled.');
check(core.intakeNotice({ manual_intake: 'true', live_intake: 'true' }).startsWith('Call intake is disabled.'), 'Malformed capability values cannot claim the operator desk is enabled.');
const manual = overrides => core.manualDraft({ reported_problem: 'DEMO caller-reported incident', demo: true, ...overrides });
check(manual({}).ok && manual({}).data.demo === true, 'A minimal explicitly marked training report can be saved for review.');
check(manual({ demo: false }).data.demo === false, 'Explicit live mode creates a live manual draft.');
const pilotCreate = core.manualDraft({ demo: false, supervised_pilot: true, caller_name: 'MAPA PILOT — NO PATIENT', callback_number: '', reported_problem: 'SUPERVISED MAPA PILOT — NO PATIENT. Two-crew drill.', pickup_address: 'Araku Railway Station', latitude: '18.332922', longitude: '82.865258', pickup_confirmed_at_creation: false });
check(pilotCreate.ok && pilotCreate.data.supervised_pilot === true && pilotCreate.data.coordinates_confirmed === false && pilotCreate.data.pickup_confirmed_at_creation === false, 'Pilot creation saves a test marker and coordinate candidate without false caller confirmation.');
check(!('patient_gender' in pilotCreate.data)&&!('breathing' in pilotCreate.data)&&!('destination_facility_name' in pilotCreate.data),'Pilot create omits clinical defaults and hospital handover fields.');
check(!core.manualDraft({ ...pilotCreate.data, patient_name: 'DEMO patient' }).ok, 'Pilot rejects patient identity even if supplied outside the visible form.');
check(!core.manualDraft({ ...pilotCreate.data, demo: true }).ok, 'Pilot cannot be mislabeled as synthetic DEMO while using live crew phones.');
check(!core.manualDraft({ ...pilotCreate.data, caller_name: 'Actual caller' }).ok, 'Pilot requires the exact no-patient label.');
check(!core.manualDraft({ ...pilotCreate.data, callback_number: '+910000000000' }).ok, 'Pilot creation cannot include a caller phone.');
check(!core.manualDraft({ ...pilotCreate.data, pickup_confirmed_at_creation: true }).ok, 'Pilot cannot claim caller pickup confirmation at creation.');
check(core.dispatchPipeline({ id: 'call_pilot', supervised_pilot: true, state: 'ready', review: { pilot_pickup_confirmed_by_operator: true, latitude: 18.332922, longitude: 82.865258 } }).map(step => step.label).join(',') === 'PILOT,TEST POINT,OFFERS,ACCEPTED,CLOSED', 'Pilot lifecycle excludes patient onboard and hospital stages.');
check(!manual({ reported_problem: '' }).ok, 'A manual report cannot be empty.');
check(!manual({ callback_number: 'caller unknown' }).ok, 'Unknown callback is blank, not an invalid phone string.');
for (const [callback_number, expected] of [
    ['+1 (202) 555-0142', '+12025550142'], ['98765 43210', '+919876543210'],
    ['919876543210', '+919876543210'], ['+44 20 7946 0958', '+442079460958'],
    ['', ''], [null, ''], ['  ', '']
]) {
    const created = manual({ callback_number }), reviewed = review({ callback_number });
    check(created.ok && created.data.callback_number === expected, 'Manual callback matches the shared server normalization.');
    check(reviewed.ok && reviewed.data.callback_number === expected, 'Reviewed callback remains deliverable to assigned crew.');
}
for (const callback_number of ['1234567', '09876543210', '+01234567890', 'tel:+12025550142', '+12025550142;123', '+12025550142,123', "+12025550142\n", 1234567890, ' '.repeat(41)]) {
    check(!manual({ callback_number }).ok && !review({ callback_number }).ok, 'An undeliverable callback is rejected before intake or review.');
}
const callbackIntent = core.makeIntent('/calls/manual', manual({ callback_number: '+1 (202) 555-0142' }).data, actionId);
check(JSON.parse(callbackIntent.body).callback_number === '+12025550142', 'The normalized callback is captured in the immutable retry body.');
check(manual({ coordinates_confirmed: true }).data.coordinates_confirmed === false, 'New manual calls never inherit coordinate confirmation.');
check(!manual({ latitude: '18.083', longitude: '' }).ok, 'A half coordinate cannot become a pickup candidate.');
check(manual({ latitude: '18.083', longitude: '82.669' }).ok, 'A candidate coordinate can be saved for later read-back.');
for (const patient_count of [0, -1, 100, 1.5, 'many']) check(!manual({ patient_count }).ok, 'Invalid patient counts are rejected.');
check(manual({ patient_count: '' }).ok, 'An unknown patient count stays unknown.');
check(manual({ patient_count: '2' }).data.patient_count === 2, 'A reported count is serialized as an integer.');
check(!manual({ consciousness: 'diagnosed_coma' }).ok, 'Diagnostic labels cannot replace the explicit caller observations.');
check(!manual({ breathing: 'automatic_triage' }).ok, 'Automated breathing classifications are rejected.');
check(!manual({ scene_safety: 'AI_verified_safe' }).ok, 'Scene safety requires a documented caller observation.');
check(manual({}).data.consciousness === 'unknown', 'Unreported consciousness defaults to unknown, not awake.');
check(manual({ priority: 'emergency' }).data.priority==='emergency'&&manual({ priority: 'emergency' }).data.coordinates_confirmed===false, 'Creation retains operator priority but cannot skip dispatch review.');
check(manual({vehicle_requirement:'als'}).data.vehicle_requirement==='als','Creation retains supported capability.');
check(!manual({vehicle_requirement:'neonatal'}).ok,'Unsupported creation capability is rejected.');
check(manual({}).data.pickup_confirmed_at_creation===false,'Creation pickup acknowledgement starts unchecked.');
check(!manual({pickup_confirmed_at_creation:true,latitude:'',longitude:''}).ok,'Creation acknowledgement requires a valid exact pin.');
check(manual({pickup_confirmed_at_creation:true,latitude:18,longitude:82,pickup_address:'DEMO entrance'}).data.coordinates_confirmed===false,'Creation pickup evidence cannot manufacture dispatch confirmation.');
check(!review({ patient_count: 100 }).ok, 'Patient count also remains bounded during final review.');
check(core.makeIntent('/calls/manual', manual({}).data, actionId).path === '/calls/manual', 'Manual creation uses the existing immutable action receipt.');
check(core.pickupCandidate({ latitude: '', longitude: '82.6' }) === null, 'A blank map coordinate is not zero.');
check(core.pickupCandidate({ latitude: '18.08', longitude: '82.66', label: '<b>Village</b>' }).label === '<b>Village</b>', 'Location labels remain plain data.');
check(core.pickupCandidate({ latitude: 'Infinity', longitude: 82 }) === null, 'Non-finite map candidates are rejected.');
check(core.pickupCandidate({ latitude: 91, longitude: 82 }) === null, 'Out-of-range map candidates are rejected.');
check(core.ASR_BOUNDS.length === 2 && core.ASR_BOUNDS[0][0] < core.ASR_BOUNDS[1][0], 'The ASR overview uses ordered map bounds.');
const evidence = core.travelPoints([{ latitude: 18.1, longitude: 82.6, recorded_at: '2026-09-19T10:02:00Z' }, { latitude: 18, longitude: 82, recorded_at: '2026-09-19T10:00:00Z' }, { latitude: '', longitude: 82, recorded_at: '2026-09-19T10:03:00Z' }, { latitude: 18, longitude: 82, recorded_at: null }]);
check(evidence.length === 2 && evidence[0].recorded < evidence[1].recorded, 'Crew evidence excludes invalid fixes and sorts accepted timestamps.');
check(core.travelPoints(null).length === 0, 'Missing history remains empty, without inferred travel.');

// A saved backend-valid value must remain editable without truncation or false rejection.
for (const field of ['caller_name', 'village', 'mandal']) {
    check(review({ [field]: 'A'.repeat(200) }).ok, `${field} retains the backend's 200-character limit.`);
    check(!review({ [field]: 'A'.repeat(201) }).ok, `${field} rejects values beyond the backend limit.`);
}
for (const field of ['consciousness', 'breathing', 'scene_safety']) {
    check(review({ [field]: 'unknown' }).data[field] === 'unknown', `${field} preserves unknown caller reports.`);
    check(!review({ [field]: 'unsupported' }).ok, `${field} rejects unsupported dropdown values.`);
}
check(!review({ dropoff_id: 'place_removed' }).ok, 'A destination absent from current backend options requires reselection.');
check(!review({ priority: '' }).ok, 'Priority cannot be silently inferred from an empty selection.');
check(!review({ vehicle_requirement: '' }).ok, 'Ambulance capability needs an explicit valid selection.');

// Destination pages remain bounded, scoped to validated detail responses and never auto-select.
for (const path of ['/calls/call_1?destination_page=1', '/calls/call_1?destination_page=99999']) check(core.validRequestPath(path), 'Only bounded positive destination-page requests are supported.');
check(core.validRequestPath('/intake-launch/exchange',true),'Authenticated launch exchange uses the exact mobile endpoint.');
check(!core.validRequestPath('/intake-launch/exchange',false),'Launch exchange is not a voice endpoint.');
for(const path of ['/intake-launch/exchange?ticket=a','/intake-launch/other','//intake-launch/exchange']) check(!core.validRequestPath(path,true),'Launch request rejects alternate paths and URL credentials.');
for (const path of ['/calls/call_1?destination_page=0','/calls/call_1?destination_page=-1','/calls/call_1?destination_page=1.5','/calls/call_1?destination_page=100000','/calls/call_1?destination_page=1&company=other','/calls/call_1/review?destination_page=1','/calls/../?destination_page=1']) check(!core.validRequestPath(path), 'Invalid destination queries cannot bypass the request allowlist.');
const savedPlace = { id: 'place_saved', name: 'DEMO Saved Hospital', address: 'DEMO base' };
const pageResult = { places: [{ id: 'place_a', name: 'DEMO Hospital A', address: '' }], destination_page: 1, destination_page_size: 200, destinations_has_more: true, call: { review: { dropoff_id: savedPlace.id } }, selected_destination: savedPlace };
const firstPage = core.destinationPage(pageResult);
check(firstPage.rows.length === 2 && firstPage.rows[1].id === savedPlace.id && firstPage.more, 'A saved eligible destination stays visible when outside the current page.');
const secondPage = core.destinationPage({ ...pageResult, places: [savedPlace, { id: 'place_b', name: 'DEMO Hospital B', address: '' }], destination_page: 2, destinations_has_more: false }, 2, firstPage.rows);
check(secondPage.rows.length === 3 && !secondPage.more && secondPage.page === 2, 'Pages accumulate and deduplicate a pinned destination without changing identity.');
check(firstPage.rows.length === 2, 'Merging pages does not mutate the previous selection options.');
check(core.destinationPage({ places: [{ id: 'place_a', name: 'DEMO Hospital A', address: '' }] }).known === false, 'An older server is labelled as a limited list, never claimed complete.');
for (const result of [{ ...pageResult, destination_page: 2 }, { ...pageResult, destination_page_size: 999 }, { ...pageResult, destinations_has_more: 'true' }, { ...pageResult, places: [pageResult.places[0],pageResult.places[0]] }, { ...pageResult, places: [{id:'../bad', name:'DEMO', address:''}] }, { ...pageResult, places: Array(201).fill(pageResult.places[0]) }, { ...pageResult, selected_destination: { ...savedPlace, id: 'place_unrelated' } }]) rejects(() => core.destinationPage(result), 'Malformed or changed destination pages fail closed.');
rejects(() => core.destinationPage({ places: [] }, 2), 'Older servers cannot pretend to return the next page.');
check(review({ dropoff_id: savedPlace.id }).ok === false, 'Loading a different list cannot authorize an unadvertised destination.');
check(core.reviewDraft({ ...draft, dropoff_id: savedPlace.id }, secondPage.rows).ok, 'An explicitly selected destination loaded on another page remains reviewable.');

console.log(`MAPA Call Intake UI: ${assertions} checks passed (pure source tests; no browser, API or provider calls).`);
