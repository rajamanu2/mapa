// New calls can use the native document/scheduling tools without making them prerequisites.
import fs from 'node:fs';
import path from 'node:path';
const root = path.resolve(process.argv[2] ?? 'node_modules/@fleetbase/fleetops-engine');
for (const [name, title, note] of [
    ['documents', '{{if @resource.isNew (concat (t "order.fields.documents-files") " (optional)") (t "order.fields.documents-files")}}', 'Documents are optional. You can create this call and assign crew without uploading files.'],
    ['orchestrator-constraints', '{{if @resource.isNew "Orchestrator Constraints (optional)" "Orchestrator Constraints"}}', 'These scheduling settings are optional. You can create this call and assign crew without adding constraints.'],
]) {
    const file = path.join(root, 'addon/components/order/form', name + '.hbs');
    let text = fs.readFileSync(file, 'utf8');
    if (!text.startsWith('<ContentPanel ')) throw new Error('Missing native advanced section header: ' + name);
    text = text.replace(/^<ContentPanel[^\r\n]*\r?\n/, '<ContentPanel @title=' + title + ' @open={{if @resource.isNew false true}} @wrapperClass="bordered-top">\n');
    if (!text.includes(note)) {
        const end = text.indexOf('\n') + 1;
        text = text.slice(0, end) + '    {{#if @resource.isNew}}\n        <p class="text-sm mb-3 opacity-80">' + note + '</p>\n    {{/if}}\n' + text.slice(end);
    }
    fs.writeFileSync(file, text);
}
console.log('New-call documents and orchestration sections are optional and initially collapsed.');
