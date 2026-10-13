/* Copyright (c) 2026 MAPA.DEV. SPDX-License-Identifier: AGPL-3.0-or-later */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

// Execute the shipped close handlers and child leave policy with synthetic DOM
// events. No credentials, browser, network, messages or operational records.
const native = fs.readFileSync(path.join(__dirname, '../public/mapa-intake-native.js'), 'utf8');
const voice = fs.readFileSync(path.join(__dirname, '../public/mapa-voice.js'), 'utf8');
const line = (source, text) => {
    const found = source.split('\n').find(row => row.includes(text));
    assert.ok(found, 'Production handler exists: ' + text);
    return found;
};
const from = native.indexOf('        function closeIntake() {');
const to = native.indexOf('        function mountIntakeTab() {', from);
assert.ok(from >= 0 && to > from, 'Production native navigation functions exist');
const production = [
    line(native, 'function on(target, name, handler'),
    native.slice(from, to),
    line(native, 'window.mapaCloseIntake='),
    line(native, "on(document, 'click', guardNativeViewChange"),
].join('\n');
let checks = 0;
const check = (value, message) => { assert.ok(value, message); checks++; };

function fixture({ state = {}, answer = false, frame = true, childPolicy = true } = {}) {
    const registered = [], removed = [], confirmations = [], effects = [];
    const document = {
        addEventListener(name, handler, capture) { registered.push({ name, handler, capture }); },
        removeEventListener(name, handler, capture) {
            const index = registered.findIndex(row => row.name === name && row.handler === handler && row.capture === capture);
            if (index >= 0) registered.splice(index, 1);
            removed.push({ name, capture });
        },
    };
    const child = {
        busy: false, pending: null, dirty: false, resourceEditor: null, ...state,
        window: { confirm(message) { confirmations.push(message); return answer; } },
    };
    vm.runInNewContext(line(voice, 'window.mapaIntakeCanLeave ='), child);
    const originalFrame = {
        contentWindow: childPolicy ? child.window : {},
        parentNode: { classList: { remove(name) { effects.push('workspace:' + name); } } },
        remove() { effects.push('frame removed'); },
    };
    const context = {
        document, window: {}, listeners: [], intakeFrame: frame ? originalFrame : null,
        intakeTab: { classList: { remove(name) { effects.push('tab:' + name); } } },
    };
    vm.runInNewContext(production, context, { filename: 'mapa-intake-native.navigation.js' });
    return {
        context, child, registered, removed, confirmations, effects, originalFrame,
        click(id = 'ops-map-view-button', { prevented = false, nested = false, noClosest = false } = {}) {
            const button = { id };
            const target = noClosest ? {} : {
                // A nested icon resolves to its containing native view button.
                ...(nested ? { nodeName: 'SPAN' } : button),
                closest(selector) { return selector.split(',').includes('#' + id) ? button : null; },
            };
            const event = {
                target, defaultPrevented: prevented, stopped: false, nativeCalls: 0,
                preventDefault() { this.defaultPrevented = true; },
                stopImmediatePropagation() { this.stopped = true; },
            };
            for (const row of registered.filter(row => row.name === 'click' && row.capture)) {
                row.handler(event);
                if (event.stopped) break;
            }
            // Native buttons use a target click handler; only capture can stop it.
            if (!event.stopped && !event.defaultPrevented) event.nativeCalls++;
            return event;
        },
        destroy() {
            vm.runInNewContext(line(native, 'of listeners) target.removeEventListener'), context);
        },
    };
}

for (const id of ['ops-map-view-button', 'ops-table-view-button', 'ops-kanban-view-button']) {
    const blocked = fixture({ state: { dirty: true } });
    check(blocked.registered.length === 1 && blocked.registered[0].capture === true, 'Native view guard runs in capture phase');
    const event = blocked.click(id, { nested: true });
    check(event.defaultPrevented && event.stopped && event.nativeCalls === 0, 'Declined dirty navigation stops the native view handler: ' + id);
    check(blocked.context.intakeFrame === blocked.originalFrame && blocked.effects.length === 0, 'Declined navigation retains the exact iframe and active tab');
    check(blocked.confirmations.length === 1 && blocked.child.dirty === true, 'Dirty draft is confirmed once and remains unchanged');

    const allowed = fixture({ state: { dirty: true }, answer: true });
    const accepted = allowed.click(id);
    check(!accepted.defaultPrevented && !accepted.stopped && accepted.nativeCalls === 1, 'Confirmed navigation permits the native view handler: ' + id);
    check(allowed.context.intakeFrame === null && allowed.effects.includes('frame removed') && allowed.effects.includes('tab:active'), 'Confirmed navigation closes intake and clears its active state');
}

for (const state of [{ busy: true }, { pending: { id: 'DEMO_uncertain_receipt' } }]) {
    const pending = fixture({ state, answer: true });
    const event = pending.click();
    check(event.stopped && pending.context.intakeFrame === pending.originalFrame && pending.confirmations.length === 0, 'Busy or uncertain work cannot be discarded even with an affirmative confirmation answer');
}
const resource = fixture({ state: { resourceEditor: { type: 'DEMO_ambulance' } } });
check(resource.click().stopped && resource.confirmations.length === 1, 'Unsaved resource edits use the same native leave policy');

const clean = fixture();
check(clean.click().nativeCalls === 1 && clean.context.intakeFrame === null && clean.confirmations.length === 0, 'Clean desk switches views without an unnecessary confirmation');
const unsigned = fixture({ childPolicy: false });
check(unsigned.click().nativeCalls === 1 && unsigned.context.intakeFrame === null, 'Waiting or sign-in iframe without a leave policy remains closable');

for (const [id, options] of [['unrelated-button', {}], ['ops-map-view-button', { prevented: true }], ['ops-map-view-button', { noClosest: true }]]) {
    const ignored = fixture({ state: { dirty: true } });
    ignored.click(id, options);
    check(ignored.effects.length === 0 && ignored.confirmations.length === 0 && ignored.context.intakeFrame === ignored.originalFrame, 'Unrelated, previously cancelled or non-element events do not touch intake');
}
const absent = fixture({ frame: false });
check(absent.click().nativeCalls === 1 && absent.effects.length === 0, 'Native views behave normally when no intake iframe exists');

const destroyed = fixture({ state: { dirty: true } });
destroyed.destroy();
check(destroyed.registered.length === 0 && destroyed.removed[0].capture === true, 'Teardown removes the capture listener with its exact registration options');
check(destroyed.click().nativeCalls === 1 && destroyed.confirmations.length === 0, 'Disposed initializer cannot intercept later view changes');

console.log(`PASS ${checks} intake navigation checks; synthetic events only, no network or operational writes.`);
