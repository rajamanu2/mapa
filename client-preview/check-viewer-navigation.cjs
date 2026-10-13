// Regression-check the real patched bundle methods without loading Ember/browser globals.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const source = fs.readFileSync(path.resolve(__dirname, '../../private/client-preview/console-pages/assets/vendor.js'), 'utf8');

function moduleText(name) {
  const marker = `;define("${name}",`;
  const start = source.indexOf(marker);
  assert(start >= 0, `Missing module ${name}`);
  const end = source.indexOf(';define(', start + marker.length);
  return source.slice(start, end < 0 ? undefined : end);
}
function methodText(text, signature) {
  const start = text.indexOf(signature);
  assert(start >= 0, `Missing method ${signature}`);
  let depth = 0;
  for (let i = text.indexOf('{', start); i < text.length; i++) {
    if (text[i] === '{') depth++;
    if (text[i] === '}' && --depth === 0) return text.slice(start, i + 1);
  }
  throw new Error('Unclosed method');
}
const abilityMethod = methodText(moduleText('@fleetbase/ember-core/abilities/dynamic'), 'get can() {');
const Ability = new Function(`return class { ${abilityMethod} };`)();
const ability = new Ability();
ability.currentUser = {isAdmin: false, permissions: []};
ability.permissions = new Set(); // Simulate the old empty constructor snapshot.
ability.service = 'fleet-ops'; ability.ability = 'see'; ability.resource = 'extension';
assert.equal(ability.can, false);
ability.currentUser.permissions = [{name: 'fleet-ops see extension'}];
assert.equal(ability.can, true, 'An ability created before login must see new grants.');
ability.ability = 'create'; ability.resource = 'order';
assert.equal(ability.can, false, 'Navigation grants must not grant writes.');
ability.ability = 'see'; ability.resource = 'extension';
ability.permissions.add('fleet-ops see extension'); // Old cached grant must not survive revocation.
ability.currentUser.permissions = [];
assert.equal(ability.can, false, 'Revoked grants must stop working immediately.');
ability.currentUser.permissions = [{name: 'fleet-ops * vehicle'}];
ability.ability = 'view'; ability.resource = 'vehicle';
assert.equal(ability.can, true, 'Existing resource wildcard behavior is preserved.');
ability.currentUser.permissions = [{name: 'fleet-ops *'}];
assert.equal(ability.can, true, 'Existing service wildcard behavior is preserved.');
ability.currentUser = {isAdmin: true, permissions: []};
assert.equal(ability.can, true, 'Existing admin behavior is preserved.');

const setUserMethod = methodText(moduleText('@fleetbase/ember-core/services/current-user'), 'async setUser(user) {');
const CurrentUser = new Function(`return class { ${setUserMethod} };`)();
const current = new CurrentUser();
const grants = [{name: 'fleet-ops see extension'}];
const seen = [];
function observe(name) {
  assert.deepEqual(current.permissions, grants, `${name} must see permissions during login.`);
  seen.push(name);
}
Object.assign(current, {
  permissions: [],
  getUserSnapshot: async () => ({id: 'demo'}),
  getUserPermissions: () => grants,
  set(key, value) { this[key] = value; },
  theme: {syncThemeFromCurrentUser() {observe('theme');}, setEnvironment() {}},
  store: {peekRecord() {return {id: 'demo-company'};}},
  trigger() {observe('current-user event');},
  events: {trackUserLoaded() {observe('events bus');}},
  universe: {trigger() {observe('universe bus');}},
  setLocale() {},
});
current.setUser({locale: 'en-us', get() {return 'demo-company';}}).then(() => {
  assert.deepEqual(seen, ['theme', 'current-user event', 'events bus', 'universe bus']);
  console.log('PASS: login grant refresh, write denial, revocation, wildcard/admin behavior, and all lifecycle listeners.');
}).catch(error => { console.error(error.message); process.exitCode = 1; });
