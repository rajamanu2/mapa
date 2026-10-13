// Pure contact-normalization/metadata tests. Does not contact services or dial.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, 'mapa-caller-phone-methods.js.txt'), 'utf8').replace('@action ', '');
const Details = vm.runInNewContext('(class Details {' + source + '})');
const details = new Details();
let count = 0;
const check = (actual, expected) => { assert.equal(actual, expected); count++; };
for (const [value, expected] of [
    ['', ''], ['   ', ''], [null, ''], ['9876543210', '+919876543210'],
    ['919876543210', '+919876543210'], ['+91 (98765) 43210', '+919876543210'],
    ['98765-43210', '+919876543210'], ['+442079460000', '+442079460000'],
    ['+12345678', '+12345678'], ['+123456789012345', '+123456789012345'],
    ['0123456789', null], ['910123456789', null], ['9876543', null],
    ['+1234567', null], ['+1234567890123456', null], ['++919876543210', null],
    ['+019876543210', null], ['phone9876543210', null], ['98765/43210', null],
    ['98765\t43210', null], ['９８７６５４３２１０', null], [' '.repeat(41), null], ['()--', null],
]) check(details.mapaNormalizeCallerPhone(value), expected);
const original = { mapa_demo: true, other: { retained: true }, mapa_dispatch_review: { callback_number: '+442079460000', reported_problem: 'DEMO training' } };
const resource = { meta: original, set(key, value) { this[key] = value; } };
details.args = { resource };
check(details.mapaCallerPhone, '+442079460000');
details.setMapaCallerPhone({ target: { value: '98765 43210' } });
check(resource.meta.mapa_caller_phone, '+919876543210');
check(resource.meta.mapa_demo, true);
check(resource.meta.other, original.other);
check(resource.meta.mapa_dispatch_review, original.mapa_dispatch_review);
check(original.mapa_caller_phone, undefined);
check(details.mapaCallerPhoneError, '');
details.setMapaCallerPhone({ target: { value: 'bad' } });
check(resource.meta.mapa_caller_phone, 'bad');
check(Boolean(details.mapaCallerPhoneError), true);
details.setMapaCallerPhone({ target: { value: '' } });
check(details.mapaCallerPhone, '');
check(details.mapaCallerPhoneError, '');
resource.meta.mapa_caller_phone = null;
check(details.mapaCallerPhone, '');
resource.meta = null;
details.setMapaCallerPhone({ target: { value: '+91 98765 43210' } });
check(resource.meta.mapa_caller_phone, '+919876543210');
resource.meta = { _index_resource: true, untouched: 'partial' };
details.setMapaCallerPhone({ target: { value: '9876543210' } });
check(resource.meta.mapa_caller_phone, undefined);
check(resource.meta.untouched, 'partial');
console.log(`MAPA caller phone: ${count} checks passed; no network, records or calls.`);
