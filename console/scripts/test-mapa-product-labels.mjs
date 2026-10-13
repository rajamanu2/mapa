import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { applyMapaProductLabels, renameProductTranslations, renameProductTemplate, renameProductJavaScript } from './apply-mapa-product-labels.mjs';

let checks = 0;
function check(condition, message) { assert.ok(condition, message); checks++; }
const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
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

// Exercise the actual production npm directory layout without requiring a network
// install or modifying checked-in upstream engine sources.
const temporary = fs.mkdtempSync(path.join(os.tmpdir(), 'mapa-branding-'));
try {
    const packages = { 'iam-engine': 'iam-engine', 'ledger-engine': 'ledger', 'fleetops-engine': 'fleetops', 'dev-engine': 'dev-engine', 'storefront-engine': 'storefront', 'registry-bridge-engine': 'registry-bridge' };
    for (const [installed, source] of Object.entries(packages)) {
        const sourceRoot = path.join(repoRoot, 'packages', source);
        const target = installed === 'fleetops-engine' ? path.join(temporary, 'package-store', installed) : path.join(temporary, 'node_modules/@fleetbase', installed);
        fs.mkdirSync(target, { recursive: true });
        for (const directory of ['addon', 'translations']) {
            const from = path.join(sourceRoot, directory);
            if (fs.existsSync(from)) fs.cpSync(from, path.join(target, directory), { recursive: true });
            else fs.mkdirSync(path.join(target, directory));
        }
    }
    fs.cpSync(path.join(repoRoot, 'console/translations'), path.join(temporary, 'translations'), { recursive: true });
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
