"""Publish the verified immutable pilot APK; never changes app/server data."""
from pathlib import Path
import hashlib
import os
import urllib.request

NAME = 'MAPA-Crew-1.14.5-Firebase.apk'
EXPECTED = '1e586f62efe6a5efe93255bb406904556c6b9a16fef8e4f8485e996186be2079'
SOURCE = Path(__file__).resolve().parent / NAME
ROOT = Path('/opt/mapa-staging/release/public-console')
TARGET = ROOT / NAME
URL = 'https://app.itda.asrhealth.in/' + NAME

def digest(payload):
    return hashlib.sha256(payload).hexdigest()

payload = SOURCE.read_bytes()
if digest(payload) != EXPECTED:
    raise SystemExit('APK hash mismatch; nothing published')
created = False
if TARGET.exists():
    if TARGET.is_symlink() or digest(TARGET.read_bytes()) != EXPECTED:
        raise SystemExit('Existing immutable APK differs; refusing overwrite')
else:
    # Exclusive creation prevents replacing a concurrent publication.
    with TARGET.open('xb') as stream:
        created = True
        try:
            stream.write(payload)
            stream.flush()
            os.fsync(stream.fileno())
        except Exception:
            TARGET.unlink(missing_ok=True)
            raise
    os.chmod(TARGET, 0o644)
try:
    request = urllib.request.Request(URL, headers={'Cache-Control': 'no-cache'})
    with urllib.request.urlopen(request, timeout=30) as response:
        public = response.read(len(payload) + 1)
    if digest(public) != EXPECTED:
        raise RuntimeError('Public download hash mismatch')
except Exception:
    if created and TARGET.is_file() and digest(TARGET.read_bytes()) == EXPECTED:
        TARGET.unlink()
    raise
print('Public APK verified:', URL)
print('SHA256:', EXPECTED)
