// MAPA UI labels for the installed AI dependency. Provider/config keys and licences stay intact.
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

export function applyMapaAiBranding(addonRoot = path.resolve('node_modules/@fleetbase/ai-engine')) {
    const relatives = [
        'addon/components/admin/ai-settings.hbs',
        'addon/components/ai-header-tray-button.hbs',
        'addon/components/ai-prompt.hbs',
        'addon/components/ai-action-preview-renderer.js',
        'addon/templates/home.hbs',
    ];
    // Validate all expected upstream source anchors before writing any file.
    const updates = relatives.map((relative) => {
        const file = path.join(addonRoot, relative);
        const original = fs.readFileSync(file, 'utf8');
        if (!original.includes('Fleetbase AI') && !original.includes('MAPA AI')) {
            throw new Error(`Expected AI product label missing from ${relative}`);
        }
        return [file, original.replaceAll('Fleetbase AI', 'MAPA AI')];
    });
    for (const [file, content] of updates) fs.writeFileSync(file, content);
    console.log('MAPA AI labels applied to five dependency source files; provider settings unchanged.');
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
    applyMapaAiBranding(process.argv[2]);
}
