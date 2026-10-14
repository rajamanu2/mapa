/* Copyright (c) 2026 MAPA.DEV. SPDX-License-Identifier: AGPL-3.0-or-later */
'use strict';
// Exercise the shipped initializer with an isolated DOM and native service doubles.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../public/mapa-feature-workspace.js'), 'utf8');
let checks = 0;
const check = (condition, message) => { assert.ok(condition, message); checks++; };

function fixture(initialPermissions = ['fleet-ops list order'], authenticated = true) {
    const origin = 'http://localhost:4200';
    let root, initializer, destroy, tick, listener, cleared = false, rejected = false, permissionError = false;
    const transitions = [], fullNavigations = [];
    class Node {
        constructor(tag) {
            this.tagName = tag; this.children = []; this.attributes = {}; this.listeners = {}; this.className = ''; this.parentNode = null;
            this.classList = {
                contains: name => this.className.split(/\s+/).includes(name),
                add: (...names) => { this.className = [...new Set([...this.className.split(/\s+/).filter(Boolean), ...names])].join(' '); },
                remove: (...names) => { this.className = this.className.split(/\s+/).filter(name => !names.includes(name)).join(' '); },
                toggle: name => { const next = !this.classList.contains(name); this.classList[next ? 'add' : 'remove'](name); return next; },
            };
        }
        set href(value) { this._href = new URL(value, origin).href; }
        get href() { return this._href; }
        get isConnected() { return this === root || !!this.parentNode?.isConnected; }
        append(...nodes) { for (const node of nodes) { node.remove(); this.children.push(node); node.parentNode = this; } }
        remove() { if (this.parentNode) this.parentNode.children = this.parentNode.children.filter(node => node !== this); this.parentNode = null; }
        insertBefore(node, before) { if (!before) return this.append(node); assert.equal(before.parentNode, this); node.remove(); this.children.splice(this.children.indexOf(before), 0, node); node.parentNode = this; }
        setAttribute(name, value) { this.attributes[name] = String(value); }
        getAttribute(name) { return this.attributes[name] ?? null; }
        removeAttribute(name) { delete this.attributes[name]; }
        addEventListener(name, fn) { this.listeners[name] = fn; }
        querySelectorAll(selector) {
            const matches = node => selector.startsWith('.') ? node.classList.contains(selector.slice(1)) : node.tagName === selector;
            const walk = node => node.children.flatMap(child => [child, ...walk(child)]);
            return walk(this).filter(matches);
        }
        querySelector(selector) { return this.querySelectorAll(selector)[0] ?? null; }
        click(extras = {}) { const event = { button: 0, prevented: false, preventDefault() { this.prevented = true; }, ...extras }; this.listeners.click?.(event); return event; }
    }
    root = new Node('body');
    const sidebar = new Node('aside'); sidebar.className = 'fleet-ops-sidebar-navigator';
    const viewport = new Node('div'); viewport.className = 'next-sidebar-navigator-viewport';
    const nativeTool = new Node('a'); nativeTool.textContent = 'Native integration'; viewport.append(nativeTool); sidebar.append(viewport); root.append(sidebar);
    const permissions = new Set(initialPermissions);
    const session = { isAuthenticated: authenticated };
    const location = { pathname: '/fleet-ops' };
    const router = {
        transitionTo(url) { transitions.push(url); return rejected ? Promise.reject(new Error('TransitionAborted')) : Promise.resolve(); },
        on(name, fn) { assert.equal(name, 'routeDidChange'); listener = fn; },
        off(name, fn) { assert.equal(name, 'routeDidChange'); assert.equal(listener, fn); listener = null; },
    };
    const window = {
        location: { assign(url) { fullNavigations.push(url); } },
        define(name, dependencies, callback) {
            assert.equal(name, '@fleetbase/console/instance-initializers/mapa-feature-workspace');
            const exports = {}; callback(exports, { registerDestructor: (_owner, fn) => { destroy = fn; } }); initializer = exports.default;
        },
    };
    const services = { session, router, abilities: { can(permission) { if (permissionError) throw new Error('Abilities unavailable'); return permissions.has(permission); } } };
    vm.runInNewContext(source, {
        window, location, URL,
        document: { createElement: tag => new Node(tag), querySelector: selector => root.querySelector(selector), querySelectorAll: selector => root.querySelectorAll(selector) },
        setInterval(fn) { tick = fn; return 1; }, clearInterval(id) { assert.equal(id, 1); cleared = true; },
    });
    initializer.initialize({ lookup: key => services[key.replace('service:', '')] });
    const links = () => root.querySelector('.mapa-feature-menu')?.querySelectorAll('a') ?? [];
    return {
        sidebar, viewport, nativeTool, session, permissions, location, window, transitions, fullNavigations,
        tick: () => tick(), route: () => listener?.(), destroy: () => destroy(), links,
        menu: () => root.querySelector('.mapa-feature-menu'), link: label => links().find(node => node.textContent === label),
        more: () => root.querySelector('.mapa-feature-more'), cleared: () => cleared,
        rejectTransitions: () => { rejected = true; }, failAbilities: () => { permissionError = true; },
    };
}

