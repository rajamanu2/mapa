/* MAPA native workspace navigation. Uses existing routes and grants. */
(() => {
    'use strict';
    const groups = [
        ['Operations', [
            ['Dashboard', '/fleet-ops/analytics', 'fleet-ops list order'],
            ['Map & orders', '/fleet-ops', 'fleet-ops list order'],
            ['Create transport order', '/fleet-ops/new', 'fleet-ops create order'],
            ['Service rates', '/fleet-ops/service-rates', 'fleet-ops list service-rate'],
            ['Scheduler', '/fleet-ops/scheduler', 'fleet-ops list order'],
            ['Order configuration', '/fleet-ops/order-config', 'fleet-ops list order-config'],
        ]],
        ['Resources', [
            ['Drivers', '/fleet-ops/manage/drivers', 'fleet-ops list driver'],
            ['Ambulances', '/fleet-ops/manage/vehicles', 'fleet-ops list vehicle'],
            ['Fleets', '/fleet-ops/manage/fleets', 'fleet-ops list fleet'],
            ['Vendors', '/fleet-ops/manage/vendors', 'fleet-ops list vendor'],
            ['Contacts', '/fleet-ops/manage/contacts', 'fleet-ops list contact'],
            ['Hospitals & places', '/fleet-ops/manage/places', 'fleet-ops list place'],
            ['Fuel reports', '/fleet-ops/manage/fuel-reports', 'fleet-ops list fuel-report'],
            ['Issues', '/fleet-ops/manage/issues', 'fleet-ops list issue'],
        ]],
        ['Settings', [
            ['Navigator app', '/fleet-ops/settings/navigator-app', 'fleet-ops view navigator-settings'],
            ['Map & routing', '/fleet-ops/settings/map', 'fleet-ops view map-settings'],
        ]],
    ];
    const primaryRoutes = new Set(['/fleet-ops/analytics', '/fleet-ops', '/fleet-ops/manage/drivers', '/fleet-ops/manage/vehicles', '/fleet-ops/manage/places']);
    if (typeof module !== 'undefined') module.exports = { groups };
    if (typeof window === 'undefined' || typeof window.define !== 'function') return;
    window.define('@fleetbase/console/instance-initializers/mapa-feature-workspace', ['exports', '@ember/destroyable/index'], (exports, destroyable) => {
        exports.default = { name: 'mapa-feature-workspace', initialize(owner) {
            const session = owner.lookup('service:session');
            const abilities = owner.lookup('service:abilities');
            const router = owner.lookup('service:router');
            const intl = owner.lookup('service:intl');
            if (typeof intl?.addTranslations === 'function') intl.addTranslations('en-us', { common: { 'search-input': 'Search…' } });
            let menu, signature = '', disposed = false;
            const allowed = permission => { try { return abilities?.can(permission) === true; } catch { return false; } };
            const make = (tag, label, cls) => { const node = document.createElement(tag); node.textContent = label; if (cls) node.className = cls; return node; };
            function mount() {
                if (disposed) return;
                for (const map of document.querySelectorAll('.leaflet-container')) {
                    if (!map.querySelector('img[src*="tile.openstreetmap.org/"]') || map.querySelector('.mapa-map-credit')) continue;
                    const credit = make('a', '© OpenStreetMap contributors', 'mapa-map-credit');
                    credit.href = 'https://www.openstreetmap.org/copyright'; credit.target = '_blank'; credit.rel = 'noopener noreferrer';
                    map.append(credit);
                }
                const sidebar = document.querySelector('.fleet-ops-sidebar-navigator');
                if (!session?.isAuthenticated || !sidebar) {
                    menu?.parentNode?.classList.remove('mapa-feature-ready', 'mapa-feature-more-open');
                    menu?.remove(); menu = null; signature = ''; return;
                }
                const visible = groups.map(([name, entries]) => [name, entries.filter(entry => allowed(entry[2]))]);
                const next = JSON.stringify(visible);
                if (!menu?.isConnected || signature !== next) {
                    menu?.remove(); signature = next;
                    menu = make('nav', '', 'mapa-feature-menu');
                    menu.setAttribute('aria-label', 'MAPA workspace features');
                    for (const [name, entries] of visible) {
                        if (!entries.length) continue;
                        const section = make('section', '', 'mapa-feature-group');
                        if (entries.every(entry => !primaryRoutes.has(entry[1]))) section.classList.add('mapa-feature-secondary-group');
                        section.append(make('h2', name));
                        for (const [label, url] of entries) {
                            const link = make('a', label, 'mapa-feature-link'); link.href = url;
                            if (!primaryRoutes.has(url)) link.classList.add('mapa-feature-secondary');
                            link.addEventListener('click', event => {
                                if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button) return;
                                event.preventDefault();
                                if (typeof window.mapaCloseIntake === 'function' && window.mapaCloseIntake() === false) return;
                                router.transitionTo(url).catch(() => {});
                            });
                            section.append(link);
                        }
                        menu.append(section);
                    }
                    const more = make('button', 'More tools & integrations', 'mapa-feature-more'); more.type = 'button';
                    more.setAttribute('aria-expanded', String(sidebar.classList.contains('mapa-feature-more-open')));
                    more.addEventListener('click', () => {
                        const expanded = sidebar.classList.toggle('mapa-feature-more-open');
                        more.setAttribute('aria-expanded', String(expanded));
                    });
                    menu.append(more);
                    const viewport = sidebar.querySelector('.next-sidebar-navigator-viewport');
                    sidebar.insertBefore(menu, viewport);
                    sidebar.classList.add('mapa-feature-ready');
                }
                for (const link of menu.querySelectorAll('a')) {
                    const target = new URL(link.href).pathname;
                    const active = target === '/' ? location.pathname === '/' : target === '/fleet-ops' ? location.pathname === target : location.pathname.startsWith(target);
                    if (active) link.setAttribute('aria-current', 'page'); else link.removeAttribute('aria-current');
                }
            }
            const timer = setInterval(mount, 750);
            router?.on?.('routeDidChange', mount); mount();
            destroyable.registerDestructor(owner, () => { disposed = true; clearInterval(timer); router?.off?.('routeDidChange', mount); menu?.remove(); document.querySelectorAll('.mapa-feature-ready').forEach(n => n.classList.remove('mapa-feature-ready', 'mapa-feature-more-open')); });
        } };
    });
})();
