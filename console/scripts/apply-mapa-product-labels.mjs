// Product copy only. Preserve translation keys, native API/model identifiers and notices.
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const groups = new Set(['developers', 'iam', 'settings', 'storefront', 'trailer', 'widget', 'fleet-ops', 'integrated-vendor', 'modals', 'order', 'order-config-manager', 'app', 'auth', 'onboard', 'install', 'invite']);
// Match product words, never namespaces, URL paths, package names or schema keys.
const words = text => text.split(/(https?:\/\/[^\s"'<>]+)/g).map(part => /^https?:\/\//.test(part) ? part : part
    .replace(/\bFleetbase\b(?![\\/])/g, 'MAPA').replace(/\bFleet[- ]Ops\b/g, 'MAPA Operations').replaceAll('MAPA CLI', 'application CLI')).join('');

export function renameProductTemplate(text, relative = '') {
    if (/legal|fleetbase-blog/.test(relative)) return text;
    // Retain upstream notices and comments byte for byte.
    return text.split(/(\{\{!--[\s\S]*?--\}\}|\{\{![\s\S]*?\}\}|<!--[\s\S]*?-->)/g)
        .map(part => /^(\{\{!|<!--)/.test(part) ? part : part.split(/\n/).map(line => /copyright|\blicen[cs]e[ds]?\b|\bAGPL\b/i.test(line) ? line : words(line.replaceAll('Fleetbase Merchant Backend', '__UPSTREAM_MERCHANT_BACKEND__')).replaceAll('__UPSTREAM_MERCHANT_BACKEND__', 'Fleetbase Merchant Backend')).join('\n'))
        .join('');
}

export function renameProductJavaScript(text, relative = '') {
    if (/legal|fleetbase-blog/.test(relative)) return text;
    // Limit changes to display fields. Imports, permission keys, routes, model
    // names, translated keys, identifiers and upstream source comments stay intact.
    return text.replace(/(\b(?:title|label|name|description|helpText|loadingMessage|message)\s*:\s*)(['"`])((?:\\.|(?!\2)[^\\\r\n])*)\2/g,
        (_, prefix, quote, value) => prefix + quote + (/legal|copyright|\blicen[cs]e[ds]?\b|\bAGPL\b/i.test(value) ? value : words(value)) + quote)
        .replaceAll("return 'Fleet Ops';", "return 'MAPA Operations';")
        .replaceAll("return 'Fleet-Ops did not find imported provider bills that still need vehicle or trip matching.';", "return 'MAPA did not find imported provider bills that still need vehicle or trip matching.';");
}

export function renameProductTranslations(text) {
    let group = '';
    return text.split('\n').map(line => {
        const root = line.match(/^([\w-]+):/);
        if (root) group = root[1];
        if (line.trimStart().startsWith('#')) return line;
        const scalar = line.match(/^(\s*[\w-]+:\s*)(.*)$/);
        const selected = groups.has(group) || (group === 'registry-bridge' && /^\s*self-managed-help-text:/.test(line));
        if (!selected) return line;
        return scalar ? scalar[1] + words(scalar[2]) : words(line);
    }).join('\n');
}

export function applyMapaProductLabels(consoleRoot = process.cwd()) {
    const updates = new Map();
    const packageRoot = name => path.join(consoleRoot, 'node_modules/@fleetbase', name);
    const templates = [
        ['iam-engine', 'addon/components/widget/policy-surface.hbs', 'Fleetbase', 'MAPA'],
        ['ledger-engine', 'addon/components/customer-invoice.hbs', 'Fleetbase Ledger', 'MAPA Ledger'],
        ['ledger-engine', 'addon/components/modals/refund-result.hbs', 'Send this Fleetbase link to the customer.', 'Send this MAPA link to the customer.'],
        ['fleetops-engine', 'addon/controllers/connectivity/telematics/details/attachments.js', 'Fleetbase could not load the full provider device list.', 'MAPA could not load the full provider device list.'],
        ['fleetops-engine', 'addon/utils/fleet-ops-options.js', 'Vendor with native API integration into Fleetbase', 'Vendor with native API integration into MAPA'],
    ];
    for (const [pkg, relative, old, replacement] of templates) {
        const file = path.join(packageRoot(pkg), relative);
        const original = fs.readFileSync(file, 'utf8');
        if (!original.includes(old) && !original.includes(replacement)) throw new Error(`Missing product label anchor: ${pkg}/${relative}`);
        updates.set(file, original.replaceAll(old, replacement));
    }
    const roots = [path.join(consoleRoot, 'translations'), ...['iam-engine', 'dev-engine', 'storefront-engine', 'fleetops-engine', 'registry-bridge-engine'].map(name => path.join(packageRoot(name), 'translations'))];
    for (const root of roots) {
        for (const entry of fs.readdirSync(root, { withFileTypes: true })) {
            if (!entry.isFile() || !/\.ya?ml$/.test(entry.name)) continue;
            const file = path.join(root, entry.name);
            updates.set(file, renameProductTranslations(fs.readFileSync(file, 'utf8')));
        }
    }
    // Production installs engines from npm, so patch the installed source that
    // Ember actually compiles, not only the optional development checkouts.
    const sourceRoots = [path.join(consoleRoot, 'app'), ...fs.readdirSync(path.join(consoleRoot, 'node_modules/@fleetbase'), { withFileTypes: true })
        .filter(entry => (entry.isDirectory() || entry.isSymbolicLink()) && fs.statSync(packageRoot(entry.name)).isDirectory()).map(entry => path.join(packageRoot(entry.name), 'addon'))];
    function visit(directory) {
        if (!fs.existsSync(directory)) return;
        for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
            const file = path.join(directory, entry.name);
            if (entry.isDirectory()) visit(file);
            else if (/\.(hbs|js)$/.test(file)) {
                const original = updates.get(file) ?? fs.readFileSync(file, 'utf8');
                const transformed = file.endsWith('.hbs') ? renameProductTemplate(original, file) : renameProductJavaScript(original, file);
                if (transformed !== original) updates.set(file, transformed);
            }
        }
    }
    sourceRoots.forEach(visit);
    for (const [file, content] of updates) fs.writeFileSync(file, content);
    console.log('MAPA product labels applied; translation keys, data contracts and upstream notices preserved.');
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) applyMapaProductLabels(process.argv[2]);
