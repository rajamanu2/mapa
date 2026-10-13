import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { applyMapaProductLabels, renameProductTranslations, renameProductTemplate, renameProductJavaScript } from './apply-mapa-product-labels.mjs';

let checks = 0;
function check(condition, message) { assert.ok(condition, message); checks++; }
const yaml = 'fleet-ops:\n  extension-name: Fleet-Ops\n  help: Connected to Fleetbase.\n  namespace: Fleetbase\\FleetOps\\Models\\Order\n  link: https://Fleetbase.io/Fleet-Ops\n# Fleet-Ops upstream comment\nlegal:\n  notice: Fleetbase copyright\n';
const translated = renameProductTranslations(yaml);
check(translated.includes('extension-name: MAPA Operations'), 'display label is branded');
check(translated.includes('help: Connected to MAPA.'), 'sentence-ending brand is replaced');
check(translated.includes('fleet-ops:'), 'translation root is unchanged');
check(translated.includes('namespace: Fleetbase\\FleetOps\\Models\\Order'), 'PHP namespace is unchanged');
check(translated.includes('https://Fleetbase.io/Fleet-Ops'), 'external URL is unchanged');
check(translated.includes('# Fleet-Ops upstream comment'), 'translation comment is unchanged');
check(translated.includes('notice: Fleetbase copyright'), 'legal group is unchanged');
check(renameProductTranslations(translated) === translated, 'translation patch is idempotent');

const template = '<h1>Fleet-Ops Settings</h1><Image alt="Fleetbase" /><X @subjectType="Fleetbase\\FleetOps\\Models\\Issue" /><p>Fleetbase Merchant Backend</p>{{!-- Fleet-Ops license note --}}';
const brandedTemplate = renameProductTemplate(template);
check(brandedTemplate.includes('<h1>MAPA Operations Settings</h1>'), 'template heading is branded');
check(brandedTemplate.includes('alt="MAPA"'), 'standalone accessible logo label is branded');
check(brandedTemplate.includes('@subjectType="Fleetbase\\FleetOps\\Models\\Issue"'), 'template model contract is unchanged');
check(brandedTemplate.includes('Fleetbase Merchant Backend'), 'third-party hosted service identity is unchanged');
check(brandedTemplate.includes('{{!-- Fleet-Ops license note --}}'), 'template notice comment is unchanged');
check(renameProductTemplate(template, 'components/modals/fleetbase-legal-notice.hbs') === template, 'legal template is unchanged');
check(renameProductTemplate('<p>Copyright Fleetbase Pte Ltd.</p>') === '<p>Copyright Fleetbase Pte Ltd.</p>', 'notice outside a legal filename is unchanged');

const js = "import X from '@fleetbase/fleetops-engine';\nconst model = 'Fleetbase\\\\FleetOps\\\\Models\\\\Order';\nconst option = { loadingMessage: `Loading Fleet-Ops...`, title: 'Fleet-Ops Settings', description: 'Rules in Fleetbase.', route: 'console.fleet-ops', permission: 'fleet-ops list order' };";
const brandedJs = renameProductJavaScript(js);
check(brandedJs.includes('`Loading MAPA Operations...`'), 'loading message is branded');
check(brandedJs.includes("title: 'MAPA Operations Settings'"), 'JavaScript title is branded');
check(brandedJs.includes("description: 'Rules in MAPA.'"), 'JavaScript description is branded');
check(brandedJs.includes("from '@fleetbase/fleetops-engine'"), 'npm namespace is unchanged');
check(brandedJs.includes("const model = 'Fleetbase\\\\FleetOps\\\\Models\\\\Order'"), 'JavaScript model contract is unchanged');
check(brandedJs.includes("route: 'console.fleet-ops', permission: 'fleet-ops list order'"), 'route and permission identifiers are unchanged');
check(renameProductJavaScript(brandedJs) === brandedJs, 'JavaScript patch is idempotent');
check(renameProductJavaScript("{ title: 'Fleetbase Legal Notices' }") === "{ title: 'Fleetbase Legal Notices' }", 'upstream legal heading remains accurate');

