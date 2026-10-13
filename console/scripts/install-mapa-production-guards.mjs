// Source equivalents of reviewed live-bundle fixes. Fail on dependency drift.
import fs from 'node:fs';
const core = 'node_modules/@fleetbase/ember-core/addon/';
const ops = 'node_modules/@fleetbase/fleetops-engine/addon/';
const data = 'node_modules/@fleetbase/fleetops-data/addon/';
function replace(file, old, replacement) {
    const text = fs.readFileSync(file, 'utf8');
    if (text.includes(replacement)) return;
    if (text.split(old).length !== 2) throw new Error(`Production guard anchor mismatch: ${file}`);
    fs.writeFileSync(file, text.replace(old, replacement));
}
replace(core + 'abilities/dynamic.js',
    'return this.permissions.has(permission) || this.permissions.has(wilcardPermission) || this.permissions.has(wildcardServicePermission);',
    `const permissions = new Set((this.currentUser.permissions ?? []).map(permission => permission.name));
        return permissions.has(permission) || permissions.has(wilcardPermission) || permissions.has(wildcardServicePermission);`);
const current = core + 'services/current-user.js';
replace(current, "this.set('userSnapshot', snapshot);\n        this.theme.syncThemeFromCurrentUser();",
    "this.set('userSnapshot', snapshot);\n        // MAPA: grants must resolve before user.loaded listeners run.\n        this.permissions = this.getUserPermissions(user);\n        this.theme.syncThemeFromCurrentUser();");
replace(current, '// Set permissions\n        this.permissions = this.getUserPermissions(user);', '// MAPA: permissions were resolved before lifecycle notifications.');
replace(data + 'models/vehicle.js', "@attr('point') location;", "@attr('mapa-vehicle-point') location;");
replace(data + 'models/vehicle.js', "return get(this.location, 'coordinates.0');", 'return this.location?.coordinates?.[0];');
replace(data + 'models/vehicle.js', "return get(this.location, 'coordinates.1');", 'return this.location?.coordinates?.[1];');
replace(ops + 'components/positions-replay.hbs', '{{#if this.resource}}', '{{#if this.hasResourcePosition}}');
replace(ops + 'components/positions-replay.js', '    get resourceName() {', `    get hasResourcePosition() {
        const coordinates = this.resource?.location?.coordinates;
        return Array.isArray(coordinates) && coordinates.length >= 2 && Number.isFinite(coordinates[0]) && Number.isFinite(coordinates[1]);
    }
    get resourceName() {`);
replace(ops + 'components/map/leaflet-live-map.js', 'const data = yield this.fetch.get(url, params,', 'let data = yield this.fetch.get(url, params,');
replace(ops + 'components/map/leaflet-live-map.js', '            this.trigger(callback, data);', `            if (path === 'vehicles') {
                data = data.filter(vehicle => {
                    const coordinates = vehicle?.location?.coordinates;
                    return Array.isArray(coordinates) && coordinates.length >= 2 && Number.isFinite(coordinates[0]) && Number.isFinite(coordinates[1]);
                });
            }
            this.trigger(callback, data);`);
replace(ops + 'services/vehicle-actions.js', '    @action locate(vehicle, options = {}) {', `    @action locate(vehicle, options = {}) {
        const coordinates = vehicle?.location?.coordinates;
        if (!Array.isArray(coordinates) || coordinates.length < 2 || !Number.isFinite(coordinates[0]) || !Number.isFinite(coordinates[1])) {
            this.notifications.info('No GPS position has been received for this vehicle.');
            return;
        }`);
replace(ops + 'services/map-adapter/leaflet.js', 'this._tileLayer = L.tileLayer(options.tileUrl, options.tileOptions ?? {}).addTo(this._map);', `let tileOptions = options.tileOptions ?? {};
            let usesDefaultOpenStreetMap = false;
            try { usesDefaultOpenStreetMap = new URL(options.tileUrl, window.location.href).origin === 'https://tile.openstreetmap.org'; } catch (_) {}
            if (usesDefaultOpenStreetMap) {
                tileOptions = { ...tileOptions, attribution: tileOptions.attribution || '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap</a> contributors' };
                if (this._map.attributionControl) this._map.attributionControl.setPosition('bottomleft');
                else this._map.attributionControl = L.control.attribution({ position: 'bottomleft' }).addTo(this._map);
            }
            this._tileLayer = L.tileLayer(options.tileUrl, tileOptions).addTo(this._map);`);
console.log('Production source retains current-grant navigation, null-GPS handling and map attribution.');
