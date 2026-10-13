"""Replace only absent/upstream mail sender defaults; never change mail transport.

Custom sender addresses and names are preserved. This changes the private Docker
environment for the next controlled API recreate, and sends no mail.
"""
from pathlib import Path
import re

ROOT = Path(__file__).resolve().parents[2]
path = ROOT / 'fleetbase/asr/.env'
original = path.read_text(encoding='utf-8')
text = original
changes = []
for key, old, new in [('MAIL_FROM_ADDRESS', 'hello@fleetbase.io', 'hello@mapa.dev'),
                      ('MAIL_FROM_NAME', 'Fleetbase', 'MAPA')]:
    pattern = r'(?m)^' + re.escape(key) + r'=(.*)$'
    matches = list(re.finditer(pattern, text))
    if len(matches) > 1:
        raise SystemExit('Duplicate sender setting; refusing ambiguous update: ' + key)
    if not matches:
        text = text.rstrip('\n') + '\n' + key + '=' + new + '\n'
        changes.append(key)
    else:
        raw = matches[0].group(1).strip()
        value = raw.strip('\"\'')
        if value == old:
            text = text[:matches[0].start()] + key + '=' + new + text[matches[0].end():]
            changes.append(key)
        elif value != new:
            print('Preserved custom sender field: ' + key)
if text != original:
    path.write_text(text, encoding='utf-8', newline='\n')
print('MAPA sender defaults ready; changed fields: ' + (', '.join(changes) or 'none (already configured)') + '.')
