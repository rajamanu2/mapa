'use strict';
const assert = require('node:assert/strict');
const { enrollmentLinkSummary: summary } = require('../public/mapa-voice.js');
const complete = { status: 'approved', driver_id: 'driver_DEMO', vehicle_id: 'vehicle_DEMO', place_id: 'place_DEMO' };
assert.equal(summary(complete).label, 'Approved');
assert.match(summary(complete).warning, /current assignment, phone connection, duty and fresh GPS/);
for (const key of ['driver_id', 'vehicle_id', 'place_id']) {
    for (const value of [undefined, null, '', '../foreign']) {
        const result = summary({ ...complete, [key]: value });
        assert.equal(result.label, 'Approved · link needs repair');
        assert.equal(result.missing.length, 1);
        assert.match(result.warning, /before this crew is used for dispatch/);
    }
}
assert.equal(summary({ status: 'approved', name: 'DEMO Driver', hospital_name: 'DEMO Hospital', ambulance_registration: 'DEMO-001' }).missing.length, 3);
for (const status of ['pending', 'rejected', 'revoked']) {
    assert.equal(summary({ status }).label, status);
    assert.equal(summary({ status }).warning, '');
}
console.log('Enrollment link display checks passed: missing identities, malformed IDs, complete records and non-approved states. No network.');
