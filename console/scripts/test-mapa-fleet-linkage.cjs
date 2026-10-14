/* Copyright (c) 2026 MAPA.DEV. SPDX-License-Identifier: AGPL-3.0-or-later */
'use strict';

// Isolated checks of the shipped display rules. No API, credentials or fleet changes.
const assert = require('node:assert/strict');
const { crewMapping, withCurrentCrewMapping } = require('../public/mapa-voice.js');
let checks = 0;
const check = (condition, message) => { assert.ok(condition, message); checks++; };
const directory = Object.freeze({
    driver_id: 'driver_DEMO_1', name: 'DEMO Crew', vehicle_id: 'vehicle_DEMO_1',
    vehicle_registration: 'DEMO-001', crew_link_status: 'linked', crew_mobile_bound: true,
    hospital_place_id: 'place_DEMO_1', hospital_name: 'DEMO Hospital', source_owned: false
});
const expected = crewMapping(directory);
check(expected.verifiedBase && expected.summary === 'Driver: DEMO Crew → Ambulance: DEMO-001 → Verified hospital / base: DEMO Hospital', 'Exact current crew binding displays the three linked identities');
check(!/eligible|ready to assign/i.test(expected.summary + expected.notes.join(' ')), 'Linkage alone never promises dispatch eligibility');

const unverified = crewMapping({ ...directory, hospital_place_id: null });
check(!unverified.verifiedBase && unverified.summary.includes('no verified crew link returned'), 'A hospital name alone cannot be shown as a verified crew base');
check(unverified.notes.includes('Recorded base: DEMO Hospital (not a verified crew link)'), 'Administrative base stays visible with its qualification');
check(!crewMapping({ ...directory, hospital_place_id: 'not a valid identity' }).verifiedBase, 'Malformed hospital identity fails closed');
check(!crewMapping({ ...directory, vehicle_id: null }).verifiedBase, 'A verified triplet requires the current vehicle identity, not registration text alone');
const disconnected = crewMapping({ ...directory, crew_mobile_bound: false });
check(!disconnected.verifiedBase && disconnected.notes.includes('Crew phone not connected'), 'Disconnected phone is visible even when an old hospital ID exists');
check(!crewMapping({ ...directory, crew_mobile_bound: undefined }).verifiedBase, 'Omitted binding evidence is unknown, never implicit approval');
const ambiguous = crewMapping({ ...directory, crew_link_status: 'ambiguous' });
check(!ambiguous.verifiedBase && ambiguous.summary.includes('multiple crew links need review') && !ambiguous.summary.includes('Driver: DEMO Crew'), 'Ambiguous assignment does not choose an arbitrary driver');
check(crewMapping({ ...directory, driver_mapping_ambiguous: true }).summary.includes('multiple crew links need review'), 'Command-desk ambiguity is handled too');
const unlinked = crewMapping({ ...directory, crew_link_status: 'unlinked' });
check(!unlinked.verifiedBase && unlinked.summary.includes('no assigned driver returned'), 'Unlinked status overrides leftover driver identity');
check(crewMapping({ vehicle_id: 'vehicle_DEMO_1', registration: 'DEMO-001' }).summary.includes('no assigned driver returned'), 'A GPS-only ambulance does not gain a fabricated crew');
check(crewMapping({ vehicle_id: 'vehicle_DEMO_1', driver_id: 'driver_DEMO_1', driver_name: null, name: 'DEMO Ambulance', registration: 'DEMO-001' }).summary.startsWith('Driver: driver_DEMO_1 →'), 'A desk row with missing driver name falls back to driver ID, never the ambulance name');
check(crewMapping({ ...directory, source_owned: true }).notes.some(note => note.includes('MAPA dispatch authority not enabled')), 'Legacy source-owned fleet does not imply MAPA dispatch authority');

