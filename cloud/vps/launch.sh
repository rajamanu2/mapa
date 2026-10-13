#!/usr/bin/env sh
# Target host only. Intentionally refuses missing migration/acceptance gates.
set -eu
cd -- "$(dirname -- "$0")"
python3 preflight.py --launch
docker compose config --quiet
docker compose up -d
