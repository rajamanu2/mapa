// Validate only a freshly compiled public console; no server or database access.
import fs from 'node:fs/promises';
import path from 'node:path';
import { createHash } from 'node:crypto';
import { makeRuntimeConfig } from '../../cloud/runtime-config.mjs';

const root = path.resolve(process.argv[2] || 'runtime/console-release/console');
const origin = 'https://app.itda.asrhealth.in';
const expected = makeRuntimeConfig(origin);
const actual = JSON.parse(await fs.readFile(path.join(root, 'fleetbase.config.json'), 'utf8'));
for (const [key, value] of Object.entries(expected)) {
    if (actual[key] !== value) throw new Error(`Wrong production runtime setting: ${key}`);
}
const index = await fs.readFile(path.join(root, 'index.html'), 'utf8');
if (/localhost|127\.0\.0\.1|mapa-preview-status/.test(index)) throw new Error('Preview/development HTML in release');
const references = [];
for (const match of index.matchAll(/<(?:script|link)\b[^>]*\b(?:src|href)=["']([^"']+)["'][^>]*>/gi)) {
    if (/^(?:https?:)?\/\//.test(match[1]) || match[1].startsWith('data:')) continue;
    const relative = decodeURIComponent(match[1].split(/[?#]/)[0]).replace(/^\//, '');
    const target = path.resolve(root, relative);
    if (!target.startsWith(root + path.sep)) throw new Error('Asset path escapes release');
    await fs.access(target);
    references.push(relative);
}
for (const feature of ['mapa-gps-device-layer', 'mapa-feature-workspace', 'mapa-intake-native']) {
    if (!references.some(name => name.includes(feature) && name.endsWith('.js'))) throw new Error(`Missing compiled feature: ${feature}`);
}
for (const stable of ['call-intake/index.html', 'track/index.html', 'track/tracking.js', 'track/tracking-i18n.js', 'track/tracking.css',
    'mapa-voice.js', 'mapa-voice.css', 'mapa-map/leaflet.js', 'mapa-map/leaflet.css', 'mapa-map/leaflet-license.txt', 'images/asr-health-icon.svg', 'mapa-icon.svg']) {
    await fs.access(path.join(root, stable));
}
const files = {};
async function walk(directory) {
    for (const item of await fs.readdir(directory, { withFileTypes: true })) {
        if (item.isSymbolicLink() || item.name.startsWith('.') || ['private', 'node_modules', 'backups'].includes(item.name)) throw new Error('Private or redirected path in console');
        const filename = path.join(directory, item.name);
        if (item.isDirectory()) { await walk(filename); continue; }
        if (!item.isFile() || /\.(?:env|sql|sqlite|key|pem|p12|jsonl|log|map)$/i.test(item.name)) throw new Error('Non-public file in console');
        const bytes = await fs.readFile(filename);
        if (/-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----/.test(bytes.toString())) throw new Error('Private key in console');
        files[path.relative(root, filename).replaceAll(path.sep, '/')] = createHash('sha256').update(bytes).digest('hex');
    }
}
await walk(root);
const manifest = { type: 'compiled-console-candidate', commit: process.env.MAPA_BUILD_SHA || null, origin,
    compiled: true, deployed: false, api_runtime_tested: false, live_dispatch_accepted: false, files };
await fs.writeFile(path.join(root, '..', 'release-manifest.json'), JSON.stringify(manifest, null, 2) + '\n');
console.log(`Verified ${Object.keys(files).length} public files for ${origin}; no deployment performed.`);
