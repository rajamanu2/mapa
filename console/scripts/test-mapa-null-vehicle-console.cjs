'use strict';
// Executes the reviewed retained AMD modules/methods offline. No browser,
// accounts, network or production console writes.
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '../../..');
const candidate = path.resolve(process.argv[2] || path.join(root, 'private/null-vehicle-console-candidate'));
const original = path.join(root, 'private/live-access/console');
let checks = 0;
function check(actual, expected, label) { assert.deepEqual(actual, expected, label); checks++; }
function yes(value, label) { assert.ok(value, label); checks++; }
function moduleText(text, name) {
    const needle = `define("${name}"`;
    assert.equal(text.split(needle).length, 2);
    const start = text.indexOf(needle);
    const rest = text.slice(start + 1);
    const next = /\n[ ;]*define\("/.exec(rest);
    return text.slice(start, next ? start + 1 + next.index : text.length);
}
const vendor = fs.readFileSync(path.join(candidate, 'assets/vendor.js'), 'utf8');
const engine = fs.readFileSync(path.join(candidate, 'engines-dist/@fleetbase/fleetops-engine/assets/engine.js'), 'utf8');
const app = fs.readFileSync(path.join(candidate, 'assets/@fleetbase/console.js'), 'utf8');
const originalVendor = fs.readFileSync(path.join(original, 'assets/vendor.js'), 'utf8');
const identityDecorator = (_target, _name, descriptor) => descriptor;
const anyDecorator = () => identityDecorator;
const attrs = {};
class Model {}
class Transform {}
function get(target, key) {
    if (target === null || target === undefined) throw Error('Ember.get null target');
    return key.split('.').reduce((value, segment) => value?.[segment], target);
}
function load(module, dependencies) {
    let result;
    vm.runInNewContext(module, { define(_name, names, callback) {
        const exports = {};
        callback(...names.map(name => name === 'exports' ? exports : dependencies[name] || {}));
        result = exports.default;
    } });
    return result;
}
const Point = load(moduleText(vendor, '@fleetbase/fleetops-data/utils/geojson/point'), {
    '@fleetbase/fleetops-data/utils/geojson/geo-json': { default: class {} },
    '@ember/array': { isArray: Array.isArray },
});
const transformStart = app.indexOf('define("@fleetbase/console/transforms/mapa-vehicle-point"');
const transformEnd = app.indexOf('\n});', transformStart) + '\n});'.length;
const VehiclePoint = load(app.slice(transformStart, transformEnd), {
    '@ember-data/serializer/transform': { default: Transform },
    '@fleetbase/fleetops-data/utils/geojson/point': { default: Point },
});
const transform = new VehiclePoint();
for (const value of [null, undefined]) {
    check(transform.deserialize(value), null, 'missing deserialize remains unknown');
    check(transform.serialize(value), null, 'missing serialize remains unknown');
}
for (const coordinates of [[83.1,17.8], [0,0], [-122.4,37.8], [0,17.8], [83.1,0]]) {
    const value = {type:'Point',coordinates};
    const decoded = transform.deserialize(value);
    check(Array.from(decoded.coordinates), coordinates, 'real point retained, including supplied zero');
    check(Array.from(transform.serialize(decoded).coordinates), coordinates, 'real serialize retained');
    yes(decoded instanceof Point, 'native Point instance retained');
}
check(moduleText(vendor, '@fleetbase/fleetops-data/transforms/point'), moduleText(originalVendor, '@fleetbase/fleetops-data/transforms/point'), 'shared PointTransform unchanged');
check(moduleText(vendor, '@fleetbase/ember-ui/helpers/point-to-coordinates'), moduleText(originalVendor, '@fleetbase/ember-ui/helpers/point-to-coordinates'), 'shared coordinate helper unchanged');
const computed = new Proxy({}, { get: () => anyDecorator });
const Vehicle = load(moduleText(vendor, '@fleetbase/fleetops-data/models/vehicle'), {
    '@ember-data/model': {default:Model, belongsTo:anyDecorator, hasMany:anyDecorator, attr(type) { return (target,name,descriptor) => { attrs[name]=type; return descriptor; }; }},
    '@ember/object': {get, computed:anyDecorator},
    '@ember/object/computed': computed,
    'date-fns': {},
    '@ember/application': {},
    '@fleetbase/ember-core/utils/is-valid-coordinates': {default:coords => Array.isArray(coords) && coords.every(Number.isFinite)},
    'ember-get-config': {default:{}},
});
check(attrs.location, 'mapa-vehicle-point', 'only Vehicle location uses nullable transform');
const vehicle = new Vehicle();
for (const location of [null, undefined]) {
    vehicle.location = location;
    check(vehicle.longitude, undefined, 'longitude getter null-safe');
    check(vehicle.latitude, undefined, 'latitude getter null-safe');
    check(Array.from(vehicle.coordinates), [undefined,undefined], 'unknown coordinates not invented');
    check(vehicle.hasValidCoordinates, false, 'unknown vehicle cannot claim map position');
    check(vehicle.latlng.lat, undefined, 'sidebar object unknown latitude');
    check(vehicle.latitudelongitude.longitude, undefined, 'sidebar object unknown longitude');
}
vehicle.location = new Point({type:'Point',coordinates:[83.1,17.8]});
check(vehicle.longitude,83.1,'known longitude retained');
check(vehicle.latitude,17.8,'known latitude retained');
check(vehicle.hasValidCoordinates,true,'known native vehicle position accepted');

function getter(module, name, next) {
    const start = module.indexOf(`    get ${name}() {`);
    const end = module.indexOf(`    get ${next}() {`, start);
    assert.ok(start >= 0 && end > start);
    const object = vm.runInNewContext('({' + module.slice(start,end) + '})');
    return Object.getOwnPropertyDescriptor(object,name).get;
}
const replayModule = moduleText(engine,'@fleetbase/fleetops-engine/components/positions-replay');
const replayGuard = getter(replayModule,'hasResourcePosition','resourceName');
for (const resource of [null, {}, {location:null}, {location:{coordinates:[undefined,undefined]}}, {location:{coordinates:[NaN,17]}}, {location:{coordinates:['83','17']}}]) {
    check(replayGuard.call({resource}),false,'replay missing/invalid vehicle has no marker');
}
check(replayGuard.call({resource:vehicle}),true,'replay retains known point');
yes(replayModule.includes('[41,[30,0,[\\"hasResourcePosition\\"]]'),'actual compiled replay marker condition guarded');
yes(!replayModule.includes('[41,[30,0,[\\"resource\\"]]'),'old unguarded marker condition removed');

const leafletModule = moduleText(engine,'@fleetbase/fleetops-engine/components/map/leaflet-live-map');
const methodStart = leafletModule.indexOf('    *loadResource(');
const methodEnd = leafletModule.indexOf('    isReady()',methodStart);
const loadResource = vm.runInNewContext('({' + leafletModule.slice(methodStart,methodEnd) + '})', {
    _string:{camelize:s=>s,capitalize:s=>s[0].toUpperCase()+s.slice(1),dasherize:s=>s},
    _emberInflector:{singularize:s=>s.replace(/s$/,'')},
    _debug:{debug:()=>{}},
}).loadResource;
const known = {id:'known',location:{coordinates:[83.1,17.8]}};
const absent = {id:'absent',location:null};
const bad = {id:'invalid',location:{coordinates:[Infinity,17.8]}};
function runLoad(name) {
    const originalRows=[known,absent,bad];
    const events=[];
    const context={abilities:{cannot:()=>false},fetch:{get:()=>originalRows},trigger:(...args)=>events.push(args)};
    let callback;
    const generator=loadResource.call(context,name,{onLoaded:data=>callback=data});
    check(generator.next().done,false,'actual load yields fetch once');
    const result=generator.next(originalRows);
    check(result.done,true,'actual load completes');
    return {originalRows,result:result.value,events,context,callback};
}
const vehicles=runLoad('vehicles');
check(Array.from(vehicles.result,x=>x.id),['known'],'only known vehicle map markers loaded');
check(vehicles.originalRows.length,3,'inventory response untouched');
check(vehicles.callback,vehicles.result,'onLoaded receives filtered map records');
check(vehicles.context.vehicles,vehicles.result,'tracked marker rows filtered');
check(vehicles.events[0][1],vehicles.result,'loaded map event filtered');
const places=runLoad('places');
check(places.result,places.originalRows,'other resources unchanged');

const actionsModule=moduleText(engine,'@fleetbase/fleetops-engine/services/vehicle-actions');
const locateStart=actionsModule.indexOf('    locate(vehicle, options = {}) {');
const locateEnd=actionsModule.indexOf('    orderReferenceList(',locateStart);
const locate=vm.runInNewContext('({' + actionsModule.slice(locateStart,locateEnd) + '})',{
    _leafletIcon:{default:options=>options},_emberGetConfig:{default:{defaultValues:{vehicleAvatar:'avatar'}}},
}).locate;
let dialogs=0, notices=0;
const actions={notifications:{info:()=>notices++},modalsManager:{show:()=>dialogs++},intl:{t:key=>key}};
for (const value of [null,absent,bad,{location:{coordinates:[]}}]) locate.call(actions,value);
check(dialogs,0,'Locate does not open unknown-location map');
check(notices,4,'Locate explains missing position');
locate.call(actions,vehicle);
check(dialogs,1,'Locate retains known position behavior');

// Canonical rebuilt source has the equivalent narrowly scoped declarations.
const canonical=fs.readFileSync(path.join(root,'fleetbase/console/app/initializers/mapa-null-vehicle-location.js'),'utf8');
yes(canonical.includes("location: attr('mapa-vehicle-point')"),'canonical Vehicle-only attribute');
yes(canonical.includes('this.location?.coordinates?.[0]') && canonical.includes('this.location?.coordinates?.[1]'),'canonical null-safe getters');
const template=fs.readFileSync(path.join(root,'fleetops-source/addon/components/positions-replay.hbs'),'utf8');
yes(template.includes('{{#if this.hasResourcePosition}}'),'canonical replay marker guard');
yes(app.indexOf('define("@fleetbase/console/transforms/mapa-vehicle-point"') < app.indexOf('if (!runningTests)'),'transform registered before console boot');
console.log(`PASS ${checks} native null-vehicle console checks (offline actual modules; physical/browser rendering unverified).`);
