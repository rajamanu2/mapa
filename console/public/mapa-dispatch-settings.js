/* Copyright (c) 2026 MAPA.DEV. SPDX-License-Identifier: AGPL-3.0-or-later */
(() => {
  'use strict';
  if (typeof window.define !== 'function') return;
  const element = (tag, text, attributes = {}) => {
    const node = document.createElement(tag);
    if (text != null) node.textContent = String(text);
    for (const [name, value] of Object.entries(attributes)) node.setAttribute(name, value);
    return node;
  };
  const isSettingsRoute = pathname => /^\/fleet-ops\/settings(?:\/|$)/.test(pathname);
  window.define('@fleetbase/console/instance-initializers/mapa-dispatch-settings',
    ['exports', '@ember/destroyable/index'], (exports, destroyable) => {
      exports.default = { name: 'mapa-dispatch-settings', initialize(owner) {
        const session = owner.lookup('service:session');
        const user = owner.lookup('service:current-user');
        const native = owner.lookup('service:fetch');
        const router = owner.lookup('service:router');
        if (!session || !user || !native?.getHeaders) return;
        const scope = () => {
          const auth = session.isAuthenticated && session.data?.authenticated;
          return auth?.token && auth?.user && user.companyId
            ? JSON.stringify([auth.user, user.companyId, auth.token]) : null;
        };
        let item, panel, section, identity, settings, busy = false, timer;
        let generation = 0, requestSequence = 0, verifiedAt = 0, statusError = '';
        const fresh = () => !!settings && identity === scope() && Date.now() - verifiedAt < 30000;
        const close = () => {
          generation++; requestSequence++; settings = null; verifiedAt = 0; statusError = ''; busy = false;
          clearInterval(timer); timer = null;
          panel?.remove(); section?.classList.remove('mapa-dispatch-open');
          item?.removeAttribute('aria-current'); panel = section = identity = null;
        };
        const notice = message => {
          const box = panel?.querySelector('[role="alert"]');
          if (box) box.textContent = message;
        };
        async function request(enabled) {
          if (!identity || identity !== scope()) throw Error('Sign in again.');
          const startedGeneration = generation, startedIdentity = identity, sequence = ++requestSequence;
          const current = () => panel && generation === startedGeneration && identity === startedIdentity && scope() === startedIdentity && sequence === requestSequence;
          const headers = native.getHeaders();
          const safe = { Authorization: headers.Authorization, Accept: 'application/json' };
          for (const key of ['Access-Console-Sandbox', 'Access-Console-Sandbox-Key'])
            if (headers[key]) safe[key] = headers[key];
          const write = typeof enabled === 'boolean';
          if (write && !fresh()) throw Error('Refresh server status before changing automatic dispatch.');
          if (write) safe['Content-Type'] = 'application/json';
          const controller = new AbortController();
          const timeout = setTimeout(() => controller.abort(), 15000);
          try {
            const response = await fetch('/mapa/voice/v1/automatic/settings', {
              method: write ? 'POST' : 'GET', headers: safe,
              body: write ? JSON.stringify({ enabled, expected_revision: settings.revision, confirmed: true }) : undefined,
              credentials: 'omit', cache: 'no-store', redirect: 'error', signal: controller.signal,
            });
            if (!current()) return null;
            if (!response.ok) {
              let message = 'Automatic dispatch settings are unavailable.';
              try { const error = await response.json(); message = error.error || error.message || message; } catch (_) {}
              throw Error(message);
            }
            const result = await response.json();
            if (!current()) return null;
            if (result.contract !== 'mapa-dispatch-settings-v1') throw Error('Unexpected server response.');
            verifiedAt = Date.now(); statusError = '';
            return result;
          } catch (error) {
            if (!current()) return null;
            settings = null; verifiedAt = 0; statusError = error.message || 'Server status unavailable.';
            render();
            throw error;
          } finally { clearTimeout(timeout); }
        }
        const button = (label, handler, disabled = false) => {
          const node = element('button', label, { type: 'button' });
          node.disabled = disabled;
          node.onclick = async () => {
            if (busy) return;
            const startedGeneration = generation;
            busy = true; node.disabled = true;
            try { await handler(); if (panel && generation === startedGeneration) render(); }
            catch (error) { if (generation === startedGeneration) notice(error.message); }
            finally { if (generation === startedGeneration) { busy = false; node.disabled = false; } }
          };
          return node;
        };
        const fact = (label, value) => {
          const row = element('div', null, { class: 'mapa-dispatch-fact' });
          row.append(element('span', label), element('strong', value));
          return row;
        };
        function render() {
          if (!panel) return;
          panel.replaceChildren();
          const header = element('header');
          header.append(element('small', 'MAPA SETTINGS'), element('h2', 'Automatic Dispatch'));
          panel.append(header, element('p', 'Runs on the MAPA server 24/7. After an operator confirms a MAPA-owned live call, the server checks on-duty crews and sends the first eligible offer. A decline or timeout moves to the next crew, up to five offers.'));
          const alert = element('p', '', { role: 'alert', 'aria-live': 'polite', class: 'mapa-dispatch-alert' });
          panel.append(alert);
          if (!fresh()) {
            panel.append(element('p', statusError || 'Loading server status…'));
            panel.append(button('Refresh status', refresh));
            return;
          }
          const facts = element('div', null, { class: 'mapa-dispatch-facts' });
          const lastTickAge = settings.last_tick_at && settings.server_time
            ? Math.max(0, Math.floor((Date.parse(settings.server_time) - Date.parse(settings.last_tick_at)) / 1000))
            : null;
          facts.append(
            fact('Automatic offers', settings.enabled ? 'ON' : 'OFF'),
            fact('Server configuration', settings.server_ready ? 'Ready' : 'Needs setup'),
            fact('Background worker', !settings.enabled ? 'Paused' : settings.worker_fresh ? 'Live' : 'Not recently verified'),
            fact('Enrolled crew accounts', settings.enrolled_crews ?? 0),
            fact('Last worker check', Number.isFinite(lastTickAge) ? `${lastTickAge} seconds ago` : 'None yet')
          );
          panel.append(facts);
          const actions = element('div', null, { class: 'mapa-dispatch-actions' });
          if (settings.enabled) actions.append(button('Turn off automatic dispatch', async () => {
            if (!window.confirm('Stop automatic dispatch now? Outstanding offers will be cancelled and affected calls will need operator attention.')) return;
            const result = await request(false); if (result) settings = result;
          }));
          else actions.append(button('Turn on automatic dispatch', async () => {
            if (!window.confirm('Enable automatic crew offers for every newly reviewed MAPA-owned live call? Eligible on-duty crews may receive real dispatch offers.')) return;
            const result = await request(true); if (result) settings = result;
          }, !settings.server_ready || settings.enrolled_crews < 1));
          actions.append(button('Refresh status', refresh));
          panel.append(actions,
            element('p', 'ASR Health-owned calls and no-patient drills are excluded. The next reviewed MAPA live call starts automatically; an already reviewed call needs an explicit operator start. If no eligible crew or route is available, the call returns to the operator.'));
        }
        async function refresh() { const result = await request(); if (result) { settings = result; render(); } }
        async function open() {
          if (panel) return;
          const target = document.querySelector('section.next-view-section');
          if (!target || !scope()) return;
          identity = scope(); section = target;
          const startedGeneration = generation;
          section.classList.add('mapa-dispatch-open');
          panel = element('div', null, { class: 'mapa-dispatch-panel', 'aria-label': 'Automatic Dispatch settings' });
          section.append(panel); item?.setAttribute('aria-current', 'page');
          render();
          try { await refresh(); } catch (error) { if (generation === startedGeneration) notice(error.message); }
          if (!panel || generation !== startedGeneration) return;
          timer = setInterval(() => { if (panel && !document.hidden && !busy) refresh().catch(() => {}); }, 15000);
        }
        function mount() {
          if (!scope() || !isSettingsRoute(location.pathname)) { close(); item?.remove(); item = null; return; }
          if (panel && identity !== scope()) close();
          if (panel && settings && !fresh()) { settings = null; statusError = 'Server status is no longer current. Refresh to verify.'; render(); }
          const navigation = document.querySelector('#sidebar-menu-items .next-sidebar-navigator-viewport.is-nested .next-sidebar-navigator-view-in');
          if (!navigation || item?.isConnected) return;
          item = element('button', 'Automatic Dispatch', { type: 'button', class: 'next-sidebar-navigator-item mapa-dispatch-settings-item' });
          item.onclick = open;
          navigation.append(item);
        }
        const navigate = event => { if (panel && event.target.closest('#sidebar-menu-items') && !item?.contains(event.target)) close(); };
        document.addEventListener('click', navigate, true);
        const routeChange = () => close();
        router?.on?.('routeWillChange', routeChange);
        const mountTimer = setInterval(mount, 1500);
        mount();
        destroyable.registerDestructor(owner, () => {
          clearInterval(mountTimer); document.removeEventListener('click', navigate, true);
          router?.off?.('routeWillChange', routeChange); close(); item?.remove();
        });
      } };
    });
})();
