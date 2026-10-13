/* Copyright (c) 2026 MAPA.DEV. SPDX-License-Identifier: AGPL-3.0-or-later */
'use strict';
const assert = require('node:assert/strict'), fs = require('node:fs'), vm = require('node:vm'), path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../public/mapa-voice.js'), 'utf8');
const core = require('../public/mapa-voice.js');
let count = 0;
function check(value, label) { assert.ok(value, label); count++; }
function setup(pathname) {
    const events = {}, opens = [];
    const location = { pathname, assign() { throw new Error('The source tab must not navigate'); } };
    const window = { addEventListener() {}, open(...args) { opens.push(args); } };
    const document = { documentElement: { dataset: {} }, readyState: 'loading',
        addEventListener(type, action, capture) { events[type] = { action, capture }; } };
    vm.runInNewContext(source, { window, document, location, MutationObserver: class { observe() {} } });
    const click = (selector, extras = {}, disabled = false, ariaDisabled = false) => {
        let prevented = false, stopped = false;
        const control = { disabled, getAttribute: () => ariaDisabled ? 'true' : null };
        events.click.action({ button: 0, defaultPrevented: false, ...extras,
            target: { closest: choices => choices.split(',').map(v => v.trim()).includes(selector) ? control : null },
            preventDefault() { prevented = true; }, stopImmediatePropagation() { stopped = true; } });
        return { prevented, stopped };
    };
    return { opens, click, location, events };
}
for (const selector of ['button.fleet-ops-sidebar-primary-action', 'a.fleet-ops-sidebar-primary-action', 'button#map-toolbar-create-order-button']) {
    const app = setup('/fleet-ops');
    const result = app.click(selector);
    check(result.prevented && result.stopped && app.events.click.capture === true, 'Native same-tab create handler is stopped before navigation');
    check(JSON.stringify(app.opens) === JSON.stringify([['/call-intake/', '_blank', 'noopener,noreferrer']]), 'Exactly one separate intake tab opens without opener access');
    check(app.location.pathname === '/fleet-ops', 'Operations stays at its original address');
    app.click(selector, {}, true); app.click(selector, {}, false, true);
    check(app.opens.length === 1, 'Disabled or unauthorized actions cannot open intake');
    app.click(selector, { button: 2 }); app.click(selector, { defaultPrevented: true });
    check(app.opens.length === 1, 'Unrelated secondary/prevented actions are preserved');
}
for (const pathname of ['/auth', '/settings', '/call-intake/']) {
    const app = setup(pathname); const result = app.click('button.fleet-ops-sidebar-primary-action');
    check(!result.prevented && app.opens.length === 0, 'Other pages do not have their navigation intercepted');
}
const app = setup('/fleet-ops');
check(!app.click('button.delete').prevented && !app.click('a.download').prevented && app.opens.length === 0, 'Unrelated Operations controls keep native behavior');
let messages = [], names = [], closes = 0;
core.notifyIntakeUpdated(class { constructor(name) { names.push(name); } postMessage(message) { messages.push(message); } close() { closes++; } });
check(names[0] === 'mapa-intake-updates' && closes === 1, 'Short-lived notification channel closes after publication');
check(JSON.stringify(messages) === '[{"type":"intake-updated","version":1}]', 'Signal contains no identity, record, location or credential');
core.notifyIntakeUpdated(undefined);
core.notifyIntakeUpdated(class { constructor() { throw new Error('Unavailable'); } });
core.notifyIntakeUpdated(class { postMessage() { throw new Error('Unavailable'); } close() { closes++; } });
check(closes === 2, 'Unavailable cross-tab messaging does not break a confirmed save');
console.log(`MAPA separate call tabs: ${count} checks passed; no HTTP requests or operational writes.`);
