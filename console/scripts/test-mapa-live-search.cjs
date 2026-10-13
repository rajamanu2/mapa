/* Copyright (c) 2026 MAPA.DEV. SPDX-License-Identifier: AGPL-3.0-or-later */
'use strict';
// Exercise the shipped pickup-map search with a small DOM/timer harness. No network.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../public/mapa-voice.js'), 'utf8');
const core = require('../public/mapa-voice.js');
const start = source.indexOf('    function pickupMap('), end = source.indexOf('    function openCrewAdmin(', start);
assert.ok(start >= 0 && end > start, 'The shipped pickup map function is available.');
let checks = 0, nextTimer = 0, changed = 0;
const timers = new Map(), requests = [], nodes = [];
class Node {
    constructor(tag, className = '', text = '') { this.tag = tag; this.className = className; this.text = text; this.children = []; this.events = {}; this.isConnected = true; this.value = ''; this.disabled = false; this.classList = { add() {} }; nodes.push(this); }
    append(...children) { this.children.push(...children); }
    replaceChildren(...children) { this.children = children; }
    addEventListener(name, fn) { this.events[name] = fn; }
    setAttribute() {}
    removeAttribute() {}
}
const el = (tag, className, text) => new Node(tag, className, text);
const field = (parent, label, name, value) => { const node = el('input'); node.name = name; node.value = value; parent.append(node); return node; };
const button = (text, action) => { const node = el('button', '', text); node.addEventListener('click', action); return node; };
const context = vm.createContext({
    el, field, button, busy: false, pending: null, removePickupMap: null, window: {}, ASR_BOUNDS: core.ASR_BOUNDS,
    pickupMapConfig: {}, googleFailureListeners: new Set(), locationQuery: core.locationQuery, googleSearchUrl: core.googleSearchUrl, pastedPickupPoint: core.pastedPickupPoint,
    requestAnimationFrame() {}, string: value => typeof value === 'string' ? value : '',
    pickupCandidate: core.pickupCandidate,
    setTimeout(fn, delay) { const id = ++nextTimer; timers.set(id, { fn, delay }); return id; },
    clearTimeout(id) { timers.delete(id); },
    request(url) { return new Promise((resolve, reject) => requests.push({ url, resolve, reject })); }
});
const pickupMap = vm.runInContext('(' + source.slice(start, end).trim() + ')', context);
const inputs = { latitude: el('input'), longitude: el('input'), pickup_address: el('input'), village: el('input'), mandal: el('input') };
pickupMap(el('section'), inputs, () => changed++);
const query = nodes.find(node => node.name === 'location_search');
const results = nodes.find(node => node.className === 'mapa-voice-search-results');
const searchButton = nodes.find(node => node.tag === 'button' && node.text === 'Search map');
const check = (ok, message) => { checks++; assert.ok(ok, message); };
for (const url of ['/locations?q=Paderu','/locations?q=Paderu&online=1','/locations?q=Hyderabad&scope=india','/locations?q=Hyderabad&online=1&scope=india']) check(core.validRequestPath(url), 'The real transport allows the supported search URL: ' + url);
for (const url of ['/locations?q=Paderu&scope=world','/locations?q=Paderu&url=https://example.com','https://example.com/locations?q=Paderu','/locations?q=Paderu#private']) check(!core.validRequestPath(url), 'The real transport rejects an unsupported or external path.');
const type = text => { query.value = text; query.events.input({ isComposing: false }); };
const fireTimers = () => { const saved = [...timers.values()]; timers.clear(); saved.forEach(timer => timer.fn()); };
const settle = () => new Promise(resolve => setImmediate(resolve));
const match = label => ({ results: [{ label, address: label + ', Andhra Pradesh', locality: label, latitude: 18.08, longitude: 82.66 }] });