(async () => {
    const denied = fixture([], false);
    check(!denied.menu(), 'Signed-out console gets no feature navigation.');
    denied.session.isAuthenticated = true; denied.tick();
    check(denied.links().length === 0, 'Missing grants do not expose resource links.');
    const app = fixture();
    check(app.links().map(node => node.textContent).join('|') === 'Dashboard|Map & orders|Scheduler', 'Only permitted destinations are shown.');
    check(!app.link('Create transport order'), 'Read access does not expose order creation.');
    check(app.link('Dashboard').href === 'http://localhost:4200/fleet-ops/analytics', 'Dashboard points to native fleet analytics.');
    check(!app.link('Dashboard').classList.contains('mapa-feature-secondary'), 'Daily navigation stays in the primary sidebar.');
    check(app.link('Scheduler').classList.contains('mapa-feature-secondary'), 'Less frequent destinations belong under More tools.');
    check(app.link('Map & orders').getAttribute('aria-current') === 'page', 'Current map route is identified.');
    check(app.viewport.children[0] === app.nativeTool && app.viewport.isConnected, 'Existing native tools remain attached.');
    app.more().click();
    check(app.sidebar.classList.contains('mapa-feature-more-open') && app.more().getAttribute('aria-expanded') === 'true', 'More tools exposes the original native viewport.');
    app.more().click();
    check(!app.sidebar.classList.contains('mapa-feature-more-open'), 'More tools can collapse again.');
    const event = app.link('Scheduler').click();
    check(event.prevented && app.transitions.join() === '/fleet-ops/scheduler', 'Plain navigation uses the native router.');
    for (const modifier of [{ ctrlKey: true }, { metaKey: true }, { shiftKey: true }, { altKey: true }, { button: 1 }]) {
        check(!app.link('Scheduler').click(modifier).prevented, 'Modified link interactions retain browser behavior.');
    }
    check(app.transitions.length === 1, 'Modified clicks do not trigger duplicate transitions.');
    app.window.mapaCloseIntake = () => false;
    check(app.link('Scheduler').click().prevented && app.transitions.length === 1, 'Pending or unsaved intake can block navigation.');
    app.window.mapaCloseIntake = () => true;
    app.link('Dashboard').click();
    check(app.transitions[1] === '/fleet-ops/analytics', 'Confirmed intake closure permits navigation.');
    app.rejectTransitions(); app.link('Scheduler').click(); await Promise.resolve();
    check(app.fullNavigations.length === 0, 'Rejected native navigation cannot force a page reload.');
    app.more().click();
    app.permissions.add('fleet-ops create order'); app.tick();
    check(!!app.link('Create transport order') && app.link('Create transport order').href.endsWith('/fleet-ops/new'), 'Newly granted creation exposes the native transport form.');
    check(app.sidebar.classList.contains('mapa-feature-more-open') && app.more().getAttribute('aria-expanded') === 'true', 'Permission changes retain expanded native tools and their accessible state.');
    app.permissions.delete('fleet-ops create order'); app.tick();
    check(!app.link('Create transport order'), 'Revoked grants remove their links.');
    app.location.pathname = '/fleet-ops/analytics'; app.route();
    check(app.link('Dashboard').getAttribute('aria-current') === 'page' && !app.link('Map & orders').getAttribute('aria-current'), 'Route events update the active destination.');
    app.failAbilities(); app.tick();
    check(app.links().length === 0, 'Unavailable grants fail closed.');
    app.session.isAuthenticated = false; app.tick();
    check(!app.menu(), 'Logout removes every feature link.');
    check(!app.sidebar.classList.contains('mapa-feature-ready') && !app.sidebar.classList.contains('mapa-feature-more-open'), 'Logout restores native navigation styling before a new session.');
    app.session.isAuthenticated = true; app.tick(); app.more().click();
    app.destroy();
    check(app.cleared() && !app.sidebar.classList.contains('mapa-feature-ready'), 'Initializer teardown clears polling and navigation styling.');
    check(!app.sidebar.classList.contains('mapa-feature-more-open'), 'Initializer teardown removes expanded-navigation state.');
    check(app.nativeTool.isConnected, 'Teardown preserves original native integration controls.');
    console.log(`MAPA feature workspace: ${checks} checks passed; isolated DOM and services, no network or operational writes.`);
})().catch(error => { console.error(error); process.exitCode = 1; });
