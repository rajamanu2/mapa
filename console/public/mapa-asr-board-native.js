/* Copyright (c) 2026 MAPA.DEV. SPDX-License-Identifier: AGPL-3.0-or-later */
(() => {
    'use strict';
    const CONTRACT = 'mapa-asr-caller-feed-v1';
    const boardRoute = () => /^\/fleet-ops\/?$/.test(location.pathname) && new URLSearchParams(location.search).get('layout') === 'kanban';
    const safe = (value, limit = 180) => typeof value === 'string' ? value.slice(0, limit) : '';
    const scopeEqual = (a, b) => !!a && !!b && a.user === b.user && a.company === b.company && a.token === b.token;
    const formatTime = value => {
        const instant = typeof value === 'string' && /^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}/.test(value) ? sourceTime(value) : new Date(value).getTime();
        if (!Number.isFinite(instant)) return 'Time unavailable';
        const d = new Date(instant + 19800000), pad = n => String(n).padStart(2, '0'), hour = d.getUTCHours();
        return `${pad(d.getUTCDate())} ${['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'][d.getUTCMonth()]} ${d.getUTCFullYear()}, ${pad(hour % 12 || 12)}:${pad(d.getUTCMinutes())} ${hour < 12 ? 'AM' : 'PM'} IST`;
    };
    function parseFeed(feed, company) {
        if (feed?.contract !== CONTRACT || feed.company_id !== company || !Array.isArray(feed.calls) || feed.calls.length > 500 || typeof feed.stale !== 'boolean' || typeof feed.truncated !== 'boolean' || !Number.isSafeInteger(feed.generated_at) || feed.generated_at < 0) throw new Error('ASR call response is invalid.');
        const ids = new Set();
        const calls = feed.calls.map(row => {
            const id = String(row?.id ?? '');
            if (!/^[1-9][0-9]{0,9}$/.test(id) || ids.has(id) || !/^[a-f0-9]{64}$/.test(row.source_revision ?? '')) throw new Error('ASR call identity is invalid.');
            ids.add(id);
            return { id, incident_number: safe(row.incident_number, 100), caller_name: safe(row.caller_name, 160), pickup_address: safe(row.pickup_address, 500), incident_type: safe(row.incident_type, 120), priority: safe(row.priority, 30), status: safe(row.status, 60), created_at: safe(row.created_at, 80), updated_at: safe(row.updated_at, 80) };
        });
        return { calls, stale: feed.stale, truncated: feed.truncated, generated_at: feed.generated_at, source: 'caller feed' };
    }
    function parseInventory(data, company) {
        if (!['mapa-asr-source-v2', 'mapa-asr-source-v3'].includes(data?.contract) || data.company_id !== company || data.dataset !== 'cad_incidents' || !Array.isArray(data.rows) || data.rows.length > 100 || !Number.isSafeInteger(data.received_at)) throw new Error('ASR source snapshot is invalid.');
        const ids = new Set();
        const calls = data.rows.filter(row => !['completed', 'cancelled'].includes(String(row?.status ?? '').toLowerCase())).map(row => {
            const id = String(row?.id ?? '');
            if (!/^[1-9][0-9]{0,9}$/.test(id) || ids.has(id)) throw new Error('ASR source identity is invalid.');
            ids.add(id);
            return { id, incident_number: safe(row.incident_number, 100), caller_name: '', pickup_address: row.from_phc_id ? `Origin PHC ID ${safe(String(row.from_phc_id), 32)}` : '', incident_type: safe(row.incident_type, 120), priority: safe(row.priority, 30), status: safe(row.status, 60), created_at: safe(row.created_at, 80), updated_at: safe(row.updated_at, 80) };
        });
        return { calls, stale: data.stale === true, truncated: data.has_more === true, generated_at: data.received_at, source: 'source snapshot' };
    }
    function sourceTime(value) {
        if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}/.test(value)) return NaN;
        return Date.parse(value.replace(' ', 'T') + (value.includes('+') || value.endsWith('Z') ? '' : '+05:30'));
    }
    function recent(row, now) {
        const last = Math.max(sourceTime(row.updated_at) || 0, sourceTime(row.created_at) || 0);
        return last > 0 && last <= now + 120000 && now - last <= 86400000;
    }
    function grouped(calls, now = Date.now()) {
        const result = { 'Awaiting ASR action': [], 'In progress at ASR': [], 'Other recent calls': [], 'Older unresolved ASR records': [] };
        for (const row of calls) {
            if (!recent(row, now)) { result['Older unresolved ASR records'].push(row); continue; }
            const status = row.status.toLowerCase();
            const group = /^(pending|new|open|received|unassigned|created|queued)$/.test(status) ? 'Awaiting ASR action' : /^(assigned|dispatched|accepted|enroute|on_road|arrived|onboard|hospital|in_progress)$/.test(status) ? 'In progress at ASR' : 'Other recent calls';
            result[group].push(row);
        }
        for (const rows of Object.values(result)) rows.sort((a, b) => Date.parse(b.created_at) - Date.parse(a.created_at));
        return result;
    }
    if (typeof document === 'undefined') { if (typeof module === 'object') module.exports = { parseFeed, parseInventory, grouped, recent, scopeEqual, formatTime }; return; }
    if (typeof window.define !== 'function') return;
    const initialized = new WeakSet();
    window.define('@fleetbase/console/instance-initializers/mapa-asr-board-native', ['exports', '@ember/destroyable/index'], (exports, destroyable) => {
        exports.default = { name: 'mapa-asr-board-native', initialize(owner) { if (initialized.has(owner)) return; initialized.add(owner); const stop = start(owner); destroyable?.registerDestructor?.(owner, stop); } };
    });
    function start(owner) {
        const session = owner.lookup('service:session'), user = owner.lookup('service:current-user'), native = owner.lookup('service:fetch'), router = owner.lookup('service:router');
        if (!session || !user || !native?.getHeaders || !router) return () => {};
        const scope = () => { const a = session.isAuthenticated && session.data?.authenticated; return a?.token && a?.user && user.companyId ? { user: a.user, company: user.companyId, token: a.token } : null; };
        let chosen = 'mapa', tabs = null, panel = null, snapshot = null, error = '', identity = null, nextFetch = 0, busy = false, controller = null, destroyed = false, selected = null, query = '';
        const el = (tag, cls, value) => { const node = document.createElement(tag); if (cls) node.className = cls; if (value !== undefined) node.textContent = value; return node; };
        function clear() { controller?.abort(); controller = null; panel?.parentElement?.classList.remove('mapa-asr-board-open'); panel?.remove(); tabs?.remove(); tabs = panel = null; snapshot = null; error = ''; selected = null; nextFetch = 0; }
        function setMode(mode) {
            if (mode === 'asr' && window.mapaCloseIntake?.() === false) return;
            chosen = mode; selected = null;
            render();
            if (mode === 'asr') refresh(true);
        }
        async function refresh(force = false) {
            if (busy || chosen !== 'asr' || !boardRoute() || document.hidden || (!force && Date.now() < nextFetch)) return;
            const own = scope(); if (!own) return;
            busy = true; nextFetch = Date.now() + 30000; controller = new AbortController();
            const timeout = setTimeout(() => controller?.abort(), 20000);
            try {
                const headers = native.getHeaders();
                if (headers.Authorization !== 'Bearer ' + own.token) throw new Error('MAPA sign-in changed.');
                const options = { method: 'GET', headers: { Authorization: headers.Authorization, Accept: 'application/json' }, credentials: 'omit', cache: 'no-store', redirect: 'error', signal: controller.signal };
                const response = await fetch('/api/int/v1/mapa/crew-review/source-calls', options);
                let feed;
                if (response.ok) feed = parseFeed(await response.json(), own.company);
                else if (response.status === 401 || response.status === 403) throw new Error('This account cannot view ASR calls.');
                else {
                    const backup = await fetch('/api/int/v1/mapa/source-inventory/cad_incidents?page=1', options);
                    if (!backup.ok) throw new Error('ASR call feed and source snapshot are unavailable.');
                    feed = parseInventory(await backup.json(), own.company);
                }
                if (!scopeEqual(own, scope()) || !boardRoute()) return;
                snapshot = feed; error = feed.source === 'source snapshot' ? 'Caller feed unavailable. Showing the last verified ASR source snapshot without caller details.' : '';
            } catch (e) { if (scopeEqual(own, scope()) && boardRoute()) error = e.name === 'AbortError' ? 'ASR call refresh timed out.' : e.message; }
            finally { clearTimeout(timeout); controller = null; busy = false; if (!destroyed) render(); }
        }
        function render() {
            const active = chosen === 'asr' && boardRoute();
            if (tabs) { tabs.querySelector('[data-mode="mapa"]').setAttribute('aria-pressed', String(!active)); tabs.querySelector('[data-mode="asr"]').setAttribute('aria-pressed', String(active)); }
            if (!panel) return;
            panel.hidden = !active; panel.parentElement?.classList.toggle('mapa-asr-board-open', active);
            if (!active) return;
            panel.replaceChildren();
            const header = el('header', 'mapa-asr-board-header');
            const heading = el('div'); heading.append(el('h2', '', 'ASR Health incoming calls'), el('p', '', 'Source-owned calls from asrhealth.in. MAPA dispatch is separate.'));
            const actions = el('div', 'mapa-asr-board-actions'); const search = el('input'); search.type = 'search'; search.placeholder = 'Search calls or pickup'; search.setAttribute('aria-label', 'Search ASR calls'); search.value = query; search.oninput = () => { query = search.value; render(); panel.querySelector('input[type="search"]')?.focus(); };
            const reload = el('button', '', 'Refresh ASR calls'); reload.type = 'button'; reload.onclick = () => refresh(true); actions.append(search, reload); header.append(heading, actions); panel.append(header);
            if (error) panel.append(el('p', 'mapa-asr-board-warning', error + (snapshot ? ' Showing last verified records.' : '')));
            if (!snapshot) { panel.append(el('p', 'mapa-asr-board-empty', busy ? 'Loading ASR incoming calls…' : 'No verified ASR calls available.')); return; }
            const groups = grouped(snapshot.calls, Date.now());
            const recentCount = snapshot.calls.length - groups['Older unresolved ASR records'].length;
            panel.append(el('p', 'mapa-asr-board-freshness', `${recentCount} updated in the last 24 hours · ${groups['Older unresolved ASR records'].length} older unresolved · ASR feed ${snapshot.stale ? 'delayed' : 'checked'} ${formatTime(snapshot.generated_at * 1000)}${snapshot.truncated ? ' · List truncated' : ''}`));
            if (!recentCount) panel.append(el('p', 'mapa-asr-board-warning', 'No recent ASR incident updates. The source still reports older incidents as open; confirm their actual status in ASR Health.'));
            const filtered = snapshot.calls.filter(row => [row.incident_number, row.caller_name, row.pickup_address, row.status].some(value => value.toLowerCase().includes(query.toLowerCase())));
            const columns = el('div', 'mapa-asr-board-columns');
            for (const [name, rows] of Object.entries(grouped(filtered, Date.now()))) {
                const column = el('section', 'mapa-asr-board-column'); column.append(el('h3', '', `${name} · ${rows.length}`));
                if (!rows.length) column.append(el('p', 'mapa-asr-board-empty', 'No calls in this state.'));
                for (const row of rows) {
                    const card = el('button', 'mapa-asr-board-card'); card.type = 'button'; card.setAttribute('aria-expanded', String(selected === row.id));
                    card.append(el('strong', '', row.incident_number || `ASR call #${row.id}`), el('span', '', row.caller_name || 'Caller not recorded'), el('small', '', row.pickup_address || 'Pickup not recorded'), el('small', '', `${row.priority || 'Priority not recorded'} · ${row.status || 'Status not recorded'} · recorded ${formatTime(sourceTime(row.created_at))} · updated ${formatTime(sourceTime(row.updated_at))}`));
                    card.onclick = () => { selected = selected === row.id ? null : row.id; render(); }; column.append(card);
                    if (selected === row.id) { const detail = el('div', 'mapa-asr-board-detail'); detail.append(el('p', '', `Incident: ${row.incident_type || 'Not recorded'}`), el('p', '', `Updated: ${formatTime(row.updated_at)}`), el('p', '', 'This call remains controlled by ASR Health. Open New Call to review caller tracking.')); const open = el('button', '', 'Open New Call desk'); open.type = 'button'; open.onclick = () => { setMode('mapa'); window.mapaOpenIntake?.(); }; detail.append(open); column.append(detail); }
                }
                columns.append(column);
            }
            panel.append(columns);
        }
        function mount() {
            if (destroyed) return;
            const own = scope(), board = document.getElementById('ops-kanban-view-button'), workspace = document.querySelector('section.next-view-section > .next-view-section-container');
            if (!own || !board || !workspace || !boardRoute() || workspace.classList.contains('mapa-integration-active')) { if (tabs || panel) clear(); chosen = 'mapa'; identity = own; return; }
            if (identity && !scopeEqual(identity, own)) { clear(); chosen = 'mapa'; }
            identity = own;
            if (!tabs || !tabs.isConnected || !panel || !panel.isConnected) {
                tabs = el('div', 'mapa-asr-board-switch'); tabs.setAttribute('role', 'group'); tabs.setAttribute('aria-label', 'Board source');
                for (const [mode, label] of [['mapa', 'MAPA'], ['asr', 'ASR Health']]) { const button = el('button', '', label); button.type = 'button'; button.dataset.mode = mode; button.onclick = () => setMode(mode); tabs.append(button); }
                board.insertAdjacentElement('afterend', tabs);
                panel = el('div', 'mapa-asr-board-panel'); panel.setAttribute('aria-label', 'ASR Health incoming calls board'); workspace.append(panel);
                render();
            }
            if (chosen === 'asr') refresh();
        }
        const interval = setInterval(mount, 1000);
        const routeChanged = () => setTimeout(mount, 0); router.on?.('routeDidChange', routeChanged);
        const pageHide = () => clear(); window.addEventListener('pagehide', pageHide);
        mount();
        return () => { destroyed = true; clearInterval(interval); router.off?.('routeDidChange', routeChanged); window.removeEventListener('pagehide', pageHide); clear(); };
    }
})();
