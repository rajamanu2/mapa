#!/usr/bin/env node
/* Copyright (c) 2026 MAPA.DEV. SPDX-License-Identifier: AGPL-3.0-or-later */
// Adapt a COPY of the already built native console. This script never runs bundle code.
import { cp, lstat, mkdir, readFile, readdir, realpath, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { createHash } from 'node:crypto';
import { fileURLToPath } from 'node:url';

const moduleName = '@fleetbase/console/config/environment';
const hash = value => createHash('sha256').update(value).digest('hex');
const inside = (parent, child) => {
    const relative = path.relative(parent, child);
    return relative === '' || (!relative.startsWith(`..${path.sep}`) && relative !== '..' && !path.isAbsolute(relative));
};

export function makeRuntimeConfig(origin, apiPrefix = '/api') {
    let url;
    try { url = new URL(origin); } catch { throw new Error('The public origin must be a valid HTTPS URL.'); }
    if (url.protocol !== 'https:' || url.username || url.password || url.search || url.hash || url.pathname !== '/' || url.port) {
        throw new Error('Use one HTTPS origin on port 443, without credentials, a path, query or fragment.');
    }
    if (!/^\/[a-z][a-z0-9-]*$/.test(apiPrefix)) throw new Error('The API prefix must be one lowercase path segment, such as /api.');
    return {
        API_HOST: url.origin + apiPrefix,
        API_NAMESPACE: 'int/v1',
        SOCKETCLUSTER_PATH: '/socketcluster/',
        SOCKETCLUSTER_HOST: url.hostname,
        SOCKETCLUSTER_SECURE: true,
        SOCKETCLUSTER_PORT: 443,
    };
}

// Locate only the known Ember environment export, then parse its balanced JSON object.
// Deliberately refuse unfamiliar bundle formats instead of replacing arbitrary strings.
export function adaptBundle(bundle, runtime) {
    const definition = /define\(\s*(['"])@fleetbase\/console\/config\/environment\1\s*,\s*\[\s*\]\s*,\s*function\s*\(\s*\)\s*\{/g;
    const matches = [...bundle.matchAll(definition)];
    if (matches.length !== 1) throw new Error('Expected exactly one native console environment module. Rebuild/review this console version.');
    const position = matches[0].index + matches[0][0].length;
    const start = /^\s*var exports\s*=\s*\{\s*(['"])default\1\s*:\s*/.exec(bundle.slice(position));
    if (!start) throw new Error('Unsupported environment export format; no output was changed.');
    const begin = position + start[0].length;
    if (bundle[begin] !== '{') throw new Error('The environment export is not a JSON object.');
    let depth = 0, quoted = false, escaped = false, end = -1;
    for (let index = begin; index < bundle.length; index++) {
        const character = bundle[index];
        if (quoted) {
            if (escaped) escaped = false;
            else if (character === '\\') escaped = true;
            else if (character === '"') quoted = false;
        } else if (character === '"') quoted = true;
        else if (character === '{') depth++;
        else if (character === '}' && --depth === 0) { end = index + 1; break; }
    }
    if (end < 0) throw new Error('Incomplete environment JSON.');
    let environment;
    try { environment = JSON.parse(bundle.slice(begin, end)); } catch { throw new Error('The compiled environment is not plain JSON.'); }
    if (environment.modulePrefix !== '@fleetbase/console' || !environment.APP || !environment.API || !environment.socket) {
        throw new Error('Unexpected native console environment structure.');
    }
    environment.API.host = runtime.API_HOST;
    environment.API.namespace = runtime.API_NAMESPACE;
    environment.socket = {
        ...environment.socket,
        path: runtime.SOCKETCLUSTER_PATH,
        hostname: runtime.SOCKETCLUSTER_HOST,
        secure: runtime.SOCKETCLUSTER_SECURE,
        port: runtime.SOCKETCLUSTER_PORT,
    };
    environment.APP.disableRuntimeConfig = false;
    if (Object.hasOwn(environment, 'API_HOST')) environment.API_HOST = runtime.API_HOST;
    // Production runtime caching keys include APP.version. Separate config revisions.
    const release = String(environment.APP.version || environment.version || 'unknown').split('+mapa-cloud.')[0];
    environment.APP.version = `${release}+mapa-cloud.${hash(JSON.stringify(runtime)).slice(0, 12)}`;
    const updated = bundle.slice(0, begin) + JSON.stringify(environment) + bundle.slice(end);
    return { updated, environment, beforeSha256: hash(bundle), afterSha256: hash(updated) };
}

async function checkTree(directory) {
    for (const entry of await readdir(directory, { withFileTypes: true })) {
        const location = path.join(directory, entry.name);
        if (entry.isSymbolicLink()) throw new Error('Static source contains a symbolic link; copy a plain exported document root first.');
        if (entry.isDirectory()) await checkTree(location);
        else if (!entry.isFile()) throw new Error('Static source contains a non-regular file.');
    }
}

function attribute(tag, name) {
    return new RegExp(`\\b${name}\\s*=\\s*(["'])(.*?)\\1`, 'i').exec(tag)?.[2];
}

export async function prepareConsole({ source, output, origin, apiPrefix = '/api' }) {
    const runtime = makeRuntimeConfig(origin, apiPrefix);
    const sourceRoot = await realpath(path.resolve(source));
    const outputRoot = path.resolve(output);
    // Resolve the existing parent as well, so a symlink cannot target the source tree.
    const actualOutput = path.join(await realpath(path.dirname(outputRoot)), path.basename(outputRoot));
    if (inside(sourceRoot, actualOutput) || inside(actualOutput, sourceRoot)) throw new Error('Source and output must be separate directories.');
    try { await lstat(outputRoot); throw new Error('Output already exists. Use a new release directory.'); }
    catch (error) { if (error.code !== 'ENOENT') throw error; }
    await checkTree(sourceRoot);
    const index = await readFile(path.join(sourceRoot, 'index.html'), 'utf8');
    const candidates = [];
    for (const match of index.matchAll(/<script\b[^>]*>/gi)) {
        const src = attribute(match[0], 'src');
        if (!src || /^(?:[a-z][a-z0-9+.-]*:|\/\/)/i.test(src)) continue;
        const name = decodeURIComponent(src.split(/[?#]/)[0]).replace(/^\//, '');
        const filename = path.resolve(sourceRoot, name);
        if (!inside(sourceRoot, filename)) throw new Error('An index script path escapes the static source.');
        const bundle = await readFile(filename, 'utf8');
        // Most scripts import the module; only its actual definition is a candidate.
        if (!/define\(\s*(['"])@fleetbase\/console\/config\/environment\1\s*,\s*\[\s*\]/.test(bundle)) continue;
        if (attribute(match[0], 'integrity')) throw new Error('This bundle uses SRI. Rebuild or update integrity as part of a reviewed release.');
        candidates.push({ name, tag: match[0], src, ...adaptBundle(bundle, runtime) });
    }
    if (candidates.length !== 1) throw new Error(`Expected one referenced ${moduleName} bundle; found ${candidates.length}.`);
    const candidate = candidates[0];
    const [pathname, originalQuery = ''] = candidate.src.split('?');
    const query = new URLSearchParams(originalQuery);
    query.set('mapa-cloud', candidate.afterSha256.slice(0, 12));
    const newTag = candidate.tag.replace(candidate.src, `${pathname}?${query.toString()}`);
    await mkdir(outputRoot); // Exclusive: do not overwrite any deployment or local console.
    for (const name of await readdir(sourceRoot)) {
        await cp(path.join(sourceRoot, name), path.join(outputRoot, name), { recursive: true, force: false, errorOnExist: true });
    }
    await writeFile(path.join(outputRoot, candidate.name), candidate.updated);
    await writeFile(path.join(outputRoot, 'index.html'), index.replace(candidate.tag, newTag));
    await writeFile(path.join(outputRoot, 'fleetbase.config.json'), JSON.stringify(runtime, null, 2) + '\n');
    return {
        status: 'prepared', origin: new URL(origin).origin, output: outputRoot,
        bundle: candidate.name, beforeSha256: candidate.beforeSha256, afterSha256: candidate.afterSha256,
        environment: candidate.environment.environment,
        note: 'Copied assets only; no service was restarted or deployed.',
    };
}

async function main(args) {
    if (args.length === 1 && args[0] === '--help') {
        console.log('node runtime-config.mjs --source EXPORTED_STATIC_ROOT --output NEW_STATIC_ROOT --origin https://your-host.example [--api-prefix /api]');
        return;
    }
    const options = {};
    const allowed = new Map([['--source', 'source'], ['--output', 'output'], ['--origin', 'origin'], ['--api-prefix', 'apiPrefix']]);
    for (let index = 0; index < args.length; index += 2) {
        const key = allowed.get(args[index]);
        if (!key || options[key] !== undefined || !args[index + 1]) throw new Error('Invalid options. Run with --help.');
        options[key] = args[index + 1];
    }
    if (!options.source || !options.output || !options.origin) throw new Error('Supply --source, --output and --origin.');
    console.log(JSON.stringify(await prepareConsole(options), null, 2));
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
    main(process.argv.slice(2)).catch(error => { console.error(`MAPA static configuration failed: ${error.message}`); process.exitCode = 1; });
}
