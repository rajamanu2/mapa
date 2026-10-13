const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

// Execute the actual browser launch/mount code with a synthetic transport. No
// production credentials, browser storage, external requests or backend writes.
const source = fs.readFileSync(path.join(__dirname, '../public/mapa-voice.js'), 'utf8');
const start = source.indexOf('    let launchInFlight = false;');
const end = source.indexOf('    let scheduled = false;', start);
const registration = source.split('\n').find(line => line.includes("window.addEventListener('hashchange'"));
assert.ok(start >= 0 && end > start && registration, 'Production launch handler is present');
const production = source.slice(start, end) + '\n' + registration;
const ticket = 'a'.repeat(64), secondTicket = 'b'.repeat(64);
const validSession = () => ({
    contract: 'mapa-native-mobile-v1', token: 'c'.repeat(64), expires_at: Date.now() + 60000,
    user: { id: 'DEMO_operator', company_id: 'DEMO_company', role: 'admin', name: 'DEMO operator' }
});
const deferred = () => { let resolve, reject; const promise = new Promise((yes, no) => { resolve = yes; reject = no; }); return { promise, resolve, reject }; };
const settle = () => new Promise(resolve => setImmediate(resolve));
let checks = 0;
function check(assertion, message) { assert.ok(assertion, message); checks++; }

function harness({ hash = '', state = {}, standalone = true, load = async () => {} } = {}) {
    const listeners = new Map(), requests = [], messages = [], replacements = [];
    let loads = 0, renders = 0, opens = 0;
    const location = { pathname: '/call-intake/', search: '?transfer=referral', hash };
    const context = {
        standalone, dialog: null, session: null, busy: false, pending: null, dirty: false,
        resourceEditor: null, manualEdit: null, editDraft: null, epoch: 0, lastError: false, lastMessage: '', ...state,
        location, Date,
        safeId: value => typeof value === 'string' && /^[A-Za-z0-9_-]{1,120}$/.test(value),
        string: value => typeof value === 'string' ? value : '',
        window: { addEventListener: (name, callback) => listeners.set(name, callback) },
        history: { replaceState: (_data, _unused, url) => { replacements.push(url); location.hash = ''; } },
        announce: (message, error = false) => { context.lastMessage = message; context.lastError = error; messages.push({ message, error }); },
        lock: value => { context.busy = value; },
        open: () => { opens++; context.dialog = {}; },
        render: () => { renders++; },
        loadCalls: async () => { loads++; await load(); },
        request: (requestPath, options) => {
            const response = deferred();
            requests.push({ path: requestPath, options, hashAtRequest: location.hash, response });
            return response.promise;
        }
    };
    vm.createContext(context);
    vm.runInContext(production, context, { filename: 'mapa-voice.launch.js' });
    return {
        context, requests, messages, replacements, listeners,
        mount: () => context.mount(),
        hash: value => { location.hash = value; listeners.get('hashchange')?.(); },
        stats: () => ({ loads, renders, opens })
    };
}

