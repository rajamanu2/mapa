"""Keep MAPA's visible operation name separate from its native permission ID.

The header checks '<menu id> see extension'. Deriving that ID from the renamed
product title hides operations from company administrators even when they have
all native grants. Patch only the reviewed FleetOps header registration.
"""
import argparse
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
ANCHOR = "menuService.registerHeaderMenuItem('MAPA Operations', 'console.fleet-ops', {"
PROPERTIES = "\n        id: 'fleet-ops',\n        slug: 'fleet-ops',\n        view: 'fleet-ops',"


def apply(console):
    bundle = console / 'assets/@fleetbase/console.js'
    original = bundle.read_bytes()
    text = original.decode('utf-8')
    newline = '\r\n' if '\r\n' in text else '\n'
    desired = ANCHOR + PROPERTIES.replace('\n', newline)
    if text.count(ANCHOR) != 1:
        raise ValueError('Expected one MAPA operations header registration; no file changed.')
    if desired in text:
        print('MAPA operations native navigation ID already preserved.')
        return
    # Refuse a changed/ambiguous registration rather than silently duplicating IDs.
    following = text.split(ANCHOR, 1)[1].split('shortcuts:', 1)[0]
    if any(key in following for key in ('id:', 'slug:', 'view:')):
        raise ValueError('Unexpected existing header identity options; no file changed.')
    result = text.replace(ANCHOR, desired, 1).encode('utf-8')
    bundle.write_bytes(result)
    print('MAPA operations title preserved; header and shortcut permissions use fleet-ops.')


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--console-root', type=Path, default=ROOT / 'private/live-access/console')
    apply(parser.parse_args().console_root.resolve())