const position = Object.freeze({
    vehicle_id: 'vehicle_DEMO_1', driver_id: 'driver_DEMO_1', driver_name: 'DEMO Crew', registration: 'DEMO-001',
    latitude: 18.3, longitude: 82.8, location_source: 'hardware_gps', location_recorded_at: 1791900000000,
    gps_stale: true, accuracy_m: null
});
const joined = withCurrentCrewMapping(position, [directory]);
check(crewMapping(joined).verifiedBase, 'Exact driver and vehicle IDs join current directory facts to a map position');
check(joined.latitude === position.latitude && joined.longitude === position.longitude && joined.location_recorded_at === position.location_recorded_at && joined.location_source === 'hardware_gps' && joined.gps_stale && joined.accuracy_m === null, 'Mapping never overwrites coordinates, provenance, time or GPS quality');
check(!Object.hasOwn(position, 'hospital_place_id') && !Object.hasOwn(directory, 'latitude'), 'Frozen source records are not modified by mapping');
const changedDriver = withCurrentCrewMapping(position, [{ ...directory, driver_id: 'driver_DEMO_2' }]);
check(!crewMapping(changedDriver).verifiedBase && changedDriver.mapping_notice.includes('changed or is ambiguous'), 'A shared vehicle with a different driver cannot inherit the prior crew base');
check(!crewMapping(withCurrentCrewMapping(position, [{ ...directory, vehicle_id: 'vehicle_DEMO_2' }])).verifiedBase, 'A shared driver with a different vehicle cannot inherit the prior crew base');
check(!crewMapping(withCurrentCrewMapping(position, [directory, directory])).verifiedBase, 'Duplicate directory matches fail closed instead of selecting the first');
check(!crewMapping(withCurrentCrewMapping({ ...position, driver_id: null }, [directory])).verifiedBase, 'A missing position driver ID cannot be joined by vehicle alone');
check(crewMapping(withCurrentCrewMapping({ ...position, driver_id: null }, [{ ...directory, driver_id: null, crew_link_status: 'ambiguous' }])).summary.includes('multiple crew links need review'), 'Explicit current vehicle ambiguity stays visible without fabricating a driver join');
const staleFacts = withCurrentCrewMapping({ ...position, ...directory }, []);
check(!crewMapping(staleFacts).verifiedBase && !Object.hasOwn(staleFacts, 'hospital_name'), 'A map position cannot retain an old verified base when no current directory supports it');
check(withCurrentCrewMapping(position, [{ ...directory, source_owned: true }]).source_owned === true, 'Exact source ownership accompanies mapped positions');
check(withCurrentCrewMapping({ ...position, source_owned: true }, [directory]).source_owned === false, 'Current exact vehicle directory replaces stale source ownership');
for (const [status, driverId] of [['unlinked', null], ['ambiguous', null], ['linked', 'driver_DEMO_reassigned']]) {
    const mapped = withCurrentCrewMapping({ ...position, source_owned: false }, [{ ...directory, source_owned: true, driver_id: driverId, crew_link_status: status }]);
    check(mapped.source_owned === true && crewMapping(mapped).notes.some(note => note.includes('MAPA dispatch authority not enabled')) && !crewMapping(mapped).verifiedBase, `Source ownership stays visible for ${status === 'linked' ? 'reassigned' : status} crew without creating a verified base link`);
}
check(withCurrentCrewMapping({ ...position, source_owned: false }, [{ ...directory, vehicle_id: 'vehicle_DEMO_other', source_owned: true }]).source_owned === false, 'Another vehicle cannot transfer its source ownership');
check(crewMapping({ ...directory, name: '<img src=x onerror=alert(1)>' }).summary.includes('<img src=x onerror=alert(1)>'), 'Record labels remain text for the existing textContent renderer and DOM tooltips');


const cutover = withCurrentCrewMapping({ ...position, source_owned: true }, [{ ...directory, source_imported: true, source_owned: false, dispatch_authority: 'mapa' }]);
check(cutover.source_owned === false && cutover.dispatch_authority === 'mapa', 'Current MAPA authority replaces old source ownership');
check(crewMapping(cutover).notes.some(note => note.includes('MAPA dispatch · ASR data feed')) && !crewMapping(cutover).notes.some(note => note.includes('not enabled')), 'ASR provenance is distinct from MAPA authority');
check(!withCurrentCrewMapping({ ...position, dispatch_authority: 'mapa' }, []).dispatch_authority, 'Missing directory does not retain stale authority');
check(!withCurrentCrewMapping(position, [{ ...directory, dispatch_authority: 'mapa' }, directory]).dispatch_authority, 'Duplicate directory cannot grant authority');
check(!crewMapping({ ...directory, dispatch_authority: 'mapa', crew_mobile_bound: false }).verifiedBase, 'MAPA authority never bypasses disconnected phone readiness');
console.log(`MAPA fleet linkage: ${checks} checks passed.`);
