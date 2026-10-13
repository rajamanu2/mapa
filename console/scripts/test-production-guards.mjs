// Run after the source guard installer, against the installed locked dependencies.
import fs from 'node:fs';
import assert from 'node:assert/strict';
const read = p => fs.readFileSync(p, 'utf8');
const ops = 'node_modules/@fleetbase/fleetops-engine/addon/';
const core = 'node_modules/@fleetbase/ember-core/addon/';
let checks = 0;
function equal(actual, expected) { assert.deepEqual(actual, expected); checks++; }
const replay = read(ops + 'components/positions-replay.js');
const getter = replay.split('get hasResourcePosition() {')[1].split('\n    }')[0];
const hasFix = new Function(getter);
for (const coordinates of [null, [], [82], ['82', 18], [82, NaN], [Infinity, 18]])
    equal(hasFix.call({resource: {location: {coordinates}}}), false);
equal(hasFix.call({resource: null}), false);
equal(hasFix.call({resource: {location: {coordinates: [82.87, 18.33]}}}), true);
const map = read(ops + 'components/map/leaflet-live-map.js');
const predicate = map.split('data = data.filter(vehicle => {')[1].split('});')[0];
const validVehicle = new Function('vehicle', predicate);
equal([null, {location: null}, {location: {coordinates: [82, 18]}}].filter(validVehicle).length, 1);
const actions = read(ops + 'services/vehicle-actions.js');
const guard = actions.split('@action locate(vehicle, options = {}) {')[1].split('const { latitude, longitude, location }')[0];
const locate = new Function('vehicle', guard + 'return "located";');
const notices = [];
const context = {notifications: {info: value => notices.push(value)}};
equal(locate.call(context, {location: null}), undefined);
equal(notices.length, 1);
equal(locate.call(context, {location: {coordinates: [82, 18]}}), 'located');
const ability = read(core + 'abilities/dynamic.js');
const permission = ability.slice(ability.indexOf('const permissions = new Set'), ability.indexOf('return permissions.has'));
const expression = ability.slice(ability.indexOf('return permissions.has')).split(';')[0] + ';';
const permitted = new Function('permission', 'wilcardPermission', 'wildcardServicePermission', permission + expression);
const user = {currentUser: {permissions: [{name: 'fleet-ops see extension'}]}};
equal(permitted.call(user, 'fleet-ops see extension', '* see extension', 'fleet-ops * extension'), true);
user.currentUser.permissions = [];
equal(permitted.call(user, 'fleet-ops see extension', '* see extension', 'fleet-ops * extension'), false);
const current = read(core + 'services/current-user.js').split('async setUser(user) {')[1];
equal(current.indexOf('this.permissions = this.getUserPermissions(user);') < current.indexOf("this.trigger('user.loaded'"), true);
console.log(`Production source guards: ${checks} behavior assertions passed.`);
