// Product copy only. Preserve translation keys, native API/model identifiers and notices.
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const groups = new Set(['developers', 'iam', 'settings', 'storefront', 'trailer', 'widget', 'fleet-ops', 'integrated-vendor', 'modals', 'order', 'order-config-manager', 'app', 'auth', 'onboard', 'install', 'invite']);
const words = text => text.replaceAll('Fleetbase', 'MAPA').replaceAll('Fleet-Ops', 'MAPA Operations').replaceAll('MAPA CLI', 'application CLI');

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
    for (const [file, content] of updates) fs.writeFileSync(file, content);
    console.log('MAPA product labels applied; translation keys, data contracts and upstream notices preserved.');
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) applyMapaProductLabels(process.argv[2]);
