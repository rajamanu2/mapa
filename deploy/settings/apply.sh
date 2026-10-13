#!/usr/bin/env bash
set -euo pipefail
cd -- "$(dirname -- "$0")"
exec 9>"$HOME/.mapa-settings-deploy.lock"
flock -n 9 || { echo 'Another deployment is active'; exit 1; }
python3 publish-settings.py --check > checked-state.json
state=$(python3 -c 'import json; print(json.load(open("checked-state.json"))["state_sha256"])')
python3 publish-settings.py --apply --expected-state-sha "$state"
if ! python3 publish-settings.py --verify-public --public-base https://app.itda.asrhealth.in; then
  python3 publish-settings.py --rollback
  echo 'Public verification failed; prior files restored.' >&2
  exit 1
fi
