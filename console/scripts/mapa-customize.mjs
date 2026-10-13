// MAPA overlay for upstream dependencies. Preserve routes and model keys.
import fs from 'node:fs';
import path from 'node:path';
import { applyMapaProductLabels } from './apply-mapa-product-labels.mjs';
import { applyMapaAiBranding } from './apply-mapa-ai-branding.mjs';
const root = path.resolve('node_modules/@fleetbase/fleetops-engine');
const ui = path.resolve('node_modules/@fleetbase/ember-ui');
function edit(file, transform) { fs.writeFileSync(file, transform(fs.readFileSync(file, 'utf8'))); }
function replace(file, from, to) { edit(file, text => { if (!text.includes(from)) throw new Error(`Missing MAPA anchor: ${file}: ${from}`); return text.replaceAll(from, to); }); }
const words = text => text.replace(/\bWork Orders\b/g, 'Maintenance Jobs').replace(/\bwork orders\b/g, 'maintenance jobs')
 .replace(/\bOrders\b/g, 'Ambulance Calls').replace(/\bOrder\b/g, 'Ambulance Call')
 .replace(/\borders\b/g, 'ambulance calls')
 .replace(/\bVehicles\b/g, 'Ambulances').replace(/\bVehicle\b/g, 'Ambulance')
 .replace(/\bvehicles\b/g, 'ambulances').replace(/\bvehicle\b/g, 'ambulance')
 .replace(/\bPlaces\b/g, 'Hospitals & Bases').replace(/\bPlace\b/g, 'Hospital / Base');
for (const file of [path.join(root, 'translations/en-us.yaml'), 'translations/en-us.yaml']) {
 edit(file, text => text.split('\n').map(line => line.replace(/^(\s*[\w-]+:\s+)(.+)$/, (_, key, value) => key + value.split(/(\{[^{}]*\})/g).map(part => part.startsWith('{') ? part : words(part)).join(''))).join('\n'));
}
// The checked-in application catalog is already branded; dependencies are fresh.
edit('translations/en-us.yaml', text => {
 if (text.includes('name: Fleetbase')) return text.replaceAll('name: Fleetbase', 'name: MAPA');
 if (text.includes('name: MAPA')) return text;
 throw new Error('Missing application product-name anchor');
});
// Product copy only; upstream package names, URLs, copyrights and Legal component stay intact.
for (const file of [path.join(root, 'translations/en-us.yaml'), 'translations/en-us.yaml']) {
 edit(file, text => text.replace(/\bFleetbase(?=\s)/g, 'MAPA').replace(/\bfleetbase(?=\s)/g, 'MAPA'));
}
replace(path.join(root, 'translations/en-us.yaml'), 'extension-name: Fleet-Ops', 'extension-name: MAPA Operations');
const extension = path.join(root, 'addon/extension.js');
// The header permission key must remain native after its visible title changes.
replace(extension, "registerHeaderMenuItem('Fleet-Ops', 'console.fleet-ops', {", "registerHeaderMenuItem('MAPA Operations', 'console.fleet-ops', {\n            id: 'fleet-ops',\n            slug: 'fleet-ops',\n            view: 'fleet-ops',");
edit(extension, text => text.replace(/title: '(Orders|Places|Vehicles)'/g, (_, label) => `title: '${words(label)}'`)
 .replaceAll('Fleet-Ops Config', 'MAPA Operations Config')
 .replace('Dispatch, fleet management, driver tracking, and logistics operations.', 'MAPA ambulance dispatch, readiness, and live tracking.')
 .replace(/\s*\{\s*title: (?:intlService\.t\('menu.trailers'\)|'Service Rates'),[\s\S]*?\n\s*\},/g, ''));
