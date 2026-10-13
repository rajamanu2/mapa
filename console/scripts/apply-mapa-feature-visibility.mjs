// Restore native MAPA controls removed by the earlier reduced ambulance console.
// This overlay keeps native routes, field bindings, permission checks and API calls.
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const fragments = JSON.parse(fs.readFileSync(new URL('./mapa-feature-fragments.json', import.meta.url), 'utf8'));
const single = (text, anchor, replacement) => {
    if (text.split(anchor).length !== 2) throw new Error(`MAPA feature anchor changed: ${anchor}`);
    return text.replace(anchor, replacement);
};
const insertBefore = (text, marker, anchor, content) => text.includes(marker) ? text : single(text, anchor, content + '\n' + anchor);
const restoreLine = (text, marker, anchor, line) => insertBefore(text, marker, anchor, line);

export function restoreFeatureSource(relative, source) {
    let text = source.replace(/\r\n/g, '\n');
    if (relative === 'fleetops-engine/addon/components/layout/fleet-ops-sidebar.js') {
        text = restoreLine(text, "this.createItem('menu.service-rates'", "        ]);\n    }\n\n    get resourcesItems()", "            this.createItem('menu.service-rates', 'file-invoice-dollar', 'operations.service-rates', 'fleet-ops list service-rate', 'fleet-ops see service-rate', ['rates', 'pricing']),");
        text = restoreLine(text, "this.createItem('menu.vendors'", "            this.createItem('menu.contacts'", "            this.createItem('menu.vendors', 'warehouse', 'management.vendors', 'fleet-ops list vendor', 'fleet-ops see vendor'),");
        text = text.replace("this.createItem('menu.dashboard', 'chart-line', 'console.home'", "this.createItem('menu.dashboard', 'chart-line', 'analytics.index'");
        const dashboard = "            this.createItem('menu.dashboard', 'chart-line', 'analytics.index', 'fleet-ops list order', 'fleet-ops see order', ['metrics', 'overview']),";
        if (!text.includes(dashboard)) text = single(text, "        return this.withRegistryItems('operations', [", "        return this.withRegistryItems('operations', [\n" + dashboard);
    } else if (relative === 'fleetops-engine/addon/extension.js') {
        const shortcut = "                {\n                    title: 'Service Rates',\n                    description: 'Configure pricing rules and service rate cards.',\n                    icon: 'tags',\n                    route: 'console.fleet-ops.operations.service-rates',\n                },";
        text = insertBefore(text, "route: 'console.fleet-ops.operations.service-rates'", "                {\n                    title: 'Devices',", shortcut);
        text = enableSummaryWidget(text, 'fleet-ops-key-metrics-widget');
    } else if (relative === 'storefront-engine/addon/extension.js') {
        text = enableSummaryWidget(text, 'storefront-key-metrics-widget');
    } else if (relative.endsWith('/layout/header/smart-nav-menu.js') || relative.endsWith('/layout/mobile-navbar.js')) {
        text = text.replaceAll("(this.universe.headerMenuItems ?? []).filter(item => /fleet-ops|iam/.test(item.route ?? item.id ?? ''))", '(this.universe.headerMenuItems ?? [])');
    } else if (relative === 'fleetops-engine/addon/components/order/form.hbs') {
        for (const name of ['Payload', 'ServiceRate']) text = insertBefore(text, `<Order::Form::${name} `, '        <Order::Form::Notes ', `        <Order::Form::${name} @resource={{@resource}} />`);
        text = insertBefore(text, '<Order::Form::Metadata ', '        <RegistryYield @registry="fleet-ops:component:order:form:end"', '        <Order::Form::Metadata @resource={{@resource}} />');
    } else if (relative === 'fleetops-engine/addon/components/order/form/details.hbs') {
        const driver = '        <InputGroup @name={{t "order.fields.assign-driver"}}';
        for (const name of ['customer', 'facilitator']) text = insertBefore(text, `@name={{t "order.fields.${name}"}}`, driver, fragments[name].replace(/\r\n/g, '\n') + '\n');
        text = insertBefore(text, '@name={{t "order.fields.service-type"}}', driver, fragments.serviceType.replace(/\r\n/g, '\n') + '\n');
        text = insertBefore(text, '@label={{t "order.fields.require-proof"}}', '        <RegistryYield @registry="fleet-ops:component:order:form:details"', fragments.proofToggle.replace(/\r\n/g, '\n') + '\n');
    } else if (relative === 'fleetops-engine/addon/components/order/form/details.js') {
        text = text.replace("return this.orderConfigActions.allOrderConfigs.filter(config => config.key === 'transport' || config.tags?.includes('asr'));", 'return this.orderConfigActions.allOrderConfigs;');
        text = text.replace('this.args.resource.order_config ?? this.mapaOrderConfigs[0]', "this.args.resource.order_config ?? this.mapaOrderConfigs.find(config => config.key === 'transport' || config.tags?.includes('asr')) ?? this.mapaOrderConfigs[0]");
    } else if (relative === 'fleetops-engine/addon/components/widget/fleet-ops-key-metrics.js') {
        text = text.replace('return TOP_KEYS.filter((key) => this.metrics[key] !== undefined)', 'return [...TOP_KEYS, ...Object.keys(this.metrics).filter((key) => !TOP_KEYS.includes(key))].filter((key) => this.metrics[key] !== undefined)');
        text = text.replace('/** Subset shown in the compact legacy widget. Full 13 metrics still come back from\n *  the API; users who want the rest should adopt the per-tile KPI widgets. */', '/** Show every metric returned by the native API, with the primary metrics first. */');
    } else if (relative === 'fleetops-engine/addon/components/widget/fleet-ops-key-metrics.hbs') {
        text = text.replace('>Legacy</span>', '>Recorded activity</span>').replace('grid grid-cols-2 lg:grid-cols-3 gap-1.5', 'grid grid-cols-2 lg:grid-cols-6 gap-1.5');
    } else if (relative === 'fleetops-engine/addon/routes/operations/orders/index/new.js') {
        text = text.replace('    activate() {\n        this.sidebar.hide();', '    activate() {\n        this.sidebar.show();');
    } else if (relative === 'fleetops-engine/addon/components/order/details/purchase-rate.hbs') {
        if (!text.includes('No purchase rate has been recorded for this call.')) text = single(text, '    </ContentPanel>\n{{/if}}', '    </ContentPanel>\n{{else}}\n    <ContentPanel @title={{t "order.fields.purchase-rate-panel-title"}} @isLoading={{@isLoading}} @open={{true}} @wrapperClass="bordered-top">\n        <p class="text-sm text-gray-500 dark:text-gray-400">No purchase rate has been recorded for this call.</p>\n    </ContentPanel>\n{{/if}}');
    } else if (relative === 'fleetops-engine/addon/templates/analytics/index.hbs') {
        text = insertBefore(text, '<Widget::FleetOpsKeyMetrics ', '    <Dashboard\n', '    <section class="p-4" aria-label="MAPA operations metrics">\n        <Widget::FleetOpsKeyMetrics />\n    </section>');
        text = text.replace('@defaultDashboardName="Fleet-Ops Dashboard"', '@defaultDashboardName="MAPA Operations"');
    } else if (relative === 'storefront-engine/addon/components/widget/storefront-key-metrics.js') {
        const request = "        this.metrics = yield this.fetch.get('metrics', this.storefrontDashboard.queryParams, { namespace: 'storefront/int/v1' }).then((response) => {\n            return this.createMetricsMapFromResponse(response);\n        });";
        if (!text.includes('}).catch(() => null);')) text = single(text, request, request.replace('        });', '        }).catch(() => null);'));
    } else if (relative === 'storefront-engine/addon/components/widget/storefront-key-metrics.hbs') {
        if (!text.includes('Storefront metrics are unavailable.')) {
            text = single(text, '                <Spinner />\n            {{else}}', '                <Spinner />\n            {{else if (eq this.metrics null)}}\n                <div role="status" class="p-4 border border-gray-200 dark:border-gray-700 rounded-lg">\n                    <p class="text-sm mb-3">Storefront metrics are unavailable. The connected service could not return metrics.</p>\n                    <Button @text="Retry" @size="xs" @onClick={{perform this.getDashboardMetrics}} />\n                </div>\n            {{else}}');
            text = single(text, '                    {{/each-in}}', '                    {{else}}\n                        <p class="col-span-full text-sm text-gray-500 dark:text-gray-400">No Storefront metrics have been recorded for this period.</p>\n                    {{/each-in}}');
        }
    }
    return text;
}

