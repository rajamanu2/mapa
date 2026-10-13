#!/usr/bin/env python3
"""Private host-side probe for the external monitoring agent; no public endpoint.

A fresh timestamp means backup.sh completed, not that restoration was verified.
"""
import argparse
from datetime import datetime, timezone
import json
from pathlib import Path

def check(path, now=None, max_age_hours=26):
    now = now or datetime.now(timezone.utc)
    try:
        path = Path(path)
        if path.is_symlink() or not path.is_file() or path.stat().st_size > 100:
            raise ValueError('Invalid success marker')
        timestamp = datetime.fromisoformat(path.read_text().strip().replace('Z', '+00:00'))
        if timestamp.tzinfo is None:
            raise ValueError('Timezone required')
        age = (now - timestamp).total_seconds()
        return {'fresh': -300 <= age <= max_age_hours * 3600,
                'age_seconds': round(age), 'restore_verified': False}
    except (OSError, ValueError, UnicodeError):
        return {'fresh': False, 'age_seconds': None, 'restore_verified': False}

def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--marker', default='/var/backups/mapa/last-success')
    args = parser.parse_args()
    report = check(args.marker)
    print(json.dumps(report))
    return 0 if report['fresh'] else 1

if __name__ == '__main__':
    raise SystemExit(main())
