import fs from 'node:fs';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import { featureSources, restoreFeatureSource } from './apply-mapa-feature-visibility.mjs';

const packages = new URL('../../packages/', import.meta.url);
const roots = { 'fleetops-engine': 'fleetops', 'ember-ui': 'ember-ui', 'storefront-engine': 'storefront' };
let checks = 0;
const check = (condition, message) => { assert.ok(condition, message); checks++; };
const originals = new Map(featureSources.map(relative => {
    const [pkg, ...rest] = relative.split('/');
    return [relative, fs.readFileSync(new URL(roots[pkg] + '/' + rest.join('/'), packages), 'utf8').replace(/\r\n/g, '\n')];
}));
const restored = new Map();
for (const [relative, original] of originals) {
    let reduced = original;
    if (relative.endsWith('/layout/fleet-ops-sidebar.js')) reduced = reduced.split('\n').filter(line => !/this\.createItem\('menu\.(service-rates|vendors)'/.test(line)).join('\n');
    if (relative === 'fleetops-engine/addon/extension.js') reduced = reduced.replace(/\s*\{\s*title: 'Service Rates',[\s\S]*?\n\s*\},/, '');
    if (relative.endsWith('/order/form.hbs')) reduced = reduced.split('\n').filter(line => !/<Order::Form::(?:Payload|ServiceRate|Metadata) /.test(line)).join('\n');
    if (relative.endsWith('/order/form/details.hbs')) {
        reduced = reduced.replace(/\s*<InputGroup @name=\{\{t "order.fields.(?:customer|facilitator)"\}\}[\s\S]*?<\/InputGroup>/g, '')
            .replace(/\s*\{\{#if \(and @resource.facilitator.isIntegratedVendor @resource.facilitator.service_types.length\)\}\}[\s\S]*?\{\{\/if\}\}/, '')
            .replace(/\s*<div class="lg:col-span-2">\s*<InputGroup @wrapperClass="pt-1">\s*<Toggle\s+@label=\{\{t "order.fields.require-proof"\}\}[\s\S]*?<\/div>/, '');
        reduced += '\n{{! MAPA caller phone and source integrations must remain intact }}\n';
    }
    if (relative.includes('/layout/header/')) reduced = reduced.replace('this.universe.headerMenuItems ?? []', "(this.universe.headerMenuItems ?? []).filter(item => /fleet-ops|iam/.test(item.route ?? item.id ?? ''))");
    if (relative.endsWith('/layout/mobile-navbar.js')) reduced = reduced.replace('this.universe.headerMenuItems;', "(this.universe.headerMenuItems ?? []).filter(item => /fleet-ops|iam/.test(item.route ?? item.id ?? ''));");
    const result = restoreFeatureSource(relative, reduced);
    check(restoreFeatureSource(relative, result) === result, `${relative}: reapplying overlay is idempotent`);
    restored.set(relative, result);
}

const sidebar = restored.get('fleetops-engine/addon/components/layout/fleet-ops-sidebar.js');
check(sidebar.includes("'operations.service-rates', 'fleet-ops list service-rate', 'fleet-ops see service-rate'"), 'service-rate navigation retains native permissions');
check(sidebar.includes("'management.vendors', 'fleet-ops list vendor', 'fleet-ops see vendor'"), 'vendor navigation retains native permissions');
check(sidebar.includes("this.createItem('menu.dashboard', 'chart-line', 'analytics.index'"), 'operations sidebar exposes the native analytics dashboard');
const analytics = restored.get('fleetops-engine/addon/templates/analytics/index.hbs');
check(analytics.indexOf('<Widget::FleetOpsKeyMetrics />') < analytics.indexOf('<Dashboard\n') && analytics.includes('@defaultDashboardName="MAPA Operations"'), 'all native metrics are visible before the saved dashboard layout');
const details = restored.get('fleetops-engine/addon/components/order/form/details.hbs');
check(details.includes('this.selectCustomer') && details.includes('this.selectFacilitator') && details.includes('this.toggleProofOfDelivery'), 'restored controls invoke existing backend-bound actions');
check(details.includes('@disabled={{cannot-write @resource}}'), 'restored controls retain resource permission checks');
check(details.includes('MAPA caller phone and source integrations'), 'restoring feature controls preserves unrelated MAPA additions');
for (const name of ['Payload', 'ServiceRate', 'Metadata']) check(restored.get('fleetops-engine/addon/components/order/form.hbs').includes(`<Order::Form::${name} `), `${name} native section restored`);
for (const name of ['header/smart-nav-menu', 'mobile-navbar']) {
    const content = restored.get(`ember-ui/addon/components/layout/${name}.js`);
    check(!content.includes('/fleet-ops|iam/') && content.includes('this.abilities.can('), `${name}: installed modules are visible while native permission filtering remains`);
}
const route = restored.get('fleetops-engine/addon/routes/operations/orders/index/new.js');
check(!route.includes('this.sidebar.hide()') && route.includes("this.abilities.cannot('fleet-ops create order')"), 'new-call sidebar stays visible and create authorization stays enforced');
check(restored.get('fleetops-engine/addon/components/order/details/purchase-rate.hbs').includes('No purchase rate has been recorded'), 'missing quote has a truthful visible empty state');
let metricSource = restored.get('fleetops-engine/addon/components/widget/fleet-ops-key-metrics.js');
metricSource = metricSource.replace(/^import .*;\n/gm, '').replace('export default class', 'globalThis.Metrics = class').replace(/@(?:service|tracked|task)\s+/g, '');
const sandbox = { Component: class {}, formatCurrency: (value, currency) => `${currency} ${value}`, formatMeters: value => `${value}m` };
vm.runInNewContext(metricSource, sandbox);
const metrics = Object.create(sandbox.Metrics.prototype);
const response = { earnings: 20, orders_completed: 4, orders_in_progress: 2, drivers_online: 1, fuel_costs: 10, total_distance_traveled: 1500, orders_canceled: 1, orders_scheduled: 3, total_drivers: 5, total_customers: 2, open_issues: 2, resolved_issues: 1, total_vehicles: 6 };
metrics.metrics = metrics.createMetricsMapFromResponse(response);
check(metrics.topMetrics.length === 13, 'all thirteen returned native metrics appear');
check(metrics.topMetrics.find(entry => entry.key === 'orders_scheduled').formattedValue === 3, 'previously omitted metric retains its API value');
metrics.metrics = metrics.createMetricsMapFromResponse({ orders_completed: 4 });
check(metrics.topMetrics.length === 1, 'missing metrics are not invented as zeros');
let storefrontSource = restored.get('storefront-engine/addon/components/widget/storefront-key-metrics.js');
storefrontSource = storefrontSource.replace(/^import .*;\n/gm, '').replace('export default class', 'globalThis.StorefrontMetrics = class').replace(/@(?:service|tracked|task)\s+/g, '');
vm.runInNewContext(storefrontSource, sandbox);
const storefrontMetrics = Object.create(sandbox.StorefrontMetrics.prototype);
storefrontMetrics.storefrontDashboard = { queryParams: { period: 'month' } };
storefrontMetrics.metrics = { prior_metric: { value: 10 } };
storefrontMetrics.fetch = { get: () => Promise.reject(new Error('DEMO service unavailable')) };
let storefrontTask = storefrontMetrics.getDashboardMetrics();
storefrontTask.next(await storefrontTask.next().value);
check(storefrontMetrics.metrics === null, 'failed Storefront service clears stale values and signals unavailable without zeros');
storefrontMetrics.fetch = { get: () => Promise.resolve({}) };
storefrontTask = storefrontMetrics.getDashboardMetrics();
storefrontTask.next(await storefrontTask.next().value);
check(storefrontMetrics.metrics !== null && Object.keys(storefrontMetrics.metrics).length === 0, 'empty successful Storefront response differs from service failure');
storefrontMetrics.fetch = { get: () => Promise.resolve({ total_products: 5 }) };
storefrontTask = storefrontMetrics.getDashboardMetrics();
storefrontTask.next(await storefrontTask.next().value);
check(storefrontMetrics.metrics.total_products.value === 5, 'retry can recover and display returned Storefront metric');
const storefrontTemplate = restored.get('storefront-engine/addon/components/widget/storefront-key-metrics.hbs');
check(storefrontTemplate.includes('Storefront metrics are unavailable.') && storefrontTemplate.includes('@onClick={{perform this.getDashboardMetrics}}') && storefrontTemplate.includes('No Storefront metrics have been recorded'), 'Storefront provides unavailable, retry and empty states');
assert.throws(() => restoreFeatureSource('fleetops-engine/addon/components/order/form.hbs', '<div />'), /anchor changed/); checks++;
console.log(`MAPA feature visibility: ${checks} checks passed.`);
