/* Copyright (c) 2026 MAPA.DEV. SPDX-License-Identifier: AGPL-3.0-or-later */
(() => {
    'use strict';
    const CONTRACT = 'mapa-native-mobile-v1';
    const id = value => typeof value === 'string' && /^[A-Za-z0-9_-]{1,120}$/.test(value);
    const sameScope = (a, b) => !!a && !!b && a.user === b.user && a.company === b.company && a.credential === b.credential;
    function allowedPath(path, method, media = false) {
        if (media) return method === 'GET' && /^\/media\/[A-Za-z0-9_-]{1,120}$/.test(path);
        return method === 'GET' ? /^\/(identity|enrollments(?:\/options)?|trip-reports)$/.test(path)
            : method === 'POST' && /^\/enrollments\/[A-Za-z0-9_-]{1,120}\/(approve|reject|revoke|ambulance|hospital)$/.test(path);
    }
    function reviewIdentity(result, scope) {
        const user = result?.user;
        return result?.contract === CONTRACT && result.native_user_uuid === scope?.user && id(user?.id)
            && user.company_id === scope?.company && ['admin', 'superadmin'].includes(user.role)
            && result.capabilities?.enrollment_review === true
            ? { user: user.id, company: user.company_id, name: typeof user.name === 'string' ? user.name : '', role: user.role } : null;
    }
    function pendingSummary(result) {
        const rows = Array.isArray(result?.enrollments) ? result.enrollments : [];
        const count = rows.filter(row => id(row?.id) && row.status === 'pending').length;
        const total = Number.isSafeInteger(result?.pending_count) && result.pending_count >= count ? result.pending_count : null;
        return { count: total ?? count, exact: total !== null || result?.truncated !== true,
            ids: rows.filter(row => id(row?.id) && row.status === 'pending').map(row => row.id).slice(0, 200) };
    }
    function driverReference(route) {
        if (!/\.management\.drivers\.index\.details(?:\.|$)/.test(route?.name || '')) return '';
        for (let part = route, depth = 0; part && depth < 12; part = part.parent, depth++)
            if (id(part.params?.public_id)) return part.params.public_id;
        return '';
    }
    function failure(message, status) { return Object.assign(new Error(message), { status }); }
    function createTransport({ origin, scope, headers, fetchImpl, invalidated = () => {} }) {
        const initial = scope();
        if (!initial) throw failure('Sign in to the MAPA console.', 401);
        const valid = () => sameScope(initial, scope());
        async function execute(path, options = {}, media = false) {
            const method = options.body === undefined ? 'GET' : 'POST';
            if (!allowedPath(path, method, media)) throw failure('Unsupported native crew review request.', 400);
            if (!valid()) throw failure('The native console identity or organization changed.', 401);
            if (options.body !== undefined) {
                if (typeof options.body !== 'string' || new TextEncoder().encode(options.body).length > 16384)
                    throw failure('Crew review request is too large or unsupported.', 400);
                let input; try { input = JSON.parse(options.body); } catch (_) { throw failure('A JSON crew review request is required.', 400); }
                if (!input || typeof input !== 'object' || Array.isArray(input)) throw failure('A JSON review object is required.', 400);
            }
            const nativeHeaders = headers();
            if (nativeHeaders?.Authorization !== `Bearer ${initial.credential}`) throw failure('Native console authorization changed.', 401);
            const safeHeaders = { Authorization: nativeHeaders.Authorization, Accept: media ? 'image/jpeg' : 'application/json' };
            for (const key of ['Access-Console-Sandbox', 'Access-Console-Sandbox-Key']) if (nativeHeaders[key] !== undefined) safeHeaders[key] = nativeHeaders[key];
            if (options.body !== undefined) safeHeaders['Content-Type'] = 'application/json';
            const controller = new AbortController(), timer = setTimeout(() => controller.abort(), 20000);
            try {
                const response = await fetchImpl(origin + '/api/int/v1/mapa/crew-review' + path, {
                    method, headers: safeHeaders, ...(options.body === undefined ? {} : { body: options.body }),
                    cache: 'no-store', credentials: 'omit', redirect: 'error', signal: controller.signal,
                });
                if (!valid()) throw failure('The native console session changed while waiting for a receipt.', 401);
                if (!response.ok) {
                    let data = {}; try { data = await response.json(); } catch (_) {}
                    if (!valid()) throw failure('The native console session changed.', 401);
                    if ([401, 403].includes(response.status)) invalidated(initial);
                    const error = failure(typeof data.error === 'string' ? data.error : 'Native crew review could not be completed.', response.status);
                    if (data.fields && typeof data.fields === 'object') error.fields = Object.values(data.fields).flat().filter(v => typeof v === 'string').join(' ');
                    throw error;
                }
                if (media) {
                    if (!/^image\/jpeg(?:;|$)/i.test(response.headers.get('content-type') || '')) throw failure('A private JPEG photograph is required.', 502);
                    const blob = await response.blob();
                    if (!valid()) throw failure('The native console session changed before the photo was received.', 401);
                    if (!blob.size || blob.size > 2 * 1024 * 1024) throw failure('The private photograph is unavailable or too large.', 502);
                    return blob;
                }
                const result = await response.json();
                if (!valid()) throw failure('The native console session changed before the receipt was received.', 401);
                if (result?.contract !== CONTRACT) throw failure('The server did not confirm a supported crew review response.', 502);
                return result;
            } catch (error) {
                if (error?.name === 'AbortError') throw failure('The response timed out. A review update may already have reached MAPA; retry its original saved action.', 0);
                throw error;
            } finally { clearTimeout(timer); }
        }
        return Object.freeze({ request: (path, options) => execute(path, options), media: path => execute(path, {}, true), valid });
    }
    const pure = { allowedPath, sameScope, reviewIdentity, pendingSummary, driverReference, createTransport };
    if (typeof document === 'undefined') { if (typeof module === 'object' && module.exports) module.exports = pure; return; }
    if (typeof window.define !== 'function') return;
    // This module is registered before the normal App boot. Ember supplies its
    // public owner to initialize; no global application/container lookup occurs.
    window.define('@fleetbase/console/instance-initializers/mapa-crew-native', ['exports'], exports => {
        exports.default = { name: 'mapa-crew-native', initialize };
    });
    function initialize(owner) {
        const session = owner.lookup('service:session'), currentUser = owner.lookup('service:current-user');
        const nativeFetch = owner.lookup('service:fetch'), router = owner.lookup('service:router');
        const notices = owner.lookup('service:notifications');
        if (!session || !currentUser || !nativeFetch || !router || typeof nativeFetch.getHeaders !== 'function') return;
        let current = null, transport = null, verified = null, inflight = null, generation = 0, lastPoll = 0;
        let summary = { count: 0, exact: true, ids: [] }, known = null, blocked = null, headerButton = null, driverButton = null;
        function readScope() {
            const auth = session.isAuthenticated && session.data?.authenticated;
            const company = currentUser.companyId;
            return auth && id(auth.user) && id(company) && typeof auth.token === 'string' && /^[A-Za-z0-9|._~-]{20,1024}$/.test(auth.token)
                ? { user: auth.user, company, credential: auth.token } : null;
        }
        function forget() {
            generation++; current = null; transport = null; verified = null; inflight = null; known = null; lastPoll = 0;
            summary = { count: 0, exact: true, ids: [] };
            window.MapaCrewReview?.invalidate?.();
            for (const node of [headerButton, driverButton]) node?.remove(); headerButton = driverButton = null;
        }
        function synchronize() {
            const scope = readScope();
            if (!sameScope(current, scope)) { if (current || verified) forget(); current = scope; }
            return scope;
        }
        function note(message) { if (typeof notices?.info === 'function') notices.info(message); }
        async function connect() {
            const scope = synchronize();
            if (!scope || sameScope(blocked, scope)) return false;
            if (verified && transport?.valid()) return true;
            if (inflight) return inflight;
            const own = generation;
            const candidate = createTransport({ origin: location.origin, scope: readScope, headers: () => nativeFetch.getHeaders(), fetchImpl: (...args) => window.fetch(...args),
                invalidated: failed => { if (sameScope(failed, readScope())) { blocked = failed; forget(); } } });
            const task = (async () => {
                try {
                    const result = await candidate.request('/identity');
                    const identity = reviewIdentity(result, scope);
                    if (!identity || own !== generation || !candidate.valid()) return false;
                    verified = identity; transport = candidate; blocked = null;
                    mount(); return true;
                } catch (_) { return false; }
            })();
            inflight = task;
            try { return await task; } finally { if (inflight === task) inflight = null; }
        }
        function openDriver(reference) {
            if (!transport?.valid() || !id(reference)) return;
            router.transitionTo('console.fleet-ops.management.drivers.index.details', reference);
        }
        async function open(button, reference = '') {
            if (!await connect() || !transport?.valid()) { note('This console account cannot review crew registrations. Ask an approved ASR Health administrator.'); return; }
            if (!window.MapaCrewReview?.configure || !window.MapaCrewReview?.open) { note('Crew registration review is still opening. Try again shortly.'); return; }
            const selected = transport, identity = verified;
            window.MapaCrewReview.configure({ request: selected.request, media: selected.media,
                identity: () => selected.valid() ? identity : null, valid: selected.valid, openDriver });
            window.MapaCrewReview.open({ trigger: button, ...(reference ? { driverId: reference } : {}) });
        }
        function button(className, click) {
            const node = document.createElement('button'); node.type = 'button'; node.className = className;
            node.addEventListener('click', event => { event.preventDefault(); click(node); }); return node;
        }
        function mount() {
            if (!verified || !transport?.valid() || !/^console(?:\.|$)/.test(router.currentRouteName || '')) {
                for (const node of [headerButton, driverButton]) node?.remove(); headerButton = driverButton = null; return;
            }
            const tray = document.getElementById('view-header-tray-items');
            if (tray && (!headerButton || !headerButton.isConnected)) { headerButton = button('mapa-native-crew-alert', node => open(node)); headerButton.id = 'mapa-native-crew-alert'; tray.append(headerButton); }
            if (headerButton) {
                const text = summary.exact ? `Crew registrations · ${summary.count} pending` : `Crew registrations · ${summary.count} in latest records`;
                const compactText = `Crew · ${summary.count}${summary.exact ? '' : '+'} pending`;
                if (headerButton.textContent !== compactText) headerButton.textContent = compactText;
                headerButton.title = text;
                headerButton.setAttribute('aria-label', text + '. Open registration review.');
                headerButton.classList.toggle('has-pending', summary.count > 0);
            }
            const driverRoute = /\.management\.drivers\.index(?:\.|$)/.test(router.currentRouteName || '');
            const target = driverRoute && document.querySelector('#next-view-section-subheader .next-view-section-subheader-actions');
            if (!target) { driverButton?.remove(); driverButton = null; return; }
            if (!driverButton || !driverButton.isConnected || driverButton.parentNode !== target) {
                driverButton?.remove(); driverButton = button('mapa-native-crew-driver', node => open(node, driverReference(router.currentRoute)));
                driverButton.id = 'mapa-native-crew-driver'; target.prepend(driverButton);
            }
            const text = driverReference(router.currentRoute) ? 'Registration & selfie' : `Crew registration (${summary.count})`;
            if (driverButton.textContent !== text) driverButton.textContent = text;
        }
        async function poll(force = false) {
            if (document.visibilityState === 'hidden') return;
            synchronize();
            if (!readScope() || (!force && Date.now() - lastPoll < 30000)) { mount(); return; }
            lastPoll = Date.now(); const own = generation;
            if (!await connect() || own !== generation || !transport?.valid()) return;
            const selected = transport;
            try {
                const result = await selected.request('/enrollments');
                if (own !== generation || transport !== selected || !selected.valid()) return;
                const next = pendingSummary(result);
                if (known && next.ids.some(value => !known.has(value))) note('A new crew registration is waiting for staff review.');
                known = new Set(next.ids); summary = next; mount();
            } catch (_) { if (own === generation) mount(); }
        }
        function routeChanged() { synchronize(); requestAnimationFrame(mount); poll(); }
        router.on?.('routeDidChange', routeChanged);
        session.on?.('invalidationSucceeded', () => { blocked = null; forget(); });
        session.on?.('authenticationSucceeded', () => { blocked = null; synchronize(); poll(true); });
        currentUser.on?.('user.organization_switched', () => { blocked = null; forget(); synchronize(); poll(true); });
        window.addEventListener('mapa:crew-review-updated', () => { lastPoll = 0; poll(true); });
        window.addEventListener('focus', routeChanged);
        document.addEventListener('visibilitychange', routeChanged);
        let scheduled = false;
        new MutationObserver(() => { if (!scheduled) { scheduled = true; requestAnimationFrame(() => { scheduled = false; synchronize(); mount(); }); } })
            .observe(document.documentElement, { childList: true, subtree: true });
        setInterval(() => { synchronize(); mount(); poll(); }, 1500);
        routeChanged();
    }
})();
