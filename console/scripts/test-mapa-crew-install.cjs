/* Copyright (c) 2026 MAPA.DEV. SPDX-License-Identifier: AGPL-3.0-or-later */
'use strict';
// Execute only in an isolated POSIX container: node console/scripts/test-mapa-crew-install.cjs.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const { spawnSync } = require('node:child_process');
if (process.platform === 'win32') throw new Error('Run this shell-installer acceptance test in an isolated POSIX container.');
const source = path.resolve(__dirname, '../public');
const installer = path.resolve(__dirname, 'install-mapa-voice.sh');
const fixtureRoot = fs.mkdtempSync(path.join(os.tmpdir(), 'mapa-native-install-'));
let assertions = 0;
function check(value, description) { assertions++; assert.ok(value, description); }
function equal(actual, expected, description) { assertions++; assert.equal(actual, expected, description); }
function install(target) { return spawnSync('sh', [installer, target, source], { encoding: 'utf8', timeout: 10000 }); }
function verify(html) {
    const scripts = [...html.matchAll(/<script\b([^>]*)><\/script>/g)].map(match => ({ attributes: match[1], src: /src="([^"]+)"/.exec(match[1])?.[1] }));
    const vendor = scripts.findIndex(script => /assets\/vendor[^/]*\.js/.test(script.src || ''));
    const native = scripts.findIndex(script => /mapa-crew-native\.js/.test(script.src || ''));
    const app = scripts.findIndex(script => /assets\/@fleetbase\/console[^/]*\.js/.test(script.src || ''));
    check(vendor >= 0 && vendor < native && native < app, 'Vendor loader executes before the native initializer, which executes before the native App boot.');
    check(!/\b(?:defer|async)\b/.test(scripts[native].attributes), 'Native initializer registration is synchronous.');
    equal(scripts.filter(script => /mapa-crew-native\.js/.test(script.src || '')).length, 1, 'Exactly one native initializer script is installed.');
    equal((html.match(/href="[^"]*mapa-crew-native\.css[^"]*"/g) || []).length, 1, 'Exactly one isolated native stylesheet is installed.');
    check(html.indexOf('mapa-crew-native.css') < html.indexOf('</head>'), 'Native stylesheet is in the document head.');
}
try {
    const syntax = spawnSync('sh', ['-n', installer], { encoding: 'utf8', timeout: 10000 }); equal(syntax.status, 0, 'Installer shell syntax is valid.');
    const samples = [
        '<!DOCTYPE html>\n<html><head></head><body>\n<script src="/assets/vendor.js"></script>\n<script src="/assets/@fleetbase/console.js"></script>\n</body></html>\n',
        '<!DOCTYPE html><html><head><script defer src="/mapa-crew-native.js?v=old"></script><link rel="stylesheet" href="/mapa-crew-native.css?v=old"></head><body><script integrity="sha256-test" src="/assets/vendor-abcd.js"></script><script src="/assets/@fleetbase/console-efgh.js"></script><script src="/mapa-crew-native.js?v=duplicate"></script></body></html>',
        fs.readFileSync(path.resolve(__dirname, '../app/index.html'), 'utf8'),
    ];
    samples.forEach((html, index) => {
        const target = path.join(fixtureRoot, `valid-${index}`); fs.mkdirSync(target); fs.writeFileSync(path.join(target, 'index.html'), html);
        const first = install(target); equal(first.status, 0, `Installer accepts valid HTML fixture ${index}: ${first.stderr}`);
        const firstHtml = fs.readFileSync(path.join(target, 'index.html'), 'utf8'); verify(firstHtml);
        for (const asset of ['mapa-crew-native.js', 'mapa-crew-native.css']) equal(fs.readFileSync(path.join(target, asset), 'utf8'), fs.readFileSync(path.join(source, asset), 'utf8'), `Shipped ${asset} matches reviewed source.`);
        const intake = fs.readFileSync(path.join(target, 'call-intake/index.html'), 'utf8'); check(!intake.includes('mapa-crew-native.js'), 'Standalone Call Intake does not load a native Ember initializer.');
        const second = install(target); equal(second.status, 0, 'Re-running installer succeeds.');
        equal(fs.readFileSync(path.join(target, 'index.html'), 'utf8'), firstHtml, 'Re-running installer is byte-idempotent, with no duplicate tags or blank-line growth.');
    });
    const voice = '<link rel="stylesheet" href="/mapa-voice.css?v=20260929-asr-landmarks"><script defer src="/mapa-voice.js?v=20260929-asr-landmarks"></script><link rel="stylesheet" href="/mapa-layout-fixes.css?v=20260919-call-layout">';
    [
        `<html><head>${voice}</head><body><script src="/assets/@fleetbase/console.js"></script><script src="/assets/vendor.js"></script></body></html>\n`,
        `<html><head>${voice}</head><body><script src="/assets/vendor.js"></script></body></html>\n`,
        `<html><head>${voice}</head><body><script src="/assets/@fleetbase/console.js"></script></body></html>\n`,
    ].forEach((html, index) => {
        const target = path.join(fixtureRoot, `invalid-${index}`); fs.mkdirSync(target); fs.writeFileSync(path.join(target, 'index.html'), html);
        const result = install(target); check(result.status !== 0, 'Missing/misordered native bundles fail installer acceptance.');
        equal(fs.readFileSync(path.join(target, 'index.html'), 'utf8'), html, 'Rejected native insertion does not overwrite the original index.');
        check(!fs.readdirSync(target).some(name => name.startsWith('.mapa-native-index.')), 'Failed installer removes its own narrow temporary index.');
    });
    console.log(`PASS ${assertions} native crew installer assertions in isolated filesystem. No running service modified.`);
} finally {
    const resolved = path.resolve(fixtureRoot);
    if (path.dirname(resolved) !== path.resolve(os.tmpdir()) || !path.basename(resolved).startsWith('mapa-native-install-')) throw new Error('Unexpected fixture cleanup target.');
    fs.rmSync(resolved, { recursive: true, force: true });
}