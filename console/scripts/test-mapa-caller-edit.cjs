const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.resolve(__dirname, '../../../fleetops-source/addon/services/order-actions.js'), 'utf8');
const start = source.indexOf('    @action async editOrderDetails(');
const end = source.indexOf('    @action async assignDriver(', start);
assert(start >= 0 && end > start);
const Actions = vm.runInNewContext('(class Actions {' + source.slice(start, end).replace('@action ', '') + '})');
(async () => {
    let shown, errors = 0, warnings = 0, checks = 0;
    const actions = new Actions();
    actions.intl = { t: key => key };
    actions.notifications = { serverError() { errors++; }, warning() { warnings++; } };
    actions.modalsManager = { show(name, options) { shown = options; }, done() {} };
    const order = {
        meta: { _index_resource: true }, payload: { rollbackAttributes() {} },
        async reload() { this.meta = { mapa_caller_phone: '+442079460000', retained: 'original' }; },
        set(key, value) { this[key] = value; },
    };
    await actions.editOrderDetails(order);
    assert(shown); checks++;
    order.meta = { ...order.meta, mapa_caller_phone: '', retained: 'updated independently' };
    shown.decline();
    assert.equal(order.meta.mapa_caller_phone, '+442079460000'); checks++;
    assert.equal(order.meta.retained, 'updated independently'); checks++;
    order.meta = { mapa_dispatch_review: { callback_number: '+442079460000' }, retained: true };
    await actions.editOrderDetails(order);
    order.meta = { ...order.meta, mapa_caller_phone: '' };
    shown.decline();
    assert.equal(Object.hasOwn(order.meta, 'mapa_caller_phone'), false); checks++;
    assert.equal(order.meta.mapa_dispatch_review.callback_number, '+442079460000'); checks++;
    shown = null;
    order.meta = { _index_resource: true };
    order.reload = async () => { throw Error('simulated reload failure'); };
    await actions.editOrderDetails(order);
    assert.equal(shown, null); checks++;
    assert.equal(errors, 1); checks++;
    order.reload = async () => {};
    await actions.editOrderDetails(order);
    assert.equal(shown, null); checks++;
    assert.equal(warnings, 1); checks++;
    console.log(`MAPA caller edit: ${checks} hydration/cancel checks passed; no records saved.`);
})().catch(error => { console.error(error); process.exitCode = 1; });