// Exercise the production npm directory layout using exact representative source
// anchors. Keep fixtures here so a clean CI checkout needs no Git submodules or
// network dependency installation to exercise the entire filesystem traversal.
const temporary = fs.mkdtempSync(path.join(os.tmpdir(), 'mapa-branding-'));
try {
    const packages = ['iam-engine', 'ledger-engine', 'fleetops-engine', 'dev-engine', 'storefront-engine', 'registry-bridge-engine'];
    const packageRoot = installed => installed === 'fleetops-engine' ? path.join(temporary, 'package-store', installed) : path.join(temporary, 'node_modules/@fleetbase', installed);
    const write = (root, relative, content) => {
        const target = path.join(root, relative);
        fs.mkdirSync(path.dirname(target), { recursive: true });
        fs.writeFileSync(target, content);
    };
    for (const installed of packages) {
        write(packageRoot(installed), 'translations/en-us.yaml', 'fleet-ops:\n  extension-name: Fleet-Ops\n  description: Fleetbase operations.\n');
    }
    const fixtures = {
        'iam-engine/addon/components/widget/policy-surface.hbs': '<small>Fleetbase</small>',
        'ledger-engine/addon/components/customer-invoice.hbs': '<span class="font-medium">Fleetbase Ledger</span>',
        'ledger-engine/addon/components/modals/refund-result.hbs': '<InputGroup @helpText="Send this Fleetbase link to the customer." />',
        'fleetops-engine/addon/controllers/connectivity/telematics/details/attachments.js': "const alert = { message: 'Fleetbase could not load the full provider device list.' };",
        'fleetops-engine/addon/utils/fleet-ops-options.js': "const option = { label: 'Integrated Vendor', value: 'integrated_vendor', description: 'Vendor with native API integration into Fleetbase' };",
        'fleetops-engine/addon/templates/settings/index.hbs': '<h1 class="fleet-ops-control-hub-title">Fleet-Ops Settings</h1>',
        'fleetops-engine/addon/controllers/settings/index.js': "const option = { label: 'Custom Fields', route: 'settings.custom-fields', description: 'Operational metadata fields for Fleet-Ops records.' };",
        'fleetops-engine/addon/routes/home.js': 'const loader = this.loader.show({ loadingMessage: `Loading Fleet-Ops...` });',
        'fleetops-engine/addon/components/issue/details/correspondence.hbs': '<X @subjectType="Fleetbase\\FleetOps\\Models\\Issue" />',
        'fleetops-engine/addon/templates/operations/scheduler/index.hbs': "{{!-- Plugins are incompatible with Fleetbase's dual AGPL v3 / commercial license. --}}",
    };
    for (const [relative, content] of Object.entries(fixtures)) {
        const slash = relative.indexOf('/');
        write(packageRoot(relative.slice(0, slash)), relative.slice(slash + 1), content);
    }
    write(temporary, 'translations/en-us.yaml', 'app:\n  name: Fleetbase\n');
    write(temporary, 'app/utils/get-service-name.js', "export default function getServiceName() { return 'Fleet Ops'; }");
    const fleetLink = path.join(temporary, 'node_modules/@fleetbase/fleetops-engine');
    const fleetStore = path.join(temporary, 'package-store/fleetops-engine');
    fs.symlinkSync(fleetStore, fleetLink, process.platform === 'win32' ? 'junction' : 'dir');
    check(fs.lstatSync(fleetLink).isSymbolicLink(), 'fixture models the pnpm installed-package link');
    applyMapaProductLabels(temporary);
    const fleetRoot = path.join(temporary, 'node_modules/@fleetbase/fleetops-engine');
    const read = relative => fs.readFileSync(path.join(fleetRoot, relative), 'utf8');
    check(read('addon/templates/settings/index.hbs').includes('MAPA Operations Settings'), 'installed npm settings heading is branded');
    check(!read('addon/controllers/settings/index.js').includes('Fleet-Ops'), 'installed npm settings descriptions are branded');
    check(read('addon/routes/home.js').includes('Loading MAPA Operations...'), 'installed npm loading screen is branded');
    check(read('addon/components/issue/details/correspondence.hbs').includes('Fleetbase\\FleetOps\\Models\\Issue'), 'installed npm subject type is preserved');
    check(read('addon/templates/operations/scheduler/index.hbs').includes("Fleetbase's dual AGPL"), 'installed npm license comment is preserved');
    check(!read('translations/en-us.yaml').includes('Fleet-Ops'), 'installed npm English catalog is branded');
    const before = read('addon/templates/settings/index.hbs');
    applyMapaProductLabels(temporary);
    check(read('addon/templates/settings/index.hbs') === before, 'repeated installed-package pass is stable');
} finally { fs.rmSync(temporary, { recursive: true, force: true }); }
console.log(`${checks} MAPA product-label checks passed.`);

