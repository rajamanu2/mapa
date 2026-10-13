// Install the native call contact field without replacing other product customizations.
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
const scripts = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(process.argv[2] ?? 'node_modules/@fleetbase/fleetops-engine');
const template = path.join(root, 'addon/components/order/form/details.hbs');
const component = path.join(root, 'addon/components/order/form/details.js');
const field = fs.readFileSync(path.join(scripts, 'mapa-caller-phone-field.hbs'), 'utf8');
const methods = fs.readFileSync(path.join(scripts, 'mapa-caller-phone-methods.js.txt'), 'utf8');
let hbs = fs.readFileSync(template, 'utf8');
let js = fs.readFileSync(component, 'utf8');
if (!hbs.includes('this.mapaCallerPhone')) {
    const anchor = '        <InputGroup @name={{t "order.fields.internal-id"}}';
    if (hbs.split(anchor).length !== 2) throw new Error('Expected one native internal-id field.');
    hbs = hbs.replace(anchor, field + '\n' + anchor);
}
const callerStart = hbs.indexOf('        <InputGroup @name="Caller phone number"');
const callerEnd = hbs.indexOf('        </InputGroup>', callerStart);
if (callerStart >= 0 && callerEnd >= 0) hbs = hbs.slice(0, callerStart) + field.trimEnd() + hbs.slice(callerEnd + '        </InputGroup>'.length);
const anchor = '    get integratedVendorServiceType() {';
if (js.split(anchor).length !== 2) throw new Error('Expected one native order details component.');
const marker = '    // MAPA caller contact is operator-supplied coordination information.';
if (js.includes(marker)) {
    const start = js.indexOf(marker);
    const end = js.indexOf(anchor, start);
    if (end < 0) throw new Error('Unexpected caller phone method boundary.');
    js = js.slice(0, start) + methods + js.slice(end);
} else {
    js = js.replace(anchor, methods + anchor);
}
fs.writeFileSync(template, hbs);
fs.writeFileSync(component, js);
// Saved-call edit uses a separate modal; share the same input and normalization.
const phoneComponent = path.join(root, 'addon/components/order/caller-phone');
fs.writeFileSync(phoneComponent + '.hbs', field.replace('lg:col-span-2', 'col-span-2'));
fs.writeFileSync(phoneComponent + '.js', "import Component from '@glimmer/component';\nimport { action } from '@ember/object';\n\nexport default class OrderCallerPhoneComponent extends Component {\n" + methods + '}\n');
const appPhoneComponent = path.join(root, 'app/components/order/caller-phone.js');
fs.mkdirSync(path.dirname(appPhoneComponent), { recursive: true });
fs.writeFileSync(appPhoneComponent, "export { default } from '@fleetbase/fleetops-engine/components/order/caller-phone';\n");
const modalPath = path.join(root, 'addon/components/modals/order-form.hbs');
let modal = fs.readFileSync(modalPath, 'utf8');
if (!modal.includes('<Order::CallerPhone ')) {
    const anchor = '<InputGroup @name={{t "common.internal-id"}}';
    if (modal.split(anchor).length !== 2) throw new Error('Expected one saved-call edit anchor.');
    modal = modal.replace(anchor, '<Order::CallerPhone @resource={{@options.order}} />\n            ' + anchor);
    fs.writeFileSync(modalPath, modal);
}
const displayPath = path.join(root, 'addon/components/order/details/detail.hbs');
let display = fs.readFileSync(displayPath, 'utf8');
if (!display.includes('mapa-caller-phone-display')) {
    const anchor = '            <div class="field-info-container field-vertical-container dashed-bottom">';
    if (!display.includes(anchor)) throw new Error('Expected native read-only details fields.');
    display = display.replace(anchor, fs.readFileSync(path.join(scripts, 'mapa-caller-phone-display.hbs'), 'utf8') + anchor);
    fs.writeFileSync(displayPath, display);
}
display = display.replace('{{if this.mapaCallerPhone this.mapaCallerPhone "Not provided"}}', '{{if @resource.meta._index_resource "Loading caller details..." (if this.mapaCallerPhone this.mapaCallerPhone "Not provided")}}');
fs.writeFileSync(displayPath, display);
const displayJsPath = path.join(root, 'addon/components/order/details/detail.js');
let displayJs = fs.readFileSync(displayJsPath, 'utf8');
const getter = methods.slice(0, methods.indexOf('    mapaNormalizeCallerPhone('));
if (!displayJs.includes('get mapaCallerPhone()')) {
    const anchor = '    get actionButtons() {';
    if (displayJs.split(anchor).length !== 2) throw new Error('Expected native details action getter.');
    displayJs = displayJs.replace(anchor, getter + anchor);
    fs.writeFileSync(displayJsPath, displayJs);
}
const displayGetterStart = displayJs.indexOf('    // MAPA caller contact is operator-supplied coordination information.');
const displayGetterEnd = displayJs.indexOf('    get actionButtons() {', displayGetterStart);
if (displayGetterStart >= 0 && displayGetterEnd > displayGetterStart) {
    displayJs = displayJs.slice(0, displayGetterStart) + getter + displayJs.slice(displayGetterEnd);
    fs.writeFileSync(displayJsPath, displayJs);
}
// Cancel restores only this field, leaving every other metadata entry intact.
const actionsPath = path.join(root, 'addon/services/order-actions.js');
let actions = fs.readFileSync(actionsPath, 'utf8');
const editMarker = actions.includes('    @action async editOrderDetails(order, options = {}) {') ? '    @action async editOrderDetails(order, options = {}) {' : '    @action editOrderDetails(order, options = {}) {';
const editStart = actions.indexOf(editMarker);
const editEnd = actions.indexOf('    @action async assignDriver(order)', editStart);
if (editStart < 0 || editEnd < 0) throw new Error('Expected native order edit action.');
let edit = actions.slice(editStart, editEnd);
if (!edit.includes('Call details are still loading.')) {
    edit = edit.replace('    @action editOrderDetails(', '    @action async editOrderDetails(');
    edit = edit.replace('        options = options === null ? {} : options;', `        options = options === null ? {} : options;
        if (order.meta?._index_resource) {
            try { await order.reload(); }
            catch (error) { this.notifications.serverError(error); return; }
            if (order.meta?._index_resource) {
                this.notifications.warning('Call details are still loading. Please reopen Edit after loading finishes.');
                return;
            }
        }`);
}
if (!edit.includes('const contactMeta')) {
    edit = edit.replace('                order.payload.rollbackAttributes();', `                const contactMeta = { ...(order.meta ?? {}) };
                if (mapaCallerContactBeforeEdit.present) contactMeta.mapa_caller_phone = mapaCallerContactBeforeEdit.value;
                else delete contactMeta.mapa_caller_phone;
                order.set('meta', contactMeta);
                order.payload.rollbackAttributes();`);
}
// Always place the snapshot after full hydration and immediately before opening.
edit = edit.replace(/        const mapaCallerContactBeforeEdit = \{[\s\S]*?        \};\r?\n/, '');
edit = edit.replace("        this.modalsManager.show('modals/order-form', {", `        const mapaCallerContactBeforeEdit = {
            present: Object.prototype.hasOwnProperty.call(order.meta ?? {}, 'mapa_caller_phone'),
            value: order.meta?.mapa_caller_phone,
        };
        this.modalsManager.show('modals/order-form', {`);
actions = actions.slice(0, editStart) + edit + actions.slice(editEnd);
fs.writeFileSync(actionsPath, actions);
console.log('Native caller phone field installed; existing metadata and save behavior preserved.');
