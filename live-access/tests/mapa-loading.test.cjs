/* Verify startup cleanup with a tiny DOM double; no native app or login state. */
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.resolve(__dirname, '../../console/public/mapa-loading.js'), 'utf8');
const css = fs.readFileSync(path.resolve(__dirname, '../../console/public/mapa-loading.css'), 'utf8');

function harness({ reduced = false, ready = false } = {}) {
    const timers = new Map();
    const listeners = new Map();
    let count = 0;
    let disconnected = false;
    let removed = 0;
    let mutation;
    let motionChange;
    let candidates = ready ? [{ getClientRects: () => [{}] }] : [];
    const message = { textContent: 'Opening your operations workspace…' };
    const splash = {
        dataset: {}, isConnected: true,
        classList: { contains: (value) => value === 'mapa-boot' },
        contains: () => false,
        remove() { this.isConnected = false; removed += 1; },
        querySelector: () => message,
    };
    const motion = {
        matches: reduced,
        addEventListener: (_type, fn) => { motionChange = fn; },
        removeEventListener: () => { motionChange = null; },
    };
    const context = vm.createContext({
        document: { body: {}, getElementById: () => splash, querySelectorAll: () => candidates },
        window: {
            matchMedia: () => motion,
            addEventListener: (type, fn) => listeners.set(type, fn),
            removeEventListener: (type) => listeners.delete(type),
        },
        MutationObserver: class {
            constructor(fn) { mutation = fn; }
            observe() {}
            disconnect() { disconnected = true; }
        },
        setTimeout: (fn, delay) => { timers.set(++count, { fn, delay }); return count; },
        clearTimeout: (id) => timers.delete(id),
    });
    vm.runInContext(source, context);
    return {
        splash, motion, timers, listeners, message,
        get removed() { return removed; },
        get disconnected() { return disconnected; },
        render() { candidates = [{ getClientRects: () => [{}] }]; mutation(); },
        nativeRemove() { splash.isConnected = false; mutation(); },
        rerun() { vm.runInContext(source, context); },
        changeMotion(value) { motion.matches = value; motionChange?.(); },
        tick(delay) { Array.from(timers.values()).find((timer) => timer.delay === delay)?.fn(); },
    };
}

test('already rendered forms remove the splash immediately with no minimum duration', () => {
    const h = harness({ ready: true });
    assert.equal(h.removed, 1);
    assert.equal(h.timers.size, 0);
    assert.equal(h.disconnected, true);
});
test('native readiness removes splash, timers and event listeners', () => {
    const h = harness();
    h.render();
    assert.equal(h.removed, 1);
    assert.equal(h.listeners.size, 0);
    assert.equal(h.timers.size, 0);
});
test('native removeBootLoader is respected and observer is cleaned up', () => {
    const h = harness();
    h.nativeRemove();
    assert.equal(h.disconnected, true);
    assert.equal(h.timers.size, 0);
});
test('reduced-motion settings work initially and when changed', () => {
    const h = harness({ reduced: true });
    assert.equal(h.splash.dataset.mapaReducedMotion, 'true');
    h.changeMotion(false);
    assert.equal(h.splash.dataset.mapaReducedMotion, 'false');
    assert.match(css, /@media \(prefers-reduced-motion: reduce\)/);
    assert.doesNotMatch(css, /\binfinite\b/);
});
test('slow boot status stays honest and maximum lifetime is bounded', () => {
    const h = harness();
    h.tick(6500);
    assert.equal(h.message.textContent, 'ASR Health is taking a little longer to open…');
    h.tick(15000);
    assert.equal(h.removed, 1);
    assert.equal(h.timers.size, 0);
});
test('errors are never hidden behind startup decoration', () => {
    const h = harness();
    h.listeners.get('error')();
    assert.equal(h.removed, 1);
});
test('loading script is idempotent and never performs network or credential work', () => {
    const h = harness();
    const timerCount = h.timers.size;
    h.rerun();
    assert.equal(h.timers.size, timerCount);
    assert.doesNotMatch(source, /\b(fetch|XMLHttpRequest|WebSocket|localStorage|sessionStorage)\b/);
    assert.match(css, /pointer-events:\s*none/);
});
