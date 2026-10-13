/* Copyright (c) 2026 MAPA.DEV. SPDX-License-Identifier: AGPL-3.0-or-later */
'use strict';
// Native console transport and supported initializer behavior, without real credentials or API writes.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const sourcePath = path.resolve(__dirname, '../public/mapa-crew-native.js');
const source = fs.readFileSync(sourcePath, 'utf8');
const core = require(sourcePath);
let assertions = 0;
function check(value, description) { assertions++; assert.ok(value, description); }
function equal(actual, expected, description) { assertions++; assert.deepEqual(actual, expected, description); }
async function rejected(run, status, description) {
    assertions++;
    await assert.rejects(run, error => error.status === status, description);
}
const contract = 'mapa-native-mobile-v1';
const originalScope = { user: 'native_user_uuid', company: 'native_company_uuid', credential: 'synthetic_credential_for_local_checks' };
const identityResult = {
    contract, native_user_uuid: originalScope.user,
    user: { id: 'user_public', company_id: originalScope.company, name: 'Local test reviewer', role: 'admin' },
    capabilities: { enrollment_review: true },
};
function response(result = { contract }, status = 200, contentType = 'application/json') {
    return { ok: status >= 200 && status < 300, status, headers: { get: () => contentType }, json: async () => result, blob: async () => new Blob(['jpeg'], { type: contentType }) };
}
function fixture(fetchImpl = async () => response()) {
    let current = { ...originalScope }, authorized = `Bearer ${originalScope.credential}`;
    const calls = [], invalidations = [];
    const transport = core.createTransport({ origin: 'https://local-mapa.invalid', scope: () => current,
        headers: () => ({ Authorization: authorized, 'Private-Unrelated-Header': 'not-forwarded' }),
        fetchImpl: async (...args) => { calls.push(args); return fetchImpl(...args); },
        invalidated: scope => invalidations.push(scope) });
    return { transport, calls, invalidations, setScope: value => { current = value; }, setAuthorization: value => { authorized = value; } };
}
async function main() {
    for (const route of ['/identity', '/enrollments', '/enrollments/options', '/trip-reports']) check(core.allowedPath(route, 'GET'), `Approved GET: ${route}`);
    for (const action of ['approve', 'reject', 'revoke', 'ambulance', 'hospital']) check(core.allowedPath(`/enrollments/enrollment_1/${action}`, 'POST'), `Explicit staff mutation: ${action}`);
    check(core.allowedPath('/media/private_selfie', 'GET', true), 'Private media has a distinct GET-only transport.');
    for (const route of ['/login', '/duty', '/gps', '/connect', '/enrollments/../approve', '/enrollments/id/approve?x=1', '/enrollments/id/approve/', 'https://other.invalid/enrollments', '/media/x', '/enrollments%2foptions']) {
        check(!core.allowedPath(route, 'GET') && !core.allowedPath(route, 'POST'), `Unsupported route rejected: ${route}`);
    }
    check(!core.allowedPath('/media/x', 'POST', true), 'Media cannot authorize mutations.');
    check(!core.allowedPath('/identity', 'DELETE'), 'Unexpected HTTP methods fail closed.');
    check(core.sameScope(originalScope, { ...originalScope }), 'Unchanged native scope is valid.');
    for (const field of ['user', 'company', 'credential']) check(!core.sameScope(originalScope, { ...originalScope, [field]: 'other' }), `Native ${field} change invalidates review.`);
    check(!core.sameScope(null, originalScope), 'Signed-out scope fails closed.');
    equal(core.reviewIdentity(identityResult, originalScope), { user: 'user_public', company: originalScope.company, name: 'Local test reviewer', role: 'admin' }, 'Only the verified public identity reaches the review UI.');
    for (const patch of [{ native_user_uuid: 'other' }, { contract: 'unsupported' }, { capabilities: { enrollment_review: false } }, { user: { ...identityResult.user, company_id: 'other' } }, { user: { ...identityResult.user, role: 'driver' } }, { user: { ...identityResult.user, id: '../private' } }]) check(!core.reviewIdentity({ ...identityResult, ...patch }, originalScope), 'Native identity, tenant, role and capability must agree.');
    const summary = core.pendingSummary({ pending_count: 245, truncated: true, enrollments: [{ id: 'pending_1', status: 'pending' }, { id: 'old_1', status: 'approved' }, { id: '../bad', status: 'pending' }] });
    equal(summary, { count: 245, exact: true, ids: ['pending_1'] }, 'Badge uses authoritative company-wide pending total, not its history window.');
    check(!core.pendingSummary({ truncated: true, enrollments: [{ id: 'pending_1', status: 'pending' }] }).exact, 'A truncated list without a total is labeled non-exact.');
    equal(core.pendingSummary({ pending_count: -1, enrollments: [{ id: 'pending_1', status: 'pending' }] }).count, 1, 'An invalid total cannot hide a returned pending registration.');
    equal(core.pendingSummary(null).count, 0, 'Missing list data has no pending candidates.');
    equal(core.driverReference({ name: 'console.fleet-ops.management.drivers.index.details.edit', parent: { params: { public_id: 'driver_public' } } }), 'driver_public', 'Driver details find the native public ID in supported RouteInfo parents.');
    for (const route of [{ name: 'console.fleet-ops.management.vehicles.index.details', params: { public_id: 'vehicle_public' } }, { name: 'console.fleet-ops.management.drivers.index.details', params: { public_id: '../driver' } }, { name: 'console.fleet-ops.management.drivers.index', params: { public_id: 'driver_public' } }]) equal(core.driverReference(route), '', 'Only a valid native driver detail reference may open a profile.');

    const good = fixture();
    await good.transport.request('/enrollments');
    equal(good.calls.length, 1, 'A GET makes one request.');
    equal(good.calls[0][0], 'https://local-mapa.invalid/api/int/v1/mapa/crew-review/enrollments', 'Native review stays on the same configured origin and narrow proxy.');
    const requestOptions = good.calls[0][1];
    equal([requestOptions.cache, requestOptions.credentials, requestOptions.redirect], ['no-store', 'omit', 'error'], 'Private responses do not use cookies, redirects or cache.');
    equal(requestOptions.headers, { Authorization: `Bearer ${originalScope.credential}`, Accept: 'application/json' }, 'Supported native authorization is forwarded without unrelated headers.');
    equal(Object.keys(good.transport).sort(), ['media', 'request', 'valid'], 'Transport does not expose its credential or scope.');
    for (const route of ['/login', '/gps', '/enrollments/id/approve?x=1', 'https://other.invalid']) await rejected(() => good.transport.request(route), 400, 'Invalid route fails before native fetch.');
    equal(good.calls.length, 1, 'Invalid routes caused no API calls.');
    for (const body of ['[]', 'null', '{broken', 10, JSON.stringify({ note: 'అ'.repeat(6000) })]) await rejected(() => good.transport.request('/enrollments/id/approve', { body }), 400, 'Nonobject or oversized UTF-8 body is rejected before sending.');
    equal(good.calls.length, 1, 'Invalid bodies caused no API calls.');
    good.setAuthorization('Bearer a_different_native_credential');
    await rejected(() => good.transport.request('/enrollments'), 401, 'Changed native service headers are rejected.');
    equal(good.calls.length, 1, 'An authorization change cannot send old credentials.');
    good.setAuthorization(`Bearer ${originalScope.credential}`);
    good.setScope({ ...originalScope, company: 'other_company' });
    check(!good.transport.valid(), 'An organization change immediately invalidates the transport.');
    await rejected(() => good.transport.request('/enrollments'), 401, 'Organization change is denied before network access.');

    const staleNetwork = fixture(async () => { staleNetwork.setScope(null); return response(); });
    await rejected(() => staleNetwork.transport.request('/enrollments'), 401, 'Sign-out while waiting cannot return an earlier tenant response.');
    equal(staleNetwork.invalidations.length, 0, 'A stale response cannot invalidate a later native session.');
    const staleParse = fixture(async () => ({ ...response(), json: async () => { staleParse.setScope({ ...originalScope, credential: 'replacement_credential' }); return { contract }; } }));
    await rejected(() => staleParse.transport.request('/enrollments'), 401, 'Identity is rechecked after asynchronous JSON parsing.');
    const denied = fixture(async () => response({ error: 'Reviewer binding removed' }, 403));
    await rejected(() => denied.transport.request('/enrollments'), 403, 'Native server role revocation remains authoritative.');
    equal(denied.invalidations.length, 1, 'Forbidden current-scope responses clear the review interface.');
    const validation = fixture(async () => response({ error: 'Select a base', fields: { hospital: ['Required', 'Use a native base'] } }, 422));
    let validationError; try { await validation.transport.request('/enrollments/id/approve', { body: '{}' }); } catch (error) { validationError = error; }
    equal([validationError.status, validationError.message, validationError.fields], [422, 'Select a base', 'Required Use a native base'], 'Definite validation rejection preserves status and actionable fields.');
    equal(validation.invalidations.length, 0, 'A form rejection does not log out a valid reviewer.');
    const unsupported = fixture(async () => response({ contract: 'different_contract' }));
    await rejected(() => unsupported.transport.request('/enrollments'), 502, 'Unsupported success contract is not accepted.');

    const actionBody = JSON.stringify({ action_id: 'saved-action', expected_revision: 7, confirmed: true });
    const uncertain = fixture(async () => { throw Object.assign(new Error('aborted'), { name: 'AbortError' }); });
    await rejected(() => uncertain.transport.request('/enrollments/id/approve', { body: actionBody }), 0, 'Timeout preserves uncertain outcome instead of inventing a rejection.');
    equal(uncertain.calls.length, 1, 'Mutation timeout never automatically retries.');
    await rejected(() => uncertain.transport.request('/enrollments/id/approve', { body: actionBody }), 0, 'Only an explicit retry resends the saved action.');
    equal(uncertain.calls.map(call => call[1].body), [actionBody, actionBody], 'Explicit retry sends the identical serialized action body.');
    const failedNetwork = fixture(async () => { throw new TypeError('connection closed'); });
    await assert.rejects(() => failedNetwork.transport.request('/enrollments/id/revoke', { body: actionBody })); assertions++;
    equal(failedNetwork.calls.length, 1, 'A network failure cannot duplicate a revoke action.');
    const photograph = fixture(async () => response({}, 200, 'image/jpeg'));
    equal((await photograph.transport.media('/media/private_selfie')).size, 4, 'A scoped private JPEG returns a Blob, not a public URL.');
    equal(photograph.calls[0][1].headers.Accept, 'image/jpeg', 'Private photos use their distinct media response contract.');
    await rejected(() => photograph.transport.media('/enrollments'), 400, 'Media transport cannot fetch JSON enrollment data.');
    const nonJpeg = fixture(async () => response({}, 200, 'text/html'));
    await rejected(() => nonJpeg.transport.media('/media/private_selfie'), 502, 'An HTML or redirected login body cannot become a displayed photograph.');
    for (const size of [0, 2 * 1024 * 1024 + 1]) {
        const over = fixture(async () => ({ ...response({}, 200, 'image/jpeg'), blob: async () => ({ size }) }));
        await rejected(() => over.transport.media('/media/private_selfie'), 502, 'Empty or oversized private media is rejected.');
    }
    const stalePhoto = fixture(async () => ({ ...response({}, 200, 'image/jpeg'), blob: async () => { stalePhoto.setScope(null); return new Blob(['jpeg']); } }));
    await rejected(() => stalePhoto.transport.media('/media/private_selfie'), 401, 'Sign-out during media read cannot return the private image.');
    assertions++; assert.throws(() => core.createTransport({ scope: () => null }), error => error.status === 401, 'No native transport exists before sign-in.');

    await initializerChecks();
    console.log(`PASS ${assertions} native crew console assertions. No real API, credentials, operational data or phone used.`);
}

