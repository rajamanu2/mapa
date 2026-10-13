"""Apply the narrowly scoped viewer navigation fix to the adapted Pages bundle."""
from pathlib import Path
import argparse

parser=argparse.ArgumentParser(); parser.add_argument('--console-root', type=Path, default=Path(__file__).resolve().parents[2] / 'private/client-preview/console-pages'); args=parser.parse_args()
bundle = args.console_root / 'assets/vendor.js'
data = bundle.read_bytes()
newline = b'\r\n' if b'      // Set permissions\r\n' in data else b'\n'

old_check = b'      return this.permissions.has(permission) || this.permissions.has(wilcardPermission) || this.permissions.has(wildcardServicePermission);'
new_check = newline.join([
    b'      // MAPA: resolve current grants after login, refresh, or organization change.',
    b'      const permissions = new Set((this.currentUser.permissions ?? []).map(permission => permission.name));',
    b'      return permissions.has(permission) || permissions.has(wilcardPermission) || permissions.has(wildcardServicePermission);',
])
old_late = newline.join([
    b'      // Set permissions',
    b'      this.permissions = this.getUserPermissions(user);',
    b'',
])
old_early = newline.join([
    b"      this.set('userSnapshot', snapshot);",
    b'      this.theme.syncThemeFromCurrentUser();',
])
new_early = newline.join([
    b"      this.set('userSnapshot', snapshot);",
    b'      // MAPA: lifecycle listeners must see the resolved user permissions.',
    b'      this.permissions = this.getUserPermissions(user);',
    b'      this.theme.syncThemeFromCurrentUser();',
])
if new_check in data and new_early in data and old_late not in data:
    print('Viewer navigation fix already applied.')
else:
    for needle in [old_check, old_late, old_early]:
        if data.count(needle) != 1:
            raise SystemExit('Expected exact bundle anchors missing or repeated (' + str(data.count(needle)) + '); no file changed.')
    data = data.replace(old_check, new_check, 1).replace(old_late, b'', 1).replace(old_early, new_early, 1)
    bundle.write_bytes(data)
    print('Updated only the adapted Pages vendor bundle; preserved existing byte formatting.')
