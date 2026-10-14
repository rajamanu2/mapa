/* Copyright (c) 2026 MAPA.DEV. SPDX-License-Identifier: AGPL-3.0-or-later */
(() => {
    'use strict';
    const mapaIST = value => { const original = new Date(value); if (!Number.isFinite(original.getTime())) return 'Time unavailable'; const d = new Date(original.getTime() + 19800000), pad = n => String(n).padStart(2, '0'), h = d.getUTCHours(); return pad(d.getUTCDate()) + ' ' + ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'][d.getUTCMonth()] + ' ' + d.getUTCFullYear() + ', ' + pad(h % 12 || 12) + ':' + pad(d.getUTCMinutes()) + ':' + pad(d.getUTCSeconds()) + ' ' + (h < 12 ? 'AM' : 'PM') + ' IST'; };

    const CONTRACT = 'mapa-native-voice-v1';
    const safeId = value => typeof value === 'string' && /^[A-Za-z0-9_-]{1,120}$/.test(value);
    const revision = value => Number.isSafeInteger(value) && value >= 0;
    const string = value => typeof value === 'string' ? value : '';
    function gpsQuality(fix) {
        if (!fix || typeof fix !== 'object') return '';
        const protocol = fix.source_protocol || fix.gps_source_protocol;
        const quality = fix.position_quality || fix.gps_position_quality;
        const accuracy = fix.accuracy_m ?? fix.gps_accuracy_m;
        const measured = typeof accuracy === 'number' && Number.isFinite(accuracy) && accuracy > 0;
        const parts = [];
        if (protocol === 'way4track' || quality === 'provider_reported') parts.push('Way4Track provider-reported position');
        parts.push(measured ? `Reported accuracy ±${Math.round(accuracy)} m` : 'Accuracy unknown');
        if (fix.dispatch_eligible === false || fix.gps_dispatch_eligible === false) parts.push('Location display only; not dispatch eligibility evidence');
        return parts.join(' · ');
    }
    const CALLBACK_ERROR = 'Enter a valid caller phone number: 10 Indian digits or an international number beginning with +. Leave it blank when unknown.';
    const PILOT_CALLER = 'MAPA PILOT — NO PATIENT';
    const PILOT_REPORT = 'SUPERVISED MAPA PILOT — NO PATIENT. Two-crew offer, decline, fallback and acceptance drill.';
    const isSupervisedPilot = call => call?.supervised_pilot === true || call?.review?.supervised_pilot === true || call?.draft?.supervised_pilot === true;
    // Keep the browser draft consistent with the server's shared CallerPhone policy.
    function normalizedCallback(value) {
        if (value == null) return '';
        if (typeof value !== 'string' || value.length > 40 || !/^[+0-9 ()-]*$/.test(value) || /[\r\n]/.test(value)) return null;
        if (value.replace(/ /g, '') === '') return '';
        let number = value.replace(/[ ()-]/g, '');
        if (/^[1-9][0-9]{9}$/.test(number)) number = '+91' + number;
        else if (/^91[1-9][0-9]{9}$/.test(number)) number = '+' + number;
        return /^\+[1-9][0-9]{7,14}$/.test(number) ? number : null;
    }
    const clone = value => JSON.parse(JSON.stringify(value));
    const ASR_BOUNDS = [[17.40, 81.80], [18.75, 83.35]];
    function inAsrDispatchMap(point) {
        return !!point && point.latitude >= ASR_BOUNDS[0][0] && point.latitude <= ASR_BOUNDS[1][0]
            && point.longitude >= ASR_BOUNDS[0][1] && point.longitude <= ASR_BOUNDS[1][1];
    }
    const showDispatchPosition = (point, stale) => !stale || inAsrDispatchMap(point);
    function locationQuery(value) {
        const raw = string(value), text = raw.trim();
        if (text.length < 3 || text.length > 160 || /[\u0000-\u001f@]|https?:\/\/|www\./iu.test(raw) || (text.match(/\p{N}/gu) || []).length >= 7 || !/\p{L}/u.test(text)) return '';
        return text;
    }
    function googleSearchUrl(value) {
        const query = locationQuery(value);
        return query ? `https://www.google.com/maps/search/?api=1&authuser=0&query=${encodeURIComponent(query)}` : 'https://www.google.com/maps?authuser=0';
    }
    function pastedPickupPoint(value) {
        const text = string(value).trim();
        // Copy the pin coordinates, never guess from a URL's map-camera centre.
        if (text.length > 96 || !/^[+-]?\d{1,3}(?:\.\d+)?\s*,\s*[+-]?\d{1,3}(?:\.\d+)?$/.test(text)) return null;
        const [latitude, longitude] = text.split(',');
        return pickupCandidate({ latitude, longitude });
    }
    function googlePlaceCandidate(place) {
        if (typeof place?.location?.lat !== 'function' || typeof place?.location?.lng !== 'function') return null;
        const address = [string(place.displayName), string(place.formattedAddress)].filter(Boolean).join(', ');
        return pickupCandidate({ latitude: place.location.lat(), longitude: place.location.lng(), label: address, address });
    }
    function pickupCandidate(input) {
        if (!input || !String(input.latitude ?? '').trim() || !String(input.longitude ?? '').trim()) return null;
        const latitude = Number(input.latitude), longitude = Number(input.longitude);
        if (!Number.isFinite(latitude) || Math.abs(latitude) > 90 || !Number.isFinite(longitude) || Math.abs(longitude) > 180) return null;
        return { latitude, longitude, label: string(input.label || input.name).slice(0, 1000),
            address: string(input.address).slice(0, 1000), locality: string(input.locality).slice(0, 160) };
    }
    function callSteps(call, point, modified = false) {
        const saved = !!call?.id, reviewed = !modified && ['ready', 'dispatched'].includes(call?.state);
        const dispatched = call?.state === 'dispatched' && !!call?.order_id;
        return [
            ['Pickup selected', !!pickupCandidate(point)], ['Call saved', saved],
            ['Review confirmed', reviewed], ['Crew dispatched', dispatched],
            ['Crew acknowledged', dispatched && call?.acknowledged === true]
        ];
    }
    function dispatchPipeline(call, modified=false){
        const trip=call?.trip_progress||{},review=call?.review||{},automatic=call?.automatic_dispatch;
        if(isSupervisedPilot(call)){
            const completed=[!!call?.id,!modified&&review.pilot_pickup_confirmed_by_operator===true&&!!pickupCandidate(review),!!call?.order_id,trip.accepted===true||call?.acknowledged===true||automatic?.state==='accepted',call?.state==='dismissed'||['canceled','cancelled'].includes(call?.order_status)];
            let active=Math.min(4,completed.lastIndexOf(true)+1);if(automatic&&['searching','offering'].includes(automatic.state))active=2;
            return ['PILOT','TEST POINT','OFFERS','ACCEPTED','CLOSED'].map((label,index)=>({label,complete:completed[index],active:index===active&&!completed[index]}));
        }
        const completed=[!!call?.id,!modified&&review.coordinates_confirmed===true&&!!pickupCandidate(review),!!call?.order_id,trip.accepted===true||call?.acknowledged===true||automatic?.state==='accepted',trip.pickup_arrived===true,trip.patient_onboard===true,trip.hospital_arrived===true,trip.completed===true||call?.order_status==='completed'];
        let active=Math.min(7,completed.lastIndexOf(true)+1);if(automatic&&['searching','offering'].includes(automatic.state))active=2;
        return ['CALL','LOCATE','DISPATCH','ACCEPTED','ARRIVED','ONBOARD','HOSPITAL','COMPLETE'].map((label,index)=>({label,complete:completed[index],active:index===active&&!completed[index]}));
    }
    function automaticOffersAvailable(call, capabilities) {
        return capabilities?.automatic_dispatch === true && call?.automatic_dispatch_available === true;
    }
    function automaticOffersUnavailableMessage(call) {
        if(call?.automatic_dispatch_unavailable_reason==='worker_stale'||call?.automatic_dispatch?.reason==='worker_stale')
            return 'Automatic dispatch worker is unavailable; operator action required. Review live crew availability and arrange manual dispatch until the worker heartbeat recovers.';
        if(isSupervisedPilot(call))return 'This no-patient pilot cannot start offers until the exact call UUID and approved crew UUIDs are scoped and live eligibility is checked. Refresh the call after server setup.';
        if (call?.automatic_dispatch_unavailable_reason === 'pilot_scope')
            return 'Automatic crew offers are limited to approved pilot calls. You can still review this call and manually choose an eligible ambulance.';
        if (call?.automatic_dispatch_unavailable_reason === 'not_commissioned')
            return 'Automatic crew offers are not enabled for this call. Review the pickup and use manual dispatch if needed.';
        return 'Automatic crew offers are unavailable for this call. Review the pickup and use manual dispatch if needed.';
    }
    function manualDraft(input) {
        const fields = {}, data = { demo: input.demo === true, coordinates_confirmed: false };
        if(input.supervised_pilot===true)data.supervised_pilot=true;
        data.priority = input.priority || input.intake_priority || 'routine';
        if (!['routine','urgent','emergency'].includes(data.priority)) fields.priority = 'Choose the operator priority.';
        data.vehicle_requirement = input.vehicle_requirement || 'any';
        if (!['any','bls','als'].includes(data.vehicle_requirement)) fields.vehicle_requirement = 'Choose a supported ambulance capability.';
        data.pickup_confirmed_at_creation = input.pickup_confirmed_at_creation === true;
        if (data.pickup_confirmed_at_creation && (!pickupCandidate(input) || !string(input.pickup_address).trim())) fields.pickup_confirmed_at_creation = 'Enter the pickup address and exact pin before confirming it with the caller.';
        for (const [key, limit] of Object.entries({ caller_name: 200, reported_problem: 2000, pickup_address: 1000, village: 200, mandal: 200, landmark: 500, access_directions: 1000, patient_name: 200, origin_phc_name: 200, origin_secretariat_name: 200, destination_facility_name: 200, dispatcher_notes: 2000 })) {
            data[key] = string(input[key]).trim();
            if (data[key].length > limit) fields[key] = `Use ${limit} characters or fewer.`;
        }
        if (!data.reported_problem) fields.reported_problem = data.supervised_pilot ? 'Describe the no-patient crew test.' : 'Record the short incident report in the caller’s words.';
        data.callback_number = normalizedCallback(input.callback_number);
        if (data.callback_number === null) fields.callback_number = CALLBACK_ERROR;
        if(data.supervised_pilot){
            if(data.demo)fields.supervised_pilot='The supervised crew pilot uses live MAPA resources; choose live mode.';
            if(data.caller_name!==PILOT_CALLER)fields.caller_name='Use the exact MAPA PILOT — NO PATIENT label.';
            if(data.callback_number)fields.callback_number='A no-patient pilot cannot have a caller phone number.';
            if(data.pickup_confirmed_at_creation)fields.pickup_confirmed_at_creation='A no-patient pilot cannot claim caller pickup confirmation.';
            if(!pickupCandidate(input)||!data.pickup_address)fields.latitude='Select the supervised test point and coordinates before saving.';
            if(!/NO PATIENT/i.test(data.reported_problem))fields.reported_problem='State NO PATIENT in the pilot test note.';
        }
        if (String(input.latitude ?? '').trim() || String(input.longitude ?? '').trim()) {
            const point = pickupCandidate(input);
            if (!point) fields.latitude = 'A pickup candidate needs both valid coordinates.';
            else { data.latitude = point.latitude; data.longitude = point.longitude; }
        }
        if (String(input.patient_count ?? '').trim()) {
            const count = Number(input.patient_count);
            if (!Number.isInteger(count) || count < 1 || count > 99) fields.patient_count = 'Enter a patient count from 1 to 99, or leave it unknown.';
            else data.patient_count = count;
        }
        if (String(input.patient_age ?? '').trim()) {
            const age = Number(input.patient_age);
            if (!Number.isInteger(age) || age < 0 || age > 120) fields.patient_age = 'Enter an age from 0 to 120, or leave it unknown.';
            else data.patient_age = age;
        }
        for (const [key, choices] of Object.entries({ patient_gender: ['unknown', 'Male', 'Female'], incident_type: ['', 'Obstetric Emergency', 'Trauma', 'Medical', 'Neonatal', 'Referral Transfer', 'Other'], intake_priority: ['', 'emergency', 'urgent', 'routine'] })) {
            data[key] = input[key] || (key === 'patient_gender' ? 'unknown' : '');
            if (!choices.includes(data[key])) fields[key] = 'Choose a listed intake option.';
        }
        for (const [key, choices] of Object.entries({ consciousness: ['awake', 'not_awake', 'unknown'], breathing: ['normal', 'not_normal', 'unknown'], scene_safety: ['safe', 'unsafe', 'unknown'] })) {
            data[key] = input[key] || 'unknown';
            if (!choices.includes(data[key])) fields[key] = 'Choose a caller-reported observation.';
        }
        for (const key of ['origin_phc_id', 'origin_secretariat_id', 'origin_village_id', 'destination_phc_id']) {
            if (input[key]) { if (typeof input[key] !== 'string' || !/^[A-Za-z0-9_-]{1,64}$/.test(input[key])) fields[key] = 'Choose a source location.'; else data[key] = input[key]; }
        }
        if(data.supervised_pilot){
            if(data.patient_name||data.patient_age!==undefined||data.patient_count!==undefined||data.patient_gender!=='unknown'||data.incident_type||data.consciousness!=='unknown'||data.breathing!=='unknown'||data.scene_safety!=='unknown'||data.destination_phc_id||data.destination_facility_name)
                fields.supervised_pilot='A no-patient pilot cannot contain patient, clinical or hospital handover details.';
            for(const key of ['patient_name','patient_age','patient_count','patient_gender','incident_type','consciousness','breathing','scene_safety','destination_phc_id','destination_facility_name','origin_phc_id','origin_secretariat_id','origin_village_id','origin_phc_name','origin_secretariat_name','dispatcher_notes'])delete data[key];
        }
        return { ok: Object.keys(fields).length === 0, fields, data };
    }
    function travelPoints(input) {
        return (Array.isArray(input) ? input : []).map(row => {
            const point = pickupCandidate(row), recorded = row?.recorded_at == null ? NaN : new Date(row.recorded_at).getTime();
            return point && Number.isFinite(recorded) ? { ...row, ...point, recorded } : null;
        }).filter(Boolean).sort((a, b) => a.recorded - b.recorded).slice(-200);
    }
    function reviewDraft(input, places) {
        const fields = {};
        const data = {};
        if(input.supervised_pilot===true)data.supervised_pilot=true;
        for (const [key, limit] of Object.entries({ caller_name: 200, reported_problem: 2000, pickup_address: 1000, landmark: 500 })) {
            data[key] = string(input[key]).trim();
            if (data[key].length > limit) fields[key] = `Use ${limit} characters or fewer.`;
        }
        data.callback_number = normalizedCallback(input.callback_number);
        if (data.callback_number === null) fields.callback_number = CALLBACK_ERROR;
        if (!data.reported_problem) fields.reported_problem = 'Describe the reported problem.';
        if (!data.pickup_address) fields.pickup_address = 'Enter the verified pickup address.';
        for (const [key, maximum] of [['latitude', 90], ['longitude', 180]]) {
            const raw = String(input[key] ?? '').trim();
            const number = raw === '' ? NaN : Number(raw);
            if (!Number.isFinite(number) || Math.abs(number) > maximum) fields[key] = `Enter a coordinate between −${maximum} and ${maximum}.`;
            else data[key] = number;
        }
        data.priority = input.priority;
        data.vehicle_requirement = input.vehicle_requirement;
        data.dropoff_id = input.dropoff_id;
        if (!['routine', 'urgent', 'emergency'].includes(data.priority)) fields.priority = 'Choose the operator-assessed priority.';
        if (!['any', 'bls', 'als'].includes(data.vehicle_requirement)) fields.vehicle_requirement = 'Choose the required vehicle capability.';
        if (!Array.isArray(places) || !places.some(place => safeId(place.id) && place.id === data.dropoff_id)) fields.dropoff_id = 'Choose a hospital or destination provided by MAPA.';
        if(data.supervised_pilot){
            if(data.caller_name!==PILOT_CALLER)fields.caller_name='Keep the exact MAPA PILOT — NO PATIENT label.';
            if(data.callback_number)fields.callback_number='A no-patient pilot cannot have a caller phone number.';
            if(input.coordinates_confirmed===true)fields.coordinates_confirmed='A no-patient pilot cannot claim caller confirmation.';
            if(input.pilot_pickup_confirmed_by_operator!==true)fields.pilot_pickup_confirmed_by_operator='Confirm the exact no-patient test point and destination as the test operator.';
        }else if (input.coordinates_confirmed !== true) fields.coordinates_confirmed = 'Verify the pickup coordinates before submitting the review.';
        const extra = manualDraft(input);
        for (const key of ['village', 'mandal', 'access_directions', 'patient_count', 'consciousness', 'breathing', 'scene_safety', 'patient_name', 'patient_age', 'patient_gender', 'incident_type', 'intake_priority', 'origin_phc_name', 'origin_secretariat_name', 'destination_facility_name', 'dispatcher_notes', 'origin_phc_id', 'origin_secretariat_id', 'origin_village_id', 'destination_phc_id']) {
            if (extra.data[key] !== undefined) data[key] = extra.data[key];
            if (extra.fields[key]) fields[key] = extra.fields[key];
        }
        data.coordinates_confirmed = !data.supervised_pilot && input.coordinates_confirmed === true;
        if(data.supervised_pilot)data.pilot_pickup_confirmed_by_operator=input.pilot_pickup_confirmed_by_operator===true;
        data.confirmed = true;
        return { ok: Object.keys(fields).length === 0, fields, data };
    }
    function eligible(call, candidates) {
        if (!call || call.state !== 'ready' || !call.review || typeof call.demo !== 'boolean' || !Array.isArray(candidates)) return [];
        return candidates.filter(candidate => candidate && safeId(candidate.id) && safeId(candidate.vehicle_id) && candidate.simulated === call.demo);
    }
    function ambulanceSearchText(value) {
        return string(value).normalize('NFKD').replace(/\p{M}/gu, '').toLocaleLowerCase().replace(/[^\p{L}\p{N}]+/gu, ' ').trim();
    }
    function ambulanceContext(row) {
        return [...new Set([row.hospital_name, row.base_name, row.hospital_address, row.source_base_address, row.mandal, row.secretariat].map(string).map(value => value.trim()).filter(Boolean))].join(' · ');
    }
    function enrollmentLinkSummary(row = {}) {
        const missing = [['driver_id', 'driver'], ['vehicle_id', 'ambulance'], ['place_id', 'hospital / base']]
            .filter(([key]) => !safeId(row[key])).map(([, label]) => label);
        if (row.status !== 'approved') return { label: row.status || 'Unknown', warning: '', missing };
        return { label: missing.length ? 'Approved · link needs repair' : 'Approved', missing,
            warning: missing.length ? `Missing saved ${missing.join(', ')} link. Control must reconcile the registration, crew account and mobile binding before this crew is used for dispatch.`
                : 'Saved driver, ambulance and hospital / base records are present. Check the current assignment, phone connection, duty and fresh GPS in the fleet view before dispatch.' };
    }
    function crewMapping(row = {}) {
        const ambiguous = row.crew_link_status === 'ambiguous' || row.driver_mapping_ambiguous === true;
        const linked = !ambiguous && row.crew_link_status !== 'unlinked' && safeId(row.driver_id);
        // In desk rows name is the ambulance; directory rows use name for the crew.
        const driverName = Object.hasOwn(row, 'driver_name') ? row.driver_name : row.name;
        const driver = ambiguous ? 'multiple crew links need review' : linked ? string(driverName).trim() || row.driver_id : 'no assigned driver returned';
        const ambulance = string(row.vehicle_registration || row.registration || row.vehicle_name).trim() || (safeId(row.vehicle_id) ? row.vehicle_id : 'not recorded');
        const verifiedBase = linked && safeId(row.vehicle_id) && row.crew_mobile_bound === true && safeId(row.hospital_place_id);
        const recordedBase = string(row.hospital_name || row.base_name).trim();
        const base = verifiedBase ? 'Verified hospital / base: ' + (recordedBase || row.hospital_place_id) : 'Hospital / base: no verified crew link returned';
        const notes = [];
        if (!verifiedBase && recordedBase) notes.push('Recorded base: ' + recordedBase + ' (not a verified crew link)');
        if (row.crew_mobile_bound === false) notes.push('Crew phone not connected');
        if (row.mapping_notice) notes.push(string(row.mapping_notice));
        if (row.dispatch_authority === 'mapa') notes.push('MAPA dispatch' + (row.source_imported === true ? ' · ASR data feed' : '') + ' · crew readiness checked for each call');
        else if (row.source_owned === true) notes.push('ASR source record · MAPA dispatch authority not enabled');
        return { summary: 'Driver: ' + driver + ' → Ambulance: ' + ambulance + ' → ' + base, notes, verifiedBase: !!verifiedBase };
    }
    /** Add directory facts only for one exact current driver/vehicle pair; never replace GPS evidence. */
    function withCurrentCrewMapping(position, directory) {
        const row = { ...position };
        const fields = ['hospital_place_id', 'hospital_name', 'base_name', 'crew_link_status', 'crew_mobile_bound'];
        for (const key of fields) delete row[key];
        const vehicleRows = Array.isArray(directory) ? directory.filter(item => item && safeId(row.vehicle_id) && item.vehicle_id === row.vehicle_id) : [];
        // Source ownership belongs to the vehicle even when its crew assignment needs review.
        if (vehicleRows.some(item => item.source_owned === true)) row.source_owned = true;
        // Authority is a current server decision, independent of ASR data provenance.
        delete row.dispatch_authority;
        if (vehicleRows.length === 1) {
            if (['mapa', 'asr'].includes(vehicleRows[0].dispatch_authority)) row.dispatch_authority = vehicleRows[0].dispatch_authority;
            if (typeof vehicleRows[0].source_owned === 'boolean') row.source_owned = vehicleRows[0].source_owned;
            if (typeof vehicleRows[0].source_imported === 'boolean') row.source_imported = vehicleRows[0].source_imported;
        }
        const matches = vehicleRows.filter(item => safeId(row.driver_id) && item.driver_id === row.driver_id);
        if (vehicleRows.length === 1 && matches.length === 1) {
            for (const key of fields) if (Object.hasOwn(matches[0], key)) row[key] = matches[0][key];
        } else if (vehicleRows.length) {
            if (vehicleRows.length === 1 && ['ambiguous', 'unlinked'].includes(vehicleRows[0].crew_link_status)) row.crew_link_status = vehicleRows[0].crew_link_status;
            row.mapping_notice = 'Crew mapping changed or is ambiguous; refresh before reviewing dispatch';
        }
        return row;
    }
    function ambulanceAvailabilityEvidence(row) {
        const facts=[];
        if(row.vehicle_online===true)facts.push('Vehicle marked online');
        else if(row.vehicle_online===false)facts.push('Vehicle marked offline');
        if(row.crew_link_status==='linked')facts.push('Crew linked');
        else if(row.crew_link_status==='unlinked')facts.push('Crew not linked');
        else if(row.crew_link_status==='ambiguous')facts.push('Crew link needs review');
        if(row.crew_on_duty===true)facts.push('Crew on duty');
        else if(row.crew_on_duty===false)facts.push('Crew off duty');
        if(row.crew_gps_status==='fresh')facts.push('Crew phone GPS fresh');
        else if(row.crew_gps_status==='stale')facts.push('Crew phone GPS stale');
        else if(row.crew_gps_status==='missing')facts.push('Crew phone GPS missing');
        return facts.join(' · ');
    }
    function ambulanceDirectory(call, candidates, unavailable, query = '', readyOnly = false) {
        if (!call || call.state !== 'ready' || !call.review || typeof call.demo !== 'boolean') return [];
        const rows = new Map();
        for (const row of eligible(call, candidates)) rows.set(row.vehicle_id, { ...row, ready: true });
        for (const row of Array.isArray(unavailable) ? unavailable : []) {
            if (row && safeId(row.vehicle_id) && row.simulated === call.demo) rows.set(row.vehicle_id, { ...row, ready: false });
        }
        const terms = ambulanceSearchText(query).split(' ').filter(Boolean);
        return [...rows.values()].filter(row => {
            if (readyOnly && !row.ready) return false;
            const searchable = [row.vehicle_name, row.vehicle_registration, row.vehicle_type, row.name, ambulanceContext(row), ambulanceAvailabilityEvidence(row), row.location_context, row.search_text].map(ambulanceSearchText).join(' ');
            const compact = searchable.replace(/ /g, '');
            return terms.every(term => searchable.includes(term) || compact.includes(term));
        }).sort((a, b) => Number(b.ready) - Number(a.ready) || (a.ready && b.ready ? (Number.isFinite(a.distance_km) ? a.distance_km : Infinity) - (Number.isFinite(b.distance_km) ? b.distance_km : Infinity) : 0) || ambulanceContext(a).localeCompare(ambulanceContext(b)) || string(a.vehicle_name).localeCompare(string(b.vehicle_name)) || a.vehicle_id.localeCompare(b.vehicle_id));
    }
    function dispatchAllowed(call, candidates, selected, dirty, pending, expected) {
        return !!call && call.state === 'ready' && revision(call.revision) && call.revision === expected && !call.order_id && !dirty && !pending && eligible(call, candidates).some(candidate => candidate.id === selected);
    }
    function makeIntent(path, data, id) {
        if (!/^\/(demo|calls\/manual|calls\/[A-Za-z0-9_-]{1,120}\/(review|dispatch|dismiss|extract|automatic\/(?:start|stop)))$/.test(path)) throw new Error('Unsupported call update.');
        if (!/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i.test(id)) throw new Error('A secure update key is required.');
        return Object.freeze({ path, body: JSON.stringify({ ...clone(data), action_id: id }) });
    }
    function failureWarning(status) { return /fail|interrupt|gap|unavailable|restart/i.test(string(status)); }
    function intakeNotice(flags) {
        if (flags?.live_intake === true) return '';
        return flags?.manual_intake === true
            ? 'Answer calls on your existing phone number. Select NEW CALL to enter the caller’s details, then review and confirm before dispatch.'
            : 'Call intake is disabled. Ask your MAPA administrator to enable manual call intake before recording or dispatching calls.';
    }

    function enrollmentRows(rows) {
        return (Array.isArray(rows) ? rows : []).filter(row => row && safeId(row.id) && revision(row.revision)
            && ['pending', 'approved', 'rejected', 'revoked'].includes(row.status)).slice(0, 100);
    }
    function crewApprovalDraft(row, input, options) {
        const fields = {};
        if (!row || !safeId(row.id) || !revision(row.revision) || row.status !== 'pending') fields.registration = 'Reload the current pending registration.';
        if (!Array.isArray(options?.vehicles) || !options.vehicles.some(v => safeId(v.id) && v.id === input.vehicle_id)) fields.vehicle_id = 'Choose a current ambulance from MAPA.';
        if (!Array.isArray(options?.places) || !options.places.some(v => safeId(v.id) && v.id === input.place_id)) fields.place_id = 'Choose a current hospital or base from MAPA.';
        if (input.identity_confirmed !== true) fields.identity_confirmed = 'Verify the crew identity and actual ambulance assignment before approval.';
        const matches = Array.isArray(row?.crew_matches) ? row.crew_matches : [];
        if (input.driver_id && !matches.some(v => safeId(v.id) && v.id === input.driver_id)) fields.driver_id = 'Reload and select a current matching crew record.';
        if (matches.length && !input.driver_id && input.create_new_crew !== true) fields.driver_id = 'Review the matching crew or confirm this is a different new person.';
        return { ok: Object.keys(fields).length === 0, fields, data: { expected_revision: row?.revision,
            vehicle_id: input.vehicle_id, place_id: input.place_id, identity_confirmed: input.identity_confirmed === true,
            ...(input.driver_id ? { driver_id: input.driver_id } : {}), create_new_crew: input.create_new_crew === true } };
    }
    function crewResourceDraft(row, kind, input) {
        const fields = {}, data = { expected_revision: row?.revision, name: string(input?.name).trim() };
        if (!row || !safeId(row.id) || !revision(row.revision) || row.status !== 'pending') fields.registration = 'Reload the current pending registration.';
        if (!['ambulance', 'hospital'].includes(kind)) fields.kind = 'Choose ambulance or hospital/base.';
        if (!data.name || data.name.length > 150) fields.name = 'Enter the actual record name within the supported length.';
        if (kind === 'ambulance') {
            data.registration = string(input?.registration).trim(); data.details_confirmed = input?.details_confirmed === true;
            const plate = value => string(value).toUpperCase().replace(/[^A-Z0-9]/g, '');
            if (!data.registration || data.registration.length > 50 || !/^[A-Za-z0-9 .-]+$/.test(data.registration) || !plate(data.registration) || plate(data.registration) !== plate(row?.ambulance_registration)) fields.registration = 'Verify the registration number; it must match the driver’s submitted ambulance.';
            if (!data.details_confirmed) fields.details_confirmed = 'Confirm the actual ambulance details before saving.';
        } else if (kind === 'hospital') {
            data.address = string(input?.address).trim(); data.location_confirmed = input?.location_confirmed === true;
            if (!data.address || data.address.length > 250) fields.address = 'Enter the hospital/base address (up to 250 characters).';
            const point = pickupCandidate(input);
            if (!point || (point.latitude === 0 && point.longitude === 0)) fields.latitude = 'Select the actual hospital/base pin or enter both valid coordinates.';
            else { data.latitude = point.latitude; data.longitude = point.longitude; }
            if (!data.location_confirmed) fields.location_confirmed = 'Confirm the actual hospital/base location before saving.';
        }
        return { ok: Object.keys(fields).length === 0, fields, data };
    }
    function crewResourceReceipt(intent, result) {
        const target = /^\/enrollments\/([A-Za-z0-9_-]{1,120})\/(ambulance|hospital)$/.exec(intent?.path || '');
        let input; try { input = JSON.parse(intent.body); } catch (_) { return null; }
        const row = enrollmentRows([result?.enrollment])[0], resource = result?.resource;
        if (result?.contract !== 'mapa-native-mobile-v1' || result.ok !== true || !target || !revision(input.expected_revision)
            || !row || row.id !== target[1] || row.status !== 'pending' || row.revision !== input.expected_revision
            || !resource || resource.kind !== target[2] || !safeId(resource.id) || resource.name !== input.name) return null;
        if (target[2] === 'ambulance') {
            const plate = value => string(value).toUpperCase().replace(/[^A-Z0-9]/g, '');
            if (typeof resource.registration !== 'string' || !plate(resource.registration) || plate(resource.registration) !== plate(input.registration)) return null;
        } else if (resource.address !== input.address || !Number.isFinite(resource.latitude) || !Number.isFinite(resource.longitude) || !pickupCandidate(resource)
            || Number(resource.latitude) !== input.latitude || Number(resource.longitude) !== input.longitude) return null;
        return { enrollment: row, resource };
    }
    function crewIntent(path, data, id) {
        if (!/^\/enrollments\/[A-Za-z0-9_-]{1,120}\/(approve|reject|revoke|ambulance|hospital)$/.test(path) || !revision(data.expected_revision)) throw new Error('Unsupported crew update or revision.');
        if (!/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i.test(id)) throw new Error('A secure update key is required.');
        return Object.freeze({ path, body: JSON.stringify({ ...clone(data), action_id: id }) });
    }
    function enrollmentReceipt(intent, result) {
        const target = /^\/enrollments\/([A-Za-z0-9_-]{1,120})\/(approve|reject|revoke)$/.exec(intent?.path || '');
        let expected; try { expected = JSON.parse(intent.body).expected_revision; } catch (_) { return null; }
        const row = enrollmentRows([result?.enrollment])[0], status = { approve: 'approved', reject: 'rejected', revoke: 'revoked' };
        return result?.ok === true && target && revision(expected) && row && row.id === target[1]
            && row.status === status[target[2]] && row.revision === expected + 1 ? row : null;
    }
    function tripReportRows(rows) {
        return (Array.isArray(rows) ? rows : []).filter(row => row && safeId(row.id) && safeId(row.order_id)).slice(0, 100);
    }
    function privateMediaPath(id) { return safeId(id) ? `/media/${id}` : null; }
    function smsSaveAllowed(path, call, consent, configured) {
        const phone = normalizedCallback((call?.review || call?.draft || {}).callback_number ?? call?.caller);
        return consent === true && configured === true && call?.demo === false && safeId(call.id)
            && /^\/(?:calls\/manual|calls\/[A-Za-z0-9_-]{1,120}\/review)$/.test(path)
            && !['dismissed', 'dispatched'].includes(call.state) && !!phone;
    }
    function smsStatusText(status) {
        return ({ queued: 'SMS queued by the gateway; sending and delivery are not confirmed.',
            unknown: 'SMS outcome unknown. Check with the caller before any further send.',
            unavailable: 'SMS is not configured. The call was saved; no SMS was sent.',
            failed: 'SMS could not be confirmed. The call remains saved.' })[status]
            || 'SMS status is unavailable. Delivery has not been confirmed.';
    }
    function callerPickup(rows, now = Date.now()) {
        return (Array.isArray(rows) ? rows : []).map(row => row?.location).filter(p => {
            const time = typeof p?.recorded_at === 'number' ? p.recorded_at : Date.parse(p?.recorded_at);
            return p && p.stale !== true && typeof p.latitude === 'number' && typeof p.longitude === 'number' && pickupCandidate(p)
                && !(p.latitude === 0 && p.longitude === 0) && Number.isFinite(time) && now - time <= 120000 && time <= now + 30000
                && typeof p.accuracy_m === 'number' && p.accuracy_m >= 0 && p.accuracy_m <= 100;
        }).sort((a, b) => new Date(b.recorded_at).getTime() - new Date(a.recorded_at).getTime())[0] || null;
    }
    function confirmedCallerPickup(rows, now = Date.now()) {
        const candidates = (Array.isArray(rows) ? rows : []).map(row => row?.caller_pickup_confirmation)
            .filter(point => point?.source === 'caller_confirmed' && Number.isSafeInteger(point.recorded_at)
                && Number.isSafeInteger(point.confirmed_at) && point.confirmed_at >= point.recorded_at - 30000
                && point.confirmed_at <= now + 30000);
        return callerPickup(candidates.map(location => ({ location })), now);
    }
    function compareCallerPickup(manual, caller, now = Date.now()) {
        const gps = callerPickup([{ location: caller }], now), entered = pickupCandidate(manual);
        if (!gps) return { state: 'unavailable', distance_m: null, message: 'Waiting for a fresh caller location. If the caller cannot open the link, denies location, or is not beside the patient, use the manual pickup and confirm the address, landmark and entrance by phone.' };
        if (!entered || (entered.latitude === 0 && entered.longitude === 0)) return { state: 'gps_only', distance_m: null, message: `Caller GPS received (accuracy ±${Math.round(gps.accuracy_m)} m). No manual pin to compare. Confirm that the caller is beside the patient before using this pickup.` };
        const radians = value => value * Math.PI / 180, dlat = radians(gps.latitude - entered.latitude), dlon = radians(gps.longitude - entered.longitude);
        const a = Math.sin(dlat / 2) ** 2 + Math.cos(radians(entered.latitude)) * Math.cos(radians(gps.latitude)) * Math.sin(dlon / 2) ** 2;
        const distance = Math.round(6371000 * 2 * Math.atan2(Math.sqrt(Math.min(1, a)), Math.sqrt(Math.max(0, 1 - a))));
        const near = distance <= Math.max(50, gps.accuracy_m);
        return { state: near ? 'near' : 'different', distance_m: distance,
            message: `Caller GPS is ${distance} m from the entered pickup (reported accuracy ±${Math.round(gps.accuracy_m)} m). ${near ? 'The points are close; confirm the correct entrance and access route.' : 'The locations differ. Ask which point is beside the patient before changing the pickup.'} Coordinates are never averaged or automatically confirmed.` };
    }
    function pickupConfirmationIntent(call, snapshot, actionId) {
        const point = confirmedCallerPickup(snapshot?.locations), row = (snapshot?.locations || []).find(item => item.caller_pickup_confirmation === point);
        if (!call || call.demo !== false || call.state !== 'dispatched' || !safeId(call.id) || !safeId(call.order_id)
            || snapshot?.can_confirm_live_pickup !== true || !/^[a-f0-9]{64}$/i.test(snapshot?.order_revision || '')
            || !point || !Number.isSafeInteger(point.recorded_at) || !safeId(row?.link_id) || !/^[A-Za-z0-9_-]{8,100}$/.test(actionId || '')) return null;
        return Object.freeze({ path: `/tracking/calls/${call.id}/pickup`, body: JSON.stringify({ link_id: row.link_id,
            recorded_at: point.recorded_at, expected_revision: snapshot.order_revision, action_id: actionId, confirmed: true }),
            point: Object.freeze({ ...point }), orderId: call.order_id });
    }
    function pickupConfirmationReceipt(intent, receipt) {
        return !!intent && receipt?.ok === true && receipt.order_id === intent.orderId && safeId(receipt.confirmation_id)
            && /^[a-f0-9]{64}$/i.test(receipt.order_revision || '') && receipt.crew_must_review === true
            && receipt.pickup?.latitude === intent.point.latitude && receipt.pickup?.longitude === intent.point.longitude
            && receipt.pickup?.recorded_at === intent.point.recorded_at;
    }
    function validRequestPath(path, native = false) {
        if (!native && path === '/command-desk') return true;
        if(!native && /^\/source-calls(?:\/[1-9][0-9]{0,9}\/open)?$/.test(path)) return true;
        if(native && path==='/intake-launch/exchange') return true;
        if (!native && path === '/calls?source_locations=1') return true;
        if (!native && /^\/calls\/[A-Za-z0-9_-]{1,120}\?destination_page=[1-9][0-9]{0,4}$/.test(path)) return true;
        if (native && /^\/tracking\/(?:sms|calls\/[A-Za-z0-9_-]{1,120}\/sms(?:\/auto)?)$/.test(path)) return true;
        if (native && /^\/(tracking\/(?:whatsapp|devices(?:\/[A-Za-z0-9_-]{1,120}\/revoke)?|calls\/[A-Za-z0-9_-]{1,120}\/(?:links|locations|whatsapp|pickup)|links\/[A-Za-z0-9_-]{1,120}\/revoke)|hospital\/overview)$/.test(path)) return true;
        return native ? /^\/(login|logout|travel(?:\?driver_id=[A-Za-z0-9_-]{1,120})?|enrollments(?:\/options|\/[A-Za-z0-9_-]{1,120}\/(?:approve|reject|revoke|ambulance|hospital))?|trip-reports|media\/[A-Za-z0-9_-]{1,120})$/.test(path) : /^\/(calls|demo|calls\/manual|locations\/nearby|locations\?q=[^&#\s]{2,1800}(?:&online=1)?(?:&scope=india)?|calls\/[A-Za-z0-9_-]{1,120}(\/(review|dispatch|dismiss|extract|automatic\/(?:start|stop)))?)$/.test(path);
    }
    function destinationPage(result, expectedPage = 1, previous = []) {
        if (!Array.isArray(result?.places) || result.places.length > 200) throw new Error('Destination options are unavailable. Reload this call.');
        const paged = result.destination_page !== undefined || result.destinations_has_more !== undefined;
        if (paged && (result.destination_page !== expectedPage || result.destination_page_size !== 200 || typeof result.destinations_has_more !== 'boolean'))
            throw new Error('Destination page changed. Reload this call.');
        if (!paged && expectedPage !== 1) throw new Error('This server does not support more destination pages.');
        const rows = new Map(previous.map(row => [row.id, row])), seen = new Set();
        const valid = row => row && safeId(row.id) && typeof row.name === 'string' && typeof row.address === 'string';
        for (const row of result.places) {
            if (!valid(row) || seen.has(row.id)) throw new Error('The server returned invalid destination options.');
            seen.add(row.id); rows.set(row.id, { id: row.id, name: row.name, address: row.address });
        }
        const selectedPlace = result.selected_destination;
        if (selectedPlace != null) {
            if (!valid(selectedPlace) || selectedPlace.id !== result.call?.review?.dropoff_id) throw new Error('The selected destination changed. Reload this call.');
            rows.set(selectedPlace.id, { id: selectedPlace.id, name: selectedPlace.name, address: selectedPlace.address });
        }
        return { rows: [...rows.values()], page: expectedPage, more: paged && result.destinations_has_more, known: paged };
    }
    function notifyIntakeUpdated(Channel) {
        // Invalidate only: each Operations tab fetches through its own scoped session.
        // No call, contact, location or credential is carried between tabs.
        let channel;
        try {
            if (typeof Channel !== 'function') return;
            channel = new Channel('mapa-intake-updates');
            channel.postMessage({ type: 'intake-updated', version: 1 });
        } catch (_) { /* Visible Operations polling remains the fallback. */ }
        finally { try { channel?.close(); } catch (_) {} }
    }
    function sourceCallRecent(call,referenceMs){
        const parse=value=>{if(typeof value!=='string'||!/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}/.test(value))return NaN;return Date.parse(value.replace(' ','T')+(value.includes('+')||value.endsWith('Z')?'':'+05:30'));};
        const updated=parse(call.updated_at),created=parse(call.created_at),last=Math.max(Number.isFinite(updated)?updated:0,Number.isFinite(created)?created:0);
        return last>0&&last<=referenceMs+120000&&referenceMs-last<=86400000;
    }
    function gpsCoverage(fleet){
        return fleet.reduce((count,vehicle)=>{count[vehicle.gps_stale===false?'recent':vehicle.gps_stale===true?'stale':'missing']++;return count;},{recent:0,stale:0,missing:0});
    }
    const pure = { dispatchPipeline, destinationPage, notifyIntakeUpdated, confirmedCallerPickup, compareCallerPickup, pickupConfirmationIntent, pickupConfirmationReceipt, smsSaveAllowed, smsStatusText, callerPickup, ambulanceDirectory, ambulanceContext, reviewDraft, manualDraft, eligible, dispatchAllowed, makeIntent, failureWarning, intakeNotice, pickupCandidate, travelPoints, ASR_BOUNDS, inAsrDispatchMap, showDispatchPosition, locationQuery, googleSearchUrl, pastedPickupPoint, googlePlaceCandidate, callSteps, validRequestPath, enrollmentRows, crewApprovalDraft, crewIntent, tripReportRows, privateMediaPath, enrollmentReceipt, crewResourceDraft, crewResourceReceipt, sourceCallRecent, gpsCoverage };
    pure.gpsQuality = gpsQuality;
    pure.crewMapping = crewMapping;
    pure.enrollmentLinkSummary = enrollmentLinkSummary;
    pure.withCurrentCrewMapping = withCurrentCrewMapping;
    if (typeof document === 'undefined') { if (typeof module === 'object' && module.exports) module.exports = pure; return; }
    if (document.documentElement.dataset.mapaVoiceInstalled) return;
    document.documentElement.dataset.mapaVoiceInstalled = 'true';

    // This token and all call data stay in this page's memory. No console storage or framework internals are read.
    let nativeCrewTransport = null, requestedCrewDriver = null;
    let session = null, epoch = 0, busy = false, pending = null, conflict = false;
    let asrCalls = [], asrCallState = null, asrCallError = '', asrSelected = null;
    const incomingSeen = new Map();
    let calls = [], capabilities = {}, sourceLocations = [], sourceDirectory = null, directoryFetchedAt = 0, selected = null, candidates = [], unavailable = [], places = [], dirty = false, editDraft = null;
    let destinationState = { page: 0, more: false, known: false };
    function setDestinations(result) { const next = destinationPage(result); places = next.rows; destinationState = next; }
    let pickupMapConfig = {};
    let smsConfigured = false;
    const smsReceipts = new Map();
    // Tokens are shown only in the operator's current authenticated session.
    const issuedCallerLinks = new Map();
    const pendingPickupConfirmations = new Map();
    let googleMapsReady = null, googleAuthFailed = false;
    const googleFailureListeners = new Set();
    function loadGoogleMaps(key) {
        if (!/^AIza[0-9A-Za-z_-]{35}$/.test(key)) return Promise.reject(new Error('Google Maps is not configured.'));
        if (googleAuthFailed) return Promise.reject(new Error('Google Maps authorization failed.'));
        if (window.google?.maps?.importLibrary) return Promise.resolve(window.google.maps);
        if (googleMapsReady) return googleMapsReady;
        googleMapsReady = new Promise((resolve, reject) => {
            const script = document.createElement('script');
            let settled = false;
            const fail = () => { if (settled) return; settled = true; clearTimeout(timer); reject(new Error('Google Maps could not load.')); };
            const timer = setTimeout(fail, 15000);
            window.__mapaPickupGoogleReady = () => {
                if (settled) return; settled = true; clearTimeout(timer);
                if (window.google?.maps?.importLibrary) resolve(window.google.maps); else reject(new Error('Google Maps did not initialize.'));
            };
            script.async = true;
            script.src = 'https://maps.googleapis.com/maps/api/js?' + new URLSearchParams({ key, loading: 'async', v: 'quarterly', callback: '__mapaPickupGoogleReady', language: 'en', region: 'IN' });
            script.onerror = fail; document.head.append(script);
        });
        return googleMapsReady;
    }
    // Keep the native map's failure handler intact, and notify only mounted pickup maps.
    const previousGoogleAuthFailure = window.gm_authFailure;
    window.gm_authFailure = () => {
        googleAuthFailed = true;
        for (const listener of googleFailureListeners) listener();
        if (typeof previousGoogleAuthFailure === 'function') previousGoogleAuthFailure();
    };
    let dialog, content, status, opener, entry, pollTimer, removePickupMap, removeHandoverMap, removeCallerSync,offerClockTimer,offerLoadedAt=0;
    const standalone = /^\/call-intake(?:\/|\.html|$)/.test(location.pathname);
    let newCall = standalone, manualEdit = null;
    let activeView = 'calls', crewRoster = [], crewSelected = '', crewEvidence = null;
    let queueFilter = 'all', secondaryTab = 'fleet', deskSnapshot = null, deskError = '', deskLoadedAt = 0, deskEpoch = 0;
    let workflowChoice = null;
    let refreshDeskPanel = null, refreshPickupMap = null;
    let recommendations = null;
    let enrollments = [], enrollmentOptions = { vehicles: [], places: [] }, enrollmentSelected = '', tripReports = [], enrollmentLoaded = false, reportsLoaded = false;
    const enrollmentSelections = new Map(); let resourceEditor = null, removeHospitalMap = null;
    let mediaEpoch = 0; const mediaUrls = new Set();
    function clearPrivateMedia() { mediaEpoch++; for (const url of mediaUrls) URL.revokeObjectURL(url); mediaUrls.clear(); }
    let lastMessage = '', lastError = false;
    const el = (tag, className, text) => { const node = document.createElement(tag); if (className) node.className = className; if (text !== undefined) node.textContent = String(text); return node; };
    function appendCrewMapping(parent, row) {
        const mapping = crewMapping(row);
        parent.append(el('p', 'mapa-voice-note mapa-crew-mapping', mapping.summary));
        for (const note of mapping.notes) parent.append(el('p', 'mapa-voice-note', note));
    }
    const date = value => { const parsed = new Date(value); return value && !Number.isNaN(parsed.getTime()) ? mapaIST(parsed) : 'Time unavailable'; };
    const badge = (text, type = '') => el('span', `mapa-voice-badge ${type}`, text);
    function button(text, action, primary = false) {
        const node = el('button', `mapa-voice-button${primary ? ' is-primary' : ''}`, text);
        node.type = 'button'; node.dataset.voiceFocus = text; node.addEventListener('click', action); return node;
    }
    function announce(message, error = false) {
        lastMessage = message; lastError = error;
        if (status) { status.textContent = message; status.classList.toggle('is-error', error); }
    }
    function lock(value) {
        busy = value;
        if (dialog) { dialog.setAttribute('aria-busy', String(value)); dialog.querySelectorAll('button,input,select,textarea').forEach(node => {
            if (value) { node.dataset.voiceWasDisabled = String(node.disabled); node.disabled = true; }
            else if (node.dataset.voiceWasDisabled !== undefined) { node.disabled = node.dataset.voiceWasDisabled === 'true'; delete node.dataset.voiceWasDisabled; }
        }); }
    }
    function expire() {
        if(offerClockTimer)window.clearInterval?.(offerClockTimer);offerClockTimer=null;offerLoadedAt=0;
        if (removeCallerSync) { removeCallerSync(); removeCallerSync = null; }
        const wasNative = session?.nativeReview === true;
        if (removeHandoverMap) { removeHandoverMap(); removeHandoverMap = null; }
        smsConfigured = false; smsReceipts.clear(); issuedCallerLinks.clear(); pendingPickupConfirmations.clear();
        if (content) content.replaceChildren(); if (status) status.textContent = ''; lastMessage = ''; lastError = false;
        clearPrivateMedia(); enrollmentSelections.clear(); resourceEditor = null; if (removeHospitalMap) { removeHospitalMap(); removeHospitalMap = null; } enrollments = []; enrollmentOptions = { vehicles: [], places: [] }; enrollmentSelected = ''; tripReports = []; enrollmentLoaded = false; reportsLoaded = false;
        destinationState = { page: 0, more: false, known: false };
        asrCalls=[];asrCallState=null;asrCallError='';asrSelected=null;incomingSeen.clear();
        deskSnapshot=null;deskError='';deskLoadedAt=0;deskEpoch++;queueFilter='all';secondaryTab='fleet';workflowChoice=null;recommendations=null;
        session = null; requestedCrewDriver = null; epoch++; calls = []; capabilities = {}; sourceLocations = []; sourceDirectory = null; directoryFetchedAt = 0; pickupMapConfig = {}; selected = null; candidates = []; unavailable = []; places = []; dirty = false; editDraft = null; manualEdit = null; crewRoster = []; crewSelected = ''; crewEvidence = null; activeView = 'calls';
        if (pending) pending = wasNative ? null : { ...pending, expired: true };
    }
    async function request(path, options = {}) {
        const native = options.native === true;
        if (!validRequestPath(path, native)) throw new Error('Unsupported MAPA request.');
        const current = epoch;
        if (!options.login && (!session || session.expires <= Date.now())) { expire(); throw Object.assign(new Error('Your Call Intake session ended. Sign in again; review the current call before another action.'), { status: 401 }); }
        if (session?.nativeReview) {
            const transport = nativeCrewTransport;
            const identity = transport?.identity();
            if (!transport?.valid() || identity?.user !== session.user || identity?.company !== session.company) { expire(); throw Object.assign(new Error('Your MAPA console session changed. Reopen driver registrations.'), { status: 401 }); }
            if (!native || !/^\/(enrollments(?:\/options|\/[A-Za-z0-9_-]{1,120}\/(?:approve|reject|revoke|ambulance|hospital))?|trip-reports)$/.test(path)) throw new Error('Use the operator desk for this action.');
            try {
                const data = await transport.request(path, { body: options.body });
                if (current !== epoch || !transport.valid() || nativeCrewTransport !== transport) throw Object.assign(new Error('The MAPA console session changed.'), { status: 401 });
                return data;
            } catch (error) { if (error.status === 401) expire(); throw error; }
        }
        const controller = new AbortController(); const timer = setTimeout(() => controller.abort(), 25000);
        try {
            const headers = { Accept: 'application/json' };
            if (!options.login) headers.Authorization = `Bearer ${session.token}`;
            if (options.body !== undefined) headers['Content-Type'] = 'application/json';
            const response = await fetch((native ? '/mapa/mobile/v1' : '/mapa/voice/v1') + path, {
                method: options.body === undefined ? 'GET' : 'POST', body: options.body, headers,
                credentials: 'omit', cache: 'no-store', redirect: 'error', signal: controller.signal
            });
            let data;
            try { data = await response.json(); } catch (_) { throw Object.assign(new Error('MAPA returned an unreadable response. Refresh or retry the same saved update.'), { status: response.status >= 400 ? response.status : 502 }); }
            if (!response.ok) {
                if (response.status === 401 && !options.login) expire();
                const detail = data.fields && typeof data.fields === 'object' ? Object.entries(data.fields).map(([key, value]) => `${key}: ${Array.isArray(value) ? value.join(' ') : String(value)}`).join(' ') : '';
                throw Object.assign(new Error(string(data.error) || `MAPA could not complete this request (${response.status}).`), { status: response.status, fields: detail });
            }
            if (current !== epoch) throw Object.assign(new Error('The Call Intake session changed. Refresh before continuing.'), { status: 401 });
            if (!native && options.body === undefined && data.contract !== (path === '/source-calls' ? 'mapa-asr-caller-feed-v1' : path === '/command-desk' ? 'mapa-command-desk-v1' : CONTRACT)) throw new Error('This server does not provide the supported MAPA Call Intake contract.');
            return data;
        } finally { clearTimeout(timer); }
    }
    function operationsButton() {
        const close = button(standalone ? 'Operations' : 'Close', () => { if (!standalone) { dialog.close(); return; } if (window.parent !== window) { try { if (typeof window.parent.mapaCloseIntake === 'function') { window.parent.mapaCloseIntake(); return; } } catch (_) {} window.top.location.assign('/fleet-ops'); return; } if (window.mapaIntakeCanLeave()) location.assign('/fleet-ops'); });
        close.setAttribute('aria-label', standalone ? 'Back to MAPA operations' : 'Close Call Intake');
        return close;
    }
    function ensureDialog() {
        if (dialog) return;
        dialog = el('dialog', `mapa-voice-dialog${standalone ? ' is-standalone' : ''}`); dialog.id = 'mapa-voice-dialog'; dialog.setAttribute('aria-labelledby', 'mapa-voice-title');
        const header = el('header', 'mapa-voice-header'); const identity = el('div');
        identity.append(el('span', 'mapa-voice-eyebrow', 'MAPA OPERATIONS')); const title = el('h2', '', standalone ? 'NEW CALL · Operator desk' : 'Call Intake'); title.id = 'mapa-voice-title'; identity.append(title);
        header.append(identity, operationsButton());
        if (!standalone) { const tab = el('a', 'mapa-voice-button', 'Open operator tab'); tab.href = '/call-intake/'; tab.target = '_blank'; tab.rel = 'noopener'; tab.addEventListener('click',event=>{event.preventDefault();openOperatorTab();}); header.append(tab); }
        status = el('div', 'mapa-voice-status'); status.tabIndex = -1; status.setAttribute('role', 'status'); status.setAttribute('aria-live', 'polite');
        content = el('div', 'mapa-voice-content'); dialog.append(header, status, content); document.body.append(dialog);
        dialog.addEventListener('cancel', event => { if (busy || standalone) event.preventDefault(); });
        dialog.addEventListener('close', () => { if (removeCallerSync) { removeCallerSync(); removeCallerSync = null; } clearPrivateMedia(); clearTimeout(pollTimer);if(offerClockTimer)window.clearInterval?.(offerClockTimer);offerClockTimer=null; if (opener?.isConnected && !opener.hidden) opener.focus(); });
        window.addEventListener('beforeunload', event => { if (pending || dirty || resourceEditor) { event.preventDefault(); event.returnValue = ''; } });
    }
    function open(trigger) {
        ensureDialog(); if (dialog.open) return; opener = trigger || document.activeElement; render(); dialog.showModal();
        const first = content.querySelector('input:not(:disabled)') || dialog.querySelector('button:not(:disabled)'); if (first) first.focus();
        if (session && !pending && !session.nativeReview) loadCalls();
    }
    function render() {
        if(offerClockTimer)window.clearInterval?.(offerClockTimer);offerClockTimer=null;
        refreshDeskPanel=null;
        refreshPickupMap=null;
        const title = document.getElementById('mapa-voice-title'); if (title) title.textContent = session?.nativeReview ? 'Driver registration review' : (standalone ? 'NEW CALL · Operator desk' : 'Call Intake');
        if (!content) return; if (removeCallerSync) { removeCallerSync(); removeCallerSync = null; } if (removeHandoverMap) { removeHandoverMap(); removeHandoverMap = null; } clearPrivateMedia(); if (removeHospitalMap) { removeHospitalMap(); removeHospitalMap = null; } if (removePickupMap) { removePickupMap(); removePickupMap = null; } const prior = document.activeElement; const focusKey = prior?.dataset?.voiceFocus; const hadFocus = content.contains(prior); content.replaceChildren(); announce(lastMessage, lastError);
        dialog.classList.toggle('is-command-desk',!!session&&!session.nativeReview&&activeView==='calls');
        if (!session) { renderLogin(); if (dialog.open) content.querySelector('input')?.focus({ preventScroll: true }); return; }
        const toolbar = el('div', 'mapa-voice-toolbar'); toolbar.append(el('p', '', `${session.name} · ${session.role === 'superadmin' ? 'Super administrator' : session.role === 'operator' ? 'Operator' : 'Administrator'}`));
        const actions = el('div', 'mapa-voice-actions');
        if (!session.nativeReview) {
            const unread=asrCalls.filter(row=>sourceCallRecent(row,Date.now())&&incomingSeen.get(row.id)!==row.source_revision).length;
            const incoming=button('Incoming calls · '+unread, async()=>{if(busy||pending)return;if(dirty&&!window.confirm('Discard unsaved intake edits and view incoming calls?'))return;dirty=false;editDraft=null;manualEdit=null;newCall=false;selected=null;asrSelected=null;activeView='calls';render();await loadCalls();});
            incoming.className+=' mapa-voice-incoming-tab';incoming.disabled=!!pending;incoming.setAttribute('aria-label','Incoming calls · '+unread+' unread');actions.append(incoming);
        }
        if (!session.nativeReview && capabilities.manual_intake === true) { const create = button('NEW CALL', beginDeskCall, true); create.disabled = !!pending; create.setAttribute('aria-label', 'NEW CALL · Create emergency call inside MAPA'); actions.append(create); }
        if (!session.nativeReview) {
        const callView = button('Call queue', () => { if (pending) return; activeView = 'calls'; render(); }); callView.disabled = !!pending; actions.append(callView);
        if (session.role !== 'operator') { const crewView = button('Crew locations', () => { if (pending) return; if (dirty && !window.confirm('Discard unsaved call edits and inspect crew locations?')) return; dirty = false; editDraft = null; manualEdit = null; activeView = 'crew'; loadCrew(); }); crewView.disabled = !!pending; actions.append(crewView); }
        }
        if (session.role !== 'operator') {
            const registrations = button('Crew registration', () => openCrewAdmin('enrollments')); registrations.disabled = !!pending; actions.append(registrations);
            const reports = button('Trip reports', () => openCrewAdmin('reports')); reports.disabled = !!pending; actions.append(reports);
        }
        if (!session.nativeReview) {
        const refresh = button('Refresh calls', () => {if(dirty)return announce('Save or discard the current call edits before refreshing.',true);return loadCalls();}); refresh.disabled = !!pending; actions.append(refresh);
        if (session.role !== 'operator' && capabilities.demo === true) { const demo = button('Create DEMO training call', () => mutate('/demo', {}, 'A DEMO training call was created. No external call was made.')); demo.disabled = !!pending; actions.append(demo); }
        if (session.role === 'operator') actions.append(button('Settings', () => { if (pending || dirty) return announce('Save or discard the current call before opening Settings.', true); activeView = 'settings'; render(); }));
        const logout = button('Sign out of Call Intake', signOut); logout.disabled = !!pending; actions.append(logout);
        } else { const closeReview = button('Close driver review', () => dialog.close()); closeReview.disabled = !!pending; actions.append(closeReview); }
        if (!session.nativeReview && session.role !== 'operator') actions.append(button('GPS devices', () => { if(pending||dirty)return announce('Save or discard the current call changes first.',true);activeView='devices';render(); }));
        toolbar.append(actions);
        const commandMode=!session.nativeReview&&activeView==='calls';
        if(commandMode)renderCommandHeader(content,toolbar);else content.append(toolbar);
        const notice = session.nativeReview||commandMode ? '' : intakeNotice(capabilities); if (notice) content.append(el('p', 'mapa-voice-note', notice));
        if (pending) renderPending();
        if (activeView === 'enrollments') { renderEnrollments(content); if (busy) lock(true); return; }
        if (activeView === 'reports') { renderTripReports(content); if (busy) lock(true); return; }
        if (activeView === 'devices') { renderTrackingDevices(content); return; }
        if (activeView === 'crew') { renderCrew(content); if (busy) lock(true); return; }
        if (activeView === 'settings') { content.append(el('h3', '', 'Operator settings'), el('p', 'mapa-voice-note', `${session.name} · MAPA operator`), el('p', 'mapa-voice-note', 'Your account can record and review MAPA calls and monitor operations. Crew enrollment, GPS device setup, integrations, and automatic-dispatch configuration require an administrator.')); return; }
        renderDeskMetrics(content);
        const layout = el('div', 'mapa-voice-layout mapa-command-grid'), list = el('aside', 'mapa-voice-list mapa-command-queue'), main = el('section', 'mapa-voice-detail mapa-command-hero'), right = el('aside', 'mapa-command-secondary');
        renderDeskQueue(list);
        const heroHead=el('div','mapa-command-panel-head');heroHead.append(el('h3','','Selected Emergency'));
        if(selected||asrSelected)heroHead.append(badge(string(asrSelected?.status||selected?.state).replaceAll('_',' ')));
        main.append(heroHead);layout.append(list,main,right);content.append(layout);
        let creation=null;
        if(asrSelected) renderAsrCall(main);
        else if (newCall && capabilities.manual_intake === true) {
            creation=el('section','mapa-command-create-dialog');creation.setAttribute('aria-label','Create Emergency Call');
            const heading=el('div','mapa-command-panel-head');heading.append(el('h3','','Create Emergency Call'),button('Back to call queue',()=>{if(busy||pending)return;if(dirty&&!window.confirm('Discard this unsaved new call?'))return;newCall=false;dirty=false;manualEdit=null;render();}));creation.append(heading);main.append(creation);renderManual(creation);
        }
        else if (!selected) main.append(el('h3', '', 'Select a call to review'), el('p', 'mapa-voice-muted', 'Verify the caller’s information, pickup coordinates and destination before choosing an ambulance.'));
        else renderCall(main);
        renderDeskSecondary(right,creation||main);
        if (busy) lock(true);
        if (dialog.open && hadFocus) { const match = [...content.querySelectorAll('[data-voice-focus]')].find(node => node.dataset.voiceFocus === focusKey && !node.disabled); (match || status).focus({ preventScroll: true }); }
    }
    function renderDeskQueue(list,sourceOnly=false) {
        const scrollTop=list.scrollTop;
        const olderOpen=list.querySelector('.mapa-command-older-source')?.open===true;
        let source=list.querySelector('.mapa-command-source-queue');
        if(!sourceOnly||!source){
            list.replaceChildren();
            list.setAttribute('aria-label', 'Active call queue');
            const queueHead=el('div','mapa-command-panel-head');queueHead.append(el('h3','','Active Queue'),button('+ New Call',beginDeskCall,true));list.append(queueHead);
            const filters=el('nav','mapa-command-filters');filters.setAttribute('aria-label','Filter active calls');
            for(const [value,label] of [['all','All'],['urgent','Critical / High'],['unconfirmed','Unconfirmed']]){const filter=button(label,()=>{queueFilter=value;render();});filter.setAttribute('aria-pressed',String(queueFilter===value));filters.append(filter);}list.append(filters);
            source=el('section','mapa-command-source-queue');list.append(source);
            sourceOnly=false;
        }else source.replaceChildren();
        if(asrCallError)source.append(el('p','mapa-voice-warning',asrCallError));
        const sourceNow=Date.now(),sourceCheckedAt=Number.isSafeInteger(asrCallState?.generated_at)?asrCallState.generated_at*1000:null;
        const sourceRows=asrCalls.filter(call=>deskQueueIncludes(call,true));
        const recentSource=sourceRows.filter(call=>sourceCallRecent(call,sourceNow));
        const olderSource=sourceRows.filter(call=>!sourceCallRecent(call,sourceNow));
        const sourceHeading=el('h4','mapa-command-queue-heading','Recent ASR Health calls · '+recentSource.length);sourceHeading.id='mapa-asr-incoming';source.append(sourceHeading);
        if(asrCallState)source.append(el('p','mapa-voice-note','ASR feed checked '+date(sourceCheckedAt)+(asrCallState.stale?' · delayed':' · current')+'. Incident dates are shown separately.'));
        if(!recentSource.length)source.append(el('p','mapa-voice-note','No ASR incidents updated in the last 24 hours. Older source-reported open records are below for review.'));
        const appendSourceRow=(target,call)=>{
            const row=button('',()=>{if(busy||pending)return;if(dirty&&!window.confirm('Discard unsaved intake edits and open the ASR call?'))return;dirty=false;editDraft=null;manualEdit=null;newCall=false;selected=null;recommendations=null;candidates=[];unavailable=[];asrSelected=call;incomingSeen.set(call.id,call.source_revision);try{window.parent.mapaSourceCallRead?.(window,call.id,call.source_revision);}catch(_){}render();});
            row.className+=' mapa-voice-call';row.classList.toggle('is-selected',asrSelected?.id===call.id);row.disabled=!!pending;
            const recorded=typeof call.created_at==='string'&&/^\d{4}-\d{2}-\d{2}/.test(call.created_at)?call.created_at.slice(0,16):'';
            row.append(el('strong','',call.incident_number||'ASR call'),el('span','',call.caller_name||'Caller'),el('small','',call.caller_phone||'Number not recorded'),badge('ASR Health',''),el('small','',call.status||''));
            if(recorded)row.append(el('small','',`ASR recorded ${recorded} · confirm current status before action`));
            target.append(row);
        };
        for(const call of recentSource)appendSourceRow(source,call);
        if(olderSource.length){const older=el('details','mapa-command-older-source');older.open=olderOpen||!!asrSelected&&olderSource.some(call=>call.id===asrSelected.id);older.append(el('summary','','Older unresolved ASR records · '+olderSource.length));older.append(el('p','mapa-voice-warning','These incidents have no source update in the last 24 hours. Confirm their actual status and check existing MAPA assignments before acting.'));for(const call of olderSource)appendSourceRow(older,call);source.append(older);}
        if (!calls.length&&!asrCalls.length) source.append(el('p', 'mapa-voice-muted', 'No operational calls have been received. Use NEW CALL to record an incoming call.'));
        if(sourceOnly){list.scrollTop=scrollTop;return;}
        list.append(el('h4','mapa-command-queue-heading','MAPA calls'));
        for (const call of calls) {
            if(call.source==='asrhealth')continue;
            if (!deskQueueIncludes(call)) continue;
            const row = button('', () => loadCall(call.id)); row.className += ' mapa-voice-call'; row.dataset.voiceFocus = `call:${call.id}`; row.classList.toggle('is-selected', selected?.id === call.id); row.disabled = !!pending;
            const pickup = call.review || call.draft || {};
            row.append(el('strong', '', string(pickup.caller_name) || string(call.caller) || 'Unknown caller'), el('span', 'mapa-voice-muted', string(call.state).replaceAll('_', ' ')), el('small', '', date(call.created_at)));
            if(call.automatic_dispatch?.state==='needs_operator')row.append(badge('Operator needed', 'is-warning'));
            else if(['searching','offering'].includes(call.automatic_dispatch?.state))row.append(badge('Automatic offers active', ''));
            else if(call.automatic_dispatch?.state==='accepted')row.append(badge('Crew accepted', 'is-success'));
            if (pickup.pickup_address) row.append(el('small', 'mapa-voice-queue-pickup', string(pickup.pickup_address)));
            const pin = pickupCandidate(pickup); if (pin) row.append(el('small', '', `${pin.latitude.toFixed(6)}, ${pin.longitude.toFixed(6)}`));
            row.append(badge('MAPA call','')); if (call.demo === true) row.append(badge('DEMO', 'is-demo'));if(isSupervisedPilot(call))row.append(badge('NO PATIENT TEST','is-warning')); list.append(row);
        }
        list.scrollTop=scrollTop;
    }
    function renderCommandHeader(parent,toolbar){
        const header=el('header','mapa-command-header'),identity=el('div','mapa-command-brand');
        identity.append(el('span','mapa-command-district','ASR DISTRICT · ITDA'),el('h2','','Call operations'),el('p','','Review calls, locate crews and manage dispatch.'));
        const controls=el('div','mapa-command-header-controls'),connection=el('span','mapa-command-connection');connection.textContent=deskError?'Data refresh unavailable':deskLoadedAt?'Latest server data · '+date(deskSnapshot?.server_time||deskLoadedAt):'Connecting to live data';
        connection.classList.toggle('is-error',!!deskError);const tools=el('details','mapa-command-tools');tools.append(el('summary','','Desk tools'),toolbar);
        controls.append(connection,el('span','mapa-command-operator',session.name||'Operator'),tools,operationsButton());header.append(identity,controls);parent.append(header);
    }
    function beginDeskCall() {
        if(busy||pending||capabilities.manual_intake!==true)return;
        if(dirty&&!window.confirm('Discard unsaved call edits and create a new call?'))return;
        dirty=false;editDraft=null;manualEdit=null;asrSelected=null;selected=null;recommendations=null;candidates=[];unavailable=[];newCall=true;activeView='calls';render();
    }
    function deskQueueIncludes(call, source=false) {
        const data=call.review||call.draft||call, priority=string(data.priority||data.intake_priority).toLowerCase();
        if(queueFilter==='urgent')return ['critical','high','urgent','emergency','p1','p2'].includes(priority);
        if(queueFilter==='unconfirmed')return source?['pending','new','unconfirmed'].includes(string(call.status).toLowerCase()):!call.review||['needs_review','received','draft'].includes(call.state);
        return true;
    }
    function deskNavigationAllowed(destination) {
        if (busy || pending) { announce('Finish the current update before opening '+destination+'.',true); return false; }
        if ((dirty || resourceEditor) && !window.confirm('Discard unsaved edits and open '+destination+'?')) return false;
        dirty=false; editDraft=null; manualEdit=null; resourceEditor=null;
        return true;
    }
    function focusDeskRegion(selector) {
        const target=content?.querySelector(selector);
        if (!target) return false;
        if (!['BUTTON','INPUT','SELECT','TEXTAREA','A'].includes(target.tagName)) target.tabIndex=-1;
        target.focus({preventScroll:true}); target.scrollIntoView?.({block:'nearest'});
        return true;
    }
    function navigateDeskMetric(key) {
        const destinations={total_fleet:'the ambulance list',on_trip:'the orders map',active_calls:'the call queue',gps_stale_offline:'Ambulance Fleet',dispatchable_now:'crew eligibility'};
        if (!destinations[key] || !deskNavigationAllowed(destinations[key])) return;
        const path=key==='total_fleet'?'/fleet-ops/manage/vehicles':key==='on_trip'?'/fleet-ops?layout=map':null;
        if (path) {
            // Keep native navigation in its same-origin parent, outside the desk iframe.
            try { if (window.parent!==window && window.parent.location.origin===location.origin) { window.parent.location.assign(path); return; } } catch (_) {}
            location.assign(path); return;
        }
        activeView='calls'; newCall=false; asrSelected=null;
        if (key==='dispatchable_now' && selected?.state==='ready' && selected.review) {
            workflowChoice=null; secondaryTab='fleet'; render();
            if (!focusDeskRegion('[name="ambulance_choice"]')) focusDeskRegion('.mapa-voice-candidates') || focusDeskRegion('.mapa-command-hero');
            announce('Review the selected call’s crew eligibility and dispatch requirements.');
            return;
        }
        selected=null; queueFilter='all';
        if (key==='gps_stale_offline') secondaryTab='fleet';
        render();
        if (key==='gps_stale_offline') {
            focusDeskRegion('.mapa-command-secondary-content');
            announce('Ambulance Fleet shows each vehicle’s latest GPS evidence, including stale and missing fixes.');
        } else {
            focusDeskRegion('.mapa-command-queue');
            announce(key==='dispatchable_now'?'Select a call and review its pickup and destination to check eligible crews.':'Call queue opened. Select a call to review.');
        }
    }
    function renderDeskMetrics(parent) {
        const strip=el('section','mapa-command-kpis');strip.setAttribute('aria-label','Live dispatch desk metrics');
        const summary=deskSnapshot?.summary;
        const fleet=Array.isArray(deskSnapshot?.fleet)&&deskSnapshot?.truncated?.fleet!==true?deskSnapshot.fleet:null;
        const coverage=summary?.gps_coverage||(fleet?gpsCoverage(fleet):null);
        const eligibleCount=selected?.state==='ready'&&selected.review?eligible(selected,candidates).length:null;
        const actions={dispatchable_now:eligibleCount===null?'Select a reviewed call to check eligibility':'Open selected call crew eligibility',total_fleet:'Open native ambulance list',on_trip:'Open orders map',gps_stale_offline:'Open Ambulance Fleet to review GPS evidence',active_calls:'Open all calls in the call queue'};
        for(const [key,label] of [['dispatchable_now','Eligible crews'],['total_fleet','Total fleet'],['on_trip','On trip'],['gps_stale_offline','GPS stale / missing'],['active_calls','Active calls']]){
            const value=key==='dispatchable_now'?eligibleCount:key==='gps_stale_offline'&&coverage?coverage.stale+coverage.missing:summary?.[key],display=Number.isInteger(value)&&value>=0?value:key==='dispatchable_now'?'Select call':'—';
            const card=button('',()=>navigateDeskMetric(key));card.className='mapa-command-kpi';card.dataset.voiceFocus='metric:'+key;card.disabled=!!busy||!!pending;
            const detail=key==='gps_stale_offline'&&coverage?`${coverage.stale} stale · ${coverage.missing} without verified fix · ${coverage.recent} recent`:'';
            card.title=actions[key]+(detail?' · '+detail:'');card.setAttribute('aria-label',`${label}: ${display}. ${card.title}`);
            card.append(el('strong','',display),el('span','',label));
            if(key==='dispatchable_now')card.append(el('small','','Choose a reviewed call to check eligibility.'));
            if(detail)card.append(el('small','',detail));
            strip.append(card);
        }
        if(deskError)strip.append(el('p','mapa-voice-warning',deskLoadedAt?'Metrics are from the last successful refresh; current refresh unavailable.':'Live metrics unavailable. Refresh the command desk.'));
        parent.append(strip);
    }
    async function loadCommandDesk() {
        if(capabilities.command_desk!==true){deskSnapshot=null;deskError='Command desk metrics are not available on this server.';return;}
        const own=epoch, version=++deskEpoch;
        try{const result=await request('/command-desk');if(own!==epoch||version!==deskEpoch)return;
            if(result.contract!=='mapa-command-desk-v1'||!Array.isArray(result.driver_statistics)||!Array.isArray(result.trip_monitoring)||result.driver_statistics.length>500||result.trip_monitoring.length>500||(result.fleet!==undefined&&(!Array.isArray(result.fleet)||result.fleet.length>500))||(result.history!==undefined&&(!Array.isArray(result.history)||result.history.length>500)))throw new Error('Unsupported command desk data.');
            deskSnapshot=result;deskLoadedAt=Date.now();deskError='';
        }catch(error){if(own===epoch&&version===deskEpoch){deskError='Command desk refresh failed: '+error.message;}}
    }
    function renderDeskSecondary(parent,main) {
        parent.setAttribute('aria-label','Pickup map, fleet and driver monitoring');
        const mapBox=el('section','mapa-command-map-panel');mapBox.append(el('h3','','Pickup & ambulance map'));
        const map=main.querySelector('.mapa-voice-pickup-map');
        if(map)mapBox.append(map);
        else {
            const data=asrSelected||selected?.review||selected?.draft,point=pickupCandidate(data);
            if(point){const latitude=el('input'),longitude=el('input'),pickup_address=el('input');latitude.value=point.latitude;longitude.value=point.longitude;pickup_address.value=string(data.pickup_address);pickupMap(mapBox,{latitude,longitude,pickup_address},()=>{},false);}
            else mapBox.append(el('p','mapa-voice-note','Select or create a call to locate its pickup. GPS evidence and caller tracking appear with the selected call.'));
        }
        parent.append(mapBox);
        const tabs=el('nav','mapa-command-secondary-tabs');tabs.setAttribute('aria-label','Secondary operations');
        const panel=el('section','mapa-command-secondary-content');
        const draw=(preserveScroll=false)=>{
            const scrollTop=preserveScroll?panel.scrollTop:0;
            panel.replaceChildren();
            if(deskError)panel.append(el('p','mapa-voice-warning',deskError+(deskLoadedAt?' Last successful refresh: '+date(deskLoadedAt):'')));
            else if(deskLoadedAt)panel.append(el('p','mapa-voice-note','Latest server data · '+date(deskSnapshot.server_time||deskLoadedAt)));
            if(deskSnapshot?.window?.days)panel.append(el('p','mapa-voice-note','Reporting window: last '+deskSnapshot.window.days+' days. '+(deskSnapshot.dispatch_authority==='mapa'?'MAPA controls dispatch; ASR supplies data. Crew readiness is checked for each call.':'MAPA trip evidence. Source history is shown separately.')));
            if(Object.values(deskSnapshot?.truncated||{}).some(value=>value===true))panel.append(el('p','mapa-voice-warning','This bounded desk shows a partial dataset. Counts and history may be incomplete; review individual records.'));
            if(secondaryTab==='fleet'){
                let rows=ambulanceDirectory(selected||{},candidates,unavailable,'',false);
                if(!rows.length&&Array.isArray(deskSnapshot?.fleet))rows=deskSnapshot.fleet.slice(0,200).map(row=>({...row,vehicle_registration:row.registration,vehicle_name:row.name,ready:null,message:'Eligibility is checked against a reviewed call, verified crew and fresh GPS.'}));
                panel.append(el('h4','','Ambulance Fleet'));
                if(!rows.length)panel.append(el('p','mapa-voice-note','Select a reviewed call to see eligible ambulances and availability reasons.'));
                for(const row of rows.slice(0,200)){
                    const evidence=deskSnapshot?.fleet?.find(vehicle=>vehicle.vehicle_id===row.vehicle_id)||row,card=el('article','mapa-command-row');card.append(el('strong','',row.vehicle_registration||row.vehicle_name||row.vehicle_id),badge(row.ready===true?'Eligible':row.ready===false?'Unavailable':'Eligibility not checked',row.ready===false?'is-error':''),el('p','mapa-voice-note',[evidence.driver_name||row.name,evidence.driver_phone,ambulanceContext(row),row.message].filter(Boolean).join(' · ')));
                    appendCrewMapping(card, withCurrentCrewMapping(evidence, [row]));
                    const gps=evidence.gps_source==='hardware_gps'?'Ambulance device GPS':evidence.gps_source==='crew_phone'?'Assigned driver phone GPS':'GPS source not recorded';
                    card.append(el('p','mapa-voice-note','Capability: '+string(evidence.capability||row.vehicle_type||'Not recorded')+' · Status: '+string(evidence.status||'Not recorded')));
                    const availability=ambulanceAvailabilityEvidence(row);if(availability)card.append(el('p','mapa-voice-note',availability));
                    card.append(el('p','mapa-voice-note',gps+' · '+(evidence.gps_recorded_at?date(evidence.gps_recorded_at)+(evidence.gps_stale===true?' · stale':evidence.gps_stale===false?' · fresh':' · freshness unknown'):'No valid fix received')));
                    if(evidence.gps_recorded_at)card.append(el('p','mapa-voice-note',gpsQuality(evidence)));
                    for(const [label,fix] of [['Crew phone',evidence.crew_phone_location],['Ambulance GPS',evidence.ambulance_gps_location]]){
                        if(fix&&pickupCandidate(fix))card.append(el('p','mapa-voice-note',label+': '+Number(fix.latitude).toFixed(6)+', '+Number(fix.longitude).toFixed(6)+' · '+date(fix.recorded_at)+(fix.stale?' · stale':' · recent')+(fix.protocol?' · '+String(fix.protocol).toUpperCase():'')));
                    }
                    if(evidence.driver_mapping_ambiguous)card.append(el('p','mapa-voice-warning','Driver mapping needs review. This row is not a dispatch recommendation.'));
                    panel.append(card);
                }
            }else if(secondaryTab==='drivers'){
                panel.append(el('h4','','Driver Performance'));
                if(!deskSnapshot)panel.append(el('p','mapa-voice-note','Driver statistics have not been loaded. Refresh the desk.'));
                for(const row of deskSnapshot?.driver_statistics||[]){
                    const card=el('article','mapa-command-row');card.append(el('strong','',row.driver_name||row.name||'Driver'),el('p','mapa-voice-note','Completed trips: '+metric(row.completed_trips)+' · Accepted offers: '+metric(row.accepted_offers)+' · Acceptance: '+metric(row.acceptance_rate)+'% · Average response: '+metric(row.average_response_seconds)+' s'),el('p','mapa-voice-note','Declined: '+metric(row.declined_offers)+' · No response: '+metric(row.no_response_offers??row.expired_offers)+(row.overdue_offers!=null?' · Actionable overdue: '+metric(row.overdue_offers):'')));
                    const history=(deskSnapshot.history||[]).filter(trip=>trip.driver_id===row.driver_id).slice(0,20),details=el('details');details.append(el('summary','','Completed trip history ('+history.length+' shown)'));
                    for(const trip of history)details.append(el('p','mapa-voice-note',string(trip.order_id)+' · '+string(trip.stage)+' · '+date(trip.completed_at)));
                    if(!history.length)details.append(el('p','mapa-voice-note','No attributable completed trips in the displayed window.'));
                    card.append(details);panel.append(card);
                }
            }else if(secondaryTab==='exceptions'){
                panel.append(el('h4','','Active Exceptions'));
                if(!deskSnapshot)panel.append(el('p','mapa-voice-note','Trip monitoring has not been loaded. Refresh the desk.'));
                for(const row of deskSnapshot?.dispatch_exceptions||[]){
                    const card=el('article','mapa-command-row');card.append(el('strong','','Call '+string(row.call_id)),badge(string(row.state).replaceAll('_',' '),'is-warning'),el('p','mapa-voice-warning',string(row.message)),el('p','mapa-voice-note','Reason: '+string(row.reason||'worker delay')+' · last update '+date(row.updated_at)));
                    const open=button('Open call & resolve',()=>loadCall(row.call_id));open.disabled=!!pending;card.append(open);panel.append(card);
                }
                for(const row of deskSnapshot?.trip_monitoring||[]){const card=el('article','mapa-command-row');card.append(el('strong','',row.vehicle_registration||row.call_id||'Trip'),el('p','mapa-voice-note',[row.stage,row.driver_name,...(Array.isArray(row.alerts)?row.alerts.map(alert=>typeof alert==='string'?alert:alert.message):[])].filter(Boolean).join(' · ')));panel.append(card);}
                if(deskSnapshot&&!deskSnapshot.trip_monitoring.length&&!deskSnapshot.dispatch_exceptions?.length)panel.append(el('p','mapa-voice-note','No monitored active MAPA trips or dispatch exceptions. Historical source records do not create MAPA assignments.'));
            }else{
                panel.append(el('h4','','Dispatch Activity'));
                if(deskSnapshot?.metrics?.offers_available===true){
                    const drivers=deskSnapshot.driver_statistics||[];
                    const total=key=>drivers.reduce((count,row)=>count+(Number.isInteger(row[key])&&row[key]>=0?row[key]:0),0);
                    panel.append(el('p','mapa-voice-note',`Known MAPA offers for ${drivers.length} displayed drivers in this ${deskSnapshot.window?.days||30}-day window: ${total('offers_received')} received · ${total('accepted_offers')} accepted · ${total('declined_offers')} declined · ${total('no_response_offers')} no response.`));
                    if(deskSnapshot.has_more||deskSnapshot.truncated?.drivers||deskSnapshot.truncated?.offers)panel.append(el('p','mapa-voice-warning','Offer totals are partial; open individual driver records for the remaining pages.'));
                }else if(deskSnapshot)panel.append(el('p','mapa-voice-note','Offer history is not available from this MAPA server.'));
                for(const run of deskSnapshot?.dispatch_exceptions||[])panel.append(el('p','mapa-command-row',`Call ${string(run.call_id)} · ${string(run.state).replaceAll('_',' ')} · ${string(run.reason||'Review needed')} · ${date(run.updated_at)}`));
                for(const offer of selected?.automatic_dispatch?.offers||[])panel.append(el('p','mapa-command-row',[offer.driver_name,offer.vehicle_registration,string(offer.state).replaceAll('_',' '),'Expires '+date(offer.expires_at)].filter(Boolean).join(' · ')));
                if(!selected?.automatic_dispatch?.offers?.length)panel.append(el('p','mapa-voice-note','Select a MAPA call with dispatch offers to view its crew response history.'));
            }
            for(const node of tabs.children)node.setAttribute('aria-selected',String(node.dataset.tab===secondaryTab));
            if(preserveScroll)panel.scrollTop=scrollTop;
        };
        for(const [value,label] of [['fleet','Ambulance Fleet'],['exceptions','Active Exceptions'],['drivers','Driver Performance'],['audit','Dispatch Activity']]){const tab=button(label,()=>{secondaryTab=value;draw();});tab.dataset.tab=value;tab.setAttribute('role','tab');tabs.append(tab);}
        const refresh=button('Refresh desk',async()=>{if(busy||pending)return;refresh.disabled=true;try{await loadCommandDesk();refreshLiveDesk();announce(deskError||'Command desk loaded with latest data.',!!deskError);}finally{refresh.disabled=false;}});
        parent.append(tabs,refresh,panel);draw();
        refreshDeskPanel=()=>{if(panel.isConnected)draw(true);};
    }
    function metric(value){return typeof value==='number'&&Number.isFinite(value)&&value>=0?Math.round(value*10)/10:'—';}
    function supportedRecommendations(value){
        if(!value||!Array.isArray(value.ranked)||value.ranked.length>10||typeof value.routing_status!=='string')return null;
        return {...value,ranked:value.ranked.filter(row=>row&&safeId(row.id)&&safeId(row.vehicle_id)&&typeof row.road_seconds==='number'&&Number.isFinite(row.road_seconds)&&row.road_seconds>=0&&row.road_seconds<=172800)};
    }
    function renderDeskMetricsRefresh(){const old=content?.querySelector('.mapa-command-kpis');if(!old)return;const holder=el('div');renderDeskMetrics(holder);old.replaceChildren(...holder.children[0].children);}
    function refreshLiveDesk(){
        if(!dialog?.open||activeView!=='calls'||!content?.querySelector('.mapa-command-grid'))return;
        renderDeskMetricsRefresh();
        const connection=content.querySelector('.mapa-command-connection');
        if(connection){connection.textContent=deskError?'Data refresh unavailable':deskLoadedAt?'Latest server data · '+date(deskSnapshot?.server_time||deskLoadedAt):'Connecting to live data';connection.classList.toggle('is-error',!!deskError);}
        refreshDeskPanel?.();
        refreshPickupMap?.();
    }
    function renderLogin() {
        const box = el('section', 'mapa-voice-login'); box.append(el('h3', '', 'MAPA operator sign-in'), el('p', 'mapa-voice-muted', 'Sign in with your approved MAPA operator or administrator account to manage calls.'));
        const form = el('form'), email = field(form, 'Email', 'email', '', 'email'), password = field(form, 'Password', 'password', '', 'password');
        email.autocomplete = 'username'; password.autocomplete = 'current-password'; email.required = true; password.required = true;
        const submit = button('Sign in to Call Intake', () => {} , true); submit.type = 'submit'; form.append(submit);
        form.addEventListener('submit', async event => {
            event.preventDefault(); if (busy) return; const identity = email.value.trim(), secret = password.value; password.value = ''; lock(true); announce('Checking MAPA access…');
            try {
                const data = await request('/login', { native: true, login: true, body: JSON.stringify({ email: identity, password: secret, device_name: 'MAPA Console Call Intake' }) });
                if (data.contract !== 'mapa-native-mobile-v1' || typeof data.token !== 'string' || !data.token || !Number.isFinite(data.expires_at) || data.expires_at <= Date.now() || !safeId(data.user?.id) || !safeId(data.user?.company_id)) throw new Error('MAPA did not provide a valid operator session.');
                session = { token: data.token, expires: data.expires_at, role: data.user?.role, name: string(data.user?.name), user: data.user?.id, company: data.user?.company_id };
                if (!['operator', 'admin', 'superadmin'].includes(session.role)) { try { await request('/logout', { native: true, body: '{}' }); } finally { expire(); } throw new Error('Call Intake requires an approved operator or administrator account.'); }
                // An uncertain update cannot be replayed under a different account or company.
                if (pending && (pending.owner !== session.user || pending.company !== session.company)) { try { await request('/logout', { native: true, body: '{}' }); } finally { expire(); } throw new Error('An earlier update has an uncertain receipt under another account. Sign in with the original approved account; this update cannot be discarded or replayed under a different account.'); }
                if (pending) pending.expired = false;
                announce('MAPA access confirmed.'); lock(false); await loadCalls();
            } catch (error) { announce(`${error.message}${error.fields ? ' ' + error.fields : ''}`, true); }
            finally { lock(false); render(); }
        });
        box.append(form, el('p', 'mapa-voice-note', 'If your account needs verification, a password reset or two-factor sign-in, contact your MAPA administrator.'));
        if (pending) box.append(el('p', 'mapa-voice-warning', 'An earlier update has an uncertain receipt. Sign back in with the same account to retry its saved key. Check the call in MAPA before taking any replacement action.'));
        content.append(box);
    }
    function field(parent, label, name, value, type = 'text') {
        const wrapper = el('label', 'mapa-voice-field'); wrapper.append(el('span', '', label));
        const input = type === 'textarea' ? el('textarea') : el('input'); if (type !== 'textarea') input.type = type;
        input.name = name; input.dataset.voiceFocus = `field:${name}`; input.value = value == null ? '' : String(value); wrapper.append(input); parent.append(wrapper); return input;
    }
    function select(parent, label, name, value, options) {
        const wrapper = el('label', 'mapa-voice-field'); wrapper.append(el('span', '', label)); const input = el('select'); input.name = name; input.dataset.voiceFocus = `field:${name}`;
        const placeholder = el('option', '', 'Choose…'); placeholder.value = ''; input.append(placeholder);
        for (const [id, text] of options) { const option = el('option', '', text); option.value = id; input.append(option); } input.value = value || ''; wrapper.append(input); parent.append(wrapper); return input;
    }
    function checkbox(parent, text) { const label = el('label', 'mapa-voice-check'), input = el('input'); input.type = 'checkbox'; input.dataset.voiceFocus = `check:${text}`; label.append(input, el('span', '', text)); parent.append(label); return input; }
    function locationFields(grid, inputs, draft) {
        for (const [key, label, limit] of [['patient_name', 'Patient name (optional)', 200], ['origin_phc_name', 'Origin PHC (caller-reported)', 200], ['origin_secretariat_name', 'Origin secretariat (caller-reported)', 200], ['destination_facility_name', 'Requested destination / other facility', 200], ['dispatcher_notes', 'Dispatcher notes', 2000]]) {
            inputs[key] = field(grid, label, key, draft[key], key === 'dispatcher_notes' ? 'textarea' : 'text'); inputs[key].maxLength = limit;
        }
        inputs.patient_age = field(grid, 'Patient age (blank if unknown)', 'patient_age', draft.patient_age, 'number'); inputs.patient_age.min = '0'; inputs.patient_age.max = '120'; inputs.patient_age.step = '1';
        inputs.patient_gender = select(grid, 'Patient gender', 'patient_gender', draft.patient_gender || 'unknown', [['unknown', 'Not specified'], ['Male', 'Male'], ['Female', 'Female']]);
        inputs.incident_type = select(grid, 'Incident type', 'incident_type', draft.incident_type, [['Obstetric Emergency', 'Obstetric emergency'], ['Trauma', 'Trauma / accident'], ['Medical', 'General medical emergency'], ['Neonatal', 'Neonatal emergency'], ['Referral Transfer', 'Referral transfer'], ['Other', 'Other / non-emergency']]);
        inputs.intake_priority = select(grid, 'Intake priority (operator selection)', 'intake_priority', draft.intake_priority, [['emergency', 'P1 · Critical'], ['urgent', 'P2 · Urgent'], ['routine', 'P3 · Routine']]);
        if (sourceLocations.length) {
            const directory = el('datalist'); directory.id = `mapa-asr-phc-${activeView}`;
            for (const row of sourceLocations) { const option = el('option'); option.value = row.name; directory.append(option); }
            grid.append(directory); inputs.origin_phc_name.setAttribute('list', directory.id); inputs.destination_facility_name.setAttribute('list', directory.id);
        }
        grid.append(el('p', 'mapa-voice-note', 'Source selections are checked against ASR’s location directory. Manual names remain caller-reported. Confirm the pickup pin and the mapped destination during review.'));
        const directoryReady=sourceDirectory&&Array.isArray(sourceDirectory.hospitals)&&sourceDirectory.hospitals.length<=1000;
        const hospitals=directoryReady?sourceDirectory.hospitals.filter(row=>safeId(row.id)&&typeof row.name==='string'):[];
        const hierarchyReady=directoryReady&&sourceDirectory.hierarchy_available===true&&Array.isArray(sourceDirectory.secretariats)&&Array.isArray(sourceDirectory.villages);
        const secretariats=hierarchyReady?sourceDirectory.secretariats.filter(row=>safeId(row.id)&&safeId(row.phc_id)&&typeof row.name==='string'):[];
        const villages=hierarchyReady?sourceDirectory.villages.filter(row=>safeId(row.id)&&safeId(row.secretariat_id)&&typeof row.name==='string'):[];
        const options=(rows,saved)=>{const values=rows.map(row=>[row.id,row.name]);if(saved&&!rows.some(row=>row.id===saved))values.push([saved,'Saved source selection · refresh directory']);return values;};
        inputs.origin_phc_id=select(grid,'ASR origin PHC (optional for manual pickup)','origin_phc_id',draft.origin_phc_id,options(hospitals,draft.origin_phc_id));
        inputs.destination_phc_id=select(grid,'ASR requested destination PHC / hospital','destination_phc_id',draft.destination_phc_id,options(hospitals,draft.destination_phc_id));
        inputs.origin_phc_id.disabled=!hospitals.length;inputs.destination_phc_id.disabled=!hospitals.length;
        inputs.origin_secretariat_id=select(grid,'ASR origin secretariat','origin_secretariat_id','',[]);
        inputs.origin_village_id=select(grid,'ASR origin village','origin_village_id','',[]);
        const populate=(input,rows,value)=>{input.replaceChildren();const placeholder=el('option','',rows.length?'Choose…':'Select the preceding location first');placeholder.value='';input.append(placeholder);for(const [id,name] of options(rows,value)){const option=el('option','',name);option.value=id;input.append(option);}input.value=value||'';input.disabled=!rows.length;};
        const updateVillages=value=>populate(inputs.origin_village_id,villages.filter(row=>row.secretariat_id===inputs.origin_secretariat_id.value),value);
        const updateSecretariats=value=>populate(inputs.origin_secretariat_id,secretariats.filter(row=>row.phc_id===inputs.origin_phc_id.value),value);
        updateSecretariats(draft.origin_secretariat_id);updateVillages(draft.origin_village_id);
        inputs.origin_phc_id.addEventListener('change',()=>{inputs.origin_phc_name.value=hospitals.find(row=>row.id===inputs.origin_phc_id.value)?.name||'';inputs.origin_secretariat_name.value='';updateSecretariats('');updateVillages('');});
        inputs.destination_phc_id.addEventListener('change',()=>{inputs.destination_facility_name.value=hospitals.find(row=>row.id===inputs.destination_phc_id.value)?.name||'';});
        inputs.origin_secretariat_id.addEventListener('change',()=>{inputs.origin_secretariat_name.value=secretariats.find(row=>row.id===inputs.origin_secretariat_id.value)?.name||'';updateVillages('');});
        inputs.origin_village_id.addEventListener('change',()=>{const row=villages.find(row=>row.id===inputs.origin_village_id.value);if(row)inputs.village.value=row.name;});
        if(!hospitals.length)grid.append(el('p','mapa-voice-warning','ASR location directory is unavailable. Enter the caller-reported names; refresh before selecting a mapped PHC or destination.'));
        else if(!hierarchyReady)grid.append(el('p','mapa-voice-note','PHCs are available; the secretariat and village hierarchy has not been received. Enter caller-reported locality details.'));
        inputs.village = field(grid, 'Village / locality', 'village', draft.village); inputs.village.maxLength = 200;
        inputs.mandal = field(grid, 'Mandal', 'mandal', draft.mandal); inputs.mandal.maxLength = 200;
        inputs.access_directions = field(grid, 'How crew can reach the caller', 'access_directions', draft.access_directions, 'textarea'); inputs.access_directions.maxLength = 1000;
        inputs.access_directions.placeholder = 'Road, bridge, turn, meeting point or access difficulty';
        inputs.patient_count = field(grid, 'Number of patients (blank if unknown)', 'patient_count', draft.patient_count, 'number'); inputs.patient_count.min = '1'; inputs.patient_count.max = '99'; inputs.patient_count.step = '1';
        inputs.consciousness = select(grid, 'Caller says the patient is', 'consciousness', draft.consciousness || 'unknown', [['awake', 'Awake'], ['not_awake', 'Not awake'], ['unknown', 'Unknown / not reported']]);
        inputs.breathing = select(grid, 'Caller describes breathing as', 'breathing', draft.breathing || 'unknown', [['normal', 'Normal'], ['not_normal', 'Not normal'], ['unknown', 'Unknown / not reported']]);
        inputs.scene_safety = select(grid, 'Caller-reported scene safety', 'scene_safety', draft.scene_safety || 'unknown', [['safe', 'Safe'], ['unsafe', 'Hazard reported'], ['unknown', 'Unknown / not reported']]);
    }
    function renderProgress(node, call, point, modified = false) {
        node.replaceChildren(); node.setAttribute('aria-label', 'Call progress');
        const steps = callSteps(call, point, modified); let current = false;
        steps.forEach(([label, done], index) => {
            const item = el('li', done ? 'is-complete' : '', `${index + 1}. ${label}`);
            if (!done && !current) { item.className = 'is-current'; item.setAttribute('aria-current', 'step'); current = true; }
            if (done) item.setAttribute('aria-label', `${label}: complete`);
            node.append(item);
        });
    }
    function renderDispatchPipeline(node,call,modified=false){
        node.replaceChildren();node.setAttribute('aria-label','Emergency dispatch pipeline');
        dispatchPipeline(call,modified).forEach((stage,index)=>{const item=el('li','mapa-command-stage'+(stage.complete?' is-complete':stage.active?' is-current':''));item.append(el('span','mapa-command-stage-dot',stage.complete?'✓':index+1),el('span','',stage.label));item.setAttribute('aria-label',stage.label+': '+(stage.complete?'recorded':stage.active?'current':'not reported'));if(stage.active)item.setAttribute('aria-current','step');node.append(item);});
    }
    function smsPermission(form, checked = false) {
        const consent = checkbox(form, 'Caller agreed: send an SMS after saving with a private link to share pickup location and follow this ambulance trip through hospital arrival.');
        consent.name = 'auto_sms_consent'; consent.checked = checked === true;
        consent.disabled = !!pending || !smsConfigured || capabilities.caller_tracking !== true;
        if (consent.disabled) { consent.checked = false; form.append(el('p', 'mapa-voice-note', 'SMS needs an enabled tracking service and configured gateway. Saving the call remains available.')); }
        return consent;
    }
    function groupIntake(form, inputs) {
        const definitions = [
            ['Caller & patient', ['caller_name','callback_number','reported_problem','patient_name','patient_age','patient_gender','incident_type','intake_priority','patient_count','consciousness','breathing','scene_safety']],
            ['Pickup & destination', ['origin_phc_id','origin_secretariat_id','origin_village_id','origin_phc_name','origin_secretariat_name','village','mandal','pickup_address','landmark','access_directions','latitude','longitude','destination_phc_id','destination_facility_name','dropoff_id']],
            ['Dispatch requirements', ['priority','vehicle_requirement','dispatcher_notes']]
        ];
        definitions.forEach(([title, keys], index) => {
            const section = el('fieldset', 'mapa-intake-section'), fields = el('div', 'mapa-voice-form-grid');
            section.append(el('legend', '', title), fields);
            const extra=el('details','mapa-command-extra-fields'),extraFields=el('div','mapa-voice-form-grid');extra.append(el('summary','','Additional patient and scene details'),extraFields);
            for (const key of keys) if (inputs[key]) {
                const target=index===0&&!['caller_name','callback_number','reported_problem','intake_priority'].includes(key)?extraFields:fields;
                target.append(inputs[key].parentElement);
            }
            if(extraFields.children.length)fields.append(extra);
            if (!fields.children.length) return;
            form.append(section);
        });
    }
    function callWorkbench(parent) {
        const desk = el('div', 'mapa-call-workbench'), main = el('div', 'mapa-call-main'), rail = el('aside', 'mapa-call-rail');
        rail.setAttribute('aria-label', 'Dispatch and caller tracking'); desk.append(main, rail); parent.append(desk);
        return { main, rail };
    }
    function workflowSections(prefix, sections, initial) {
        const tabs=el('nav','mapa-command-workflow-tabs'),buttons=new Map();tabs.setAttribute('role','tablist');tabs.setAttribute('aria-label','Call workflow sections');
        const context=prefix+':'+string(selected?.id||'new');
        const show=key=>{workflowChoice={context,key};for(const section of sections){const active=section.key===key,tab=buttons.get(section.key);section.panel.hidden=!active;tab.setAttribute('aria-selected',String(active));tab.tabIndex=active?0:-1;if(active&&section.open)section.open();}return sections.find(section=>section.key===key)?.panel;};
        for(const [index,section] of sections.entries()){
            section.panel.id=prefix+'-'+section.key;section.panel.setAttribute('role','tabpanel');section.panel.setAttribute('aria-label',section.label);
            const tab=button(section.label,()=>show(section.key));tab.type='button';tab.setAttribute('role','tab');tab.setAttribute('aria-controls',section.panel.id);
            tab.addEventListener('keydown',event=>{const next=event.key==='ArrowRight'?sections[(index+1)%sections.length]:event.key==='ArrowLeft'?sections[(index+sections.length-1)%sections.length]:event.key==='Home'?sections[0]:event.key==='End'?sections.at(-1):null;if(next){event.preventDefault();show(next.key);buttons.get(next.key).focus();}});
            tabs.append(tab);buttons.set(section.key,tab);
        }
        const restored=workflowChoice?.context===context&&sections.some(section=>section.key===workflowChoice.key)?workflowChoice.key:initial;
        show(restored);return {tabs,show};
    }
    function compactCreationFields(form, inputs){
        const quick=el('fieldset','mapa-intake-section mapa-command-quick-fields'),tabs=el('nav','mapa-command-create-slides');
        quick.append(el('legend','','New emergency call'),tabs);tabs.setAttribute('role','tablist');tabs.setAttribute('aria-label','New call sections');
        const sections=[['caller','Caller & priority',['caller_name','callback_number','priority','vehicle_requirement']],['pickup','Pickup & incident',['pickup_address','reported_problem','latitude','longitude']]];
        const controls=new Map(),panels=new Map();
        const show=key=>{for(const [name] of sections){const active=name===key,tab=controls.get(name),panel=panels.get(name);tab.setAttribute('aria-selected',String(active));tab.tabIndex=active?0:-1;panel.hidden=!active;}return panels.get(key);};
        for(const [key,label,fields] of sections){
            const tab=button(label,()=>show(key));tab.type='button';tab.setAttribute('role','tab');tab.setAttribute('aria-controls','mapa-new-call-'+key);
            tab.addEventListener('keydown',event=>{const index=sections.findIndex(item=>item[0]===key);const target=event.key==='ArrowRight'?sections[(index+1)%sections.length][0]:event.key==='ArrowLeft'?sections[(index+sections.length-1)%sections.length][0]:event.key==='Home'?sections[0][0]:event.key==='End'?sections.at(-1)[0]:null;if(target){event.preventDefault();show(target);controls.get(target).focus();}});
            tabs.append(tab);controls.set(key,tab);
            const panel=el('section','mapa-command-create-slide mapa-voice-form-grid');panel.id='mapa-new-call-'+key;panel.setAttribute('role','tabpanel');panel.setAttribute('aria-label',label);
            for(const name of fields)panel.append(inputs[name].parentElement);
            const move=button(key==='caller'?'Next: Pickup & incident':'Back: Caller & priority',()=>{const target=key==='caller'?'pickup':'caller';show(target);controls.get(target).focus();});move.type='button';move.className+=' mapa-command-slide-next';panel.append(move);
            panels.set(key,panel);quick.append(panel);
        }
        show('caller');form.append(quick);return show;
    }
    function renderSavedTrackerCard(parent, call, onLocation) {
        if(isSupervisedPilot(call)){
            parent.replaceChildren(el('h4','','No caller tracking for this pilot'),el('p','mapa-voice-note','This is a supervised no-patient crew test. No caller phone, WhatsApp link or location request is used.'));
            return;
        }
        const phone=normalizedCallback((call.review||call.draft||{}).callback_number??call.caller);
        let link=issuedCallerLinks.get(call.id);
        if(link&&(link.callback_number!==phone||!Number.isFinite(Number(link.expires_at))||Number(link.expires_at)<=Date.now())){issuedCallerLinks.delete(call.id);link=null;}
        parent.replaceChildren(el('h4','','WhatsApp tracker link'));
        const feedback=el('p','mapa-voice-note');feedback.setAttribute('role','status');feedback.setAttribute('aria-live','polite');
        let locationPending=false;
        const checkLocation=async()=>{
            if(locationPending||!parent.isConnected||selected?.id!==call.id||!newCall)return;
            locationPending=true;
            try{const receipt=await request(`/tracking/calls/${call.id}/locations`,{native:true});
                if(!parent.isConnected||selected?.id!==call.id||!newCall)return;
                const confirmed=confirmedCallerPickup(receipt.locations),shared=confirmed||callerPickup(receipt.locations);
                if(confirmed){onLocation(confirmed);feedback.textContent=`Caller-confirmed pickup received ${mapaIST(confirmed.recorded_at)} (accuracy ±${Math.round(confirmed.accuracy_m)} m). Check the address with the caller before dispatch.`;}
                else feedback.textContent=shared?'Caller GPS received, but the patient pickup is not confirmed yet. Waiting for the caller to confirm it in the tracker.':'Waiting for the caller to open the tracker, allow GPS, and confirm the patient pickup.';
            }catch(error){if(parent.isConnected)feedback.textContent='Caller location check interrupted: '+error.message;}
            finally{locationPending=false;if(parent.isConnected&&selected?.id===call.id&&newCall)setTimeout(checkLocation,10000);}
        };
        if(call.demo===true||capabilities.caller_tracking!==true){parent.append(el('p','mapa-voice-note','Caller tracking is unavailable for this call.'),feedback);return;}
        const consent=checkbox(parent,'Caller agreed to receive the private tracking link.');consent.checked=!!link;
        if(!phone)parent.append(el('p','mapa-voice-warning','Save a valid caller phone number before creating a link.'));
        if(link&&typeof link.token==='string'&&/^[a-f0-9]{64}$/.test(link.token)){
            const url=el('input');url.type='text';url.readOnly=true;url.value=location.origin+'/track/#'+link.token;url.setAttribute('aria-label','Private WhatsApp tracker link');
            const copy=button('Copy link',async()=>{try{await navigator.clipboard.writeText(url.value);feedback.textContent='Link copied.';}catch(_){url.focus();url.select();feedback.textContent='Select and copy the link above.';}});
            const actionId=uuid(),send=button('Send tracker & get location',async()=>{
                if(!consent.checked)return void(feedback.textContent='Confirm the caller agreed before sending.');
                send.disabled=true;
                try{const receipt=await request(`/tracking/calls/${call.id}/whatsapp`,{native:true,body:JSON.stringify({action_id:actionId,token:link.token,consent_to_message:true})});
                    link.message_sent=true;issuedCallerLinks.set(call.id,link);
                    feedback.textContent=({accepted:'WhatsApp accepted the message; delivery is not yet confirmed.',sent:'Message sent; delivery is not yet confirmed.',delivered:'Delivered to the caller.',read:'Read by the caller.',failed:'WhatsApp delivery failed.',rejected:'WhatsApp rejected the message.',unknown:'Send outcome unknown. Check delivery before trying again.'})[receipt.status]||'Check WhatsApp delivery status.';
                    if(['accepted','sent','delivered','read'].includes(receipt.status))void checkLocation();
                }catch(error){link.message_sent_unknown=true;issuedCallerLinks.set(call.id,link);feedback.textContent=error.message+' Check delivery before starting another send.';}
            },true);
            send.hidden=!!link.message_sent||!!link.message_sent_unknown;
            const checkDelivery=button('Check WhatsApp delivery',async()=>{checkDelivery.disabled=true;try{const receipt=await request(`/tracking/calls/${call.id}/whatsapp`,{native:true});const latest=receipt.messages?.[0];feedback.textContent=latest?`Latest WhatsApp status: ${latest.status}. ${latest.delivered?'Delivered to the caller.':'Confirm with the caller before sending another link.'}`:'No WhatsApp attempt is recorded for this call.';}catch(error){feedback.textContent='Delivery check failed: '+error.message;}finally{checkDelivery.disabled=false;}});
            parent.append(url,el('p','mapa-voice-note',`Link expires ${mapaIST(link.expires_at)}. Sending starts location checks; a caller-confirmed pickup fills the coordinates below.`),copy,send,checkDelivery);
            if(link.message_sent)void checkLocation();
        }else{
            parent.append(el('p','mapa-voice-note','No link has been sent from this desk. Sending creates the private link and WhatsApp attempt together.'));
            const replacementNotice=el('p','mapa-voice-warning','An active tracker link already exists. Check with the caller before replacing it: replacement immediately expires the old link, even if the caller has opened it.');replacementNotice.hidden=true;
            const actionId=uuid();
            const sendNew=async replaceExisting=>{
                if(!consent.checked||!phone)return void(feedback.textContent='Confirm caller permission and a valid phone number first.');
                send.disabled=true;replace.disabled=true;
                try{
                    const receipt=await request(`/tracking/calls/${call.id}/whatsapp`,{native:true,body:JSON.stringify({action_id:actionId,issue_new:true,consent_to_message:true,...(replaceExisting?{replace_existing:true}:{})})});
                    const status=({accepted:'WhatsApp accepted the tracker; delivery is not yet confirmed.',sent:'Tracker sent; delivery is not yet confirmed.',delivered:'Tracker delivered to the caller.',read:'Caller read the tracker message.',failed:'WhatsApp reported delivery failure. Check before attempting another message.',rejected:'WhatsApp rejected this message. Check before attempting another message.',unknown:'Send outcome unknown. Check delivery before trying another action.'})[receipt.status]||'Check WhatsApp delivery status.';
                    if(receipt.tracker_link?.token&&/^[a-f0-9]{64}$/.test(receipt.tracker_link.token)){
                        issuedCallerLinks.set(call.id,{...receipt.tracker_link,callback_number:phone,message_sent:true});renderSavedTrackerCard(parent,call,onLocation);
                        const liveStatus=parent.querySelector('[role="status"]');if(liveStatus)liveStatus.textContent=status;
                    }else{send.hidden=true;replace.hidden=true;feedback.textContent=status+' The original link is in the caller’s message; check delivery before sending again.';}
                }catch(error){
                    feedback.textContent=error.status===409?'An active tracker link already exists. Verify whether the caller received it before replacing.':error.message+' If the outcome is uncertain, check delivery before starting a new action.';
                    if(error.status===409){replacementNotice.hidden=false;replace.hidden=false;send.hidden=true;}
                    else send.disabled=false;
                    replace.disabled=false;
                }
            };
            const send=button('Send tracker & get location',()=>sendNew(false));send.disabled=!phone;
            const replace=button('Confirm replacement and send',()=>{if(!window.confirm('Have you checked with the caller? Replacing the active link expires it immediately, including any link already opened.'))return;return sendNew(true);});replace.hidden=true;
            parent.append(send,replacementNotice,replace);
        }
        parent.append(feedback);
    }
    function renderSavedManual(parent,call){
        const draft=call.review||call.draft||{},pilot=isSupervisedPilot(call),body=el('section','mapa-command-saved-call'),grid=el('div','mapa-voice-form-grid');
        body.append(el('p',pilot?'mapa-voice-warning':'mapa-voice-note',pilot?'SUPERVISED PILOT · NO PATIENT. Saved as a test record. Automatic offers will remain off until this exact call and the approved crews are scoped on the server. No caller message is sent.':'Call saved in MAPA. Confirm caller permission, then use Send tracker below; no link or message is created automatically.'));
        const detail=(label,value)=>{const field=el('div','mapa-voice-field');field.append(el('span','',label),el('strong','',string(value)||'Not recorded'));return field;};
        grid.append(detail(pilot?'Test label':'Caller name',draft.caller_name),detail(pilot?'Caller phone · not used':'Caller phone / WhatsApp',pilot?'':draft.callback_number||call.caller),detail('Emergency priority · operator decision',draft.priority),detail('Required capability',draft.vehicle_requirement));
        const tracker=el('section','mapa-command-inline-tracker');
        grid.append(detail(pilot?'No-patient test point':'Pickup village / road / landmark',draft.pickup_address),detail(pilot?'Pilot test note':'Short incident report · caller’s words',draft.reported_problem));
        const coordinate=(label,value,name)=>{const field=el('label','mapa-voice-field'),input=el('input');input.type='number';input.name=name;input.step='any';input.readOnly=true;input.value=value??'';field.append(el('span','',label),input);return {field,input};};
        const lat=coordinate('Pickup candidate latitude',draft.latitude,'latitude'),lon=coordinate('Pickup candidate longitude',draft.longitude,'longitude');grid.append(lat.field,lon.field);
        let confirmedPoint=null;
        renderSavedTrackerCard(tracker,call,point=>{confirmedPoint=point;lat.input.value=point.latitude.toFixed(6);lon.input.value=point.longitude.toFixed(6);});
        if(pilot)body.append(grid,tracker);
        else{const sections=workflowSections('mapa-saved-call',[{key:'tracker',label:'Tracker & caller location',panel:tracker},{key:'summary',label:'Saved call details',panel:grid}],'tracker');body.append(sections.tabs,tracker,grid);}
        body.append(button(pilot?'Continue to pilot review':'Continue to review & dispatch',()=>{
            if(confirmedPoint){editDraft={id:call.id,revision:call.revision,data:{...draft,latitude:lat.input.value,longitude:lon.input.value,coordinates_confirmed:false}};dirty=true;}
            newCall=false;render();
        },true));parent.append(body);
    }
    function compactReviewFields(form,grid,inputs){
        const quick=el('fieldset','mapa-intake-section mapa-command-quick-fields'),quickGrid=el('div','mapa-voice-form-grid');quick.append(el('legend','','Confirm pickup & dispatch'),quickGrid);
        for(const key of ['caller_name','callback_number','priority','vehicle_requirement','pickup_address','reported_problem','latitude','longitude','dropoff_id'])if(inputs[key])quickGrid.append(inputs[key].parentElement);
        form.append(quick);
        const source=el('details','mapa-command-create-options'),sourceFields=el('fieldset','mapa-intake-section'),sourceGrid=el('div','mapa-voice-form-grid');source.append(el('summary','','Pickup & destination · ASR directory'),sourceFields);sourceFields.append(el('legend','','Pickup & destination'),sourceGrid);
        for(const key of ['origin_phc_id','origin_secretariat_id','origin_village_id','origin_phc_name','origin_secretariat_name','village','mandal','landmark','access_directions','destination_phc_id','destination_facility_name'])if(inputs[key])sourceGrid.append(inputs[key].parentElement);
        const patient=el('details','mapa-command-create-options'),patientFields=el('fieldset','mapa-intake-section'),patientGrid=el('div','mapa-voice-form-grid');patient.append(el('summary','','Patient & incident details'),patientFields);patientFields.append(el('legend','','Patient & incident'),patientGrid);
        for(const key of ['patient_name','patient_age','patient_gender','incident_type','patient_count','consciousness','breathing','scene_safety','dispatcher_notes'])if(inputs[key])patientGrid.append(inputs[key].parentElement);
        sourceFields.append(grid);form.append(source,patient);
    }
    function renderManual(parent) {
        if(selected&&safeId(selected.id)&&newCall){renderSavedManual(parent,selected);return;}
        const draft = manualEdit || { demo: false, supervised_pilot: false, incident_type: new URLSearchParams(location.search).get('transfer') === 'referral' ? 'Referral Transfer' : '' };
        if (draft.demo === true && capabilities.demo !== true) {
            parent.append(el('p', 'mapa-voice-warning', 'Training mode has been disabled. Start a new call for an actual incident. This earlier draft has not been converted into a real call.'));
            return;
        }
        const form = el('form', 'mapa-voice-review mapa-voice-intake-form mapa-command-create-form'), grid = el('div', 'mapa-voice-form-grid'), inputs = {};
        const modes = [['live', 'Live manual call · real operational record']];
        if (capabilities.demo === true) modes.push(['training', 'DEMO training · fictional caller and DEMO ambulances']);
        const training=el('details','mapa-command-training'),modeFields=el('div');training.open=draft.demo===true;training.append(el('summary','','Training settings'),modeFields);
        const mode = select(modeFields, 'Call mode', 'demo_mode', draft.demo === true ? 'training' : 'live', modes);
        mode.required = true;
        const modeNote = el('p', 'mapa-voice-note');
        const describeMode = () => { modeNote.textContent = mode.value === 'live' ? 'New call · not saved yet' : 'DEMO training · fictional caller and ambulances only'; };
        describeMode(); mode.addEventListener('change', describeMode); form.append(modeNote);
        const pilotSettings=el('details','mapa-command-training mapa-command-pilot'),pilotOption=checkbox(pilotSettings,'Supervised no-patient pilot using the approved live crew phones.');
        pilotOption.name='supervised_pilot';pilotOption.checked=draft.supervised_pilot===true;pilotSettings.open=pilotOption.checked;
        pilotSettings.insertBefore(el('summary','','Supervised crew dispatch pilot · no patient'),pilotSettings.children[0]);
        pilotSettings.append(el('p','mapa-voice-warning','Test only. This creates a MAPA record and may notify the scoped crew after a separate review. There is no patient, caller, WhatsApp link or hospital handover.'));
        inputs.caller_name = field(grid, 'Caller name', 'caller_name', draft.caller_name); inputs.caller_name.maxLength = 200;
        inputs.callback_number = field(grid, 'Caller phone / WhatsApp', 'callback_number', draft.callback_number, 'tel'); inputs.callback_number.maxLength = 40; inputs.callback_number.placeholder = '10 Indian digits or +country code';
        inputs.priority = select(grid, 'Emergency priority · operator decision', 'priority', draft.priority||draft.intake_priority||'routine', [['emergency','CRITICAL · Red alert'],['urgent','HIGH · Urgent'],['routine','NORMAL · Standard']]);
        inputs.vehicle_requirement = select(grid, 'Required capability', 'vehicle_requirement', draft.vehicle_requirement||'any', [['any','ANY AVAILABLE'],['bls','BLS · Basic life support'],['als','ALS · Advanced life support']]);
        inputs.pickup_address = field(grid, 'Pickup village / road / landmark', 'pickup_address', draft.pickup_address, 'textarea'); inputs.pickup_address.maxLength = 1000;
        inputs.reported_problem = field(grid, 'Short incident report · caller’s words', 'reported_problem', draft.reported_problem, 'textarea'); inputs.reported_problem.maxLength = 2000; inputs.reported_problem.required = true;
        inputs.latitude = field(grid, 'Pickup candidate latitude', 'latitude', draft.latitude, 'number'); inputs.latitude.step = 'any';
        inputs.longitude = field(grid, 'Pickup candidate longitude', 'longitude', draft.longitude, 'number'); inputs.longitude.step = 'any';
        const showCreationSlide=compactCreationFields(form,inputs);
        if(capabilities.supervised_pilot===true && session.role!=='operator')form.append(pilotSettings);
        if(capabilities.demo===true)form.append(training);
        const verified = checkbox(form, 'I have confirmed this exact pickup address and pin with the caller.'); verified.name='pickup_confirmed_at_creation';verified.checked=draft.pickup_confirmed_at_creation===true;
        const trackerConsent = checkbox(form, 'Caller agreed to receive a private WhatsApp tracking link.'); trackerConsent.name='tracker_consent';trackerConsent.checked=draft.tracker_consent===true;
        const trackerNote=el('p','mapa-voice-note','After saving, send the private tracker with caller permission. Review the caller-confirmed pickup before dispatch.');form.append(trackerNote);
        const updateTrackerMode = () => { trackerConsent.disabled=capabilities.caller_tracking!==true||mode.value!=='live'||pilotOption.checked; if(trackerConsent.disabled)trackerConsent.checked=false; };
        const applyPilotMode=()=>{
            const pilot=capabilities.supervised_pilot===true&&pilotOption.checked;
            if(pilot){mode.value='live';mode.disabled=true;inputs.caller_name.value=PILOT_CALLER;inputs.callback_number.value='';inputs.reported_problem.value=PILOT_REPORT;verified.checked=false;trackerConsent.checked=false;}
            else {mode.disabled=!!pending;if(inputs.caller_name.value===PILOT_CALLER)inputs.caller_name.value='';if(inputs.reported_problem.value===PILOT_REPORT)inputs.reported_problem.value='';}
            inputs.caller_name.readOnly=pilot;inputs.callback_number.disabled=pilot||!!pending;verified.disabled=pilot||!!pending;verified.parentElement.hidden=pilot;trackerConsent.parentElement.hidden=pilot;
            const patientSection=[...form.querySelectorAll('.mapa-command-create-options')].find(section=>section.textContent.includes('Patient & incident details'));if(patientSection)patientSection.hidden=pilot;
            trackerNote.textContent=pilot?'This test has no real caller or patient. Check the station point on the map. Saving does not send an offer; a separate scoped server review is required.':'After saving, send the private tracker with caller permission. Review the caller-confirmed pickup before dispatch.';
            modeNote.textContent=pilot?'SUPERVISED PILOT · NO PATIENT · live crew phones, no caller messaging':mode.value==='live'?'New call · not saved yet':'DEMO training · fictional caller and ambulances only';
            updateTrackerMode();
        };
        mode.addEventListener('change',()=>{if(mode.value!=='live')pilotOption.checked=false;applyPilotMode();});
        pilotOption.addEventListener('change',applyPilotMode);
        const remember = event => { if (['location_search', 'location_scope'].includes(event?.target?.name)) return; if(event?.target!==verified&&event?.target!==trackerConsent)verified.checked=false;dirty = true; manualEdit = { ...Object.fromEntries(Object.entries(inputs).map(([key, input]) => [key, input.value])), pickup_confirmed_at_creation:verified.checked,demo: mode.value !== 'live', supervised_pilot:pilotOption.checked&&capabilities.supervised_pilot===true, tracker_consent:trackerConsent.checked }; };
        form.addEventListener('input', remember); form.addEventListener('change', remember);
        pickupMap(form, inputs, remember);
        const actions=el('div','mapa-intake-submit-actions');
        const clear=button('Cancel',()=>{if(busy||pending)return;if(dirty&&!window.confirm('Discard this unsaved call?'))return;manualEdit=null;dirty=false;newCall=false;render();});
        const save = button('Save call', () => {}, true); save.type = 'submit'; save.disabled = !!pending; actions.append(clear,save); form.append(actions);
        for (const input of [mode, ...Object.values(inputs)]) input.disabled = !!pending;
        applyPilotMode();
        pilotOption.addEventListener('change',()=>{save.textContent=pilotOption.checked?'Save no-patient pilot call':'Save call';});
        if(pilotOption.checked)save.textContent='Save no-patient pilot call';
        form.addEventListener('submit', event => {
            event.preventDefault(); if (busy || pending) return;
            const pilot=pilotOption.checked&&capabilities.supervised_pilot===true;
            const values = { ...Object.fromEntries(Object.entries(inputs).map(([key, input]) => [key, input.value])), pickup_confirmed_at_creation:verified.checked,demo: mode.value !== 'live',supervised_pilot:pilot };
            const result = manualDraft(values);
            for (const input of Object.values(inputs)) input.removeAttribute('aria-invalid');pilotOption.removeAttribute('aria-invalid');
            if (!result.ok) { for (const key of Object.keys(result.fields)) (inputs[key]||verified).setAttribute('aria-invalid', 'true'); announce(Object.values(result.fields).join(' '), true); const first=Object.keys(result.fields)[0];showCreationSlide(['pickup_address','reported_problem','latitude','longitude'].includes(first)?'pickup':'caller');(inputs[first]||verified).focus(); return; }
            if (trackerConsent.checked && !result.data.callback_number) { announce('Enter the caller phone number before creating a WhatsApp tracking link.', true); showCreationSlide('caller');inputs.callback_number.focus(); return; }
            mutate('/calls/manual', result.data, pilot?'Supervised no-patient pilot saved. The exact test call and crew must be scoped before operator review starts offers.':'Call saved for operator review. Confirm the pickup with the caller before dispatch.', false, pilot?false:trackerConsent.checked);
        });
        const desk = callWorkbench(parent); desk.main.append(form);
        const summary = el('section', 'mapa-intake-summary');
        const summaryHeading=el('h4','','Dispatch & caller tracking'),summaryNote=el('p','mapa-voice-note','The private tracker appears below the saved call when permission and a valid phone are recorded. Choose the destination during review.');summary.append(summaryHeading,summaryNote);
        const details = el('p'); summary.append(details);
        const updateSummary = () => { const pilot=pilotOption.checked&&capabilities.supervised_pilot===true;summaryHeading.textContent=pilot?'Supervised no-patient pilot':'Dispatch & caller tracking';summaryNote.textContent=pilot?'This pilot uses only the approved crew phones. No caller tracker or WhatsApp message is created. Choose the test destination during review.':'The private tracker appears below the saved call when permission and a valid phone are recorded. Choose the destination during review.';details.textContent=pilot?[PILOT_CALLER,inputs.pickup_address.value||'Test point not entered'].join(' · '):[inputs.caller_name.value || 'Caller name not entered', inputs.callback_number.value || 'Callback unknown', inputs.pickup_address.value || 'Pickup not entered'].join(' · '); };
        form.addEventListener('input', updateSummary); form.addEventListener('change', updateSummary); updateSummary();
        desk.rail.append(summary);
    }
    function pickupMap(parent, inputs, changed, editable = true) {
        const box = el('section', 'mapa-voice-pickup-map'+(editable?'':' is-readonly')); box.append(el('h4', '', 'Locate the pickup'));
        box.append(el('p', 'mapa-voice-note', 'Keep patient details out of map search. Verify the pickup before saving.'));
        const areaRow = el('label', 'mapa-voice-field'), area = el('select'); area.name = 'location_scope'; area.disabled = !!pending;
        areaRow.append(el('span', '', 'Search area'));
        for (const [value, label] of [['asr', 'ASR district and nearby areas'], ['india', 'Anywhere in India']]) { const option = el('option', '', label); option.value = value; area.append(option); }
        area.value = 'asr'; areaRow.append(area);
        const searchRow = el('div', 'mapa-voice-search-row'), query = field(searchRow, 'Search location', 'location_search', ''); query.maxLength = 160; query.placeholder = 'ITDA Paderu, village, hospital or landmark'; query.autocomplete = 'off';
        const searchButton = button('Search map', () => searchLocations(true)), results = el('div', 'mapa-voice-search-results'), mapNode = el('div', 'mapa-voice-map');
        searchButton.disabled = !!pending; query.disabled = !!pending; searchRow.append(searchButton); results.setAttribute('aria-live', 'polite'); mapNode.setAttribute('aria-label', 'Pickup map. Click anywhere to select a candidate; coordinates can also be entered in the form.');
        const googleLink = el('a', 'mapa-voice-button', 'Open Google Maps ↗');
        googleLink.href = googleSearchUrl(''); googleLink.target = '_blank'; googleLink.rel = 'noopener noreferrer';
        googleLink.setAttribute('aria-label', 'Open Google Maps in a new tab');
        const googleNote = el('p', 'mapa-voice-note', 'Google Maps opens in a new tab. Copy the exact pin’s latitude and longitude and paste them below.');
        const pasteRow = el('div', 'mapa-voice-search-row'), pasted = field(pasteRow, 'Paste Google Maps coordinates', 'pickup_pasted_coordinates', '');
        pasted.placeholder = 'Latitude, longitude'; pasted.maxLength = 96; pasted.autocomplete = 'off'; pasted.disabled = !!pending;
        const pasteStatus = el('p', 'mapa-voice-note'); pasteStatus.setAttribute('aria-live', 'polite');
        function applyCoordinates() {
            if (removed || busy || pending) return;
            const point = pastedPickupPoint(pasted.value);
            if (!point) { pasteStatus.textContent = 'Paste a decimal latitude, longitude pair copied from the pin, not a Maps link. The current pickup has not changed.'; return; }
            selectPoint(point); pasteStatus.textContent = 'Coordinates added to this draft. Verify the address and exact entrance before saving.';
        }
        const applyPin = button('Use these coordinates', applyCoordinates);
        pasted.addEventListener('keydown', event => { if (event.key === 'Enter') { event.preventDefault(); applyCoordinates(); } });
        applyPin.disabled = !!pending; pasteRow.append(applyPin);
        const providerStatus = el('p', 'mapa-voice-note');
        const configured = pickupMapConfig.google_maps_configured === true;
        providerStatus.textContent = configured ? 'Loading Google Maps…' : 'Search uses OpenStreetMap (Photon). Choose a result to fill the pickup draft.';
        const mapOptions=el('details','mapa-voice-map-options');mapOptions.append(el('summary','','Search area & Google Maps pin'),areaRow,googleLink,googleNote,pasteRow,pasteStatus);
        box.append(searchRow, results, mapNode, providerStatus, mapOptions); parent.append(box);
        let map = null, marker = null, googleMap = null, googleMarker = null, googleClick = null, googlePlaces = null, fleetMarkers=[];
        let searchVersion = 0, searchTimer = null, pointVersion = 0, pointTimer = null, removed = false, mapReady = null, mode = 'local';
        let autoAddress = inputs.pickup_address.value.startsWith('Pinned pickup · ') ? inputs.pickup_address.value : '';
        const summary = el('div', 'mapa-voice-pin-summary'); summary.setAttribute('aria-live', 'polite'); box.append(summary);
        summary.textContent = 'Choose a search result, tap the map, or drag the pickup pin. Coordinates and pickup description update here.';
        const mapStatus = el('p', 'mapa-voice-note mapa-voice-map-status', 'District overview only; this is not a surveyed boundary. Verify the exact pickup or test point before review.'); box.append(mapStatus);
        function pointText(point) { return `${point.latitude.toFixed(6)}, ${point.longitude.toFixed(6)}`; }
        function describePoint(point, context = '') {
            const label = `Pinned pickup · ${context ? context.slice(0, 880) + ' · ' : ''}${pointText(point)}`;
            if (!inputs.pickup_address.value.trim() || inputs.pickup_address.value === autoAddress) {
                inputs.pickup_address.value = label; autoAddress = label;
            }
            summary.textContent = `Pickup pin: ${pointText(point)}${context ? ' · ' + context : ''}. Added to this call draft; save to put it in the call queue.`;
        }
        function selectPoint(value, useAddress = false, zoom = true) {
            if(!editable)return;
            const point = pickupCandidate(value);
            if (!point || removed) return;
            if (busy || pending) { showPoint(pickupCandidate({ latitude: inputs.latitude.value, longitude: inputs.longitude.value })); return; }
            clearTimeout(pointTimer); const version = ++pointVersion;
            inputs.latitude.value = point.latitude.toFixed(6); inputs.longitude.value = point.longitude.toFixed(6);
            if (useAddress && (point.address || point.label)) {
                // Explicit selection replaces the previous address as well as its pin.
                // Passive context responses still preserve the operator's later edits.
                inputs.pickup_address.value = point.address || point.label;
                autoAddress = inputs.pickup_address.value;
                if (inputs.village) inputs.village.value = point.locality;
                // Provider county/district names do not reliably identify an Indian mandal.
                if (inputs.mandal) inputs.mandal.value = '';
                summary.textContent = `Pickup filled: ${autoAddress} · ${pointText(point)}. Check the entrance and mandal before review.`;
            } else describePoint(point);
            changed(); showPoint(point, zoom);
            mapStatus.textContent = 'Pin selected. Drag it to the exact entrance and verify the pickup or test point and access directions.';
            if (useAddress && (point.address || point.label)) return;
            pointTimer = setTimeout(async () => {
                pointTimer = null;
                try {
                    const response = await request('/locations/nearby', { body: JSON.stringify({ latitude: point.latitude, longitude: point.longitude }) });
                    if (removed || version !== pointVersion || !box.isConnected || busy || pending || inputs.latitude.value !== point.latitude.toFixed(6) || inputs.longitude.value !== point.longitude.toFixed(6)) return;
                    const near = response.nearest;
                    const context = near && typeof near.name === 'string' && Number.isFinite(near.distance_m) && near.distance_m >= 0 ? `Near ${near.name} (${Math.round(near.distance_m)} m from mapped settlement centre; verify exact point)` : 'No nearby place name available; verify village and entrance';
                    describePoint(point, context); changed();
                } catch (_) {
                    if (!removed && version === pointVersion && box.isConnected) mapStatus.textContent = 'Pickup coordinates captured. Nearby place details are unavailable; enter the address and verify the entrance.';
                }
            }, 350);
        }
        function showPoint(point, zoom = false) {
            if (!point) {
                if (marker) { marker.remove(); marker = null; }
                if (googleMarker) { googleMarker.setMap(null); googleMarker = null; }
                return;
            }
            if (googleMap) {
                const position = { lat: point.latitude, lng: point.longitude };
                if (!googleMarker) {
                    googleMarker = new window.google.maps.Circle({ map: googleMap, center: position, radius: 25, draggable: editable, strokeColor: '#064e3b', fillColor: '#2dd4bf', fillOpacity: 1, strokeWeight: 3 });
                    googleMarker.addListener('dragend', () => { const p = googleMarker.getCenter(); selectPoint({ latitude: p.lat(), longitude: p.lng() }, false, false); });
                } else googleMarker.setCenter(position);
                if (zoom) { googleMap.setCenter(position); googleMap.setZoom(Math.max(googleMap.getZoom() || 10, 16)); }
                return;
            }
            if (!map) return;
            if (!marker) {
                marker = window.L.marker([point.latitude, point.longitude], { draggable: editable, title: editable?'Pickup pin — drag to adjust':'Saved pickup', alt: 'Pickup pin', icon: window.L.divIcon({ className: 'mapa-pickup-pin', html: '<span aria-hidden="true"></span>', iconSize: [30, 38], iconAnchor: [15, 38] }) }).addTo(map);
                marker.on('dragend', () => { const p = marker.getLatLng(); selectPoint({ latitude: p.lat, longitude: p.lng }, false, false); });
            } else marker.setLatLng([point.latitude, point.longitude]);
            if (zoom) map.setView([point.latitude, point.longitude], Math.max(map.getZoom(), 14));
        }
        function showFleet(reframe=true){
            for(const item of fleetMarkers){if(item.setMap)item.setMap(null);else item.remove?.();}fleetMarkers=[];
            const points=[];
            let remoteStalePositions=0;
            const fleet=[];
            for(const row of (deskSnapshot?.fleet||[]).slice(0,200)){
                let distinct=false;
                for(const [source,fix] of [['driver_phone_gps',row.crew_phone_location],['hardware_gps',row.ambulance_gps_location]])if(fix&&pickupCandidate(fix)){
                    fleet.push({...row,...fix,vehicle_registration:row.registration,name:row.driver_name,location_recorded_at:fix.recorded_at,location_source:source,gps_stale:fix.stale,source_protocol:fix.protocol,position_quality:fix.quality,accuracy_m:fix.accuracy_m});distinct=true;
                }
                if(!distinct&&['crew_phone','hardware_gps'].includes(row.gps_source))fleet.push({...row,vehicle_registration:row.registration,name:row.driver_name,location_recorded_at:row.gps_recorded_at,location_source:row.gps_source==='hardware_gps'?'hardware_gps':'driver_phone_gps'});
            }
            const rows=new Map(fleet.map(row=>[row.vehicle_id+':'+row.location_source,row]));for(const row of recommendations?.ranked||[])rows.set(row.vehicle_id+':driver_phone_gps',row);
            const currentDirectory=ambulanceDirectory(selected||{},candidates,unavailable,'',false);
            for(const row of [...rows.values()].slice(0,400)){
                const point=pickupCandidate(row),time=new Date(row.location_recorded_at).getTime(),now=Number.isFinite(deskSnapshot?.server_time)?deskSnapshot.server_time+(Date.now()-(deskLoadedAt||Date.now())):Date.now();
                if(!point||!Number.isFinite(time)||time<=0||time>now+30000||!['driver_phone_gps','hardware_gps'].includes(row.location_source))continue;
                const stale=row.gps_stale===true||now-time>180000,color=stale?'#b45309':row.location_source==='hardware_gps'?'#1d4ed8':'#047857',fill=stale?'#fbbf24':row.location_source==='hardware_gps'?'#60a5fa':'#34d399';
                // Keep recent out-of-area fixes visible. A distant stale fix is
                // historical evidence, not a useful dispatch-map position.
                if(!showDispatchPosition(point,stale)){remoteStalePositions++;continue;}
                const mapping=crewMapping(withCurrentCrewMapping(row,currentDirectory));
                const text=[mapping.summary,...mapping.notes,row.location_source==='hardware_gps'?'Ambulance device GPS':'Driver phone GPS',date(row.location_recorded_at),stale?'Stale last reported position':'Recent reported position',gpsQuality(row)].filter(Boolean).join(' · ');
                if(map&&window.L?.circleMarker){const pin=window.L.circleMarker([point.latitude,point.longitude],{radius:8,color,fillColor:fill,fillOpacity:stale?.6:1}).addTo(map);pin.bindTooltip?.(el('span','',text));fleetMarkers.push(pin);points.push([point.latitude,point.longitude]);}
                else if(googleMap&&window.google?.maps?.Circle){fleetMarkers.push(new window.google.maps.Circle({map:googleMap,center:{lat:point.latitude,lng:point.longitude},radius:30,strokeColor:color,fillColor:fill,fillOpacity:stale?.6:1,clickable:false}));}
            }
            if(reframe&&points.length&&map){const pickup=pickupCandidate({latitude:inputs.latitude.value,longitude:inputs.longitude.value});if(pickup)points.push([pickup.latitude,pickup.longitude]);map.fitBounds(points,{padding:[20,20],maxZoom:14});}
            if(fleetMarkers.length||remoteStalePositions)mapStatus.textContent=fleetMarkers.length+' reported positions shown'+(remoteStalePositions?' · '+remoteStalePositions+' distant stale positions omitted from this dispatch map; see vehicle GPS details':'')+'. Blue is ambulance GPS, green is crew phone GPS, amber is stale. Both sources have separate timestamps. Displayed positions do not establish dispatch eligibility. The pickup pin is separate.';
        }
        async function searchLocations(online = false) {
            clearTimeout(searchTimer); searchTimer = null;
            const version = ++searchVersion, text = query.value.trim(), scope = area.value === 'india' ? 'india' : 'asr';
            if (removed || !box.isConnected || busy || pending) return;
            if (!locationQuery(text)) { results.replaceChildren(el('p', 'mapa-voice-note', 'Enter 3–160 characters of a village, road or landmark. Do not enter contact or patient details.')); return; }
            searchButton.disabled = true; results.replaceChildren(el('p', 'mapa-voice-note', 'Searching locations…'));
            try {
                if (mapReady) await mapReady;
                if (removed || version !== searchVersion || query.value.trim() !== text || !box.isConnected) return;
                let response;
                if (mode === 'google' && googlePlaces) {
                    const googleResult = await googlePlaces.searchByText({
                        textQuery: text + (scope === 'india' ? ', India' : ', Alluri Sitharama Raju district, Andhra Pradesh, India'),
                        fields: ['displayName', 'formattedAddress', 'location'], maxResultCount: 8, region: 'in', language: 'en',
                        ...(scope === 'asr' ? { locationBias: { south: ASR_BOUNDS[0][0], west: ASR_BOUNDS[0][1], north: ASR_BOUNDS[1][0], east: ASR_BOUNDS[1][1] } } : {})
                    });
                    response = { results: (googleResult.places || []).map(googlePlaceCandidate).filter(Boolean), attribution: 'Google Maps · verify the exact pickup or test point' };
                } else response = await request(`/locations?q=${encodeURIComponent(text)}${online ? '&online=1' : ''}${scope === 'india' ? '&scope=india' : ''}`);
                if (removed || version !== searchVersion || query.value.trim() !== text || !box.isConnected) return;
                results.replaceChildren();
                const items = Array.isArray(response.results) ? response.results.map(pickupCandidate).filter(Boolean).slice(0, 40) : [];
                const directory = Array.isArray(response.directory_results) ? response.directory_results.filter(row => typeof row.address === 'string' && typeof row.name === 'string').slice(0, 8) : [];
                if (!items.length && !directory.length) results.append(el('p', 'mapa-voice-note', online ? 'No matching place in the available map data. Try its village or town name, widen Search area, or select a verified pin.' : 'No local match. Press Search map to look for more landmarks online.'));
                for (const item of items) { const pick = button(item.label || `${item.latitude}, ${item.longitude}`, () => selectPoint(item, true)); pick.classList.add('mapa-voice-location-result'); results.append(pick); }
                for (const row of directory) {
                    const pick = button(`${row.name} · ${row.address} · address only — select a pin`, () => {
                        if (busy || pending || removed) return;
                        clearTimeout(pointTimer); pointVersion++; autoAddress = ''; inputs.pickup_address.value = row.address;
                        if (inputs.village) inputs.village.value = '';
                        if (inputs.mandal) inputs.mandal.value = '';
                        summary.textContent = 'Address selected. Tap the map to add its exact pickup pin.';
                        // Never keep a previous pickup pin attached to a different address.
                        inputs.latitude.value = ''; inputs.longitude.value = '';
                        if (marker) { marker.remove(); marker = null; }
                        if (googleMarker) { googleMarker.setMap(null); googleMarker = null; }
                        changed(); mapStatus.textContent = 'Address selected from the district directory. Exact coordinates are not supplied: locate and verify the entrance.';
                    });
                    pick.classList.add('mapa-voice-location-result'); results.append(pick);
                }
                if (response.directory_attribution) results.append(el('small', 'mapa-voice-muted', string(response.directory_attribution)));
                if (response.local_status === 'unavailable') results.append(el('p', 'mapa-voice-note', 'Some local place lists are unavailable. Google Maps, any online results below and manual pin selection remain available.'));
                if (response.local_map_attribution || response.online_attribution) {
                    const credit = el('a', 'mapa-voice-muted', string(response.local_map_attribution || response.online_attribution)); credit.href = 'https://www.openstreetmap.org/copyright'; credit.target = '_blank'; credit.rel = 'noopener noreferrer'; results.append(credit);
                }
                if (Number.isInteger(response.local_match_count) && Number.isInteger(response.local_shown_count) && response.local_match_count > response.local_shown_count) results.append(el('p', 'mapa-voice-note', `Showing ${response.local_shown_count} of ${response.local_match_count} local matches. Add the village, landmark name or station code to narrow the search.`));
                if (['unavailable', 'busy', 'disabled'].includes(response.online_status)) results.append(el('p', 'mapa-voice-note', response.online_status === 'busy' ? 'Online search is busy. Local results are shown; wait a moment and press Search map again.' : 'Online search is temporarily unavailable. Local results and manual pickup selection remain available.'));
                if (response.attribution) results.append(el('small', 'mapa-voice-muted', string(response.attribution)));
            } catch (_) { if (!removed && version === searchVersion && query.value.trim() === text && box.isConnected) results.replaceChildren(el('p', 'mapa-voice-note', 'Place search is temporarily unavailable. You can still click or drag a pin on the map, paste coordinates, or open Google Maps above. Your selected pickup is unchanged.')); }
            finally { if (!removed && version === searchVersion) searchButton.disabled = !!pending; }
        }
        query.addEventListener('input', event => {
            googleLink.href = googleSearchUrl(query.value);
            // Invalidate immediately, including requests already in flight for the old text.
            clearTimeout(searchTimer); searchTimer = null; searchVersion++; results.replaceChildren(); searchButton.disabled = !!pending;
            if (removed || busy || pending || event.isComposing || query.value.trim().length < 3) return;
            searchTimer = setTimeout(() => { searchTimer = null; searchLocations(); }, 450);
        });
        query.addEventListener('keydown', event => { if (event.key === 'Enter') { event.preventDefault(); searchLocations(true); } });
        area.addEventListener('change', () => {
            clearTimeout(searchTimer); searchTimer = null; searchVersion++; results.replaceChildren(); searchButton.disabled = !!pending;
            if (removed || busy || pending) return;
            query.placeholder = area.value === 'india' ? 'Village, town, hospital or landmark in India' : 'ITDA Paderu, village, hospital or landmark';
            if (locationQuery(query.value)) searchLocations(true);
        });
        function localMap() {
            if (removed || !mapNode.isConnected) return;
            if (map) return;
            if (googleClick) googleClick.remove(); googleClick = null;
            if (googleMarker) googleMarker.setMap(null); googleMarker = null;
            if (googleMap) window.google.maps.event.clearInstanceListeners(googleMap); googleMap = null;
            mode = 'local'; googlePlaces = null; mapNode.replaceChildren();
            if (!window.L?.map) { mapNode.replaceChildren(el('p', 'mapa-voice-warning', 'Map display is unavailable. Location search and manual coordinates remain available.')); return; }
            map = window.L.map(mapNode, { scrollWheelZoom: false }).fitBounds(ASR_BOUNDS, { padding: [14, 14] });
            window.L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '© <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap contributors</a>' }).addTo(map);
            map.on('click', event => selectPoint({ latitude: event.latlng.lat, longitude: event.latlng.lng }));
            const point = pickupCandidate({ latitude: inputs.latitude.value, longitude: inputs.longitude.value }); showPoint(point, !!point); showFleet(); map.invalidateSize();
        }
        function googleFailure() {
            if (removed) return;
            searchVersion++; results.replaceChildren(); searchButton.disabled = !!pending;
            searchButton.textContent = 'Search map';
            providerStatus.textContent = 'Google Maps could not be authorized. ASR directory search and the overview map remain available; ask your administrator to check the Maps key, APIs and billing.';
            localMap();
        }
        requestAnimationFrame(() => {
            if (!configured) { localMap(); return; }
            googleFailureListeners.add(googleFailure);
            mapReady = (async () => {
                try {
                    const maps = await loadGoogleMaps(pickupMapConfig.google_maps_api_key);
                    const [mapLibrary, placesLibrary] = await Promise.all([maps.importLibrary('maps'), maps.importLibrary('places')]);
                    if (removed || !mapNode.isConnected) return;
                    if (googleAuthFailed) throw new Error('Google authorization failed.');
                    if (map) { map.remove(); map = null; marker = null; }
                    mapNode.replaceChildren();
                    googleMap = new mapLibrary.Map(mapNode, { center: { lat: 18.07776, lng: 82.66037 }, zoom: 10, mapTypeId: 'roadmap', streetViewControl: false, fullscreenControl: true, gestureHandling: 'cooperative' });
                    googleMap.fitBounds({ south: ASR_BOUNDS[0][0], west: ASR_BOUNDS[0][1], north: ASR_BOUNDS[1][0], east: ASR_BOUNDS[1][1] });
                    googleClick = googleMap.addListener('click', event => { if (event.latLng) selectPoint({ latitude: event.latLng.lat(), longitude: event.latLng.lng() }); });
                    googlePlaces = placesLibrary.Place; mode = 'google';
                    providerStatus.textContent = 'Google Maps · search villages, roads, hospitals and landmarks. Location search terms are sent to Google; call and patient fields are not included.';
                    searchButton.textContent = 'Search Google Maps';
                    const point = pickupCandidate({ latitude: inputs.latitude.value, longitude: inputs.longitude.value }); showPoint(point, !!point); showFleet();
                } catch (_) { googleFailure(); }
            })();
        });
        for (const input of [inputs.latitude, inputs.longitude]) input.addEventListener('change', () => {
            const point = pickupCandidate({ latitude: inputs.latitude.value, longitude: inputs.longitude.value });
            if (point) selectPoint(point);
            else { clearTimeout(pointTimer); pointVersion++; showPoint(null); summary.textContent = 'Enter both valid coordinates or select a pickup pin.'; changed(); }
        });
        const existingPoint = pickupCandidate({ latitude: inputs.latitude.value, longitude: inputs.longitude.value });
        if (existingPoint) { summary.textContent = `Pickup pin: ${pointText(existingPoint)}. Verify the exact pickup or test point before dispatch.`; mapStatus.textContent = 'Saved pickup pin shown. Drag it to correct the location; any change requires a new operator review.'; }
        refreshPickupMap=()=>{if(box.isConnected&&!removed)showFleet(false);};
        removePickupMap = () => {
            removed = true; searchVersion++; pointVersion++; clearTimeout(pointTimer); clearTimeout(searchTimer); searchTimer = null; googleFailureListeners.delete(googleFailure);
            if(refreshPickupMap)refreshPickupMap=null;
            if (map) map.remove(); if (googleClick) googleClick.remove(); if (googleMarker) googleMarker.setMap(null);
            if (googleMap) window.google.maps.event.clearInstanceListeners(googleMap);
            for(const item of fleetMarkers){if(item.setMap)item.setMap(null);else item.remove?.();}fleetMarkers=[];
        };
    }

    function openCrewAdmin(view) {
        if (busy || pending) return;
        if (dirty && !window.confirm('Discard unsaved call edits and open crew records?')) return;
        dirty = false; editDraft = null; manualEdit = null; activeView = view; clearTimeout(pollTimer);
        if (view === 'enrollments') loadEnrollments(); else loadTripReports();
    }
    async function loadEnrollments() {
        if (busy || pending) return; lock(true); announce('Loading crew registrations.');
        try {
            const rows = await request('/enrollments', { native: true });
            const options = await request('/enrollments/options', { native: true });
            if (rows.contract !== 'mapa-native-mobile-v1' || options.contract !== 'mapa-native-mobile-v1') throw new Error('This server does not support crew registration review.');
            enrollments = enrollmentRows(rows.enrollments); enrollmentLoaded = true;
            enrollmentOptions = { vehicles: Array.isArray(options.vehicles) ? options.vehicles.filter(v => safeId(v.id)) : [], places: Array.isArray(options.places) ? options.places.filter(v => safeId(v.id)) : [] };
            if (requestedCrewDriver) { const match = enrollments.find(row => row.driver_id === requestedCrewDriver); enrollmentSelected = match?.id || ''; requestedCrewDriver = null; }
            if (enrollmentSelected && !enrollments.some(row => row.id === enrollmentSelected)) enrollmentSelected = '';
            announce('Crew registration records refreshed. Pending crew cannot receive dispatches.');
        } catch (error) { enrollments = []; enrollmentOptions = { vehicles: [], places: [] }; enrollmentLoaded = false; announce(error.message, true); }
        finally { lock(false); render(); }
    }
    function privatePhoto(parent, id, label) {
        const path = privateMediaPath(id); if (!path) { parent.append(el('p', 'mapa-voice-note', 'No photograph is available.')); return; }
        const box = el('section', 'mapa-crew-photo'); const open = button(label, async () => {
            if (busy || pending || !session || session.expires <= Date.now()) { announce('Sign in again to view this private photograph.', true); return; }
            const ownerEpoch = epoch, rendered = mediaEpoch, token = session.token, nativeTransport = session.nativeReview ? nativeCrewTransport : null; open.disabled = true;
            const controller = new AbortController(), timeout = setTimeout(() => controller.abort(), 25000);
            try {
                let blob;
                if (nativeTransport) {
                    if (!nativeTransport.valid()) throw new Error('The MAPA console session ended.');
                    blob = await nativeTransport.media(path);
                    if (!nativeTransport.valid() || nativeCrewTransport !== nativeTransport) return;
                } else {
                    const response = await fetch('/mapa/mobile/v1' + path, { headers: { Authorization: `Bearer ${token}`, Accept: 'image/jpeg' }, credentials: 'omit', cache: 'no-store', redirect: 'error', signal: controller.signal });
                    if (!response.ok) { if (response.status === 401 && ownerEpoch === epoch) { expire(); render(); } throw new Error('Private photograph could not be loaded. Refresh the current record and sign-in if needed.'); }
                    if (!(response.headers.get('Content-Type') || '').startsWith('image/jpeg')) throw new Error('The server did not return a supported photograph.');
                    blob = await response.blob();
                }
                if (!(blob instanceof Blob) || !blob.type.startsWith('image/jpeg') || blob.size > 3 * 1024 * 1024 || !blob.size) throw new Error('Photograph exceeds the supported limit or is not JPEG.');
                if (ownerEpoch !== epoch || rendered !== mediaEpoch || !box.isConnected || session?.token !== token || session.expires <= Date.now()) return;
                const url = URL.createObjectURL(blob); mediaUrls.add(url); const picture = el('img'); picture.src = url; picture.alt = label; picture.referrerPolicy = 'no-referrer'; box.replaceChildren(picture);
            } catch (error) { if (ownerEpoch === epoch && rendered === mediaEpoch && box.isConnected) { box.append(el('p', 'mapa-voice-warning', error.message)); open.disabled = false; } }
            finally { clearTimeout(timeout); }
        }); box.append(open); parent.append(box);
    }
    function recordFields(parent, rows) {
        const dl = el('dl', 'mapa-crew-record');
        for (const [label, value] of rows) { dl.append(el('dt', '', label), el('dd', '', value == null || value === '' ? 'Not provided' : value)); }
        parent.append(dl);
    }
    function renderEnrollments(parent) {
        const section = el('section'); section.append(el('h3', '', 'Crew registration'), el('p', 'mapa-voice-note', 'Drivers register on their own phone with a selfie and actual ambulance/base details. Verify identity and the ambulance before approving the connection. A submitted selfie is a photo for staff review.'));
        const reload = button('Refresh registrations', loadEnrollments); reload.disabled = !!pending; section.append(reload); parent.append(section);
        if (!enrollmentLoaded) { section.append(el('p', 'mapa-voice-warning', 'Registration review has not loaded. No crew changes are available.')); return; }
        if (!enrollments.length) { section.append(el('p', 'mapa-voice-note', 'No crew registrations have been submitted.')); return; }
        const layout = el('div', 'mapa-voice-layout mapa-crew-review'), list = el('aside', 'mapa-voice-list'), detail = el('section', 'mapa-voice-detail');
        list.setAttribute('aria-label', 'Crew registrations');
        for (const row of enrollments) { const links = enrollmentLinkSummary(row); const choose = button('', () => { enrollmentSelected = row.id; render(); }); choose.disabled = !!pending; choose.className += ' mapa-voice-call'; choose.append(el('strong', '', string(row.name) || row.id), badge(links.label, row.status === 'approved' && !links.missing.length ? 'is-success' : ''), el('small', '', string(row.ambulance_registration)), el('small', '', date(row.created_at))); list.append(choose); }
        layout.append(list, detail); section.append(layout);
        const row = enrollments.find(row => row.id === enrollmentSelected);
        if (!row) { detail.append(el('p', 'mapa-voice-note', 'Select a registration to review the driver, photograph and requested ambulance. Showing the latest 100 records.')); return; }
        const links = enrollmentLinkSummary(row);
        detail.append(el('h4', '', string(row.name) || 'Crew member'), badge(links.label, row.status === 'approved' && !links.missing.length ? 'is-success' : ''));
        if (links.warning) detail.append(el('p', links.missing.length ? 'mapa-voice-warning' : 'mapa-voice-note', links.warning));
        recordFields(detail, [['Contact email (self-reported)', row.email], ['Phone', row.phone], ['Requested ambulance registration', row.ambulance_registration], ['Requested ambulance name', row.ambulance_name], ['Reported hospital / base', row.hospital_name], ['Submitted', date(row.created_at)], ['Device location at registration', pickupCandidate(row) ? `${Number(row.latitude).toFixed(6)}, ${Number(row.longitude).toFixed(6)}` : 'Unavailable'], ['Accuracy', Number.isFinite(Number(row.accuracy_m)) && row.accuracy_m != null ? `±${Number(row.accuracy_m).toFixed(0)} m` : 'Unavailable'], ['Location fix time', row.recorded_at ? date(row.recorded_at) : 'Unavailable']]);
        privatePhoto(detail, row.selfie_media_id, 'View private registration selfie');
        if (row.status === 'pending') {
            const recordsMissing = !enrollmentOptions.vehicles.length || !enrollmentOptions.places.length;
            if (recordsMissing) detail.append(el('p', 'mapa-voice-warning', 'Add the actual ambulance and hospital/base below, then select them to approve this driver. Each saved record also appears in MAPA Operations.'));
            renderResourceEditor(detail, row);
            const chosen = enrollmentSelections.get(row.id) || {};
            const form = el('form'), grid = el('div', 'mapa-voice-form-grid');
            const matches = Array.isArray(row.crew_matches) ? row.crew_matches : [];
            detail.append(el('p', 'mapa-voice-note', matches.length ? 'Possible existing crew found. Compare the private selfie, name and phone before linking. A name match alone does not establish identity.' : 'No matching MAPA crew found. Approval will create a new crew record.'));
            const crew = select(grid, 'Link to existing crew, or create new', 'enrollment_driver', chosen.driver_id || '', [['new', 'Create a new crew record'], ...matches.map(v => [v.id, `${string(v.name)} · ${string(v.phone)} · matches ${(v.matched_on || []).join(', ')}`])]);
            const differentPerson = matches.length ? checkbox(form, 'I checked the matches and this is a different person requiring a new crew record.') : null;
            const vehicle = select(grid, 'Verified ambulance', 'enrollment_vehicle', chosen.vehicle_id || '', enrollmentOptions.vehicles.map(v => [v.id, [string(v.registration), string(v.name)].filter(Boolean).join(' · ') || v.id]));
            const place = select(grid, 'Verified hospital / base', 'enrollment_place', chosen.place_id || '', enrollmentOptions.places.map(v => [v.id, string(v.name) || v.id]));
            for (const input of [vehicle, place, crew]) input.addEventListener('change', () => enrollmentSelections.set(row.id, { vehicle_id: vehicle.value, place_id: place.value, driver_id: crew.value }));
            form.append(grid); const confirmed = checkbox(form, 'I verified this driver’s identity, ambulance and hospital/base assignment.');
            const approve = button('Approve crew connection', () => {}, true); approve.type = 'submit'; approve.disabled = !!pending || recordsMissing; form.append(approve);
            form.addEventListener('submit', event => { event.preventDefault(); const draft = crewApprovalDraft(row, { vehicle_id: vehicle.value, place_id: place.value, driver_id: crew.value !== 'new' ? crew.value : '', create_new_crew: !matches.length || (crew.value === 'new' && differentPerson.checked), identity_confirmed: confirmed.checked }, enrollmentOptions); if (!draft.ok) { announce(Object.values(draft.fields).join(' '), true); return; } mutateEnrollment(row, 'approve', draft.data, 'Crew connection approved. The phone must receive the approval before starting duty.'); });
            detail.append(form);
            const reason = field(detail, 'Reason if rejecting this registration', 'enrollment_reason', '', 'textarea'); reason.maxLength = 500;
            const reject = button('Reject registration', () => { if (!reason.value.trim()) { announce('Enter the reason for rejection.', true); return; } mutateEnrollment(row, 'reject', { expected_revision: row.revision, reason: reason.value.trim() }, 'Crew registration rejected.'); }); reject.disabled = !!pending; detail.append(reject);
        } else if (row.status === 'approved') {
            const ambulanceTile=el('section','mapa-voice-result');ambulanceTile.append(el('h4','','Ambulance & assigned driver location'));
            recordFields(ambulanceTile, [['Registered ambulance', row.ambulance_registration], ['Registered crew', row.name]]);
            for (const [key, label] of [['driver_phone', 'Driver phone GPS'], ['ambulance_gps', 'Ambulance GPS device']]) {
                const fix = row.locations?.[key];
                recordFields(ambulanceTile, [[label, fix && pickupCandidate(fix) ? `${Number(fix.latitude).toFixed(6)}, ${Number(fix.longitude).toFixed(6)} · ${fix.stale ? 'Stale' : 'Recent'} · ${date(fix.recorded_at)}` : 'No GPS report received']]);
                if(fix&&pickupCandidate(fix))recordFields(ambulanceTile, [[label+' quality',gpsQuality(fix)]]);
            }
            detail.append(ambulanceTile);
            if (session.nativeReview && safeId(row.driver_id) && nativeCrewTransport?.openDriver) detail.append(button('Open driver in MAPA', () => { if (!pending && nativeCrewTransport.valid()) { dialog.close(); nativeCrewTransport.openDriver(row.driver_id); } }));
            recordFields(detail, [['Linked driver', row.driver_id], ['Linked ambulance', row.vehicle_id], ['Linked hospital / base', row.place_id], ['Approved', date(row.approved_at)]]);
            const reason = field(detail, 'Reason for revoking this phone connection', 'enrollment_revoke_reason', '', 'textarea'); reason.maxLength = 500;
            const revoke = button('Revoke phone connection', () => { if (!reason.value.trim()) { announce('Enter the reason for revocation.', true); return; } if (window.confirm('Revoke this crew phone connection? It will require a new approved connection. This does not complete an active trip.')) mutateEnrollment(row, 'revoke', { expected_revision: row.revision, reason: reason.value.trim() }, 'Crew phone connection revoked.'); }); revoke.disabled = !!pending; detail.append(revoke);
        } else if (row.review_reason) detail.append(el('p', 'mapa-voice-note', `Review reason: ${string(row.review_reason)}`));
    }
    function renderResourceEditor(parent, row) {
        const actions = el('div', 'mapa-voice-actions mapa-crew-add-actions');
        for (const [kind, label] of [['ambulance', 'Add ambulance'], ['hospital', 'Add hospital / base']]) {
            const add = button(label, () => {
                if (busy || pending) return;
                if (resourceEditor && (resourceEditor.id !== row.id || resourceEditor.kind !== kind) && !window.confirm('Discard the unsaved resource form?')) return;
                if (!resourceEditor || resourceEditor.id !== row.id || resourceEditor.kind !== kind) resourceEditor = { id: row.id, kind, values: kind === 'ambulance'
                    ? { name: string(row.ambulance_name), registration: string(row.ambulance_registration) }
                    : { name: string(row.hospital_name), address: '', latitude: '', longitude: '' } };
                render();
            }); add.disabled = !!pending; actions.append(add);
        }
        parent.append(actions);
        if (!resourceEditor || resourceEditor.id !== row.id) return;
        const draft = resourceEditor, hospital = draft.kind === 'hospital', form = el('form', 'mapa-crew-resource-form');
        form.append(el('h4', '', hospital ? 'Add hospital / base to MAPA' : 'Add ambulance to MAPA'), el('p', 'mapa-voice-note', 'Review the submitted name and enter the actual details. Saving adds an organization record; approve the driver separately below.'));
        const grid = el('div', 'mapa-voice-form-grid'), inputs = {};
        inputs.name = field(grid, hospital ? 'Hospital / base name' : 'Ambulance name', 'resource_name', draft.values.name); inputs.name.maxLength = 150; inputs.name.required = true;
        if (!hospital) { inputs.registration = field(grid, 'Ambulance registration number', 'resource_registration', draft.values.registration); inputs.registration.maxLength = 50; inputs.registration.required = true; }
        else {
            inputs.address = field(grid, 'Hospital / base address', 'resource_address', draft.values.address, 'textarea'); inputs.address.maxLength = 250; inputs.address.required = true;
            inputs.latitude = field(grid, 'Hospital / base latitude', 'resource_latitude', draft.values.latitude, 'number'); inputs.latitude.min = '-90'; inputs.latitude.max = '90'; inputs.latitude.step = 'any'; inputs.latitude.required = true;
            inputs.longitude = field(grid, 'Hospital / base longitude', 'resource_longitude', draft.values.longitude, 'number'); inputs.longitude.min = '-180'; inputs.longitude.max = '180'; inputs.longitude.step = 'any'; inputs.longitude.required = true;
        }
        form.append(grid);
        const confirmationKey = hospital ? 'location_confirmed' : 'details_confirmed';
        const confirmed = checkbox(form, hospital ? 'I verified this hospital/base address and map pin.' : 'I verified this ambulance name and registration number.'); confirmed.checked = draft.values[confirmationKey] === true;
        const remember = () => { draft.values = { ...Object.fromEntries(Object.entries(inputs).map(([key, input]) => [key, input.value])), [confirmationKey]: confirmed.checked }; };
        for (const input of Object.values(inputs)) input.addEventListener('input', () => { confirmed.checked = false; remember(); });
        confirmed.addEventListener('change', remember);
        const mapBox = el('div'); if (hospital) { form.insertBefore(mapBox, confirmed.parentElement); hospitalMap(mapBox, inputs, () => { confirmed.checked = false; remember(); }); }
        const controls = el('div', 'mapa-voice-actions');
        const save = button(hospital ? 'Save hospital / base' : 'Save ambulance', () => {}, true); save.type = 'submit';
        const cancel = button('Cancel adding record', () => { if (busy || pending) return; resourceEditor = null; render(); });
        for (const input of [...Object.values(inputs), confirmed, save, cancel]) input.disabled = !!pending;
        controls.append(save, cancel); form.append(controls);
        form.addEventListener('submit', event => {
            event.preventDefault(); if (busy || pending || !session) return; remember();
            const result = crewResourceDraft(row, draft.kind, draft.values);
            for (const input of Object.values(inputs)) input.removeAttribute('aria-invalid');
            if (!result.ok) { for (const key of Object.keys(result.fields)) inputs[key]?.setAttribute('aria-invalid', 'true'); announce(Object.values(result.fields).join(' '), true); inputs[Object.keys(result.fields)[0]]?.focus(); return; }
            try { pending = { intent: crewIntent(`/enrollments/${row.id}/${draft.kind}`, result.data, uuid()), native: true, kind: 'crew-resource', owner: session.user, company: session.company, message: hospital ? 'Hospital/base saved and selected. Review the driver assignment below.' : 'Ambulance saved and selected. Review the driver assignment below.' }; sendPending(); }
            catch (error) { announce(error.message, true); }
        }); parent.append(form);
    }
    function hospitalMap(parent, inputs, changed) {
        const box = el('section', 'mapa-crew-hospital-map'); box.append(el('h4', '', 'Locate the hospital / base'), el('p', 'mapa-voice-note', 'Select the actual hospital/base entrance. The driver’s registration GPS is not used as the hospital location. Search sends only the place query to OpenStreetMap search.'));
        const searchRow = el('div', 'mapa-voice-search-row'), query = field(searchRow, 'Find hospital / base on map', 'hospital_search', inputs.name.value); query.maxLength = 160;
        const search = button('Search hospital map', searchPlaces), results = el('div', 'mapa-voice-search-results'), mapNode = el('div', 'mapa-voice-map');
        searchRow.append(search); if (!session?.nativeReview) box.append(searchRow); box.append(results, mapNode); parent.append(box); mapNode.setAttribute('aria-label', 'Hospital/base map. Click to place its pin, or enter its coordinates above.');
        let map = null, marker = null, removed = false, searchVersion = 0;
        const currentEpoch = epoch;
        const update = (point, address = '', zoom = false) => {
            if (removed || busy || pending || currentEpoch !== epoch || !box.isConnected) return;
            inputs.latitude.value = point.latitude.toFixed(6); inputs.longitude.value = point.longitude.toFixed(6);
            if (address) inputs.address.value = address;
            if (map) {
                if (!marker) { marker = window.L.marker([point.latitude, point.longitude], { draggable: true, title: 'Hospital / base pin', icon: window.L.divIcon({ className: 'mapa-pickup-pin', html: '<span aria-hidden="true"></span>', iconSize: [30, 38], iconAnchor: [15, 38] }) }).addTo(map); marker.on('dragend', () => { const p = marker.getLatLng(); update({ latitude: p.lat, longitude: p.lng }); }); }
                else marker.setLatLng([point.latitude, point.longitude]);
                if (zoom) map.setView([point.latitude, point.longitude], 16);
            }
            changed();
        };
        async function searchPlaces() {
            if (busy || pending || removed) return; const text = locationQuery(query.value), version = ++searchVersion;
            if (!text) { results.replaceChildren(el('p', 'mapa-voice-note', 'Enter a hospital, village or landmark name with at least 3 characters.')); return; }
            search.disabled = true; results.replaceChildren(el('p', 'mapa-voice-note', 'Searching places.'));
            try {
                const response = await request(`/locations?q=${encodeURIComponent(text)}&online=1`);
                if (removed || currentEpoch !== epoch || version !== searchVersion || text !== query.value.trim() || !box.isConnected) return;
                results.replaceChildren();
                const items = (Array.isArray(response.results) ? response.results : []).map(pickupCandidate).filter(Boolean).slice(0, 12);
                for (const item of items) results.append(button(item.label || item.address || `${item.latitude}, ${item.longitude}`, () => update(item, item.address || item.label, true)));
                if (!items.length) results.append(el('p', 'mapa-voice-note', 'No match in the available data. Select the actual location on the map or enter its coordinates.'));
                if (response.attribution) results.append(el('small', 'mapa-voice-muted', string(response.attribution)));
            } catch (error) { if (!removed && currentEpoch === epoch) results.replaceChildren(el('p', 'mapa-voice-warning', error.message)); }
            finally { if (!removed && version === searchVersion) search.disabled = !!pending; }
        }
        query.addEventListener('keydown', event => { if (event.key === 'Enter') { event.preventDefault(); searchPlaces(); } });
        requestAnimationFrame(() => {
            if (removed || !box.isConnected || !window.L) { if (!removed) mapNode.replaceWith(el('p', 'mapa-voice-note', 'Map unavailable. Enter the verified hospital/base coordinates above.')); return; }
            map = window.L.map(mapNode).fitBounds(ASR_BOUNDS); window.L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '© OpenStreetMap contributors', maxZoom: 19 }).addTo(map);
            map.on('click', event => update({ latitude: event.latlng.lat, longitude: event.latlng.lng }));
            const point = pickupCandidate(draftPoint()); if (point) {
                // Mounting a saved form must not change its confirmation checkbox.
                marker = window.L.marker([point.latitude, point.longitude], { title: 'Hospital / base pin' }).addTo(map); map.setView([point.latitude, point.longitude], 15);
            }
        });
        function draftPoint() { return { latitude: inputs.latitude.value, longitude: inputs.longitude.value }; }
        for (const input of [inputs.latitude, inputs.longitude]) input.addEventListener('change', () => { const point = pickupCandidate(draftPoint()); if (point) update(point, '', true); else if (marker) { marker.remove(); marker = null; } });
        removeHospitalMap = () => { removed = true; searchVersion++; if (map) map.remove(); };
    }
    function mutateEnrollment(row, operation, data, message) {
        if (busy || pending || !session) return;
        try { pending = { intent: crewIntent(`/enrollments/${row.id}/${operation}`, data, uuid()), native: true, kind: 'enrollment', owner: session.user, company: session.company, message }; sendPending(); }
        catch (error) { announce(error.message, true); }
    }
    async function loadTripReports() {
        if (busy || pending) return; lock(true); announce('Loading received trip-end reports.');
        try { const result = await request('/trip-reports', { native: true }); if (result.contract !== 'mapa-native-mobile-v1') throw new Error('This server does not support trip-end evidence.'); tripReports = tripReportRows(result.reports); reportsLoaded = true; announce('Received trip-end reports loaded.'); }
        catch (error) { tripReports = []; reportsLoaded = false; announce(error.message, true); }
        finally { lock(false); render(); }
    }
    function renderTripReports(parent) {
        const section = el('section'); section.append(el('h3', '', 'Trip-end reports'), el('p', 'mapa-voice-note', 'Crew-submitted notes, end photographs and the accepted device GPS report recorded with completion. These are reports for operational review; they do not independently verify patient handover.'));
        const reload = button('Refresh trip reports', loadTripReports); reload.disabled = !!pending; section.append(reload); parent.append(section);
        if (!reportsLoaded) { section.append(el('p', 'mapa-voice-warning', 'Trip-end reports have not loaded.')); return; }
        if (!tripReports.length) { section.append(el('p', 'mapa-voice-note', 'No trip-end reports have been received.')); return; }
        section.append(el('p', 'mapa-voice-note', 'Showing the latest 100 received reports.'));
        for (const row of tripReports) {
            const card = el('article', 'mapa-crew-trip-report'); card.append(el('h4', '', `Call ${row.order_id}`));
            recordFields(card, [['Crew', string(row.driver_name) || row.driver_id], ['Ambulance', string(row.vehicle_registration) || row.vehicle_id], ['Trip-end note', row.note], ['Device coordinates', pickupCandidate(row) ? `${Number(row.latitude).toFixed(6)}, ${Number(row.longitude).toFixed(6)}` : 'Unavailable'], ['Accuracy', row.accuracy_m != null && Number.isFinite(Number(row.accuracy_m)) ? `±${Number(row.accuracy_m).toFixed(0)} m` : 'Unavailable'], ['GPS fix time', date(row.recorded_at)], ['Photo capture time', date(row.photo_captured_at)], ['Server received', date(row.received_at)]]);
            privatePhoto(card, row.photo_media_id, 'View private trip-end photograph'); section.append(card);
        }
    }
    async function loadCrew(id = '') {
        if (busy || pending || (id && !safeId(id))) return; lock(true); announce('Loading accepted crew location records…');
        try {
            const result = await request(id ? `/travel?driver_id=${encodeURIComponent(id)}` : '/travel', { native: true });
            if (result.contract !== 'mapa-native-mobile-v1') throw new Error('Crew location history is not supported by this server.');
            if (!id) { crewRoster = Array.isArray(result.drivers) ? result.drivers.filter(row => safeId(row.id)) : []; crewSelected = ''; crewEvidence = null; }
            else { crewSelected = id; crewEvidence = result.travel_evidence || null; }
            announce('Crew location records loaded. GPS records alone do not confirm a completed journey.');
        } catch (error) { crewEvidence = null; announce(error.message, true); }
        finally { lock(false); render(); }
    }
    function renderCrew(parent) {
        const section = el('section', 'mapa-voice-crew'); section.append(el('h3', '', 'Crew location evidence'), el('p', 'mapa-voice-note', 'Accepted device GPS reports from the last 24 hours. These records do not prove a journey, patient pickup, arrival or call completion.'));
        const tools = el('div', 'mapa-voice-search-row'), choose = select(tools, 'Crew member', 'crew_history', crewSelected, crewRoster.map(row => [row.id, string(row.name) || row.id]));
        const refresh = button('Load location records', () => { if (safeId(choose.value)) loadCrew(choose.value); }); refresh.disabled = !crewRoster.length || !!pending;
        tools.append(refresh); section.append(tools); parent.append(section);
        if (!crewRoster.length) section.append(el('p', 'mapa-voice-note', 'No crew members are available for this account.'));
        if (!crewEvidence) { section.append(el('p', 'mapa-voice-note', 'Choose a crew member to inspect their recorded fixes.')); return; }
        const points = travelPoints(crewEvidence.points), mapNode = el('div', 'mapa-voice-map'), summary = el('div', 'mapa-voice-evidence-summary');
        let gaps = 0, longest = 0;
        for (let i = 1; i < points.length; i++) { const gap = points[i].recorded - points[i - 1].recorded; if (gap > 300000) { gaps++; longest = Math.max(longest, gap); } }
        summary.append(badge(`${points.length} accepted fixes`), badge(`${gaps} gaps over 5 minutes`));
        if (points.some(point => point.demo === true || point.simulated === true)) summary.append(badge('Includes DEMO / simulated fixes', 'is-demo'));
        section.append(summary);
        if (!points.length) { section.append(el('p', 'mapa-voice-warning', 'No accepted fixes are available in this window. The device may be offline or may not have submitted a new fix since location history was enabled. This does not establish whether travel occurred.')); return; }
        section.append(el('p', 'mapa-voice-note', `First fix: ${date(points[0].recorded_at)} · Last fix: ${date(points[points.length - 1].recorded_at)}${longest ? ` · Longest gap: ${Math.round(longest / 60000)} minutes` : ''}`));
        if (crewEvidence.truncated === true) section.append(el('p', 'mapa-voice-warning', 'Only the latest available subset is shown; earlier fixes have been truncated.'));
        section.append(mapNode, el('p', 'mapa-voice-note', 'Each dot is one reported fix. No route is inferred between dots. Approximate accuracy is shown in the record list.'));
        const table = el('table', 'mapa-voice-evidence-table'), head = el('thead'), heading = el('tr');
        for (const text of ['Device time', 'Server received', 'Coordinates', 'Accuracy', 'Evidence']) heading.append(el('th', '', text)); head.append(heading); table.append(head);
        const body = el('tbody');
        for (const point of [...points].reverse().slice(0, 30)) {
            const row = el('tr'), accuracy = Number(point.accuracy_m);
            const labels = [date(point.recorded_at), date(point.received_at), `${point.latitude.toFixed(5)}, ${point.longitude.toFixed(5)}`, point.accuracy_m !== null && point.accuracy_m !== undefined && Number.isFinite(accuracy) ? `±${accuracy.toFixed(0)} m` : 'Unknown', point.demo === true || point.simulated === true ? 'DEMO / simulated' : point.mock_location === true ? 'Mock location reported' : 'Device report'];
            for (const text of labels) row.append(el('td', '', text)); body.append(row);
        }
        table.append(body); const scroll = el('div', 'mapa-voice-table-scroll'); scroll.append(table); section.append(scroll, el('p', 'mapa-voice-note', 'The table shows up to 30 recent records; the map shows up to 200 accepted fixes.'));
        let map = null, removed = false;
        requestAnimationFrame(() => {
            if (removed || !mapNode.isConnected) return;
            if (!window.L?.map) { mapNode.append(el('p', 'mapa-voice-warning', 'Map display is unavailable. The recorded coordinate table is still available.')); return; }
            map = window.L.map(mapNode, { scrollWheelZoom: false });
            window.L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '© <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap contributors</a>' }).addTo(map);
            const bounds = [];
            for (const point of points) { bounds.push([point.latitude, point.longitude]); const label = el('span', '', `${date(point.recorded_at)} · ${point.accuracy_m == null ? 'Accuracy unknown' : `±${point.accuracy_m} m`}`); window.L.circleMarker([point.latitude, point.longitude], { radius: 5, fillOpacity: .8, color: point.demo || point.simulated ? '#fbbf24' : '#0d9488' }).addTo(map).bindTooltip(label); }
            map.fitBounds(bounds, { padding: [25, 25], maxZoom: 16 }); map.invalidateSize();
        });
        removePickupMap = () => { removed = true; if (map) map.remove(); };
    }
    function renderTrackingDevices(parent) {
        const section=el('section','mapa-voice-result');section.append(el('h3','','Ambulance GPS devices'));parent.append(section);
        section.append(el('p','','Each device must be linked to its actual ambulance. Supplier protocol integration and a working SIM/data connection are required. Device GPS does not automatically put a crew on duty.'));
        if(!capabilities.device_tracking){section.append(el('p','mapa-voice-warning','Device ingestion is awaiting server activation and supplier setup. No hardware tracker is connected by this screen yet.'));return;}
        const rows=el('div'),form=el('form'),feedback=el('p');section.append(button('Refresh device positions',load),rows,form,feedback);
        async function load(){try{const d=await request('/tracking/devices',{native:true});rows.replaceChildren();form.replaceChildren();
            for(const row of d.devices||[]){const card=el('article');const p=row.location;card.append(el('h4','',`${row.registration||row.vehicle_name||'Unavailable ambulance'} · ${row.label}`),el('p','',`Device ${row.external_id} · ${row.revoked_at?'Revoked':p?(p.stale?'Offline / stale position':'Recent position'):'No position received'}`));if(p)card.append(el('p','',`${p.latitude}, ${p.longitude} · ${mapaIST(p.recorded_at)} · ${p.accuracy_m == null ? 'Accuracy unknown' : `±${p.accuracy_m} m`}${p.source_protocol ? ` · ${p.source_protocol.toUpperCase()}` : ''}`));if(!row.revoked_at)card.append(button('Revoke device',async()=>{if(!window.confirm('Stop accepting GPS from this device?'))return;try{await request(`/tracking/devices/${row.id}/revoke`,{native:true,body:'{}'});load();}catch(e){feedback.textContent=e.message;}}));rows.append(card);}
            if(!(d.devices||[]).length)rows.append(el('p','','No GPS devices registered.'));
            const choice=select(form,'Ambulance','vehicle_id','',[['','Choose ambulance'],...(d.vehicles||[]).map(v=>[v.id,`${v.registration||v.name} · ${v.name}`])]);
            const external=field(form,'Device ID / IMEI','external_id','','text'),label=field(form,'Device label','label','','text');
            choice.required=true;external.required=true;external.maxLength=100;external.pattern='[A-Za-z0-9._:-]+';label.required=true;label.maxLength=150;form.addEventListener('submit',event=>event.preventDefault());
            const submit=button('Register device',async()=>{if(!choice.value||!external.value.trim()||!label.value.trim()||!form.reportValidity()){feedback.textContent='Choose an ambulance and enter a valid device ID and label.';return;}submit.disabled=true;try{const r=await request('/tracking/devices',{native:true,body:JSON.stringify({vehicle_id:choice.value,external_id:external.value.trim(),label:label.value.trim()})});feedback.replaceChildren(el('p','','Device registered. Save this one-time credential privately in the supplier adapter; do not share it with crew or callers.'));const secret=el('input');secret.readOnly=true;secret.value=r.secret;secret.setAttribute('aria-label','One-time GPS device credential');feedback.append(secret);}catch(e){feedback.textContent=e.message;}finally{submit.disabled=false;}});form.append(submit);
        }catch(e){feedback.textContent=e.message;}}
        load();
    }
    function renderConnectedCall(parent, call, target = parent) {
        if (call.demo === true) return;
        const section = el('section', 'mapa-voice-result'); section.append(el('h4', '', 'Caller location & ambulance link')); target.append(section);
        if (smsReceipts.has(call.id)) section.append(el('p', 'mapa-voice-note', smsReceipts.get(call.id)));
        if (!capabilities.caller_tracking) section.append(el('p', 'mapa-voice-note', 'Caller links are awaiting server activation. SMS and WhatsApp also require a configured gateway or approved sender.'));
        else {
            const consent = checkbox(section, 'Caller agreed to receive a private link for pickup location sharing and ambulance tracking through hospital arrival.');
            let savedLink = issuedCallerLinks.get(call.id);
            const savedPhone = normalizedCallback((call.review || call.draft || {}).callback_number ?? call.caller);
            if (savedLink && (savedLink.callback_number !== savedPhone || !Number.isFinite(Number(savedLink.expires_at)) || Number(savedLink.expires_at) <= Date.now())) { issuedCallerLinks.delete(call.id); savedLink=null; }
            const result = el('div'), locationResult = el('p'); let issued = savedLink?.callback_number === savedPhone ? savedLink : null, actionId = uuid(), latestCaller = null, confirmedCaller = null, locationSnapshot = null, pickupIntent = pendingPickupConfirmations.get(call.id) || null;
            consent.checked = !!issued;
            let syncStopped = false, syncTimer = null, locationLoading = false;
            const syncEpoch = epoch;
            const mounted = () => !syncStopped && section.isConnected && dialog?.open && epoch === syncEpoch && selected?.id === call.id;
            const comparison = el('p', 'mapa-voice-note'); comparison.setAttribute('role', 'status');
            const manualPoint = () => {
                const form = parent.querySelector('form.mapa-voice-review');
                return form ? { latitude: form.querySelector('[name="latitude"]')?.value, longitude: form.querySelector('[name="longitude"]')?.value } : call.review || call.draft;
            };
            const callbackMatches = () => {
                const input = parent.querySelector('form.mapa-voice-review [name="callback_number"]');
                return !input || normalizedCallback(input.value) === normalizedCallback((call.review || call.draft || {}).callback_number ?? call.caller);
            };
            const showComparison = () => {
                const value = compareCallerPickup(manualPoint(), latestCaller);
                comparison.textContent = callbackMatches() ? value.message : 'Callback number changed in the draft. Save the review and send a link for the new caller before using GPS from this link.';
                comparison.className = value.state === 'different' || !callbackMatches() ? 'mapa-voice-warning' : 'mapa-voice-note';
                return value;
            };
            const changedManual = () => { showComparison(); if (!callbackMatches()) usePickup.disabled = true; };
            parent.addEventListener('input', changedManual);
            removeCallerSync = () => { syncStopped = true; clearTimeout(syncTimer); parent.removeEventListener('input', changedManual); };
            const showIssuedLink = () => {
                if (!issued) return;
                result.replaceChildren(); const link = el('input'); link.readOnly = true; link.value = location.origin + '/track/#' + issued.token; link.setAttribute('aria-label', 'Private trip link');
                const copyStatus = el('p', 'mapa-voice-note');
                const copy = button('Copy caller link', async () => {
                    if (!issued || !link.isConnected) return;
                    try { await navigator.clipboard.writeText(link.value); copyStatus.textContent = issued.message_sent?'Link copied. Confirm delivery before sharing it again.':'Link copied. Send it privately to this caller; no message has been sent by MAPA.'; }
                    catch (_) { link.focus(); link.select(); copyStatus.textContent = 'Select and copy the link above, then send it privately to this caller.'; }
                });
                result.append(el('p', '', issued.message_sent?'Private tracker sent from MAPA. Check delivery below.':'Private WhatsApp tracking link created by MAPA. It has not been sent.'), link, copy,
                    el('p', 'mapa-voice-note', `Link expires ${mapaIST(issued.expires_at)}. Copy it before closing this desk.`), copyStatus);
            };
            const replacementNotice=el('p','mapa-voice-warning','An active tracker link already exists. Check WhatsApp delivery and ask the caller before replacing it. Replacement immediately expires the old link, even if the caller opened it.');replacementNotice.hidden=true;
            const replace=button('Confirm replacement and send',()=>{if(!window.confirm('Have you checked WhatsApp delivery and asked the caller? The old link will expire immediately, including any link already opened.'))return;return sendNow(true);});replace.hidden=true;
            const deliveryLabels = { accepted: 'Accepted by WhatsApp; delivery not yet confirmed', sent: 'Sent by WhatsApp; delivery not yet confirmed', delivered: 'Delivered to the caller', read: 'Read by the caller', failed: 'WhatsApp delivery failed', rejected: 'WhatsApp rejected the message', unknown: 'Send outcome unknown; confirm with caller before sending again' };
            const sendNow=async replaceExisting=>{
                if(!consent.checked)return announce('Confirm caller permission before sending.',true);
                if(!callbackMatches())return announce('Save the reviewed callback number before sending a tracker.',true);
                send.disabled=true;replace.disabled=true;
                try{
                    const receipt=await request(`/tracking/calls/${call.id}/whatsapp`,{native:true,body:JSON.stringify({action_id:actionId,...(issued?{token:issued.token}:{issue_new:true}),consent_to_message:true,...(replaceExisting?{replace_existing:true}:{})})});
                    if(receipt.tracker_link?.token&&/^[a-f0-9]{64}$/.test(receipt.tracker_link.token)){
                        issued={...receipt.tracker_link,callback_number:savedPhone,message_sent:true};issuedCallerLinks.set(call.id,issued);showIssuedLink();revoke.disabled=false;
                    }else if(issued){issued.message_sent=true;issuedCallerLinks.set(call.id,issued);showIssuedLink();}
                    send.hidden=true;replace.hidden=true;replacementNotice.hidden=true;
                    announce(deliveryLabels[receipt.status]||'WhatsApp status unavailable. Check delivery before sending again.',!['accepted','sent','delivered','read'].includes(receipt.status));
                }catch(error){
                    if(error.status===409&&!issued){replacementNotice.hidden=false;replace.hidden=false;send.hidden=true;announce('An active tracker link exists. Check delivery and ask the caller before deliberately replacing it; no new link was sent.',true);}
                    else{announce(error.message+' Check delivery before starting another action if the outcome is uncertain.',true);send.disabled=false;}
                    replace.disabled=false;
                }
            };
            const send=button('Send via WhatsApp',()=>sendNow(false));send.disabled=!savedPhone||!!issued?.message_sent||!!issued?.message_sent_unknown;send.hidden=!!issued?.message_sent||!!issued?.message_sent_unknown;
            const revoke = button('Revoke this link', async () => {
                if (!issued) return;
                try { await request(`/tracking/links/${issued.id}/revoke`, { native: true, body: '{}' }); issued = null; issuedCallerLinks.delete(call.id); result.replaceChildren(el('p', '', 'Link revoked.')); send.disabled = !savedPhone; send.hidden=false; actionId=uuid(); revoke.disabled = true; replace.hidden=true;replacementNotice.hidden=true; }
                catch (error) { announce(error.message, true); }
            }); revoke.disabled = !issued;
            const delivery = el('p');
            const deliveryCheck = button('Check WhatsApp delivery', async () => {
                deliveryCheck.disabled = true;
                try { const receipt = await request(`/tracking/calls/${call.id}/whatsapp`, { native: true }); delivery.textContent = (receipt.messages || []).map(message => deliveryLabels[message.status] || 'Status unavailable').join(' · ') || 'No WhatsApp message has been sent for this call.'; }
                catch (error) { delivery.textContent = error.message; } finally { deliveryCheck.disabled = false; }
            });
            const smsCheck = button('Check SMS status', async () => {
                smsCheck.disabled = true;
                try { const receipt = await request(`/tracking/calls/${call.id}/sms`, { native: true }); delivery.textContent = (receipt.messages || []).map(message => smsStatusText(message.status)).join(' · ') || 'No SMS attempt is recorded for this call.'; }
                catch (error) { delivery.textContent = error.message; } finally { smsCheck.disabled = false; }
            });
            const pickupLabel = call.state === 'dispatched' ? 'Accept caller-confirmed pickup for this trip' : 'Use caller-confirmed pickup pin';
            const usePickup = button(pickupLabel, async () => {
                if (!parent.isConnected || busy || pending) return;
                if (!callbackMatches()) return announce('Save the new callback number and obtain its own caller location first.', true);
                showComparison();
                if (call.state === 'dispatched') {
                    if (!pickupIntent) {
                        const candidate = pickupConfirmationIntent(call, locationSnapshot, uuid());
                        if (!candidate) return announce('Ask the caller to confirm the patient pickup in their link, then check again. A fresh accurate confirmed point and an eligible active trip are required.', true);
                        if (!window.confirm(comparison.textContent + '\n\nAccept the pickup confirmed by the caller in their link? Verify the address and entrance by phone. The crew must review the changed pickup.')) return;
                        pickupIntent = candidate; pendingPickupConfirmations.set(call.id, candidate);
                    }
                    usePickup.disabled = true; checkLocation.disabled = true;
                    try {
                        const receipt = await request(pickupIntent.path, { native: true, body: pickupIntent.body });
                        if (!pickupConfirmationReceipt(pickupIntent, receipt)) throw Object.assign(new Error('Pickup confirmation receipt could not be verified.'), { status: 502 });
                        pickupIntent = null; pendingPickupConfirmations.delete(call.id); locationSnapshot = null; latestCaller = confirmedCaller = null;
                        locationResult.textContent = 'Pickup confirmed for this trip. The crew must review the changed pickup in the crew app.';
                        announce(locationResult.textContent);
                        notifyIntakeUpdated(window.BroadcastChannel);
                        const refreshed = await loadCall(call.id, true);
                        if (!refreshed && session) {
                            conflict = true;
                            announce('Pickup accepted, but the current call details could not refresh. The displayed pickup may be old. Reload the call to see the accepted point; do not submit the confirmation again.', true);
                            render();
                        }
                    } catch (error) {
                        if (error.status === 401 || !session) return;
                        const definite = error.status >= 400 && error.status < 500 && ![408, 429].includes(error.status);
                        if (definite) { pickupIntent = null; pendingPickupConfirmations.delete(call.id); locationSnapshot = null; latestCaller = null; }
                        locationResult.textContent = definite ? 'Pickup was not confirmed. Refresh the caller location and trip before trying again.' : 'Pickup confirmation outcome is unknown. Retry the same confirmation to recover its result; do not create a different change.';
                        announce(locationResult.textContent, true);
                    } finally {
                        usePickup.textContent = pickupIntent ? 'Retry same pickup confirmation' : pickupLabel;
                        usePickup.disabled = !pickupIntent; checkLocation.disabled = !!pickupIntent;
                    }
                    return;
                }
                const point = confirmedCallerPickup([{ caller_pickup_confirmation: confirmedCaller }]);
                if (!point || busy || pending || ['dispatched', 'dismissed'].includes(call.state)) return announce('A recent caller fix and an editable intake are required.', true);
                const form = parent.querySelector('form.mapa-voice-review'), latitude = form?.querySelector('[name="latitude"]'), longitude = form?.querySelector('[name="longitude"]');
                if (!latitude || !longitude) return announce('Reload the editable call before using the pin.', true);
                if (!window.confirm(comparison.textContent + '\n\nUse the patient pickup confirmed by the caller in their link? Verify the address and entrance, then save the normal operator review before dispatch.')) return;
                latitude.value = String(point.latitude); longitude.value = String(point.longitude);
                latitude.dispatchEvent(new Event('input', { bubbles: true })); longitude.dispatchEvent(new Event('input', { bubbles: true }));
                // The map uses change events; keep its pin and coordinate summary in sync.
                latitude.dispatchEvent(new Event('change', { bubbles: true }));
                announce('Caller-confirmed pin copied into the draft. Verify the address and entrance match this new point, then save the operator review.');
                latitude.focus();
            }); usePickup.disabled = true;
            const checkLocation = button('Check caller location', () => checkCallerLocation());
            async function checkCallerLocation() {
                clearTimeout(syncTimer);
                if (!mounted() || locationLoading) return;
                if (busy || pending || pickupIntent) { syncTimer = setTimeout(checkCallerLocation, 10000); return; }
                locationLoading = true; checkLocation.disabled = true;
                try {
                    const receipt = await request(`/tracking/calls/${call.id}/locations`, { native: true });
                    if (!mounted()) return;
                    locationSnapshot = receipt; confirmedCaller = confirmedCallerPickup(receipt.locations); latestCaller = confirmedCaller || callerPickup(receipt.locations);
                    locationResult.textContent = confirmedCaller ? `Caller confirmed this patient pickup in their link: ${confirmedCaller.latitude}, ${confirmedCaller.longitude}; accuracy ±${confirmedCaller.accuracy_m} m; ${mapaIST(confirmedCaller.confirmed_at)}. Verify the address and entrance, then accept this point.` : latestCaller ? `Phone GPS shared: ${latestCaller.latitude}, ${latestCaller.longitude}; accuracy ±${latestCaller.accuracy_m} m. Waiting for the caller to select “Confirm patient pickup” in their link. Shared GPS alone does not change the pickup.` : 'No fresh caller location received. Waiting automatically; manual pickup remains available if sharing is unavailable.';
                    showComparison();
                    usePickup.disabled = !confirmedCaller || !callbackMatches() || call.state === 'dismissed' || (call.state === 'dispatched' && !pickupConfirmationIntent(call, locationSnapshot, 'check_only'));
                } catch (error) { if (mounted()) { latestCaller = confirmedCaller = null; locationSnapshot = null; usePickup.disabled = true; locationResult.textContent = 'Caller updates interrupted. Retrying automatically. ' + error.message; showComparison(); } }
                finally { locationLoading = false; if (mounted()) { checkLocation.disabled = !!pickupIntent; syncTimer = setTimeout(checkCallerLocation, document.hidden ? 30000 : 10000); } }
            }
            const manualFallback = button('Caller cannot share location · use manual pickup', () => {
                const form = parent.querySelector('form.mapa-voice-review');
                if (!form) return announce('Use the current trip pickup review controls; do not change a dispatched trip without confirmation.', true);
                announce('Manual fallback selected. Confirm the patient’s address, landmark and entrance by phone, select the pickup pin, then save the operator review.');
                form.querySelector('[name="pickup_address"]')?.focus();
            });
            section.append(el('p', 'mapa-voice-note', 'Caller GPS is checked automatically while this call is open. Compare it with the entered pickup; confirm one point before dispatch. New fixes never overwrite your draft or move a confirmed pickup automatically.'), comparison, manualFallback);
            showComparison(); syncTimer = setTimeout(checkCallerLocation, 0);
            if (pickupIntent) { usePickup.textContent = 'Retry same pickup confirmation'; usePickup.disabled = false; checkLocation.disabled = true; locationResult.textContent = 'An earlier pickup confirmation has an unknown outcome. Retry the same confirmation to recover its result.'; }
            if (issued) showIssuedLink();
            section.append(result, replacementNotice, replace, send, revoke, deliveryCheck, smsCheck, delivery, checkLocation, usePickup, locationResult);
        }
        if (call.order_id && capabilities.hospital_handover) {
            const hospital = el('p'), map = el('div');
            section.append(el('h4', '', 'Hospital handover'), button('Check hospital acknowledgement', async () => {
                try { const receipt = await request('/hospital/overview', { native: true }); const row = (receipt.handovers || []).find(item => item.order_id === call.order_id); hospital.textContent = row ? `Hospital response: ${row.status}. Condition reported by crew: ${row.condition}. ${row.note || ''}` : 'No patient-onboard report received yet.'; renderHandoverJourney(map, row?.tracking); }
                catch (error) { hospital.textContent = error.message; renderHandoverJourney(map, null); }
            }), hospital, map);
        }
    }
    function renderHandoverJourney(parent, tracking) {
        if (!parent.isConnected) return;
        if (removeHandoverMap) { removeHandoverMap(); removeHandoverMap = null; }
        parent.replaceChildren();
        if (!tracking || typeof tracking !== 'object') return;
        parent.append(el('p', 'mapa-voice-note', `Trip stage: ${string(tracking.stage)} · leg: ${string(tracking.leg)}. Refreshed ${new Date().toLocaleTimeString()}; refresh again for the latest position.`));
        const rows = [['Pickup', tracking.pickup], ['Hospital', tracking.destination], ['Ambulance', tracking.ambulance?.location]].filter(([, point]) => point && pickupCandidate(point) && !(Number(point.latitude) === 0 && Number(point.longitude) === 0));
        if (!rows.length) { parent.append(el('p', 'mapa-voice-note', 'No verified trip coordinates are available.')); return; }
        const node = el('div', 'mapa-voice-handover-map'); node.setAttribute('aria-label', 'Authorized hospital trip snapshot'); parent.append(node);
        if (!window.L?.map) { node.textContent = 'Map display is unavailable. Check the current trip in the crew app.'; return; }
        const map = window.L.map(node, { scrollWheelZoom: false });
        window.L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '© <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap contributors</a>' }).addTo(map);
        const bounds = [];
        for (const [label, point] of rows) {
            bounds.push([Number(point.latitude), Number(point.longitude)]);
            const recorded = new Date(point.recorded_at).getTime();
            const stale = label === 'Ambulance' && (point.stale === true || !point.recorded_at || !Number.isFinite(recorded) || Date.now() - recorded > 120000 || recorded > Date.now() + 30000);
            window.L.circleMarker(bounds[bounds.length - 1], { radius: 7, color: stale ? '#d97706' : label === 'Ambulance' ? '#0d9488' : '#2563eb' }).addTo(map).bindTooltip(el('span', '', label + (stale ? ' · last known / stale' : '')));
        }
        map.fitBounds(bounds, { padding: [20, 20], maxZoom: 15 });
        removeHandoverMap = () => map.remove();
    }
    function renderCall(parent) {
        if(selected?.source==='asrhealth'){const draft=selected.review||selected.draft||{};parent.append(el('h3','',`${draft.source_context?.incident_number||'ASR incident'} · Caller tracking`),el('p','',`${draft.caller_name||'Caller'} · ${draft.callback_number||'Number not recorded'}`),el('p','',draft.pickup_address||'Pickup awaiting caller GPS'),el('p','mapa-voice-note','Historical ASR call · tracking only. For a new response, create a reviewed MAPA call after checking for an existing assignment. Caller GPS requires the caller to open the link and grant location permission.'));renderConnectedCall(parent,selected);return;}

        const call = selected,pilot=isSupervisedPilot(call); const progress = el('ol', 'mapa-command-pipeline mapa-voice-progress'); renderDispatchPipeline(progress, call, dirty); parent.append(progress); const heading = el('div', 'mapa-voice-heading'); heading.append(el('h3', '', string((call.review || call.draft || {}).caller_name) || string(call.caller) || 'Unknown caller'), badge(string(call.state).replaceAll('_', ' ')));
        if (call.demo === true) heading.append(badge('DEMO · TRAINING', 'is-demo'));if(pilot)heading.append(badge('SUPERVISED PILOT · NO PATIENT','is-warning')); parent.append(heading);
        parent.append(el('p', 'mapa-voice-muted', `Received ${date(call.created_at)} · Provider: ${string(call.provider_status) || 'Status unavailable'}`));
        if (call.demo === true) parent.append(el('p', 'mapa-voice-warning', 'DEMO: synthetic intake and DEMO ambulances only. No real phone call or live GPS is represented.'));
        if (failureWarning(call.provider_status)&&!pilot) parent.append(el('p', 'mapa-voice-warning', 'The call or transcription stream was interrupted. The transcript may be incomplete; contact the caller and verify every required detail.'));
        if(pilot)parent.append(el('p','mapa-voice-warning','No patient or caller is involved. Only the two approved live crew phones may receive this test offer after this exact call is scoped. Confirm the station point and test destination yourself; do not claim a caller read-back or patient handover.'));
        else if (call.source === 'manual') parent.append(el('p', 'mapa-voice-note', 'Operator-entered phone call. Check the caller’s words and confirm the location directly with them.'));
        else { const transcript = el('section', 'mapa-voice-transcript'); transcript.append(el('h4', '', 'Transcript · unverified'), el('p', '', string(call.transcript) || 'No final transcript is available. Enter verified caller information below.')); parent.append(transcript); }
        const trackingBox = el('section', 'mapa-call-tracking');if(pilot)trackingBox.append(el('p','mapa-voice-note','Caller tracking and messaging are disabled for this no-patient pilot.'));else renderConnectedCall(parent, call, trackingBox); parent.append(trackingBox);
        if (call.state === 'dispatched') {
            const tripPanel=el('section','mapa-command-trip-panel');renderResult(tripPanel,call);
            if(pilot)parent.append(tripPanel,trackingBox);
            else{const workflow=workflowSections('mapa-active-trip',[{key:'status',label:'Trip status',panel:tripPanel},{key:'tracker',label:'Caller tracker',panel:trackingBox}],'status');parent.append(workflow.tabs,tripPanel,trackingBox);}
            return;
        }
        if (call.state === 'dismissed') { parent.append(el('p', 'mapa-voice-note', 'This intake has been dismissed. It cannot be dispatched from this panel.')); return; }
        if (!revision(call.revision) || !safeId(call.id)) { parent.append(el('p', 'mapa-voice-warning', 'This call has no supported revision. Refresh or contact your administrator.')); return; }
        if (conflict) { parent.append(el('p', 'mapa-voice-warning', 'This call changed in MAPA. Reload the current call and review it again before dispatching.')); parent.append(button('Reload current call', () => loadCall(call.id), true)); return; }
        if (!pilot && capabilities.extraction === true && string(call.transcript).trim() && !pending) {
            parent.append(button('Suggest draft from transcript', () => {
                if (dirty && !window.confirm('Replace your unsaved edits with an unverified transcript suggestion?')) return;
                mutate(`/calls/${call.id}/extract`, { expected_revision: call.revision }, 'Draft suggested. Verify every detail and submit an operator review before dispatch.');
            }));
        }
        const draft = editDraft && editDraft.id === call.id && editDraft.revision === call.revision ? editDraft.data : call.review || call.draft || {}, form = el('form', 'mapa-voice-review mapa-command-dispatch-review'), grid = el('div', 'mapa-voice-form-grid');
        form.append(el('h4', '', pilot?'Confirm supervised test point & offers':'Confirm pickup & dispatch'), el('p', 'mapa-voice-note', pilot?'Verify the exact Araku Railway Station test pin and select a MAPA destination for this no-patient drill. No caller confirmation or hospital notification is represented.':'Read back the exact pickup and select the receiving destination. Priority follows your operator protocol.'));
        const inputs = {};
        inputs.callback_number = field(grid, pilot?'Caller phone · not used':'Callback number (blank if unknown)', 'callback_number',pilot?'':draft.callback_number ?? call.caller, 'tel'); inputs.callback_number.maxLength = 40; inputs.callback_number.placeholder = pilot?'No caller for this test':'10 Indian digits or +country code';
        inputs.caller_name = field(grid, pilot?'Pilot label':'Caller name', 'caller_name', draft.caller_name); inputs.caller_name.maxLength = 200;
        inputs.reported_problem = field(grid, pilot?'Pilot test note':'Reported problem', 'reported_problem', draft.reported_problem, 'textarea'); inputs.reported_problem.maxLength = 2000;
        inputs.pickup_address = field(grid, pilot?'Verified no-patient test point':'Verified pickup address', 'pickup_address', draft.pickup_address, 'textarea'); inputs.pickup_address.maxLength = 1000;
        if(pilot){inputs.callback_number.readOnly=true;inputs.caller_name.readOnly=true;inputs.reported_problem.readOnly=true;}
        inputs.landmark = field(grid, 'Nearby landmark', 'landmark', draft.landmark); inputs.landmark.maxLength = 500;
        locationFields(grid, inputs, draft);
        inputs.dropoff_id = select(grid, 'Hospital / destination', 'dropoff_id', draft.dropoff_id, places.filter(place => safeId(place.id)).map(place => [place.id, `${string(place.name)}${place.address ? ' · ' + string(place.address) : ''}`]));
        const destinationTools = el('div', 'mapa-voice-field'), destinationNote = el('p', 'mapa-voice-note');
        const moreDestinations = button('Load more destinations', async () => {
            if (busy || pending || !destinationState.more) return;
            const currentEpoch = epoch, currentCall = selected?.id, currentRevision = selected?.revision, nextPage = destinationState.page + 1;
            moreDestinations.disabled = true; destinationNote.textContent = 'Loading more destinations…';
            try {
                const result = await request(`/calls/${currentCall}?destination_page=${nextPage}`);
                if (currentEpoch !== epoch || selected?.id !== currentCall || selected?.revision !== currentRevision || !inputs.dropoff_id.isConnected) return;
                if (result.call?.id !== currentCall || result.call?.revision !== currentRevision || result.call?.demo !== call.demo)
                    throw new Error('The call changed. Reload it before selecting a destination.');
                const next = destinationPage(result, nextPage, places);
                const chosen = inputs.dropoff_id.value;
                places = next.rows; destinationState = next;
                const existing = new Map([...inputs.dropoff_id.options].map(option => [option.value, option]));
                for (const place of places) {
                    let option = existing.get(place.id);
                    if (!option) { option = el('option'); option.value = place.id; inputs.dropoff_id.append(option); }
                    option.textContent = `${place.name}${place.address ? ' · ' + place.address : ''}`;
                }
                inputs.dropoff_id.value = chosen;
                showDestinationState();
            } catch (error) { if (currentEpoch === epoch && inputs.dropoff_id.isConnected) destinationNote.textContent = error.message; }
            finally { if (currentEpoch === epoch && inputs.dropoff_id.isConnected) moreDestinations.disabled = !!pending || !destinationState.more; }
        });
        const showDestinationState = () => {
            destinationNote.textContent = `${places.length} destination options loaded. ` + (destinationState.more ? 'More destinations are available below.' : destinationState.known ? 'All current destinations loaded.' : 'This server returned a limited destination list.');
            if (draft.dropoff_id && !places.some(place => place.id === draft.dropoff_id)) destinationNote.textContent += ' The saved destination is no longer available; select and confirm a current destination.';
            moreDestinations.hidden = !destinationState.more; moreDestinations.disabled = !!pending || !destinationState.more;
        };
        showDestinationState(); destinationTools.append(destinationNote, moreDestinations); grid.append(destinationTools);
        inputs.latitude = field(grid, pilot?'Test point latitude':'Confirmed pickup latitude', 'latitude', draft.latitude, 'number'); inputs.latitude.step = 'any'; inputs.latitude.min = '-90'; inputs.latitude.max = '90';
        inputs.longitude = field(grid, pilot?'Test point longitude':'Confirmed pickup longitude', 'longitude', draft.longitude, 'number'); inputs.longitude.step = 'any'; inputs.longitude.min = '-180'; inputs.longitude.max = '180';
        inputs.priority = select(grid, 'Priority · operator protocol decision', 'priority', draft.priority, [['routine', 'Routine'], ['urgent', 'Urgent'], ['emergency', 'Emergency']]);
        inputs.vehicle_requirement = select(grid, 'Required ambulance capability', 'vehicle_requirement', draft.vehicle_requirement, [['any', 'Any eligible ambulance'], ['bls', 'BLS'], ['als', 'ALS']]);
        compactReviewFields(form,grid,inputs);form.querySelector('.mapa-command-quick-fields').append(destinationTools);
        if(pilot){const patientSection=[...form.querySelectorAll('.mapa-command-create-options')].find(section=>section.textContent.includes('Patient & incident details'));if(patientSection)patientSection.hidden=true;}
        const confirmed = checkbox(form, pilot?'I confirm this exact no-patient test point, station entrance and test destination as the supervising operator. No caller or patient is involved.':'I have read back the exact pickup point, village, landmark and access directions with the caller, and checked the destination.'); confirmed.checked = pilot?draft.pilot_pickup_confirmed_by_operator===true&&!!call.review:draft.coordinates_confirmed===true&&!!call.review; confirmed.disabled = !!pending; for (const input of Object.values(inputs)) input.disabled = !!pending;
        const smsConsent = pilot?null:smsPermission(form, draft.auto_sms_consent);
        const autoOffersAvailable = automaticOffersAvailable(call, capabilities);
        if(pilot&&!autoOffersAvailable)form.append(el('p','mapa-voice-warning','This no-patient pilot is not yet scoped for automatic offers. The server must bind this exact call UUID and the two approved crew UUIDs; then refresh this call. Review cannot start a manual dispatch.'));
        else if (!autoOffersAvailable && call.automatic_dispatch_unavailable_reason)
            form.append(el('p', 'mapa-voice-note', automaticOffersUnavailableMessage(call)));
        const submit = button(pilot?autoOffersAvailable?'Confirm test point & start scoped crew offers':'Pilot awaiting server scope':autoOffersAvailable ? 'Confirm pickup & start automatic offers' : 'Save reviewed intake & find ambulances', () => {}, true); submit.type = 'submit'; submit.disabled = !!pending||(pilot&&!autoOffersAvailable); form.append(submit);
        const candidateBox = el('section', 'mapa-voice-candidates');
        const changed = event => { if (['location_search', 'location_scope'].includes(event?.target?.name)) return; if (event?.target !== confirmed && event?.target !== smsConsent) confirmed.checked = false; dirty = true; editDraft = { id: call.id, revision: call.revision, data: { ...Object.fromEntries(Object.entries(inputs).map(([key, input]) => [key, input.value])), coordinates_confirmed: !pilot&&confirmed.checked, supervised_pilot:pilot,pilot_pickup_confirmed_by_operator:pilot&&confirmed.checked,auto_sms_consent:!!smsConsent?.checked } }; renderDispatchPipeline(progress,call,true); candidateBox.replaceChildren(el('p', 'mapa-voice-note', 'The draft has changed. Save and review again to refresh eligible ambulances.')); };
        form.addEventListener('input', changed);
        pickupMap(form, inputs, changed);
        // Put the map before the read-back acknowledgement and save action.
        const mapSection = form.querySelector('.mapa-voice-pickup-map'); form.insertBefore(mapSection, confirmed.parentElement);
        form.addEventListener('submit', event => {
            event.preventDefault(); if (busy || pending || (pilot&&!autoOffersAvailable)) return; const values = Object.fromEntries(Object.entries(inputs).map(([key, input]) => [key, input.value])); values.coordinates_confirmed = !pilot&&confirmed.checked;values.supervised_pilot=pilot;values.pilot_pickup_confirmed_by_operator=pilot&&confirmed.checked;
            const result = reviewDraft(values, places);
            for (const input of Object.values(inputs)) input.removeAttribute('aria-invalid'); confirmed.removeAttribute('aria-invalid');
            if (!result.ok) { for (const key of Object.keys(result.fields)) (inputs[key] || confirmed).setAttribute('aria-invalid', 'true'); announce(Object.values(result.fields).join(' '), true); (inputs[Object.keys(result.fields)[0]] || confirmed).focus(); return; }
            if (smsConsent?.checked && !result.data.callback_number) { announce('Enter the callback number before requesting SMS.', true); inputs.callback_number.focus(); return; }
            mutate(`/calls/${call.id}/review`, { expected_revision: call.revision, ...result.data }, pilot?'No-patient test point confirmed by the operator. MAPA is starting scoped crew offers; check the offer status below.':autoOffersAvailable ? 'Pickup and destination confirmed. MAPA is starting automatic crew offers; check the offer status below.' : 'Review saved. Choose an eligible ambulance and confirm dispatch.', !!smsConsent?.checked);
        });
        const desk = callWorkbench(parent);
        if(call.review){
            const summary=el('section','mapa-command-selected-summary');summary.append(el('h4','',draft.caller_name||call.caller||'Caller'),el('p','',[draft.callback_number,draft.pickup_address].filter(Boolean).join(' · ')),el('p','mapa-voice-note','Priority: '+string(draft.priority)+' · Capability: '+string(draft.vehicle_requirement).toUpperCase()+' · Destination: '+string(draft.destination_facility_name||places.find(place=>place.id===draft.dropoff_id)?.name)));
            const activeOffers=['searching','offering'].includes(call.automatic_dispatch?.state);
            if(activeOffers)summary.append(el('p','mapa-voice-warning','Automatic crew offers are active. Review the Dispatch & offers section for the current responses; the call details cannot be changed until offers stop.'));
            const editor=el('details','mapa-command-review-editor');editor.open=!!dirty;editor.append(el('summary','','Review / edit call details'),form);
            desk.rail.append(editor);
            const sections=[{key:'dispatch',label:'Dispatch & offers',panel:candidateBox},{key:'tracker',label:'Caller tracker',panel:trackingBox}];
            if(!activeOffers)sections.push({key:'review',label:'Review / edit',panel:desk.rail,open:()=>{editor.open=true;}});
            else desk.rail.hidden=true;
            const workflow=workflowSections('mapa-current-call',sections,dirty&&!activeOffers?'review':'dispatch');
            desk.main.append(summary,workflow.tabs,candidateBox,trackingBox);
        }else{
            const workflow=workflowSections('mapa-current-call',[{key:'review',label:'Review & pickup',panel:form},{key:'tracker',label:'Caller tracker',panel:trackingBox}],'review');
            desk.main.append(workflow.tabs,form,trackingBox);desk.rail.append(candidateBox);
            form.addEventListener('input',event=>{if(form.hidden&&['latitude','longitude'].includes(event.target?.name))workflow.show('review');});
        }
        renderCandidates(candidateBox, call);
        if (['searching', 'offering'].includes(call.automatic_dispatch?.state)) {
            form.hidden = true;
            desk.main.append(el('h4', '', 'Reviewed call · automatic offers in progress'), el('p', 'mapa-voice-note', [draft.caller_name, draft.pickup_address, draft.destination_facility_name, draft.priority].filter(Boolean).join(' · ')), el('p', 'mapa-voice-note', 'Stop automatic dispatch before changing the reviewed intake.'));
        }
    }
    function renderCandidates(parent, call) {
        parent.replaceChildren(); if (call.state !== 'ready' || !call.review || dirty) return;
        const rankedBox=el('section','mapa-command-recommendations');rankedBox.append(el('h4','','Recommended ambulances · road travel time'));
        if(recommendations?.routing_status==='ready'&&Array.isArray(recommendations.ranked)&&recommendations.ranked.length){
            rankedBox.append(el('p','mapa-voice-note','Verified eligibility and fresh GPS are checked before private road travel-time ranking. '+(recommendations.shortlist_truncated?'Nearest eligible shortlist only; more ambulances may be available.':'')));
        }else rankedBox.append(el('p','mapa-voice-warning','Road-ranked recommendations are unavailable. Refresh availability; no road ETA has been estimated.'));
        parent.append(rankedBox);
        const autoOffersAvailable = automaticOffersAvailable(call, capabilities);
        const automatic = call.automatic_dispatch;
        if (autoOffersAvailable || automatic) {
            const box = el('section', 'mapa-voice-confirm mapa-command-cascade');
            box.append(el('h4', '', automatic?.cascade_active===true?'⚡ AUTOMATIC DISPATCH ACTIVE':'⚡ Automatic dispatch'), el('p', 'mapa-voice-note', isSupervisedPilot(call)?'SUPERVISED PILOT · NO PATIENT. MAPA checks the operator-verified test point within 500 m, then expands by 1 km bands. Only the approved, online crew phones are in scope.':'MAPA checks the confirmed pickup within 500 m, then expands by 1 km bands. Only crews under MAPA dispatch control with verified links, availability and fresh phone GPS receive offers. ASR data alone does not establish readiness.'));
            if (!autoOffersAvailable) box.append(el('p', 'mapa-voice-warning', automaticOffersUnavailableMessage(call)));
            if (automatic) {
                if(automatic.first_acceptance_wins===true&&automatic.state==='offering'&&automatic.outstanding_count>0)box.append(el('strong','mapa-command-cascade-policy','FIRST ACCEPTANCE WINS ASSIGNMENT'),el('p','mapa-voice-note','Outstanding crews may still accept. The first valid acceptance assigns this trip and releases the other offers.'));
                if(Number.isInteger(automatic.outstanding_count))box.append(badge(automatic.outstanding_count+' outstanding offers'+(automatic.overdue_count?' · '+automatic.overdue_count+' overdue':'')));
                box.append(el('p', '', 'Status: ' + string(automatic.state).replaceAll('_', ' ') + (automatic.reason ? ' · ' + string(automatic.reason).replaceAll('_', ' ') : '') + ' · ' + metric(automatic.offer_count) + '/' + metric(automatic.max_offers || 5) + ' offers checked'));
                if(automatic.state==='needs_operator')box.append(el('p','mapa-voice-warning',automatic.reason==='worker_stale'?automaticOffersUnavailableMessage(call):isSupervisedPilot(call)?'No approved crew accepted within the supervised pilot search. MAPA cancelled unanswered test offers and stopped automatic dispatch. Crews already offered on this call cannot receive another offer for it. Check both crew phones before creating a new supervised no-patient call to retry the same two crews.':'No crew accepted within the automatic search. MAPA cancelled unanswered offers and stopped automatic dispatch. Previously offered crews cannot receive another offer for this call; contact available crews directly or use manual dispatch after checking current eligibility.'));
                const timers=[];
                for (const offer of automatic.offers || []) {
                    const row=el('article','mapa-command-offer'+(offer.state==='overdue'?' is-overdue':offer.state==='offered'?' is-active':'')),identity=el('div');identity.append(el('strong','',string(offer.vehicle_registration)+' — '+(offer.state==='overdue'&&offer.can_respond===true?'OVERDUE (ACTIONABLE)':string(offer.state).replaceAll('_',' ').toUpperCase())),el('p','mapa-voice-note','Driver: '+string(offer.driver_name)+(offer.driver_phone?' · '+string(offer.driver_phone):' · phone not recorded')+' · ETA: '+metric(offer.road_seconds/60)+' min by road'+(Number.isFinite(offer.distance_m)?' · '+metric(offer.distance_m)+' m from pickup':'')+(Number.isFinite(offer.radius_band_m)?' · within '+metric(offer.radius_band_m)+' m search band':'')),el('p','mapa-voice-note','Response window: '+metric(offer.timeout_seconds)+' s · expires '+date(offer.expires_at)));row.append(identity);
                    if(offer.response_seconds!=null)row.append(el('p','mapa-voice-note','Driver response: '+metric(offer.response_seconds)+' s'+(offer.responded_at?' · '+date(offer.responded_at):'')));
                    if(offer.no_response===true)row.append(el('p','mapa-voice-warning',offer.can_respond===true?'No response within the first window. This offer remains actionable while the next eligible crew is considered.':'No response within the offer window. This expired offer cannot assign the trip.'));
                    else if(offer.state==='declined')row.append(el('p','mapa-voice-warning','Crew declined. Check the next crew offer below.'));
                    if(offer.decline_reason||offer.decline_detail)row.append(el('p','mapa-voice-note','Decline reason: '+[string(offer.decline_reason).replaceAll('_',' '),string(offer.decline_detail)].filter(Boolean).join(' · ')));
                    if(offer.is_late_response===true)row.append(el('p','mapa-voice-note','Driver responded after the initial offer window. Assignment still requires the server’s first-acceptance result.'));
                    if(['offered','overdue'].includes(offer.state)&&Number.isFinite(offer.remaining_seconds)){const timer=el('span','mapa-command-offer-timer');timer.setAttribute('aria-label','Offer response countdown');row.append(timer);timers.push({node:timer,seconds:Math.max(0,offer.remaining_seconds),at:offerLoadedAt||Date.now(),actionable:offer.can_respond===true});}
                    box.append(row);
                }
                const tick=()=>{for(const timer of timers){const left=Math.max(0,Math.ceil(timer.seconds-(Date.now()-timer.at)/1000));timer.node.textContent=left?String(Math.floor(left/60)).padStart(2,'0')+':'+String(left%60).padStart(2,'0'):timer.actionable?'OVERDUE · ACTIONABLE':'Window elapsed';}};tick();
                if(timers.length&&typeof window.setInterval==='function'){offerClockTimer=window.setInterval(()=>{if(!box.isConnected){window.clearInterval?.(offerClockTimer);offerClockTimer=null;return;}tick();},1000);}
                for(const alert of automatic.alerts||[])if(alert&&typeof alert.message==='string')box.append(el('p','mapa-voice-warning',alert.message));
                if(automatic.replacement_routing_status==='refresh_selected_call')box.append(el('p','mapa-voice-note','Refresh this call for current road-ranked replacement candidates. Declined and expired offers advance through eligible crews automatically.'));
                box.append(button('Refresh offers', () => loadCall(call.id)));
                if(autoOffersAvailable&&['stopped','needs_operator'].includes(automatic.state)&&automatic.reason!=='top_five_exhausted'){
                    const retryConfirmed=checkbox(box,isSupervisedPilot(call)?'I reconfirm this no-patient test point and authorize remaining approved crew offers.':'I confirm the reviewed pickup and destination and authorize offers to the remaining eligible crews.');
                    const retryOffers=button('Retry automatic offers',()=>{if(!retryConfirmed.checked||dirty||pending||busy)return;mutate('/calls/'+call.id+'/automatic/start',{expected_revision:call.revision,confirmed:true},'Automatic offers resumed. Previous responses remain in the call history.');},true);
                    retryOffers.disabled=true;retryConfirmed.addEventListener('change',()=>{retryOffers.disabled=!retryConfirmed.checked||dirty||!!pending;});
                    box.append(el('p','mapa-voice-note','Retry preserves declined and expired offers. Previously attempted crews and ambulances are not offered this call again.'),retryOffers);
                }
                if (['searching', 'offering'].includes(automatic.state)) {
                    box.append(button('Stop automatic dispatch', () => mutate('/calls/' + call.id + '/automatic/stop', { expected_revision: call.revision, confirmed: true }, 'Automatic offers stopped.')));
                    clearTimeout(pollTimer); pollTimer = setTimeout(() => {
                        if (dialog.open && session && !busy && !pending && !dirty && selected?.id === call.id) loadCall(call.id, true);
                    }, 10000);
                    parent.append(box); return;
                }
            } else if (autoOffersAvailable) {
                const fastest=recommendations?.ranked?.[0];
                if(fastest){const hero=el('div','mapa-command-fastest-summary');hero.append(el('strong','','DISPATCH FASTEST: '+string(fastest.vehicle_registration||fastest.vehicle_name)),el('span','mapa-command-road-eta',metric(fastest.road_seconds/60)+' MIN ROAD ETA'));box.append(hero);}
                box.append(el('p','mapa-voice-warning','Automatic offers did not start after review. Check the worker and current call status before starting them manually.'));
                const confirmAuto = checkbox(box, isSupervisedPilot(call)?'I reconfirm the no-patient test point and authorize offers to the approved crew phones.':'I confirm the reviewed pickup and destination and authorize automatic crew offers.');
                const startAuto = button('Dispatch fastest · start automatic offers', () => {
                    if (!confirmAuto.checked || dirty || pending) return;
                    mutate('/calls/' + call.id + '/automatic/start', { expected_revision: call.revision, confirmed: true }, 'Automatic dispatch started. Refresh offers to check crew response.');
                }, true);
                startAuto.disabled = true;
                confirmAuto.addEventListener('change', () => { startAuto.disabled = !confirmAuto.checked || dirty || !!pending; });
                box.append(startAuto);
            }
            parent.append(box);
        } else parent.append(el('p', 'mapa-voice-note', automaticOffersUnavailableMessage(call)));
        if(isSupervisedPilot(call)){
            parent.append(el('p','mapa-voice-warning','Manual ambulance dispatch is unavailable for this no-patient pilot. Stop automatic offers if the test cannot continue; after acceptance, cancel the test order in Operations → Ambulance Calls. Never record a patient pickup or hospital handover for this drill.'));
            return;
        }
        parent.append(el('h4', '', 'Manual dispatch · choose an ambulance'));
        parent.append(el('p', 'mapa-voice-note', 'Search the current MAPA ambulance records by area, hospital/base, ambulance name or registration. Availability is checked again when you dispatch.'));
        const controls = el('div', 'mapa-ambulance-search');
        const search = field(controls, 'Find ambulances by area or registration', 'ambulance_search', '', 'search');
        search.placeholder = 'Area, hospital, ambulance or registration'; search.maxLength = 160; search.autocomplete = 'off';
        const refresh = button('Refresh availability', () => loadCall(call.id)); refresh.disabled = !!pending; controls.append(refresh);
        const readyOnly = checkbox(parent, 'Show ready to assign only');
        const count = el('p', 'mapa-voice-note'); count.setAttribute('aria-live', 'polite');
        const selectBox = el('div', 'mapa-ambulance-select');
        const chooser = select(selectBox, 'Ambulance', 'ambulance_choice', '', [['', 'Choose an ambulance…']]);
        chooser.setAttribute('aria-describedby', 'mapa-ambulance-match-count mapa-ambulance-chooser-hint'); count.id = 'mapa-ambulance-match-count';
        const chooserHint = el('p', 'mapa-voice-note mapa-ambulance-chooser-hint'); chooserHint.id='mapa-ambulance-chooser-hint';selectBox.append(chooserHint);
        const detail = el('div', 'mapa-ambulance-selection'); detail.setAttribute('aria-live', 'polite');
        const unavailableBox = el('div');
        let choice = '', visible = [];
        const confirmBox = el('div', 'mapa-voice-confirm');
        confirmBox.append(el('h4', '', '3. Confirm dispatch'), el('p', 'mapa-voice-note', 'Pickup: ' + string(call.review.pickup_address) + ' · Priority: ' + string(call.review.priority) + ' · Requirement: ' + string(call.review.vehicle_requirement).toUpperCase()));
        const checked = checkbox(confirmBox, call.demo ? 'I confirm dispatch of this DEMO ambulance for training.' : 'I confirm dispatch of the selected ambulance to this verified pickup.');
        const selectedRow = () => visible.find(row => row.ready && row.vehicle_id === choice);
        const dispatch = button('Confirm dispatch', () => {
            const row = selectedRow();
            if (!checked.checked || !row || !dispatchAllowed(selected, candidates, row.id, dirty, pending, call.revision)) return;
            mutate('/calls/' + call.id + '/dispatch', { expected_revision: call.revision, confirmed: true, driver_id: row.id }, 'MAPA confirmed the dispatch.');
        }, true);
        const update = () => {
            const row = selectedRow();
            dispatch.disabled = !checked.checked || !row || !dispatchAllowed(selected, candidates, row.id, dirty, pending, call.revision);
            checked.disabled = !!pending || !row;
            detail.replaceChildren();
            if (row) {
                detail.append(el('strong', '', (string(row.vehicle_name) || row.vehicle_id) + ' · ' + string(row.vehicle_registration)));
                detail.append(el('p', '', [ambulanceContext(row), string(row.name) && 'Crew: ' + row.name, string(row.vehicle_type)].filter(Boolean).join(' · ')));
                appendCrewMapping(detail, row);
                const availability=ambulanceAvailabilityEvidence(row);if(availability)detail.append(el('p','mapa-voice-note',availability));
                const distance = typeof row.distance_km === 'number' && Number.isFinite(row.distance_km) ? row.distance_km.toFixed(1) + ' km straight-line' : 'Distance unavailable';
                detail.append(el('p', 'mapa-voice-note', distance + ' · ' + (row.simulated ? 'Simulated fix' : 'GPS fix') + ': ' + date(row.fix_recorded_at)));
            }
        };
        const filter = () => {
            visible = ambulanceDirectory(call, candidates, unavailable, search.value, readyOnly.checked);
            const ready = visible.filter(row => row.ready), blocked = visible.filter(row => !row.ready);
            if (!ready.some(row => row.vehicle_id === choice)) choice = '';
            checked.checked = false;
            chooser.replaceChildren(el('option', '', ready.length ? 'Choose a ready ambulance…' : 'No ready ambulance matches')); chooser.children[0].value = '';
            const appendOptions = (rows, label) => {
                if (!rows.length) return; const group = el('optgroup'); group.label = label;
                for (const row of rows) {
                    const label = [row.vehicle_registration, row.vehicle_name, ambulanceContext(row), row.ready ? 'Ready to assign' : 'Not ready: ' + (string(row.message) || 'Check crew and availability')].map(string).filter(Boolean).join(' · ');
                    const option = el('option', '', label); option.value = row.vehicle_id; option.disabled = !row.ready; group.append(option);
                }
                chooser.append(group);
            };
            // A native select with the entire fleet opens a viewport-sized OS popup.
            // Keep assignment choices short; every other vehicle remains searchable
            // and the complete blocked inventory is visible in the details below.
            const readyChoices = ready.slice(0, 8);
            const chosenReady = ready.find(row => row.vehicle_id === choice);
            if (chosenReady && !readyChoices.includes(chosenReady)) readyChoices.push(chosenReady);
            appendOptions(readyChoices, 'Ready to assign');
            chooser.value = choice; chooser.disabled = !!pending || !visible.length;
            chooser.disabled = !!pending || !ready.length;
            count.textContent = visible.length + ' ambulance' + (visible.length === 1 ? '' : 's') + ' found · ' + ready.length + ' ready to assign · ' + blocked.length + ' not ready';
            chooserHint.textContent = ready.length > readyChoices.length ? 'Showing the first 8 ready ambulances. Search by hospital, area, crew, or registration to find the others.' : 'Only ready ambulances can be selected. Open the availability list below to inspect other vehicles.';
            unavailableBox.replaceChildren();
            if (!visible.length) unavailableBox.append(el('p', 'mapa-voice-note', 'No matching ambulance records. Try another area, hospital name or registration, or clear the search.'));
            else if (!ready.length) unavailableBox.append(el('p', 'mapa-voice-warning', 'No matching ambulance is ready for this call yet. Review the availability reasons below, then refresh after the crew is ready.'));
            if (blocked.length) {
                const reasons = el('details', 'mapa-voice-unavailable');
                reasons.append(el('summary', '', 'Why matching ambulances are unavailable (' + blocked.length + ')'));
                const list = el('div', 'mapa-ambulance-reasons');
                for (const row of blocked) {
                    const item = el('div', 'mapa-voice-warning');
                    item.append(el('strong', '', [row.vehicle_registration, row.vehicle_name].map(string).filter(Boolean).join(' · ') || row.vehicle_id), el('p', '', ambulanceContext(row)), el('p', '', string(row.message)));
                    appendCrewMapping(item, row);
                    const availability=ambulanceAvailabilityEvidence(row);if(availability)item.append(el('p','mapa-voice-note',availability));
                    if (row.order_id) item.append(el('small', '', 'Existing call: ' + string(row.order_id) + ' · ' + string(row.order_status)));
                    list.append(item);
                }
                reasons.append(list); unavailableBox.append(reasons);
            }
            update();
        };
        search.disabled = !!pending; readyOnly.disabled = !!pending;
        search.addEventListener('input', filter); readyOnly.addEventListener('change', filter);
        chooser.addEventListener('change', () => { choice = chooser.value; checked.checked = false; update(); });
        checked.addEventListener('change', update); confirmBox.append(dispatch);
        parent.insertBefore(controls, readyOnly.parentElement);
        parent.append(count, selectBox, detail, unavailableBox, confirmBox); filter();
        for(const candidate of (recommendations?.ranked||[]).slice(0,10)){
            const card=el('article','mapa-command-row mapa-command-candidate'+(candidate.rank===1?' is-first':''));
            card.append(el('span','mapa-command-rank',candidate.rank||'—'),el('strong','',string(candidate.vehicle_registration||candidate.vehicle_name)),el('span','mapa-command-road-eta',metric(candidate.road_minutes??candidate.road_seconds/60)+' min by road'),el('p','',string(candidate.name)),el('p','mapa-voice-note','GPS: '+date(candidate.fix_recorded_at)+' · '+string(candidate.vehicle_type||call.review.vehicle_requirement).toUpperCase()));
            const choose=button('Choose this ambulance',()=>{if(pending||dirty||busy)return;search.value='';readyOnly.checked=true;filter();const row=visible.find(row=>row.ready&&row.id===candidate.id&&row.vehicle_id===candidate.vehicle_id);if(!row)return announce('This ambulance is no longer eligible. Refresh availability.',true);choice=row.vehicle_id;chooser.value=choice;checked.checked=false;update();confirmBox.scrollIntoView?.({behavior:'smooth',block:'nearest'});});
            choose.disabled=!!pending;card.append(choose);rankedBox.append(card);
        }
    }
    function renderResult(parent, call) {
        const pilot=isSupervisedPilot(call),result = el('section', 'mapa-voice-result'); result.append(el('h4', '', pilot?'No-patient crew test assigned by MAPA':'Dispatch confirmed by MAPA'), el('p', '', `Native order: ${string(call.order_id) || 'Reference unavailable'}`));
        const pickup = call.review || call.draft || {}; const pin = pickupCandidate(pickup);
        result.append(el('p', '', `${pilot?'Test point':'Pickup'}: ${string(pickup.pickup_address)}${pin ? ' · ' + pin.latitude.toFixed(6) + ', ' + pin.longitude.toFixed(6) : ''}`));
        result.append(el('p', 'mapa-voice-note', `Native call status: ${string(call.order_status) || 'Awaiting status'}`));
        result.append(badge(call.acknowledged === true ? 'Crew acknowledgement received' : 'Awaiting crew acknowledgement', call.acknowledged === true ? 'is-success' : ''));
        if(pilot){
            result.append(el('p','mapa-voice-warning','SUPERVISED PILOT · NO PATIENT. Crew acceptance verifies the offer workflow only. Cancel this test order through Operations → Ambulance Calls; do not enter arrival, patient onboard, hospital handover or trip completion.'));
            const refresh=button('Refresh test status',()=>loadCall(call.id));refresh.disabled=!!pending;result.append(refresh);parent.append(result);
            if(!['canceled','cancelled'].includes(call.order_status))pollTimer=setTimeout(()=>{if(dialog.open&&session&&!busy&&!pending&&selected?.id===call.id)loadCall(call.id,true);},10000);
            return;
        }
        const trip=call.trip_progress||{},timeline=el('ol','mapa-command-trip-timeline');timeline.setAttribute('aria-label','Trip lifecycle');
        for(const [label,confirmed,stamp] of [
            ['Crew accepted',trip.accepted===true],['Arrived at pickup',trip.pickup_arrived===true,trip.pickup_arrived_at],
            ['Patient onboard',trip.patient_onboard===true],['Arrived at hospital',trip.hospital_arrived===true,trip.hospital_arrived_at],
            ['Trip completed',trip.completed===true||call.order_status==='completed']
        ]){
            const row=el('li',confirmed?'is-complete':'is-pending');row.append(el('strong','',label),el('span','',confirmed?(stamp?date(stamp):'Confirmed'):'Awaiting verified crew or order update'));timeline.append(row);
        }
        result.append(timeline,el('p','mapa-voice-note','Stages change only when the assigned crew or native order reports them. GPS movement alone does not confirm patient or hospital events.'));
        if(trip.completed!==true&&call.order_status!=='completed')result.append(el('p','mapa-voice-warning','To close this trip, the assigned crew must confirm hospital handover and complete the trip in the MAPA crew app. If they cannot, control must resolve it in the native trip workflow; MAPA will not infer completion from GPS.'));
        const refresh = button('Refresh trip status', () => loadCall(call.id)); refresh.disabled = !!pending; result.append(refresh); parent.append(result);
        clearTimeout(pollTimer);if(trip.completed!==true&&call.order_status!=='completed')pollTimer = setTimeout(() => { if (dialog.open && session && !busy && !pending && selected?.id === call.id) loadCall(call.id, true); }, 10000);
    }
    function renderPending() {
        const box = el('section', 'mapa-voice-warning'); box.append(el('strong', '', 'Receipt not confirmed'), el('p', '', 'This update may already have reached MAPA. Keep this page open. Retry uses exactly the same saved key and details; do not create a replacement dispatch.'));
        const retry = button('Retry the same update', () => sendPending(), true); retry.disabled = pending.expired === true; box.append(retry); content.append(box);
    }
    function renderAsrCall(parent) {
        const back=button('← Back to Incoming Calls',()=>{if(busy||pending)return;asrSelected=null;selected=null;newCall=false;activeView='calls';render();});back.disabled=!!busy||!!pending;parent.append(back);
        const row=asrSelected;parent.append(el('h3','',row.incident_number+' · ASR Health call'),el('p','mapa-voice-note','ASR source history · tracking only. This view does not create a MAPA assignment. For a new response, use New Call and review the current incident and any existing assignment.'));
        const details=el('dl','mapa-voice-source-details');
        for(const [label,key] of [['Caller','caller_name'],['Caller number','caller_phone'],['Patient','patient_name'],['Age','patient_age'],['Gender','patient_gender'],['Incident','incident_type'],['Priority','priority'],['Pickup','pickup_address'],['Origin PHC','from_phc_id'],['Origin secretariat','from_secretariat_id'],['Origin village','from_village_id'],['Destination PHC','to_phc_id'],['Other destination','destination_facility'],['Notes','notes'],['Source status','status']]){details.append(el('dt','',label),el('dd','',row[key]==null||row[key]===''?'Not recorded':String(row[key])));}
        parent.append(details);
        const open=button('Open caller tracking & create link',async()=>{if(busy||pending)return;lock(true);try{const result=await request('/source-calls/'+row.id+'/open',{body:JSON.stringify({source_revision:row.source_revision})});asrSelected=null;lock(false);await loadCall(result.call.id);}catch(e){announce(e.message,true);}finally{lock(false);render();}},true);
        open.disabled=!!busy||!!pending||asrCallState?.stale||asrCallState?.truncated;parent.append(open,el('p','mapa-voice-note','Opening the tracking desk preserves the ASR incident identity. Create a private link only after confirming caller permission; SMS and WhatsApp require configured providers.'));
    }
    async function refreshAsrCalls(force=false){
        if(!session||session.nativeReview||session.expires<=Date.now()||busy||pending||dirty||(!force&&(selected||asrSelected))||activeView!=='calls'||document.hidden||!dialog?.open)return;
        const own=epoch;try{const feed=await request('/source-calls');if(own!==epoch)return;if(feed.contract!=='mapa-asr-caller-feed-v1'||feed.company_id!==session.company||!Array.isArray(feed.calls)||feed.calls.length>500)throw new Error('Unsupported caller feed');asrCalls=feed.calls;asrCallState=feed;asrCallError='';announce(feed.stale||feed.truncated?'Incoming calls loaded, but the source data is delayed or incomplete.':'Incoming calls loaded with latest data · '+new Date().toLocaleTimeString(),!!(feed.stale||feed.truncated));const list=content?.querySelector('.mapa-command-queue');if(list)renderDeskQueue(list,true);else render();}catch(e){if(own===epoch){asrCallError='ASR caller refresh unavailable. Existing call edits have been preserved.';asrCallState=asrCallState?{...asrCallState,stale:true}:null;const list=content?.querySelector('.mapa-command-queue');if(list)renderDeskQueue(list,true);else render();}}
    }
    if(typeof window.setInterval==='function')window.setInterval(refreshAsrCalls,30000);
    if(typeof window.setInterval==='function')window.setInterval(async()=>{
        if(!session||session.nativeReview||!dialog?.open||document.hidden||busy||pending||dirty||capabilities.command_desk!==true)return;
        await loadCommandDesk();if(session&&!dirty&&!pending&&!busy&&activeView==='calls')refreshLiveDesk();
    },30000);
    async function loadCalls() {
        if (busy || pending) { render(); return; } lock(true); announce('Loading intake calls…');
        try { const withDirectory = Date.now() - directoryFetchedAt >= 300000; const result = await request(withDirectory ? '/calls?source_locations=1' : '/calls'); calls = Array.isArray(result.calls) ? result.calls : []; capabilities = result.capabilities || {}; if (withDirectory) { directoryFetchedAt = Date.now(); sourceDirectory = result.source_directory || null; sourceLocations = Array.isArray(result.source_locations) && result.source_locations.length <= 1000 ? result.source_locations.filter(row => row && typeof row.name === 'string' && row.name.length <= 200) : []; } pickupMapConfig = result.pickup_map || {};
            smsConfigured = false;
            if (capabilities.caller_tracking === true) { try { const sms = await request('/tracking/sms', { native: true }); smsConfigured = sms.configured === true; } catch (_) {} }
            const latest = selected && calls.find(call => call.id === selected.id); if (latest && latest.revision !== selected.revision) { conflict = true; candidates = []; recommendations=null; announce('The selected call changed in MAPA. Reload and review it again before dispatch.', true); } else announce('Calls refreshed.'); }
        catch (error) { announce(error.message, true); }
        finally { lock(false); render(); }
        await refreshAsrCalls(true);
        await loadCommandDesk(); if (!dirty && !pending && session) render();
    }
    async function loadCall(id, quiet = false) {
        asrSelected=null;
        if (busy || pending || !safeId(id)) return false;
        if (dirty && !quiet && !window.confirm('Discard your unsaved intake edits and load the current call?')) return false;
        lock(true); if (!quiet) announce('Loading the current call and eligibility…');
        try { const result = await request(`/calls/${id}`); if (!result.call || result.call.id !== id || !revision(result.call.revision)) throw new Error('MAPA did not return a supported call revision.'); selected = result.call; offerLoadedAt=Date.now(); recommendations=supportedRecommendations(result.recommendations); newCall = false; candidates = Array.isArray(result.candidates) ? result.candidates : []; unavailable = Array.isArray(result.unavailable) ? result.unavailable : []; setDestinations(result); dirty = false; editDraft = null; conflict = false; replaceCall(selected); if (!quiet) announce('Current call loaded.'); return true; }
        catch (error) { announce(error.message, true); return false; }
        finally { lock(false); render(); }
    }
    function replaceCall(call) { const index = calls.findIndex(row => row.id === call.id); if (index >= 0) calls[index] = call; else calls.unshift(call); }
    function uuid() { if (typeof crypto.randomUUID === 'function') return crypto.randomUUID(); throw new Error('A secure browser context is required to update MAPA calls.'); }
    function mutate(path, data, message, smsConsent = false, trackerConsent = false) {
        if (busy || pending || !session) return;
        try { pending = { intent: makeIntent(path, data, uuid()), owner: session.user, company: session.company, message, smsConsent: smsConsent === true, smsActionId: smsConsent === true ? uuid() : null, trackerConsent: trackerConsent === true }; sendPending(); }
        catch (error) { announce(error.message, true); }
    }
    async function sendPending() {
        if (busy || !pending || pending.expired) return;
        const saved = pending; lock(true); announce('Waiting for MAPA to confirm this update…');
        try {
            const result = await request(saved.intent.path, { native: saved.native === true, body: saved.intent.body });
            if (saved.kind === 'crew-resource') {
                const receipt = crewResourceReceipt(saved.intent, result);
                if (!receipt) throw Object.assign(new Error('MAPA has not returned a complete resource receipt.'), { status: 502 });
                const resource = receipt.resource, key = resource.kind === 'ambulance' ? 'vehicles' : 'places';
                const index = enrollmentOptions[key].findIndex(v => v.id === resource.id);
                if (index >= 0) enrollmentOptions[key][index] = resource; else enrollmentOptions[key].push(resource);
                enrollmentSelections.set(receipt.enrollment.id, { ...(enrollmentSelections.get(receipt.enrollment.id) || {}), [key === 'vehicles' ? 'vehicle_id' : 'place_id']: resource.id });
                const rowIndex = enrollments.findIndex(v => v.id === receipt.enrollment.id); if (rowIndex >= 0) enrollments[rowIndex] = receipt.enrollment;
                resourceEditor = null; pending = null; announce(saved.message); return;
            }
            if (saved.kind === 'enrollment') {
                const row = enrollmentReceipt(saved.intent, result);
                if (!row) throw Object.assign(new Error('MAPA has not returned a complete crew review receipt.'), { status: 502 });
                const index = enrollments.findIndex(v => v.id === row.id); if (index >= 0) enrollments[index] = row; else enrollments.unshift(row);
                pending = null; window.dispatchEvent(new CustomEvent('mapa:crew-review-updated')); announce(saved.message); return;
            }
            if (result.ok !== true || !result.call || !safeId(result.call.id) || !revision(result.call.revision)) throw Object.assign(new Error('MAPA has not returned a complete update receipt.'), { status: 502 });
            selected = result.call; offerLoadedAt=Date.now(); recommendations=supportedRecommendations(result.recommendations); newCall = saved.intent.path === '/calls/manual'; manualEdit = null; places = []; destinationState = { page: 0, more: false, known: false }; replaceCall(selected); candidates = Array.isArray(result.candidates) ? result.candidates : []; unavailable = Array.isArray(result.unavailable) ? result.unavailable : []; dirty = false; editDraft = null; conflict = false; pending = null; announce(saved.message);
            notifyIntakeUpdated(window.BroadcastChannel);
            if (saved.intent.path === '/calls/manual' && saved.trackerConsent && selected.demo === false && capabilities.caller_tracking === true)
                announce('Call saved. Open the tracker section and select Send tracker & get location. The link and WhatsApp attempt are created together only when you send.');
            // The SMS endpoint creates its own link. Never invoke it after the
            // operator chose a WhatsApp tracker, or it could revoke that link.
            if (!saved.trackerConsent && smsSaveAllowed(saved.intent.path, selected, saved.smsConsent, smsConfigured && capabilities.caller_tracking === true)) {
                const callId = selected.id;
                try {
                    const receipt = await request(`/tracking/calls/${callId}/sms/auto`, { native: true, body: JSON.stringify({ action_id: saved.smsActionId, consent_to_message: true, expected_callback_number: normalizedCallback((selected.review || selected.draft || {}).callback_number ?? selected.caller) }) });
                    if (!session || saved.owner !== session.user || saved.company !== session.company) return;
                    smsReceipts.set(callId, smsStatusText(receipt.status));
                } catch (error) {
                    if (error.status === 401 || !session) return;
                    smsReceipts.set(callId, error.status === 409 ? 'No SMS was sent. The callback may have changed or an active tracker link already exists. Check the current link and caller number before choosing a replacement.' : error.status === 503 ? smsStatusText('unavailable') : smsStatusText('unknown'));
                }
                announce(`${saved.message} ${smsReceipts.get(callId)}`);
            }
            // A confirmed demo creation/review may need the native destination list.
            if (selected.state !== 'dispatched') {
                try { const detail = await request(`/calls/${selected.id}`); if (detail.call?.id === selected.id && revision(detail.call.revision)) { selected = detail.call; offerLoadedAt=Date.now(); recommendations=supportedRecommendations(detail.recommendations); replaceCall(selected); candidates = Array.isArray(detail.candidates) ? detail.candidates : []; unavailable = Array.isArray(detail.unavailable) ? detail.unavailable : []; setDestinations(detail); } }
                catch (error) { announce(`The update was confirmed, but refreshing the call failed: ${error.message}`, true); }
            }
        } catch (error) {
            const definite = Number.isInteger(error.status) && error.status >= 400 && error.status < 500 && ![401, 408, 429].includes(error.status);
            if (definite) { pending = null; if (error.status === 409) conflict = true; }
            announce(`${error.message}${error.fields ? ' ' + error.fields : ''}${!definite && error.status !== 401 ? ' Receipt uncertain: retry the same update.' : ''}`, true);
        } finally { lock(false); render(); }
    }
    async function signOut() {
        if (busy || pending) return; lock(true); let revoked = false;
        try { await request('/logout', { native: true, body: '{}' }); revoked = true; } catch (_) {}
        finally { expire(); lock(false); announce(revoked ? 'Signed out of Call Intake.' : 'Signed out locally. Server revocation was not confirmed; ask an administrator to revoke this session.', !revoked); render(); }
    }
    window.MapaCrewReview = Object.freeze({
        configure(transport) {
            if (standalone || !transport || !['request', 'media', 'identity', 'valid'].every(key => typeof transport[key] === 'function')) return false;
            const identity = transport.identity();
            if (!transport.valid() || !safeId(identity?.user) || !safeId(identity?.company) || !['admin', 'superadmin'].includes(identity.role)) return false;
            if (pending || busy) return session?.nativeReview === true && nativeCrewTransport?.valid()
                && nativeCrewTransport.request === transport.request && nativeCrewTransport.media === transport.media
                && session.user === identity.user && session.company === identity.company;
            expire(); nativeCrewTransport = transport;
            session = { ...identity, expires: Number.isFinite(identity.expires) ? identity.expires : Date.now() + 12 * 60 * 60 * 1000, nativeReview: true };
            return true;
        },
        open({ trigger, enrollmentId, driverId } = {}) {
            if (!session?.nativeReview || !nativeCrewTransport?.valid() || busy) return false;
            if (pending) { open(trigger); return true; }
            enrollmentSelected = safeId(enrollmentId) ? enrollmentId : '';
            requestedCrewDriver = safeId(driverId) ? driverId : null;
            activeView = 'enrollments'; newCall = false; open(trigger); loadEnrollments(); return true;
        },
        invalidate() { if (session?.nativeReview) { expire(); if (dialog?.open) dialog.close(); } nativeCrewTransport = null; }
    });
    window.mapaIntakeCanLeave = () => !busy && !pending && (!(dirty || resourceEditor) || window.confirm('Discard unsaved intake edits and return to Operations?'));
    function openOperatorTab() {
        if(standalone) {
            if(busy||pending) return;
            if(dirty&&!window.confirm('Discard this unsaved draft and start a new call?')) return;
            asrSelected=null;newCall=true;manualEdit=null;dirty=false;activeView='calls';render();return;
        }
        if (!standalone && typeof window.mapaOpenIntake === 'function') { window.mapaOpenIntake(); return; }
        // Keep Operations and any unfinished call in their original tabs.
        window.open('/call-intake/', '_blank', 'noopener,noreferrer');
    }
    // Keep the sidebar's authorized New Call action on the operator desk.
    // The map toolbar keeps its native transport-order form and route planning.
    function routeNativeCreate(event) {
        if (standalone || event.defaultPrevented || (event.button !== undefined && event.button !== 0) || !/^\/fleet-ops(?:\/|$)/.test(location.pathname)) return;
        const trigger = event.target?.closest?.('button.fleet-ops-sidebar-primary-action, a.fleet-ops-sidebar-primary-action');
        if (!trigger || trigger.disabled || trigger.getAttribute('aria-disabled') === 'true') return;
        event.preventDefault(); event.stopImmediatePropagation();
        openOperatorTab();
    }
    document.addEventListener('click', routeNativeCreate, true);
    let launchInFlight = false;
    function mount() {
        if (standalone) { if (!dialog) { open(); consumeLaunch(); } return; }
        // The native sidebar already provides New Call; a floating duplicate covers table pagination.
        const ready = /^\/fleet-ops(?:\/|$)/.test(location.pathname) && !!document.querySelector('.mapa-attribution')
            && !document.querySelector('button.fleet-ops-sidebar-primary-action, a.fleet-ops-sidebar-primary-action');
        if (!entry && ready) { entry = el('a', 'mapa-voice-button is-primary', 'NEW CALL'); entry.href = '/call-intake/'; entry.target = '_blank'; entry.rel = 'noopener noreferrer'; entry.id = 'mapa-voice-entry'; entry.setAttribute('aria-label', 'NEW CALL · Open MAPA operator desk in a new tab'); entry.addEventListener('click',event=>{event.preventDefault();openOperatorTab();}); document.body.append(entry); }
        if (entry && entry.hidden === ready) entry.hidden = !ready;
    }
    async function consumeLaunch() {
        if (!standalone) return;
        const fragment=location.hash;
        const protectedState = () => session || pending || dirty || resourceEditor || manualEdit || editDraft;
        if(fragment==='#launching') {
            if (!launchInFlight && !busy && !protectedState()) announce('Opening with your MAPA operator session…');
            return;
        }
        if(!fragment.startsWith('#launch=')) return;
        const ticket=fragment.slice(8);
        // Remove even ignored handoffs; a fragment must never replace current work.
        history.replaceState(null,'',location.pathname+location.search);
        if (launchInFlight || busy || protectedState()) return;
        if(!/^[a-f0-9]{64}$/.test(ticket)) { announce('Invalid launch link. Sign in below.',true); return; }
        const current = epoch;
        let installedSession = null, ownsLock = true;
        const mayUpdate = () => current === epoch && session === installedSession
            && !pending && !dirty && !resourceEditor && !manualEdit && !editDraft && (ownsLock || !busy);
        launchInFlight = true;
        lock(true); announce('Checking your MAPA operator session…');
        try {
            const data=await request('/intake-launch/exchange',{native:true,login:true,body:JSON.stringify({ticket})});
            if (!mayUpdate()) return;
            if(data.contract!=='mapa-native-mobile-v1'||!/^[a-f0-9]{64}$/.test(data.token)||!Number.isFinite(data.expires_at)||data.expires_at<=Date.now()||!safeId(data.user?.id)||!safeId(data.user?.company_id)||!['admin','superadmin'].includes(data.user?.role)) throw new Error('Operator launch could not be verified. Sign in below.');
            installedSession=session={token:data.token,expires:data.expires_at,role:data.user.role,name:string(data.user.name),user:data.user.id,company:data.user.company_id};
            lock(false); ownsLock = false;
            await loadCalls();
            if (mayUpdate() && !lastError) announce('Opened from your authenticated MAPA Operations session.');
        } catch(error) { if (mayUpdate()) announce(error.message,true); }
        finally {
            launchInFlight = false;
            if (mayUpdate()) { if (ownsLock) lock(false); render(); }
        }
    }
    let scheduled = false;
    const observer = new MutationObserver(() => { if (!scheduled) { scheduled = true; requestAnimationFrame(() => { scheduled = false; mount(); }); } });
    observer.observe(document.documentElement, { childList: true, subtree: true });
    window.addEventListener('popstate', mount);
    if (standalone) window.addEventListener('hashchange', () => { if (!dialog) mount(); else consumeLaunch(); });
    window.addEventListener('mapa:open-voice', event => { event.preventDefault(); if (/^\/fleet-ops(?:\/|$)/.test(location.pathname)) openOperatorTab(); });
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mount, { once: true }); else mount();
})();
