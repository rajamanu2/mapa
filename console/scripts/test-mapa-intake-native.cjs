'use strict';
// Read-only transport/DOM fixtures. No live credentials, records, messages or navigation.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const filename = require.resolve('../public/mapa-intake-native.js');
const source = fs.readFileSync(filename, 'utf8'), core = require(filename);
let checks = 0;
const check = (value, note) => { checks++; assert.ok(value, note); };
const equal = (actual, expected, note) => { checks++; assert.deepEqual(actual, expected, note); };
const rejected = async (fn, status, note) => { checks++; await assert.rejects(fn, error => error.status === status, note); };
const scope = { user: 'user_native', company: 'company_native', credential: 'synthetic_native_bearer_for_tests' };
const contract = 'mapa-native-mobile-v1';
const call = (patch = {}) => ({ id: 'call_1', revision: 1, state: 'needs_review', caller_label: 'DEMO caller', pickup_address: 'DEMO village',
    created_at: '2026-10-05T00:00:00Z', updated_at: '2026-10-05T00:00:00Z', order_id: null, demo: true, ...patch });
const payload = (calls = [call()], truncated = false) => ({ contract, company_id: scope.company, calls, truncated });
const response = (data = payload(), status = 200) => ({ ok: status >= 200 && status < 300, status, json: async () => data });
const settled = async () => { for (let i = 0; i < 14; i++) await Promise.resolve(); await new Promise(resolve => setImmediate(resolve)); };
async function transportChecks() {
    for (const route of ['console.fleet-ops.operations.orders.index', 'console.fleet-ops.operations', 'console.fleet-ops.operations.orders.index.details']) check(core.operationsRoute(route), 'Operations route accepted: ' + route);
    for (const route of ['auth.login', 'console.fleet-ops.management.drivers.index', 'console.other.operations', 'console.fleet-ops.operations-other', '']) check(!core.operationsRoute(route), 'Unrelated route rejected: ' + route);
    check(core.updateSignal({ type: 'intake-updated', version: 1 }), 'Metadata-only update signal accepted.');
    for (const signal of [null, [], { type: 'intake-updated', version: 2 }, { type: 'intake-updated', version: 1, token: 'secret' }, { type: 'order-created', version: 1 }]) check(!core.updateSignal(signal), 'Wrong or overbroad broadcast ignored.');
    equal(core.snapshot(payload()).calls[0], call(), 'Only approved saved-call fields are retained.');
    const sanitized = core.snapshot(payload([call({ token: 'not retained', caller_label: '<img src=x>', pickup_address: 'a'.repeat(3000) })]));
    check(!('token' in sanitized.calls[0]) && sanitized.calls[0].pickup_address.length === 1000, 'Drop unrelated fields and bound strings.');
    equal(sanitized.calls[0].caller_label, '<img src=x>', 'Display data remains text; rendering must never interpret it as HTML.');
    for (const data of [{}, { ...payload(), contract: 'wrong' }, { ...payload(), truncated: undefined }, payload(Array.from({ length: 101 }, (_, i) => call({ id: 'c_' + i }))), payload([call(), call()]), payload([call({ id: '../other' })]), payload([call({ revision: -1 })]), payload([call({ state: 'unknown' })]), payload([call({ order_id: 'https://evil.invalid' })]), payload([call({ demo: 1 })])]) {
        checks++; assert.throws(() => core.snapshot(data), /unsupported|supported/, 'Malformed/beyond-cap lists fail closed.');
    }
    equal(core.changedOrders(null, core.snapshot(payload([call({ state: 'dispatched', order_id: 'order_1' })]))), [], 'Initial load does not trigger a native refresh.');
    equal(core.changedOrders(core.snapshot(payload()), core.snapshot(payload([call({ revision: 2 })]))), [], 'Undispatched revision changes need no native order refresh.');
    equal(core.changedOrders(core.snapshot(payload()), core.snapshot(payload([call({ state: 'dispatched', order_id: 'order_1', revision: 3 })]))), ['order_1'], 'Dispatch receipt identifies only the changed order.');
    let current = { ...scope }, authorization = `Bearer ${scope.credential}`;
    const requests = [];
    const transport = core.createTransport({ origin: 'https://local-mapa.invalid', scope: () => current,
        headers: () => ({ Authorization: authorization, 'Secret-Unrelated': 'never-forward', 'Access-Console-Sandbox': '0' }),
        fetchImpl: async (...args) => { requests.push(args); return response(); } });
    await transport.request();
    equal(requests[0][0], 'https://local-mapa.invalid/api/int/v1/mapa/crew-review/intake', 'Only fixed native read endpoint is used.');
    equal(requests[0][1].headers, { Authorization: authorization, Accept: 'application/json', 'Access-Console-Sandbox': '0' }, 'Only native credential and supported sandbox headers are sent.');
    equal([requests[0][1].method, requests[0][1].credentials, requests[0][1].cache, requests[0][1].redirect], ['GET', 'omit', 'no-store', 'error'], 'Polling cannot send cookies, write data, cache or follow redirects.');
    equal(Object.keys(transport).sort(), ['cancel', 'request', 'valid'], 'Transport exposes no credential.');
    authorization = 'Bearer changed'; await rejected(() => transport.request(), 401, 'Native header mismatch blocks request.');
    equal(requests.length, 1, 'Header mismatch makes no request.');
    authorization = `Bearer ${scope.credential}`; current = { ...scope, company: 'other_company' };
    await rejected(() => transport.request(), 401, 'Company switch invalidates the original transport.');
    equal(requests.length, 1, 'Company mismatch makes no request.');
    for (const status of [401, 403, 500]) {
        const denied = core.createTransport({ origin: 'https://local-mapa.invalid', scope: () => scope, headers: () => ({ Authorization: `Bearer ${scope.credential}` }), fetchImpl: async () => response({ error: 'private raw error' }, status) });
        await rejected(() => denied.request(), status, 'HTTP denial remains a local transport error.');
    }
    const wrongCompany = core.createTransport({ origin: 'https://local-mapa.invalid', scope: () => scope, headers: () => ({ Authorization: `Bearer ${scope.credential}` }), fetchImpl: async () => response({ ...payload(), company_id: 'another_company' }) });
    await rejected(() => wrongCompany.request(), 403, 'Response organization must match the native scope.');
    current = { ...scope };
    const late = core.createTransport({ origin: 'https://local-mapa.invalid', scope: () => current, headers: () => ({ Authorization: `Bearer ${scope.credential}` }), fetchImpl: async () => { current = null; return response(); } });
    await rejected(() => late.request(), 401, 'A response arriving after sign-out cannot expose the earlier tenant.');
    check(!source.includes('session.invalidate(') && !source.includes('sessionStorage') && !source.includes('localStorage') && !source.includes('innerHTML'), 'No native logout, credential storage or HTML injection sinks.');
    check(!source.includes('router.refresh(') && !source.includes('transitionTo(') && !source.includes('location.reload'), 'Background updates cannot navigate or discard native drafts.');
}
function browserFixture({ route = 'console.fleet-ops.operations.orders.index', fetchImpl } = {}) {
    class Element {
        constructor(tag = 'div') { Object.assign(this, { tag, children: [], parentNode: null, attributes: {}, events: {}, textContent: '', classes: new Set(), style: {}, dataset: {} }); this.classList = { toggle: (name, enabled) => enabled ? this.classes.add(name) : this.classes.delete(name) }; }
        get isConnected() { return this === html || !!this.parentNode?.isConnected; }
        append(...nodes) { for (const node of nodes) { node.remove(); node.parentNode = this; this.children.push(node); } }
        replaceChildren(...nodes) { for (const node of [...this.children]) node.remove(); this.append(...nodes); }
        remove() { if (this.parentNode) this.parentNode.children = this.parentNode.children.filter(node => node !== this); this.parentNode = null; }
        setAttribute(name, value) { this.attributes[name] = value; }
        addEventListener(name, fn) { this.events[name] = fn; }
        removeEventListener(name, fn) { if (this.events[name] === fn) delete this.events[name]; }
        contains(node) { return this === node || this.children.some(child => child.contains(node)); }
        click() { this.events.click?.({ preventDefault() {} }); }
        focus() { document.activeElement = this; }
    }
    const html = new Element('html'), head = new Element('head'), body = new Element('body'), tray = new Element('div');
    tray.id = 'view-header-tray-items'; html.append(head, body); body.append(tray);
    const find = (node, id) => node.id === id ? node : node.children.map(child => find(child, id)).find(Boolean);
    const allText = node => node.textContent + node.children.map(allText).join(' ');
    const events = {}, intervals = new Map(), channels = [], requests = [], lookups = [];
    let clock = 100000, initializer, destructor, result = payload(), status = 200, disconnected = false, nextInterval = 0, mutatedAuth = 0;
    function emitter(value) { value.events = {}; value.on = (name, fn) => { value.events[name] = fn; }; value.off = (name, fn) => { if (value.events[name] === fn) delete value.events[name]; }; return value; }
    const session = emitter({ isAuthenticated: true, data: { authenticated: { user: scope.user, token: scope.credential } }, invalidate: () => mutatedAuth++ });
    const currentUser = emitter({ companyId: scope.company });
    const router = emitter({ currentRouteName: route, refresh: () => { throw new Error('Background route refresh would risk drafts'); }, transitionTo: () => { throw new Error('Navigation is forbidden'); } });
    const services = { 'service:session': session, 'service:current-user': currentUser, 'service:router': router,
        'service:fetch': { getHeaders: () => ({ Authorization: `Bearer ${session.data.authenticated.token}` }) } };
    const document = { documentElement: html, head, body, visibilityState: 'visible', activeElement: null, createElement: tag => new Element(tag), getElementById: id => find(html, id),
        addEventListener: (name, fn) => { events['document:' + name] = fn; }, removeEventListener: name => { delete events['document:' + name]; } };
    class Channel { constructor(name) { this.name = name; this.closed = false; channels.push(this); } close() { this.closed = true; } }
    const window = { BroadcastChannel: Channel, define: (name, deps, callback) => { equal(name, '@fleetbase/console/instance-initializers/mapa-intake-native', 'Register supported native initializer.'); const exports = {}; callback(exports, { registerDestructor: (_owner, fn) => { destructor = fn; } }); initializer = exports.default; },
        addEventListener: (name, fn) => { events[name] = fn; }, removeEventListener: name => { delete events[name]; },
        fetch: async (...args) => { requests.push(args); return fetchImpl ? fetchImpl(...args) : response({ ...result, company_id: currentUser.companyId }, status); } };
    class Clock extends Date { static now() { return clock; } }
    vm.runInNewContext(source, { window, document, location: { origin: 'https://local-mapa.invalid' }, Date: Clock, AbortController, setTimeout, clearTimeout,
        setInterval: fn => { const key = ++nextInterval; intervals.set(key, fn); return key; }, clearInterval: key => intervals.delete(key), requestAnimationFrame: fn => fn(),
        MutationObserver: class { observe() {} disconnect() { disconnected = true; } } }, { filename });
    const lifecycle = initializer.initialize({ lookup: name => { lookups.push(name); return services[name]; } });
    return { document, session, currentUser, router, events, channels, requests, lookups, lifecycle, tray, body,
        text: () => allText(body), setPayload: value => { result = value; }, setStatus: value => { status = value; },
        tick: (ms = 10000) => { clock += ms; for (const fn of intervals.values()) fn(); },
        advance: ms => { clock += ms; }, destroyOwner: () => { check(typeof destructor === 'function', 'Ember owner has a registered destructor.'); destructor(); },
        authMutations: () => mutatedAuth, isDestroyed: () => disconnected && intervals.size === 0 };
}
async function browserChecks() {
    const ui = browserFixture(); await settled();
    equal(ui.lookups, ['service:session', 'service:current-user', 'service:fetch', 'service:router'], 'Only public native services are obtained.');
    equal(ui.requests.length, 1, 'Initial Operations view reads one saved-call snapshot.');
    equal(ui.channels[0].name, 'mapa-intake-updates', 'Tab updates use dedicated notification-only channel.');
    const summary = ui.document.getElementById('mapa-intake-summary'); check(summary.textContent.endsWith('1') && summary.title.includes('1 awaiting dispatch'), 'Compact header retains full confirmed saved-call status in its tooltip.');
    summary.click(); await settled();
    const panel = ui.document.getElementById('mapa-intake-panel');
    check(panel.parentNode === ui.body && panel.parentNode !== ui.tray, 'Call list is body-owned; Operations map/table/board children stay untouched.');
    check(ui.text().includes('DEMO caller') && ui.text().includes('DEMO village') && ui.text().includes('Awaiting review'), 'Panel shows readable saved call, pickup and state.');
    ui.document.activeElement = ui.body;
    let prevented = false; ui.events['document:keydown']({ key: 'Escape', preventDefault() { prevented = true; } });
    check(!prevented && ui.document.getElementById('mapa-intake-panel'), 'Escape outside intake panel is left to native dialogs.');
    ui.setPayload(payload([call({ caller_label: '<script>synthetic</script>', revision: 2 })])); ui.tick(); await settled();
    check(ui.text().includes('<script>synthetic</script>'), 'Caller strings are rendered as literal text.');
    const findTag = node => node.tag === 'script' || node.children.some(findTag); check(!findTag(panel), 'Caller text cannot create executable elements.');
    const beforeSignal = ui.requests.length; ui.advance(1100);
    ui.channels[0].onmessage({ data: { type: 'intake-updated', version: 1, caller: 'never accepted' } }); await settled(); equal(ui.requests.length, beforeSignal, 'Patient-bearing broadcast is ignored.');
    ui.setPayload(payload([call({ state: 'dispatched', order_id: 'order_1', revision: 3 })])); ui.channels[0].onmessage({ data: { type: 'intake-updated', version: 1 } }); await settled();
    equal(ui.requests.length, beforeSignal + 1, 'A metadata-only broadcast triggers an authenticated refetch.');
    check(ui.text().includes('Dispatched') && ui.text().includes('Trip: order_1') && ui.text().includes('Dispatch details changed'), 'Dispatch updates visible panel without router refresh.');
    ui.channels[0].onmessage({ data: { type: 'intake-updated', version: 1 } }); await settled(); equal(ui.requests.length, beforeSignal + 1, 'Repeated signals are debounced.');
    ui.document.visibilityState = 'hidden'; const hiddenCount = ui.requests.length; ui.tick(30000); await settled(); equal(ui.requests.length, hiddenCount, 'Hidden Operations does not poll.');
    ui.document.visibilityState = 'visible'; ui.events['document:visibilitychange'](); await settled(); equal(ui.requests.length, hiddenCount + 1, 'Visible Operations refreshes immediately.');
    ui.setStatus(503); ui.tick(); await settled(); check(ui.text().includes('Showing saved calls from') && ui.text().includes('Trip: order_1'), 'Transient failure retains dated snapshot and labels it stale.');
    ui.setStatus(403); ui.tick(); await settled(); check(!ui.text().includes('Trip: order_1') && !ui.text().includes('DEMO village'), 'Authorization failure clears private call content.');
    check(ui.text().includes('Operations remains signed in') && ui.session.isAuthenticated && ui.authMutations() === 0, 'Intake 403 never logs Operations out.');
    const blockedCount = ui.requests.length; ui.tick(30000); await settled(); equal(ui.requests.length, blockedCount, 'Blocked scope does not hammer the read endpoint.');
    ui.setStatus(200); ui.currentUser.companyId = 'different_company'; ui.currentUser.events['user.organization_switched'](); await settled();
    check(!ui.document.getElementById('mapa-intake-panel'), 'Organization switch closes and clears prior private panel.');
    equal(ui.requests.length, blockedCount + 1, 'New organization refetches under own current native scope.');
    ui.router.currentRouteName = 'console.fleet-ops.management.drivers.index'; ui.router.events.routeDidChange(); await settled();
    check(!ui.document.getElementById('mapa-intake-summary'), 'Non-Operations routes do not get a saved-call panel.');
    const offRouteCount = ui.requests.length; ui.tick(20000); await settled(); equal(ui.requests.length, offRouteCount, 'Other sections do not poll intake.');
    ui.router.currentRouteName = 'console.fleet-ops.operations.orders.index'; ui.router.events.routeDidChange(); await settled();
    ui.session.isAuthenticated = false; ui.session.events.invalidationSucceeded(); await settled();
    check(!ui.document.getElementById('mapa-intake-summary'), 'Native sign-out removes all private intake controls.');
    const signedOutCount = ui.requests.length; ui.tick(); await settled(); equal(ui.requests.length, signedOutCount, 'Signed-out tabs make no intake request.');
    ui.destroyOwner(); check(ui.isDestroyed() && ui.channels[0].closed, 'Owner teardown removes interval/observer and closes broadcast channel.');

    let resolve;
    const late = browserFixture({ fetchImpl: () => new Promise(done => { resolve = done; }) }); await settled();
    late.currentUser.companyId = 'switched_before_receipt'; resolve(response(payload([call({ caller_label: 'OLD TENANT PRIVATE' })]))); await settled();
    check(!late.text().includes('OLD TENANT PRIVATE'), 'Late old-tenant receipt is never rendered even without a service event.'); late.lifecycle.destroy();
    const unrelated = browserFixture({ route: 'auth.login' }); await settled(); equal(unrelated.requests.length, 0, 'Authentication page is outside this integration.'); unrelated.lifecycle.destroy();
}
(async () => { await transportChecks(); await browserChecks(); console.log(`MAPA native intake sync: ${checks} checks passed (isolated read-only fixtures).`); })().catch(error => { console.error(error); process.exitCode = 1; });