(async () => {
    type('Pa'); check(timers.size === 0 && requests.length === 0, 'Fewer than three characters never schedules search.');
    type('Pad'); check(timers.size === 1 && [...timers.values()][0].delay === 450, 'Typing uses a 450ms debounce.');
    type('Pade'); check(timers.size === 1 && requests.length === 0, 'Further typing replaces the pending timer.');
    fireTimers(); check(requests[0].url.endsWith('q=Pade'), 'The debounced request uses the latest text.');
    type('Paderu'); check(results.children.length === 0 && !searchButton.disabled, 'Query edits immediately invalidate old results and unlock manual search.');
    requests[0].resolve(match('Old result')); await settle();
    check(results.children.length === 0, 'An old response cannot reappear before the new debounce runs.');
    fireTimers(); requests[1].resolve(match('Paderu')); await settle();
    check(results.children.some(node => node.text === 'Paderu'), 'Current results appear.');
    check(changed === 0 && inputs.latitude.value === '', 'Suggestions do not select or confirm coordinates.');
    inputs.pickup_address.value = 'Previous caller address'; inputs.village.value = 'Previous village'; inputs.mandal.value = 'Previous mandal';
    results.children.find(node => node.tag === 'button').events.click();
    check(inputs.pickup_address.value === 'Paderu, Andhra Pradesh' && inputs.latitude.value === '18.080000' && inputs.longitude.value === '82.660000', 'Explicit place selection replaces an old address and both coordinates together.');
    check(inputs.village.value === 'Paderu' && inputs.mandal.value === '', 'Provider locality fills the form; an unrelated old mandal is not retained or guessed.');
    type('Ar'); check(results.children.length === 0 && timers.size === 0, 'Shortening below the minimum clears suggestions immediately.');
    type('Araku'); let prevented = false;
    query.events.keydown({ key: 'Enter', preventDefault() { prevented = true; } });
    check(prevented && timers.size === 0 && requests[2].url.endsWith('q=Araku&online=1'), 'Enter explicitly requests online search and cancels the pending debounce.');
    requests[2].resolve(match('Araku')); await settle();
    type('itda'); fireTimers();
    requests[3].resolve({results:[], directory_results:[{name:'ITDA Office, Paderu',address:'ITDA Office, Paderu'}]}); await settle();
    const directory = results.children.find(node=>node.tag==='button');
    inputs.latitude.value='18.123'; inputs.longitude.value='82.345';
    directory.events.click();
    check(inputs.pickup_address.value==='ITDA Office, Paderu' && inputs.latitude.value==='' && inputs.longitude.value==='', 'Address-only selection clears an old pin and never invents coordinates.');
    check(changed===2, 'Directory selection marks the draft dirty and requires a new review.');
    check(inputs.village.value === '' && inputs.mandal.value === '', 'Address-only selection cannot retain a previous locality.');
    check(nodes.some(node=>node.tag==='a' && node.href===core.googleSearchUrl('itda') && node.target==='_blank'), 'The Google Maps browser link follows only the location search query.');
    type('+91 80088 16919'); fireTimers(); await settle();
    check(requests.length===4, 'Phone-like searches do not go to the server.');
    type('Huku'); fireTimers(); type('Hukumpeta');
    requests[4].reject(new Error('Old request failed')); await settle();
    check(results.children.length === 0, 'An error from an old query cannot replace current suggestions.');
    const area = nodes.find(node => node.name === 'location_scope');
    query.value = 'Hyderabad'; area.value = 'india'; area.events.change();
    check(requests[5].url.endsWith('q=Hyderabad&online=1&scope=india') && timers.size === 0, 'Widening the area cancels pending typeahead and explicitly searches India.');
    area.value = 'asr'; area.events.change();
    requests[5].resolve(match('Stale India result')); await settle();
    check(!results.children.some(node => node.text === 'Stale India result'), 'An old scope response cannot repopulate the current results.');
    requests[6].resolve({results:Array.from({length:35},(_,i)=>match('Place '+i).results[0]),local_match_count:70,local_shown_count:32,local_map_attribution:'© OpenStreetMap contributors · ODbL · regional map directory'}); await settle();
    check(results.children.filter(node=>node.tag==='button').length===35,'Landmark results beyond the old twelve-result cutoff remain selectable.');
    check(results.children.some(node=>node.text.includes('32 of 70')),'Truncated local matches are disclosed so operators can narrow the query.');
    check(results.children.some(node=>node.tag==='a'&&node.href==='https://www.openstreetmap.org/copyright'),'Local OpenStreetMap data keeps source attribution without an online request.');
    check(changed===2, 'Changing the search area never changes the selected pickup.');
    context.removePickupMap(); check(timers.size === 0, 'Unmount clears the pending search timer.');
    fireTimers(); check(requests.length === 7, 'Unmount prevents a delayed request.');
    searchButton.events.click(); check(requests.length === 7, 'Detached search cannot issue another request.');
    console.log(`MAPA live search: ${checks} checks passed (DOM/timer harness; no API calls).`);
})().catch(error => { console.error(error); process.exitCode = 1; });