function enableSummaryWidget(text, id) {
    const start = text.indexOf(`id: '${id}'`);
    if (start === -1) throw new Error(`Missing native summary widget: ${id}`);
    const end = text.indexOf('default:', start);
    if (end === -1) throw new Error(`Missing native summary widget default: ${id}`);
    const scope = text.slice(start, end).replace("name: 'Fleet-Ops Metrics (Legacy)'", "name: 'MAPA Operations Metrics'").replace("name: 'Storefront Metrics (Legacy)'", "name: 'Storefront Metrics'").replace("description: 'DEPRECATED — replaced by individual KPI tile widgets. Will be removed in the next major release.'", "description: 'All recorded fleet metrics from the native API.'").replace("description: 'Legacy grouped Storefront metrics.'", "description: 'Recorded Storefront metrics from the native API.'").replace("category: 'Legacy'", "category: 'Metrics'");
    return text.slice(0, start) + scope + text.slice(end).replace(/^default: false/, 'default: true');
}

export const featureSources = [
    'fleetops-engine/addon/components/layout/fleet-ops-sidebar.js',
    'fleetops-engine/addon/extension.js',
    'storefront-engine/addon/extension.js',
    'ember-ui/addon/components/layout/header/smart-nav-menu.js',
    'ember-ui/addon/components/layout/mobile-navbar.js',
    'fleetops-engine/addon/components/order/form.hbs',
    'fleetops-engine/addon/components/order/form/details.hbs',
    'fleetops-engine/addon/components/order/form/details.js',
    'fleetops-engine/addon/components/widget/fleet-ops-key-metrics.js',
    'fleetops-engine/addon/components/widget/fleet-ops-key-metrics.hbs',
    'fleetops-engine/addon/routes/operations/orders/index/new.js',
    'fleetops-engine/addon/components/order/details/purchase-rate.hbs',
    'fleetops-engine/addon/templates/analytics/index.hbs',
    'storefront-engine/addon/components/widget/storefront-key-metrics.js',
    'storefront-engine/addon/components/widget/storefront-key-metrics.hbs',
];

export function applyMapaFeatureVisibility(consoleRoot = process.cwd()) {
    // Validate every transformation before writing, so dependency drift is reported first.
    const updates = featureSources.map(relative => {
        const file = path.join(consoleRoot, 'node_modules/@fleetbase', relative);
        return [file, restoreFeatureSource(relative, fs.readFileSync(file, 'utf8'))];
    });
    for (const [file, text] of updates) fs.writeFileSync(file, text);
    console.log('MAPA native navigation, call controls and dashboard metrics are visible.');
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) applyMapaFeatureVisibility(process.argv[2]);