edit(path.join(root, 'addon/components/layout/fleet-ops-sidebar.js'), text => text.split('\n').filter(line => !/this\.createItem\('menu\.(trailers|vendors|service-rates|payments)'/.test(line)).join('\n'));
// Navigation extent only, not an administrative geofence. Do not alter resource GPS.
const map = path.join(root, 'addon/components/map/leaflet-live-map.js');
replace(map, 'this.location.getLatitude()', '18.01');
replace(map, 'this.location.getLongitude()', '82.59');
replace(map, 'return 14;', 'return 9;');
replace(map, "set(map, 'livemap', this);", "set(map, 'livemap', this);\n        map.fitBounds([[17.50, 82.02], [18.52, 83.16]], { padding: [24, 24] });");
replace(map, "this.universe.on('user.located', this._locationUpdateHandler);", '// Open on ASR district independently of operator IP location.');
for (const [relative, anchor] of [['layout/header/smart-nav-menu.js', 'const raw = this.universe.headerMenuItems ?? [];'], ['layout/mobile-navbar.js', 'const headerMenuItems = this.universe.headerMenuItems;']]) {
 const expression = "(this.universe.headerMenuItems ?? []).filter(item => /fleet-ops|iam/.test(item.route ?? item.id ?? ''))";
 replace(path.join(ui, 'addon/components', relative), anchor, anchor.startsWith('const raw') ? `const raw = ${expression};` : `const headerMenuItems = ${expression};`);
}
// Remove freight/pricing inputs from ambulance call creation.
edit(path.join(root, 'addon/components/order/form.hbs'), text => text.split('\n').filter(line => !/<Order::Form::(?:Payload|ServiceRate|Metadata) /.test(line)).join('\n'));
edit(path.join(root, 'addon/components/order/form/details.hbs'), text => text
 .replace(/\s*<InputGroup @name=\{\{t "order.fields.(?:customer|facilitator)"\}\}[\s\S]*?<\/InputGroup>/g, '')
 .replace(/\s*\{\{#if \(and @resource.facilitator.isIntegratedVendor @resource.facilitator.service_types.length\)\}\}[\s\S]*?\{\{\/if\}\}/, ''));
replace(path.join(root, 'addon/components/order/form/details.hbs'), 'this.orderConfigActions.allOrderConfigs', 'this.mapaOrderConfigs');
replace(path.join(root, 'addon/components/order/form/details.js'), 'this.orderConfigActions.loadAll.perform();', `this.orderConfigActions.loadAll.perform().then(() => {
            if (!this.isDestroying && !this.isDestroyed) {
                const config = this.args.resource.order_config ?? this.mapaOrderConfigs[0];
                if (config) this.selectOrderConfig.perform(config);
            }
        });`);
replace(path.join(root, 'addon/components/order/form/details.js'), 'get integratedVendorServiceType() {', `get mapaOrderConfigs() {
        return this.orderConfigActions.allOrderConfigs.filter(config => config.key === 'transport' || config.tags?.includes('asr'));
    }

    get integratedVendorServiceType() {`);
console.log('MAPA overlay applied');
// Use our product footer while retaining the dependency's accurate legal notices.
function visitTemplates(directory) {
 for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
  const file = path.join(directory, entry.name);
  if (entry.isDirectory()) visitTemplates(file);
  else if (file.endsWith('.hbs')) edit(file, text => {
    text = text.replaceAll('<FleetbaseAttribution', '<MapaAttribution');
    // Do not relabel third-party notices or a third-party news source.
    if (!/legal|fleetbase-blog/.test(file)) text = text.replace(/\bFleetbase(?=[\s!,.])/g, 'MAPA');
    return text;
  });
 }
}
visitTemplates(path.join(ui, 'addon'));
visitTemplates('app');
for (const relative of ['app/controllers/invite/for-user.js', 'app/controllers/onboard/verify-email.js', 'app/components/onboarding/form.js', 'app/components/onboarding/verify-email.js', 'app/components/configure/notification-channels.js', 'app/components/configure/services.js']) {
 edit(relative, text => text.replaceAll('Welcome to Fleetbase!', 'Welcome to MAPA!').replaceAll('welcome to Fleetbase!', 'welcome to MAPA!').replaceAll('Hello World from Fleetbase', 'Hello from MAPA').replaceAll('This is a Fleetbase SMS test.', 'This is a MAPA SMS test.'));
}
// A few upstream screens use literal labels instead of the translation catalog.
for (const relative of ['addon/components/layout/fleet-ops-sidebar/operations-monitor.js', 'addon/controllers/management/index.js', 'addon/components/fleet/hierarchy-tree.js', 'addon/controllers/management/fleets/index/details.js']) {
 edit(path.join(root, relative), text => text.replace(/((?:label|title|description|action):\s*)'([^']*)'/g, (_, prefix, value) => prefix + "'" + words(value).replace(/\bvehicle\b/g, 'ambulance') + "'"));
}
replace(path.join(root, 'addon/components/layout/fleet-ops-sidebar/operations-monitor.js'), '} vehicles online', '} ambulances online');
replace(path.join(root, 'addon/components/layout/fleet-ops-sidebar.hbs'), 'Search Fleet-Ops...', 'Search MAPA operations...');
replace(path.join(root, 'addon/components/map/drawer.js'), "_createMenuItem('Vehicles'", "_createMenuItem('Ambulances'");
replace(path.join(root, 'addon/components/map/drawer.js'), "_createMenuItem('Places'", "_createMenuItem('Hospitals & Bases'");
for (const relative of ['addon/components/map/drawer/vehicle-listing.hbs', 'addon/components/map/drawer/place-listing.hbs']) {
 edit(path.join(root, relative), text => text.replace(/((?:title|description)=)"([^"]*)"/g, (_, prefix, value) => prefix + '"' + words(value) + '"'));
}
// Keep transport evidence optional, with the ASR handover record replacing the freight proof toggle.
edit(path.join(root, 'addon/components/order/form/details.hbs'), text => text.replace(/\s*<div class="lg:col-span-2">\s*<InputGroup @wrapperClass="pt-1">\s*<Toggle\s+@label=\{\{t "order.fields.require-proof"\}\}[\s\S]*?<\/div>/, ''));
edit(path.join(root, 'addon/templates/operations/orders/index/new.hbs'), text => {
 text = /@modelName="[^"]*"/.test(text)
  ? text.replace(/@modelName="[^"]*"/, '@modelName="call"')
  : text.replace('@saveTask={{this.save}}', '@saveTask={{this.save}}\n    @modelName="call"');
 const hint = '<p class="mapa-create-call-help">Select pickup and drop-off to enable Create call.</p>';
 return text.includes(hint) ? text : text.replace('<Order::Form ', hint + '\n    <Order::Form ');
});
// This is the display label only; permissions and saving continue to use @resource.
edit(path.join(ui, 'addon/components/layout/resource/panel/header.hbs'), text =>
 text.includes('@modelName={{@modelName}}') ? text
 : text.replace('@resource={{@resource}}', '@resource={{@resource}}\n            @modelName={{@modelName}}'));
applyMapaAiBranding(path.resolve('node_modules/@fleetbase/ai-engine'));


// Keep product-owned documentation chrome neutral; upstream destinations stay accurate.
edit(path.join(ui, 'addon/components/layout/header.js'), text => text.replaceAll("title: 'Fleetbase Documentation'", "title: 'Documentation'"));
edit(path.join(ui, 'addon/components/docs-panel.hbs'), text => text.replaceAll('Open Fleetbase documentation', 'Open documentation').replaceAll('Open MAPA documentation', 'Open documentation'));
applyMapaProductLabels();
// Show release notes for this MAPA deployment, rather than unrelated upstream releases.
edit(path.join(ui, 'addon/components/modals/changelog.js'), text => text.replaceAll('https://api.github.com/repos/fleetbase/fleetbase/releases', '/mapa-changelog.json'));
