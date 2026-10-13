/* Copyright (c) 2026 MAPA.DEV. SPDX-License-Identifier: AGPL-3.0-or-later */

(() => {

    'use strict';
    const mapaIST = value => { const original = new Date(value); if (!Number.isFinite(original.getTime())) return 'Time unavailable'; const d = new Date(original.getTime() + 19800000), pad = n => String(n).padStart(2, '0'), h = d.getUTCHours(); return pad(d.getUTCDate()) + ' ' + ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'][d.getUTCMonth()] + ' ' + d.getUTCFullYear() + ', ' + pad(h % 12 || 12) + ':' + pad(d.getUTCMinutes()) + ':' + pad(d.getUTCSeconds()) + ' ' + (h < 12 ? 'AM' : 'PM') + ' IST'; };


    const CONTRACT = 'mapa-native-mobile-v1';

    const PATH = '/api/int/v1/mapa/crew-review/intake';

    const safeId = value => typeof value === 'string' && /^[A-Za-z0-9_-]{1,120}$/.test(value);

    const sameScope = (a, b) => !!a && !!b && a.user === b.user && a.company === b.company && a.credential === b.credential;

    const operationsRoute = route => /^console\.fleet-ops\.operations(?:\.|$)/.test(route || '');

    const updateSignal = data => !!data && typeof data === 'object' && !Array.isArray(data)

        && Object.keys(data).length === 2 && data.type === 'intake-updated' && data.version === 1;

    const text = (value, limit) => typeof value === 'string' ? value.slice(0, limit) : '';

    const failure = (message, status = 0) => Object.assign(new Error(message), { status });

    function snapshot(result) {

        if (result?.contract !== CONTRACT || !Array.isArray(result.calls) || result.calls.length > 100 || typeof result.truncated !== 'boolean')

            throw failure('The server did not provide a supported saved-call list.', 502);

        const seen = new Set();

        const calls = result.calls.map(row => {

            if (!safeId(row?.id) || seen.has(row.id) || !Number.isSafeInteger(row.revision) || row.revision < 0

                || !['needs_review', 'ready', 'dispatched', 'dismissed'].includes(row.state)

                || (row.order_id != null && !safeId(row.order_id)) || typeof row.demo !== 'boolean')

                throw failure('The server returned an unsupported saved-call record.', 502);

            seen.add(row.id);

            return { id: row.id, revision: row.revision, state: row.state, caller_label: text(row.caller_label, 200),

                pickup_address: text(row.pickup_address, 1000), created_at: text(row.created_at, 64), updated_at: text(row.updated_at, 64),

                order_id: row.order_id || null, demo: row.demo };

        });

        return { calls, truncated: result.truncated };

    }

    function changedOrders(previous, next) {

        if (!previous) return [];

        const before = new Map(previous.calls.map(row => [row.id, row]));

        return next.calls.filter(row => row.order_id && (!before.has(row.id)

            || before.get(row.id).order_id !== row.order_id || before.get(row.id).revision !== row.revision))

            .map(row => row.order_id);

    }

    function createTransport({ origin, scope, headers, fetchImpl }) {

        const initial = scope();

        if (!initial) throw failure('Sign in to Operations to view saved calls.', 401);

        const valid = () => sameScope(initial, scope());

        const controllers = new Set();

        async function request() {

            if (!valid()) throw failure('Operations identity or organization changed.', 401);

            const nativeHeaders = headers();

            if (nativeHeaders?.Authorization !== `Bearer ${initial.credential}`) throw failure('Operations authorization changed.', 401);

            const safeHeaders = { Authorization: nativeHeaders.Authorization, Accept: 'application/json' };

            for (const name of ['Access-Console-Sandbox', 'Access-Console-Sandbox-Key'])

                if (nativeHeaders[name] !== undefined) safeHeaders[name] = nativeHeaders[name];

            const controller = new AbortController(); controllers.add(controller);

            const timer = setTimeout(() => controller.abort(), 20000);

            try {

                const response = await fetchImpl(origin + PATH, { method: 'GET', headers: safeHeaders,

                    credentials: 'omit', redirect: 'error', cache: 'no-store', signal: controller.signal });

                if (!valid()) throw failure('Operations identity or organization changed.', 401);

                if (!response.ok) throw failure(response.status === 401 || response.status === 403

                    ? 'Saved-call access is unavailable for this Operations account.' : 'Saved calls could not be refreshed.', response.status);

                const result = await response.json();

                if (!valid()) throw failure('Operations identity or organization changed.', 401);

                if (result?.company_id !== initial.company) throw failure('Saved-call organization did not match Operations.', 403);

                return snapshot(result);

            } catch (error) {

                if (error?.name === 'AbortError') throw failure('Saved-call refresh was interrupted.');

                throw error;

            } finally { clearTimeout(timer); controllers.delete(controller); }

        }

        return Object.freeze({ request, valid, cancel: () => { for (const controller of controllers) controller.abort(); } });

    }

    function sourceCallUnread(rows,seen){
        if(!Array.isArray(rows)||rows.length>500)throw new Error('Invalid ASR call list');
        const ids=new Set();let count=0;for(const row of rows){const id=String(row?.id??'');if(!/^[1-9][0-9]{0,9}$/.test(id)||ids.has(id)||!/^[a-f0-9]{64}$/.test(row?.source_revision??''))throw new Error('Invalid ASR call identity');ids.add(id);if(seen.get(id)!==row.source_revision)count++;}return count;
    }
    const pure = { sourceCallUnread, sameScope, operationsRoute, updateSignal, snapshot, changedOrders, createTransport };

    if (typeof document === 'undefined') { if (typeof module === 'object' && module.exports) module.exports = pure; return; }

    if (typeof window.define !== 'function') return;

    const initialized = new WeakSet();

    window.define('@fleetbase/console/instance-initializers/mapa-intake-native', ['exports', '@ember/destroyable/index'], (exports, destroyable) => {

        exports.default = { name: 'mapa-intake-native', initialize(owner) {

            const lifecycle = initialize(owner);

            if (lifecycle && typeof destroyable?.registerDestructor === 'function') destroyable.registerDestructor(owner, lifecycle.destroy);

            return lifecycle;

        } };

    });

    function initialize(owner) {

        if (initialized.has(owner)) return;

        initialized.add(owner);

        const session = owner.lookup('service:session'), currentUser = owner.lookup('service:current-user');

        const nativeFetch = owner.lookup('service:fetch'), router = owner.lookup('service:router');

        if (!session || !currentUser || !router || typeof nativeFetch?.getHeaders !== 'function') return;

        let current = null, transport = null, generation = 0, inflight = null, blocked = false;

        let data = null, lastAttempt = 0, lastSuccess = 0, message = '', orderNotice = false;

        let button = null, panel = null, panelStatus = null, list = null, dispatchNotice = null, rendered = '';

        let opened = false, destroyed = false, scheduled = false, channel = null;

        const listeners = [], serviceListeners = [];

        function on(target, name, handler) { target.addEventListener(name, handler); listeners.push([target, name, handler]); }

        function serviceOn(target, name, handler) { target.on?.(name, handler); serviceListeners.push([target, name, handler]); }

        function readScope() {

            const auth = session.isAuthenticated && session.data?.authenticated, company = currentUser.companyId;

            return auth && safeId(auth.user) && safeId(company) && typeof auth.token === 'string' && /^[A-Za-z0-9|._~-]{20,1024}$/.test(auth.token)

                ? { user: auth.user, company, credential: auth.token } : null;

        }

        let intakeTab = null, intakeFrame = null, sourceCalls = [], sourceLastAttempt = 0, sourceBusy = false, sourceUnavailable = false;
        const sourceSeen = new Map();
        window.mapaSourceCallRead=(child,id,revision)=>{if(child!==intakeFrame?.contentWindow||!safeId(id)||!/^[a-f0-9]{64}$/.test(revision))return;sourceSeen.set(id,revision);mountIntakeTab();};
        async function pollSourceCalls(){
            if(blocked||sourceBusy||Date.now()-sourceLastAttempt<30000||!current||document.visibilityState==='hidden')return;
            sourceBusy=true;sourceLastAttempt=Date.now();const own=readScope();const controller=new AbortController(),timer=setTimeout(()=>controller.abort(),20000);
            try{const h=nativeFetch.getHeaders();if(h.Authorization!==`Bearer ${own.credential}`)return;const result=await window.fetch('/api/int/v1/mapa/crew-review/source-calls',{signal:controller.signal,headers:{Authorization:h.Authorization,Accept:'application/json'},credentials:'omit',redirect:'error',cache:'no-store'});const feed=await result.json();if(!sameScope(own,readScope()))return;if(!result.ok||feed.contract!=='mapa-asr-caller-feed-v1'||feed.company_id!==own.company||!Array.isArray(feed.calls)||feed.calls.length>500)throw new Error('Caller feed unavailable');sourceCallUnread(feed.calls,sourceSeen);sourceCalls=feed.calls;sourceUnavailable=false;}catch(_){if(sameScope(own,readScope()))sourceUnavailable=true;}finally{clearTimeout(timer);sourceBusy=false;mountIntakeTab();}
        }

        function closeIntake() {

            intakeFrame?.parentNode?.classList.remove('mapa-intake-workspace-open');

            intakeFrame?.remove(); intakeFrame = null;

            intakeTab?.classList.remove('active');

        }

        function mountIntakeTab() {

            const board = document.getElementById('ops-kanban-view-button');

            if (!board || !current) return;

            if (!intakeTab) {

                intakeTab = action('New Call', openIntake); intakeTab.id = 'mapa-new-call-view-button';

                on(document, 'click', event => {

                    if (event.target?.closest?.('#ops-map-view-button,#ops-table-view-button,#ops-kanban-view-button')) closeIntake();

                });

            }

            if (intakeTab.parentNode !== board.parentNode) board.parentNode.append(intakeTab);
            const unread=sourceCallUnread(sourceCalls,sourceSeen);
            const pending=data?.calls.filter(row=>['needs_review','ready'].includes(row.state)).length||0;
            const count=unread+pending;intakeTab.textContent='New Call'+(count?' · '+count:'');intakeTab.classList.toggle('has-pending-notifications',count>0);intakeTab.title=sourceUnavailable?'ASR caller updates unavailable; last known alert count retained':'ASR caller updates refresh every 30 seconds';intakeTab.setAttribute('aria-label',count?`New Call · ${unread} unread ASR calls · ${pending} MAPA calls awaiting dispatch`:'New Call');

        }

        async function openIntake(options = {}) {

            const initial=readScope();
            const intakePath = options.referral === true ? '/call-intake/?transfer=referral' : '/call-intake/';

            const workspace = document.querySelector('.next-view-section-container');

            if (!workspace || !initial) return;

            mountIntakeTab();

            if (intakeFrame) { intakeFrame.focus(); return; }

            intakeFrame = node('iframe', 'mapa-intake-workspace-frame');

            intakeFrame.title = 'New Call · MAPA operator desk';

            intakeFrame.src = intakePath+'#launching';

            intakeFrame.addEventListener('load', () => {

                try { if (intakeFrame && !/^\/call-intake(?:\/|$)/.test(intakeFrame.contentWindow.location.pathname)) { closeIntake(); return; } intakeFrame?.contentDocument?.documentElement.classList.toggle('dark', (document.documentElement.classList.contains('dark') || document.body.classList.contains('dark-theme'))); } catch (_) {}

            });

            workspace.append(intakeFrame); workspace.classList.add('mapa-intake-workspace-open');

            intakeTab?.classList.add('active');

            const frame = intakeFrame;

            const tab = { location: { replace: url => { if (intakeFrame === frame) frame.src = url; } }, get closed() { return intakeFrame !== frame; } };

            const controller=new AbortController(), timer=setTimeout(()=>controller.abort(),15000);

            try {

                const response=await window.fetch('/api/int/v1/mapa/crew-review/intake-launch',{

                    method:'POST',headers:{Authorization:`Bearer ${initial.credential}`,Accept:'application/json','Content-Type':'application/json'},

                    body:'{}',credentials:'omit',cache:'no-store',redirect:'error',signal:controller.signal

                });

                const result=await response.json();

                if(!response.ok||!sameScope(initial,readScope())||!/^[a-f0-9]{64}$/.test(result.ticket)||result.expires_at<=Date.now()) throw failure('Launch unavailable.');

                tab.location.replace(intakePath+'#launch='+result.ticket);

            } catch(error) { if(!tab.closed) tab.location.replace('/call-intake/'); }

            finally { clearTimeout(timer); }

        }

        window.mapaOpenIntake=openIntake;
        window.mapaCloseIntake=()=>{const guard=intakeFrame?.contentWindow?.mapaIntakeCanLeave;if(typeof guard==='function'&&!guard())return false;closeIntake();return true;};

        function clearView() {

            closeIntake(); intakeTab?.remove(); intakeTab = null;

            opened = false; button?.remove(); panel?.remove(); button = panel = panelStatus = list = dispatchNotice = null; rendered = '';

        }

        function forget() {

            sourceCalls=[];sourceSeen.clear();sourceLastAttempt=0;sourceUnavailable=false;
            generation++; transport?.cancel(); current = transport = inflight = null; data = null; blocked = false;

            lastAttempt = lastSuccess = 0; message = ''; orderNotice = false; clearView();

        }

        function synchronize() {

            const scope = readScope();

            if (destroyed || !scope || !operationsRoute(router.currentRouteName)) { if (current || button) forget(); return null; }

            if (!sameScope(current, scope)) {

                forget(); current = scope;

                transport = createTransport({ origin: location.origin, scope: readScope, headers: () => nativeFetch.getHeaders(), fetchImpl: (...args) => window.fetch(...args) });

            }

            return scope;

        }

        function node(tag, className, value) {

            const result = document.createElement(tag); if (className) result.className = className;

            if (value !== undefined) result.textContent = value; return result;

        }

        function action(label, handler) {

            const result = node('button', 'mapa-intake-action', label); result.type = 'button';

            result.addEventListener('click', event => { event.preventDefault(); handler(); }); return result;

        }

        function date(value) {

            const time = Date.parse(value); return Number.isFinite(time) ? mapaIST(time) : 'Not available';

        }

        function closePanel() { opened = false; panel?.remove(); panel = panelStatus = list = dispatchNotice = null; rendered = ''; button?.setAttribute('aria-expanded', 'false'); button?.focus(); }

        function renderPanel() {

            if (!opened || !current || !transport?.valid()) return;

            if (!panel) {

                panel = node('aside', 'mapa-intake-panel'); panel.id = 'mapa-intake-panel'; panel.tabIndex = -1;

                panel.setAttribute('role', 'region'); panel.setAttribute('aria-labelledby', 'mapa-intake-title');

                const heading = node('div', 'mapa-intake-heading'), title = node('h2', '', 'Saved calls'); title.id = 'mapa-intake-title';

                heading.append(title, action('Close', closePanel));

                const controls = node('div', 'mapa-intake-controls'), refresh = action('Refresh calls', () => { blocked = false; lastAttempt = 0; poll(true); });

                const desk = node('a', 'mapa-intake-action', 'Open operator desk ↗'); desk.href = '/call-intake/'; desk.target = '_blank'; desk.rel = 'noopener noreferrer';

                desk.addEventListener('click',event=>{event.preventDefault();openIntake();});

                controls.append(refresh, desk);

                panelStatus = node('p', 'mapa-intake-status'); panelStatus.setAttribute('role', 'status');

                dispatchNotice = node('p', 'mapa-intake-note');

                list = node('ol', 'mapa-intake-list');

                panel.append(heading, node('p', 'mapa-intake-note', 'Calls saved at the operator desk appear here. Calls awaiting review become ambulance trips after dispatch.'), controls, panelStatus, dispatchNotice, list);

                document.body.append(panel); panel.focus();

            }

            const statusText = message || (lastSuccess ? `Updated ${date(new Date(lastSuccess).toISOString())}. Refreshes every 10 seconds while Operations is visible.` : 'Loading saved calls…');

            if (panelStatus.textContent !== statusText) panelStatus.textContent = statusText;

            const notice = orderNotice ? 'Dispatch details changed. This saved-call panel is current. Use the Operations refresh action to refresh its trip list when you are ready.' : '';

            if (dispatchNotice.textContent !== notice) dispatchNotice.textContent = notice;

            const signature = JSON.stringify(data);

            if (signature === rendered) return;

            rendered = signature; list.replaceChildren();

            if (!data) return;

            if (!data.calls.length) list.append(node('li', 'mapa-intake-empty', 'No saved calls in the current organization.'));

            const labels = { needs_review: 'Awaiting review', ready: 'Ready to dispatch', dispatched: 'Dispatched', dismissed: 'Dismissed' };

            for (const row of data.calls) {

                const card = node('li', 'mapa-intake-call');

                card.append(node('strong', 'mapa-intake-caller', `${row.demo ? 'DEMO · ' : ''}${row.caller_label || 'Caller details not recorded'}`),

                    node('span', 'mapa-intake-state', labels[row.state]),

                    node('p', 'mapa-intake-address', row.pickup_address || 'Pickup awaiting confirmation'),

                    node('small', 'mapa-intake-updated', `Last updated: ${date(row.updated_at || row.created_at)}`));

                if (row.order_id) card.append(node('small', 'mapa-intake-order', `Trip: ${row.order_id}`));

                list.append(card);

            }

            if (data.truncated) list.append(node('li', 'mapa-intake-note', 'Showing the latest 100 calls. Earlier calls remain in the operator desk.'));

        }

        function mount() {

            if (!current || !transport?.valid() || !operationsRoute(router.currentRouteName) || destroyed) { clearView(); return; }

            mountIntakeTab();

            try { const root = intakeFrame?.contentDocument?.documentElement; const dark = (document.documentElement.classList.contains('dark') || document.body.classList.contains('dark-theme')); if (root && root.classList.contains('dark') !== dark) root.classList.toggle('dark', dark); } catch (_) {}

            const tray = document.getElementById('view-header-tray-items');

            if (!button) {

                button = action('Saved calls', () => { if (!synchronize()) return; opened = !opened; if (opened) { mount(); poll(true); } else closePanel(); });

                button.id = 'mapa-intake-summary'; button.setAttribute('aria-controls', 'mapa-intake-panel');

            }

            const parent = tray || document.body;

            if (button.parentNode !== parent) parent.append(button);

            button.classList.toggle('mapa-intake-floating', !tray);

            const pending = data?.calls.filter(row => ['needs_review', 'ready'].includes(row.state)).length;

            const label = blocked ? 'Saved calls · unavailable' : data ? `Saved calls · ${pending}${data.truncated ? '+' : ''} awaiting dispatch` : 'Saved calls';

            const compactLabel = blocked ? 'Saved calls · unavailable' : data ? `Saved calls · ${pending}${data.truncated ? '+' : ''}` : 'Saved calls';

            if (button.textContent !== compactLabel) button.textContent = compactLabel;

            button.classList.toggle('has-pending-notifications', !blocked && pending > 0);
            button.setAttribute('aria-label', label);

            button.title = label;

            button.setAttribute('aria-expanded', String(opened));

            renderPanel();

        }

        async function poll(force = false) {

            if (destroyed || document.visibilityState === 'hidden' || !synchronize()) return;

            mount();

            if (blocked || inflight || Date.now() - lastAttempt < (force ? 1000 : 10000)) return;

            lastAttempt = Date.now(); const own = generation, selected = transport;

            const task = (async () => {

                try {

                    const next = await selected.request();

                    if (destroyed || own !== generation || transport !== selected || !selected.valid() || !operationsRoute(router.currentRouteName)) return;

                    if (changedOrders(data, next).length) orderNotice = true;

                    data = next; lastSuccess = Date.now(); message = ''; mount();

                } catch (error) {

                    if (destroyed || own !== generation || transport !== selected) return;

                    if (!selected.valid()) { synchronize(); return; }

                    if ([401, 403].includes(error.status)) {

                        // An intake permission failure must never invalidate the native Operations session.

                        blocked = true; data = null; orderNotice = false; lastSuccess = 0;

                        message = 'Saved-call access is unavailable for this account. Operations remains signed in. Contact an administrator or retry after access is restored.';

                    } else message = lastSuccess ? `Refresh unavailable. Showing saved calls from ${date(new Date(lastSuccess).toISOString())}; retrying automatically.` : 'Saved calls are temporarily unavailable. Retrying automatically.';

                    mount();

                }

            })();

            inflight = task;

            try { await task; } finally { if (inflight === task) inflight = null; }

        }

        function routeChanged() { if (synchronize()) { mount(); poll(true); } }

        function resetScope() { forget(); routeChanged(); }

        function destroy() {

            if (destroyed) return; destroyed = true; forget(); observer.disconnect(); clearInterval(interval); channel?.close();

            if(window.mapaOpenIntake===openIntake) { delete window.mapaOpenIntake; delete window.mapaCloseIntake; }

            for (const [target, name, handler, capture] of listeners) target.removeEventListener(name, handler, capture);

            for (const [target, name, handler] of serviceListeners) target.off?.(name, handler);

        }

        const observer = new MutationObserver(() => {

            if (!scheduled && !destroyed) { scheduled = true; requestAnimationFrame(() => { scheduled = false; if (synchronize()) mount(); }); }

        });

        observer.observe(document.documentElement, { childList: true, subtree: true });

        const interval = setInterval(() => { if (synchronize()) { mount(); poll(); pollSourceCalls(); } }, 1000);

        serviceOn(router, 'routeDidChange', routeChanged);

        serviceOn(session, 'invalidationSucceeded', resetScope);

        serviceOn(session, 'authenticationSucceeded', resetScope);

        serviceOn(currentUser, 'user.organization_switched', resetScope);

        on(window, 'focus', routeChanged);

        on(document, 'visibilitychange', () => { if (document.visibilityState === 'hidden') transport?.cancel(); else routeChanged(); });

        on(window, 'pageshow', routeChanged);

        on(window, 'pagehide', event => { if (event.persisted) forget(); else destroy(); });

        on(document, 'keydown', event => {

            if (event.key === 'Escape' && opened && !event.defaultPrevented

                && (document.activeElement === button || panel?.contains(document.activeElement))) { event.preventDefault(); closePanel(); }

        });

        if (typeof window.BroadcastChannel === 'function') {

            try { channel = new window.BroadcastChannel('mapa-intake-updates'); channel.onmessage = event => { if (updateSignal(event.data)) poll(true); }; } catch (_) { /* Periodic reads and focus refresh remain available. */ }

        }

        routeChanged();

        return Object.freeze({ destroy });

    }

})();

