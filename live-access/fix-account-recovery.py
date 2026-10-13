"""Adapt only the live console to the existing-workspace recovery policy."""
import json
from pathlib import Path
import re

root = Path(__file__).resolve().parents[2]
bundle = root / 'private/live-access/console/assets/@fleetbase/console.js'
text = bundle.read_text(encoding='utf-8')

def module_bounds(name):
    start = text.index(';define("@fleetbase/console/' + name + '"')
    end = text.find(';define(', start + 1)
    return start, len(text) if end < 0 else end

start, end = module_bounds('templates/auth/login')
section = text[start:end]
match = re.search(r'"block":\s*("(?:\\.|[^"\\])*")', section)
if not match:
    raise SystemExit('Missing native login template block; no file changed.')
block = json.loads(json.loads(match.group(1)))
removed = 0

def contains_label(value):
    if isinstance(value, list):
        return any(contains_label(child) for child in value)
    return value == 'auth.login.form.create-account-button'

def clean(value):
    global removed
    if not isinstance(value, list):
        return value
    result = []
    for child in value:
        if isinstance(child, list) and child and child[0] == 8 and contains_label(child):
            removed += 1
            continue
        result.append(clean(child))
    return result

block = clean(block)
if removed not in (0, 1):
    raise SystemExit('Unexpected account-button count; no file changed.')
encoded = json.dumps(json.dumps(block, separators=(',', ':')), ensure_ascii=False)
section = section[:match.start(1)] + encoded + section[match.end(1):]
text = text[:start] + section + text[end:]

old = 'class OnboardRoute extends _route.default {}'
new = "class OnboardRoute extends _route.default { beforeModel() { window.location.replace('/auth'); } }"
if old in text:
    if text.count(old) != 1:
        raise SystemExit('Ambiguous onboarding route; no file changed.')
    text = text.replace(old, new, 1)
elif new not in text:
    raise SystemExit('Missing onboarding route; no file changed.')

start, end = module_bounds('routes/auth/reset-password')
section = text[start:end]
old = "return this.fetch.get('auth/validate-verification', {\n        id\n      });"
new = "return this.fetch.get('auth/validate-verification', {\n        id, for: 'password_reset'\n      });"
if old in section:
    section = section.replace(old, new, 1)
elif new not in section:
    raise SystemExit('Missing recovery validation call; no file changed.')
old = "controller.brand = await this.store.findRecord('brand', 1);"
new = "controller.brand = this.store.peekRecord('brand', 1); // MAPA: password recovery needs no private brand request."
if old in section:
    section = section.replace(old, new, 1)
elif new not in section:
    raise SystemExit('Missing recovery brand setup; no file changed.')
text = text[:start] + section + text[end:]
bundle.write_text(text, encoding='utf-8')
print('Live signup hidden; onboarding redirects to sign-in; native password recovery prepared.')
