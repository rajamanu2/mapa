#!/bin/sh
# Copyright (c) 2026 MAPA.DEV. SPDX-License-Identifier: AGPL-3.0-or-later
# Install into either a new public directory or an already-built console image.
# API proxy configuration remains owned by the host build.
set -eu
script_dir=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
target=${1:?Usage: install-mapa-voice.sh TARGET_PUBLIC_DIRECTORY [SOURCE_PUBLIC_DIRECTORY]}
source_dir=${2:-"$script_dir/../public"}
test -r "$source_dir/mapa-voice.js"
test -r "$source_dir/mapa-voice.css"
test -r "$source_dir/mapa-crew-native.js"
test -r "$source_dir/mapa-crew-native.css"
test -r "$source_dir/mapa-intake-native.js"
test -r "$source_dir/mapa-intake-native.css"
mkdir -p "$target"
for asset in mapa-voice.js mapa-voice.css mapa-layout-fixes.css mapa-crew-native.js mapa-crew-native.css mapa-intake-native.js mapa-intake-native.css; do
    if ! cmp -s "$source_dir/$asset" "$target/$asset"; then cp "$source_dir/$asset" "$target/$asset"; fi
    chmod 644 "$target/$asset"
done
mkdir -p "$target/call-intake"
if ! cmp -s "$source_dir/call-intake/index.html" "$target/call-intake/index.html"; then cp "$source_dir/call-intake/index.html" "$target/call-intake/index.html"; fi
chmod 644 "$target/call-intake/index.html"
mkdir -p "$target/mapa-map"
for asset in leaflet.js leaflet.css leaflet-license.txt; do
    test -r "$source_dir/mapa-map/$asset"
    if ! cmp -s "$source_dir/mapa-map/$asset" "$target/mapa-map/$asset"; then cp "$source_dir/mapa-map/$asset" "$target/mapa-map/$asset"; fi
    chmod 644 "$target/mapa-map/$asset"
done
index="$target/index.html"
test -f "$index"
if ! grep -Fq '</head>' "$index"; then
    printf '%s\n' 'MAPA Call Intake: target index.html has no closing head tag.' >&2
    exit 1
fi
if ! grep -Fq '/mapa-voice.css' "$index"; then
    sed -i 's@</head>@<link rel="stylesheet" href="/mapa-voice.css?v=20260929-asr-landmarks"></head>@' "$index"
fi
if ! grep -Fq '/mapa-voice.js' "$index"; then
    sed -i 's@</head>@<script defer src="/mapa-voice.js?v=20261005-intake-sync"></script></head>@' "$index"
fi
sed -i 's@href="/mapa-voice.css[^" ]*"@href="/mapa-voice.css?v=20260929-asr-landmarks"@g;s@src="/mapa-voice.js[^" ]*"@src="/mapa-voice.js?v=20261005-intake-sync"@g' "$index"
if ! grep -Fq '/mapa-layout-fixes.css' "$index"; then
    sed -i 's@</head>@<link rel="stylesheet" href="/mapa-layout-fixes.css?v=20260919-call-layout"></head>@' "$index"
fi
# Native review is an Ember instance initializer. A deferred head tag would run
# after the console's synchronous App.create(), so install it between the loader
# and app bundle. Replace older/misplaced native tags rather than duplicating them.
native_index="$target/.mapa-native-index.$$"
trap 'rm -f "$native_index"' EXIT HUP INT TERM
awk '
    { html = html $0 "\n" }
    END {
        gsub(/<script[^>]*src="[^"]*mapa-crew-native\.js[^"]*"[^>]*>[[:space:]]*<\/script>/, "", html)
        gsub(/<link[^>]*href="[^"]*mapa-crew-native\.css[^"]*"[^>]*>/, "", html)
        gsub(/<script[^>]*src="[^"]*mapa-intake-native\.js[^"]*"[^>]*>[[:space:]]*<\/script>/, "", html)
        gsub(/<link[^>]*href="[^"]*mapa-intake-native\.css[^"]*"[^>]*>/, "", html)
        if (!match(html, /<script[^>]*src="[^"]*assets\/vendor[^"]*\.js[^"]*"[^>]*>/)) {
            print "MAPA native crew review: native vendor script is missing." > "/dev/stderr"; exit 1
        }
        vendor = RSTART + RLENGTH - 1
        tail = substr(html, vendor + 1)
        if (!match(tail, /<\/script>/)) {
            print "MAPA native crew review: vendor script closing tag is missing." > "/dev/stderr"; exit 1
        }
        vendor_end = vendor + RSTART + RLENGTH - 1
        if (!match(html, /<script[^>]*src="[^"]*assets\/@fleetbase\/console[^"]*\.js[^"]*"[^>]*>/)) {
            print "MAPA native crew review: native console app script is missing." > "/dev/stderr"; exit 1
        }
        console_start = RSTART
        if (vendor_end >= console_start) {
            print "MAPA native crew review: vendor must load before the console app." > "/dev/stderr"; exit 1
        }
        html = substr(html, 1, console_start - 1) "<script src=\"/mapa-crew-native.js?v=20261001-crew-native1\"></script><script src=\"/mapa-intake-native.js?v=20261005-intake-sync\"></script>" substr(html, console_start)
        if (!match(html, /<\/head>/)) {
            print "MAPA native crew review: closing head tag is missing." > "/dev/stderr"; exit 1
        }
        html = substr(html, 1, RSTART - 1) "<link rel=\"stylesheet\" href=\"/mapa-crew-native.css?v=20261001-crew-native1\"><link rel=\"stylesheet\" href=\"/mapa-intake-native.css?v=20261005-intake-sync\">" substr(html, RSTART)
        printf "%s", html
    }
' "$index" > "$native_index"
if ! cmp -s "$native_index" "$index"; then mv "$native_index" "$index"; else rm -f "$native_index"; fi
chmod 644 "$index"
trap - EXIT HUP INT TERM
printf '%s\n' 'MAPA Call Intake and native crew review assets installed with loader-before-initializer-before-app ordering; API proxy remains host-owned.'

# MAPA Text Flow loads after the vendor loader and before the application bundle.
for asset in mapa-text-flow.js mapa-text-flow.css; do
    if ! cmp -s "$source_dir/$asset" "$target/$asset"; then cp "$source_dir/$asset" "$target/$asset"; fi
done
if ! grep -Fq 'mapa-text-flow.css' "$index"; then
    sed -i 's@</head>@<link rel="stylesheet" href="/mapa-text-flow.css?v=20261008"></head>@' "$index"
fi
if ! grep -Fq 'mapa-text-flow.js' "$index"; then
    sed -i 's@<script[^>]*src="[^"]*assets/\(@fleetbase/console\|console\)[^"]*\.js"[^>]*>@<script src="/mapa-text-flow.js?v=20261008"></script>&@' "$index"
fi
grep -Fq 'mapa-text-flow.js' "$index"
