#!/bin/sh
set -eu
index=/usr/share/nginx/html/index.html
test -f "$index"
if ! grep -q 'mapa-legal.js' "$index"; then
    sed -i 's#</head>#<link rel="stylesheet" href="/mapa-legal.css?v=20260919"><script defer src="/mapa-legal.js?v=20260919"></script></head>#' "$index"
fi
sed -i 's#mapa-legal.js?v=20260918#mapa-legal.js?v=20260919#g;s#mapa-legal.css?v=20260918#mapa-legal.css?v=20260919#g' "$index"
test "$(grep -o 'mapa-legal.js' "$index" | wc -l)" -eq 1