// Minimal public browser/Ember owner contract. This tests shipped initializer behavior,
// not a replicated enrollment implementation or private framework containers.
function browserFixture({ role = 'admin', route = 'console.fleet-ops.management.drivers.index' } = {}) {
    class Element {
        constructor(tag = 'div') { this.tagName = tag; this.children = []; this.parentNode = null; this.isConnected = false; this.textContent = ''; this.listeners = {}; this.attributes = {}; this.classes = new Set(); this.classList = { toggle: (name, enabled) => enabled ? this.classes.add(name) : this.classes.delete(name) }; }
        append(node) { node.remove(); node.parentNode = this; node.isConnected = this.isConnected; this.children.push(node); }
        prepend(node) { node.remove(); node.parentNode = this; node.isConnected = this.isConnected; this.children.unshift(node); }
        remove() { if (this.parentNode) this.parentNode.children = this.parentNode.children.filter(child => child !== this); this.parentNode = null; this.isConnected = false; }
        setAttribute(name, value) { this.attributes[name] = value; }
        addEventListener(name, handler) { this.listeners[name] = handler; }
        click() { this.listeners.click?.({ preventDefault() {} }); }
    }
    const root = new Element(), tray = new Element(), actions = new Element(); root.isConnected = true; tray.id = 'view-header-tray-items'; root.append(tray); root.append(actions);
    const find = (node, wanted) => node.id === wanted ? node : node.children.map(child => find(child, wanted)).find(Boolean);
    const events = {}, intervals = [], lookups = [], calls = [], opens = [], configured = [], invalidations = [], notices = [], transitions = [];
    let clock = 100000, initializer, pending = 1;
    function eventService(value) { value.events = {}; value.on = (name, callback) => { value.events[name] = callback; }; return value; }
    const session = eventService({ isAuthenticated: true, data: { authenticated: { user: originalScope.user, token: originalScope.credential } } });
    const currentUser = eventService({ companyId: originalScope.company });
    const router = eventService({ currentRouteName: route, currentRoute: { name: route, params: {} }, transitionTo: (...args) => transitions.push(args) });
    const services = { 'service:session': session, 'service:current-user': currentUser, 'service:router': router,
        'service:fetch': { getHeaders: () => ({ Authorization: `Bearer ${session.data.authenticated.token}` }) }, 'service:notifications': { info: text => notices.push(text) } };
    const document = { visibilityState: 'visible', documentElement: root, createElement: tag => new Element(tag),
        getElementById: wanted => find(root, wanted), querySelector: selector => selector === '#next-view-section-subheader .next-view-section-subheader-actions' ? actions : null,
        addEventListener: (name, callback) => { events[`document:${name}`] = callback; } };
    const window = { define: (name, dependencies, callback) => { equal(name, '@fleetbase/console/instance-initializers/mapa-crew-native', 'Asset registers a normal instance initializer before native app boot.'); const exports = {}; callback(exports); initializer = exports.default; },
        addEventListener: (name, callback) => { events[name] = callback; },
        MapaCrewReview: { configure: value => configured.push(value), open: value => opens.push(value), invalidate: () => invalidations.push(true) },
        fetch: async (url, options) => {
            calls.push([url, options]);
            if (url.endsWith('/identity')) return response({ ...identityResult, native_user_uuid: session.data.authenticated.user,
                user: { ...identityResult.user, company_id: currentUser.companyId, role }, capabilities: { enrollment_review: role === 'admin' } });
            return response({ contract, pending_count: pending, enrollments: [{ id: `pending_${pending}`, status: 'pending' }] });
        } };
    class Clock extends Date { static now() { return clock; } }
    vm.runInNewContext(source, { window, document, location: { origin: 'https://local-mapa.invalid' }, Date: Clock,
        TextEncoder, AbortController, setTimeout, clearTimeout, setInterval: callback => { intervals.push(callback); },
        requestAnimationFrame: callback => callback(), MutationObserver: class { constructor(callback) { this.callback = callback; } observe() {} } }, { filename: sourcePath });
    check(typeof initializer?.initialize === 'function', 'Ember owns initializer activation; the asset does not probe global containers.');
    initializer.initialize({ lookup: name => { lookups.push(name); return services[name]; } });
    return { document, events, intervals, calls, opens, configured, invalidations, notices, transitions, session, currentUser, router, lookups,
        tick: () => { clock += 31000; intervals.forEach(callback => callback()); }, setPending: count => { pending = count; } };
}
const settled = async () => { for (let i = 0; i < 12; i++) await Promise.resolve(); await new Promise(resolve => setImmediate(resolve)); };
async function initializerChecks() {
    const browser = browserFixture(); await settled();
    equal(browser.lookups, ['service:session', 'service:current-user', 'service:fetch', 'service:router', 'service:notifications'], 'The initializer obtains only supported public owner services.');
    check(browser.calls[0][0].endsWith('/identity') && browser.calls[1][0].endsWith('/enrollments'), 'Staff identity is verified before any private enrollment list is fetched.');
    const header = browser.document.getElementById('mapa-native-crew-alert'), driver = browser.document.getElementById('mapa-native-crew-driver');
    check(header?.textContent.includes('1 pending') && header.classes.has('has-pending'), 'Native header displays the confirmed pending badge.');
    equal(driver?.textContent, 'Crew registration (1)', 'Drivers list gains a registration review entry.');
    header.click(); await settled();
    equal(browser.opens.length, 1, 'Badge click opens the same-page review once.');
    equal(browser.configured.length, 1, 'Review receives a verified native transport on explicit open.');
    const config = browser.configured[0];
    check(config.valid() && config.identity().user === 'user_public' && !('credential' in config.identity()), 'Private native credential is not exported into UI identity.');
    equal(Object.keys(config).sort(), ['identity', 'media', 'openDriver', 'request', 'valid'], 'Review interface matches the parent UI contract.');
    config.openDriver('driver_public');
    equal(browser.transitions[0], ['console.fleet-ops.management.drivers.index.details', 'driver_public'], 'Approved driver profile uses the supported native detail route.');
    config.openDriver('../driver'); equal(browser.transitions.length, 1, 'Arbitrary driver route IDs cannot navigate.');
    browser.router.currentRouteName = 'console.fleet-ops.management.drivers.index.details';
    browser.router.currentRoute = { name: browser.router.currentRouteName, params: { public_id: 'driver_public' } };
    browser.router.events.routeDidChange(); await settled();
    const detailsButton = browser.document.getElementById('mapa-native-crew-driver'); equal(detailsButton.textContent, 'Registration & selfie', 'Native driver profile offers private registration and selfie review.');
    detailsButton.click(); await settled();
    equal(browser.opens[1].driverId, 'driver_public', 'Profile review selects the matching driver without global store access.');
    browser.setPending(2); browser.events['mapa:crew-review-updated'](); await settled();
    check(browser.document.getElementById('mapa-native-crew-alert').textContent.includes('2 pending'), 'A confirmed review update refreshes the company badge.');
    equal(browser.notices, ['A new crew registration is waiting for staff review.'], 'New-registration notification contains no driver or caller identity.');
    check(browser.calls.every(call => call[1].method === 'GET'), 'Automatic badge polling performs only reads.');
    browser.document.visibilityState = 'hidden'; const beforeHidden = browser.calls.length; browser.tick(); await settled();
    equal(browser.calls.length, beforeHidden, 'Hidden console does not continue enrollment polling.');
    browser.document.visibilityState = 'visible'; browser.session.isAuthenticated = false; browser.session.events.invalidationSucceeded(); await settled();
    check(!config.valid() && config.identity() === null, 'Native logout invalidates the previously open review immediately.');
    check(!browser.document.getElementById('mapa-native-crew-alert') && !browser.document.getElementById('mapa-native-crew-driver'), 'Native logout removes both private review entry points.');
    check(browser.invalidations.length > 0, 'Native logout clears parent private enrollment/media UI.');
    const beforeLogout = browser.calls.length; browser.tick(); await settled(); equal(browser.calls.length, beforeLogout, 'Signed-out console fetches no private enrollment data.');

    const companyChange = browserFixture(); await settled(); companyChange.document.getElementById('mapa-native-crew-alert').click(); await settled();
    const oldConfig = companyChange.configured[0]; companyChange.currentUser.companyId = 'other_company'; companyChange.currentUser.events['user.organization_switched'](); await settled();
    check(!oldConfig.valid() && oldConfig.identity() === null, 'Organization switch invalidates the previous tenant review and media transport.');
    check(companyChange.invalidations.length > 0, 'Organization switch clears cached private review state.');

    const crew = browserFixture({ role: 'driver' }); await settled(); crew.tick(); await settled();
    check(crew.calls.length >= 1 && crew.calls.every(call => call[0].endsWith('/identity')), 'Crew without review capability never fetches the private registration directory.');
    check(!crew.document.getElementById('mapa-native-crew-alert') && !crew.document.getElementById('mapa-native-crew-driver'), 'Crew cannot see staff approval controls.');
}
main().catch(error => { console.error(error); process.exitCode = 1; });