(async () => {
    const delayed = harness({ hash: '#launching' });
    delayed.mount();
    check(delayed.requests.length === 0 && delayed.stats().opens === 1, 'Initial waiting fragment mounts without exchanging');
    check(delayed.messages.at(-1).message.includes('Opening with your MAPA'), 'Waiting launch shows its current status');
    delayed.hash('#launch=' + ticket);
    check(delayed.requests.length === 1, 'A later fragment-only iframe navigation starts the exchange');
    const exchange = delayed.requests[0];
    check(exchange.path === '/intake-launch/exchange' && exchange.options.native === true && exchange.options.login === true,
        'Delayed launch uses only the existing native authentication exchange');
    assert.deepEqual(JSON.parse(exchange.options.body), { ticket }); checks++;
    check(exchange.hashAtRequest === '' && delayed.replacements[0] === '/call-intake/?transfer=referral', 'Ticket is removed before transport and existing query is retained');
    check(delayed.context.busy === true, 'Exchange locks manual sign-in');
    delayed.hash('#launch=' + ticket);
    delayed.hash('#launch=' + secondTicket);
    check(delayed.requests.length === 1 && delayed.context.location.hash === '', 'Duplicate and competing fragments are stripped during one in-flight exchange');
    exchange.response.resolve(validSession());
    await settle();
    check(delayed.context.session.user === 'DEMO_operator' && delayed.context.session.company === 'DEMO_company', 'Exchange establishes the verified response identity');
    check(delayed.stats().loads === 1 && !delayed.context.busy, 'One completed launch loads calls and releases the lock');

    const direct = harness({ hash: '#launch=' + ticket });
    direct.mount(); direct.mount();
    check(direct.requests.length === 1, 'Direct launch is consumed exactly once despite repeated mounting');
    direct.requests[0].response.resolve(validSession()); await settle();
    const originalSession = direct.context.session;
    direct.hash('#launch=' + secondTicket);
    check(direct.requests.length === 1 && direct.context.session === originalSession && direct.context.location.hash === '', 'A later ticket cannot replace an authenticated session');
    const priorMessage = direct.messages.at(-1);
    direct.hash('#launching');
    check(direct.messages.at(-1) === priorMessage, 'Waiting fragment cannot overwrite authenticated status');

    const beforeMount = harness();
    beforeMount.hash('#launch=' + ticket);
    check(beforeMount.stats().opens === 1 && beforeMount.requests.length === 1, 'Hash change before initial mounting opens the desk and consumes once');
    beforeMount.requests[0].response.resolve(validSession()); await settle();

    for (const hash of ['#launch=invalid', '#launch=' + ticket.toUpperCase(), '#launch=' + ticket + '&role=superadmin']) {
        const invalid = harness({ hash }); invalid.mount();
        check(invalid.requests.length === 0 && invalid.context.location.hash === '' && invalid.messages.at(-1).error, 'Malformed ticket is removed and rejected without transport');
    }
    for (const protectedState of [
        { busy: true }, { pending: { owner: 'DEMO_operator' } }, { dirty: true },
        { resourceEditor: { type: 'ambulance' } }, { manualEdit: { caller_name: 'DEMO draft' } },
        { editDraft: { id: 'DEMO_call' } }, { session: { user: 'DEMO_existing' } }
    ]) {
        const protectedDesk = harness({ hash: '#launch=' + ticket, state: protectedState });
        protectedDesk.mount();
        check(protectedDesk.requests.length === 0 && protectedDesk.context.location.hash === '', 'Existing work or sign-in blocks handoff and removes ticket');
        for (const [key, value] of Object.entries(protectedState)) assert.equal(protectedDesk.context[key], value);
        checks++;
    }

    const failure = harness({ hash: '#launch=' + ticket }); failure.mount();
    failure.requests[0].response.reject(new Error('DEMO expired ticket')); await settle();
    check(failure.context.session === null && !failure.context.busy && failure.messages.at(-1).error, 'Failed exchange leaves manual sign-in available');
    failure.hash('#launch=' + secondTicket);
    check(failure.requests.length === 2, 'A fresh launch can retry after a failed exchange');
    failure.requests[1].response.resolve(validSession()); await settle();

    for (const mutate of [desk => { desk.context.epoch++; }, desk => { desk.context.dirty = true; }, desk => { desk.context.session = { user: 'DEMO_new_session' }; }]) {
        const changed = harness({ hash: '#launch=' + ticket }); changed.mount(); mutate(changed);
        const prior = changed.context.session;
        changed.requests[0].response.resolve(validSession()); await settle();
        check(changed.context.session === prior && changed.stats().loads === 0, 'A late exchange cannot replace changed identity or work');
    }
    for (const response of [{ ...validSession(), user: { ...validSession().user, role: 'crew' } }, { ...validSession(), expires_at: Date.now() - 1 }, { ...validSession(), token: 'invalid' }]) {
        const invalidResponse = harness({ hash: '#launch=' + ticket }); invalidResponse.mount();
        invalidResponse.requests[0].response.resolve(response); await settle();
        check(invalidResponse.context.session === null && invalidResponse.stats().loads === 0 && invalidResponse.messages.at(-1).error,
            'Unverified response cannot establish an operator session');
    }

    const loading = deferred(), loadingDesk = harness({ hash: '#launch=' + ticket, load: () => loading.promise });
    loadingDesk.mount(); loadingDesk.requests[0].response.resolve(validSession()); await settle();
    loadingDesk.hash('#launch=' + secondTicket);
    check(loadingDesk.requests.length === 1, 'Handoff remains protected while initial calls load');
    loading.resolve(); await settle();

    const expiredDuringLoad = harness({ hash: '#launch=' + ticket, load: async () => {
        // Match request's 401 -> expire() transition and loadCalls' caught error.
        expiredDuringLoad.context.session = null;
        expiredDuringLoad.context.epoch++;
        expiredDuringLoad.context.busy = false;
        expiredDuringLoad.context.announce('DEMO session expired while loading', true);
    } });
    expiredDuringLoad.mount(); expiredDuringLoad.requests[0].response.resolve(validSession()); await settle();
    check(expiredDuringLoad.context.session === null && expiredDuringLoad.messages.at(-1).error
        && expiredDuringLoad.messages.at(-1).message === 'DEMO session expired while loading',
        'Expiry during call loading retains the sign-in error instead of announcing launch success');
    check(expiredDuringLoad.stats().renders === 0, 'Stale launch does not render after loadCalls has expired its session');

    const failedLoad = harness({ hash: '#launch=' + ticket, load: async () => {
        failedLoad.context.announce('DEMO calls temporarily unavailable', true);
    } });
    failedLoad.mount(); failedLoad.requests[0].response.resolve(validSession()); await settle();
    check(failedLoad.context.session !== null && failedLoad.messages.at(-1).message === 'DEMO calls temporarily unavailable'
        && failedLoad.messages.at(-1).error, 'A caught call-loading error is not overwritten by a green launch message');

    for (const newerWork of [
        desk => { desk.context.epoch++; },
        desk => { desk.context.session = { user: 'DEMO_new_operator' }; },
        desk => { desk.context.dirty = true; }
    ]) {
        const stale = harness({ hash: '#launch=' + ticket }); stale.mount(); newerWork(stale);
        stale.context.busy = true;
        stale.context.announce('DEMO newer work in progress');
        const renders = stale.stats().renders, messages = stale.messages.length;
        stale.requests[0].response.reject(new Error('DEMO stale exchange rejection')); await settle();
        check(stale.context.busy === true && stale.stats().renders === renders && stale.messages.length === messages,
            'Stale exchange rejection does not unlock, render or overwrite newer work');
    }
    for (const outcome of ['resolve', 'reject']) {
        const laterLoad = deferred(), newerDuringLoad = harness({ hash: '#launch=' + ticket, load: () => laterLoad.promise });
        newerDuringLoad.mount(); newerDuringLoad.requests[0].response.resolve(validSession()); await settle();
        // loadCalls has already taken ownership of its own lock; a subsequent
        // operation under the same identity must not be unlocked by launch.
        newerDuringLoad.context.busy = true;
        newerDuringLoad.context.announce('DEMO newer request running');
        const renders = newerDuringLoad.stats().renders, messages = newerDuringLoad.messages.length;
        if (outcome === 'reject') laterLoad.reject(new Error('DEMO stale load rejection'));
        else laterLoad.resolve();
        await settle();
        check(newerDuringLoad.context.busy === true && newerDuringLoad.stats().renders === renders && newerDuringLoad.messages.length === messages,
            'Launch completion cannot release another operation lock or status after handing off call loading');
    }

    const embedded = harness({ hash: '#launch=' + ticket, standalone: false });
    await embedded.context.consumeLaunch();
    check(!embedded.listeners.has('hashchange') && embedded.requests.length === 0, 'Native console does not consume operator desk fragments');

    console.log(`PASS ${checks} intake launch checks; synthetic transport only, no credentials or network.`);
})().catch(error => { console.error(error); process.exitCode = 1; });
