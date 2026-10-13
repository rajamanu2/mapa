"""Package only an inspected release APK and password-free crew registration guide."""
from pathlib import Path
import hashlib, json, shutil, zipfile
ROOT=Path(__file__).resolve().parent.parent
OUT=ROOT/'output'/'MAPA-25-Phone-Trial'
DOWNLOADS=ROOT/'private'/'live-access'/'console'/'downloads'
manifest=json.loads((ROOT/'mapa-android'/'outputs'/'BUILD-VALIDATION.json').read_text(encoding='utf-8-sig'))
if manifest.get('version')!='1.12.2-pilot' or manifest.get('version_code')!=17:
    raise SystemExit('The inspected 1.12 release manifest is required; package stopped.')
APK_NAME='MAPA-Crew-1.12.2.apk'
apk=DOWNLOADS/APK_NAME
if hashlib.sha256(apk.read_bytes()).hexdigest()!=manifest.get('sha256'):
    raise SystemExit('Published APK does not match the inspected release; package stopped.')
OUT.mkdir(parents=True,exist_ok=True)
shutil.copy2(apk,OUT/APK_NAME)
shutil.copy2(ROOT/'mapa-android'/'SELF-REGISTRATION-GUIDE.html',OUT/'MAPA-Crew-Guide.html')
shutil.copy2(OUT/'MAPA-Crew-Guide.html',DOWNLOADS/'MAPA-Crew-Guide.html')
start="""MAPA ASR HEALTH - CREW INSTALLATION AND REGISTRATION

1. Install/update MAPA-Crew-1.12.2.apk on Android 8 or newer.
   Update in place; keep Play Protect enabled. No USB/debugging is needed.
2. Server: https://mapa-asr-health.pages.dev (no extra path).
3. Select Register / connect to ASR Health. Enter your own actual name, contact email,
   phone, ambulance registration/name and hospital/base. Read the consent notice.
4. Allow precise location and camera. Take your new registration selfie and submit.
5. Wait for command-center identity and actual-ambulance approval. Check status.
   Each new crew phone registers with its own real details; this does not create a mailbox.
6. Confirm the approved crew and ambulance before Start duty > Start sharing.
   Allow notifications. Verify recent received GPS with command center.
7. Open/check a new assigned call to silence the repeating alert. Explicitly
   Accept is a separate action; checking the notification does not accept it.
8. Confirm response start and transport when those events happen.
9. At receiving-team handover, enter an end note, take a new ambulance/safe-area
   photo and wait for fresh GPS received by MAPA. Submit and wait for receipt.
10. End duty/stop sharing separately after completion is confirmed and no call
    remains active. Do not end duty while an update has an uncertain receipt.

For a rejected duplicate-email request, choose Edit rejected registration to correct
your own driver email, then take a fresh selfie and GPS. Use existing account
returns to password sign-in; it does not delete or replace the existing account.
Pending/approved registrations must be reviewed by control.

If offline, retain the original uncertain action and retry exactly. A definitively
rejected stale capture requires fresh photo/GPS and explicit resubmission.
Photographs are private authenticated records. Avoid patient faces/documents.
The connection is encrypted on the phone, revocable and valid for a bounded
period. A lost/reinstalled/signed-out phone needs control-assisted reconnection.

CONTROL: Main command center > Drivers > Crew registration (or operator desk) >
verify driver/selfie/actual imported native
ambulance and hospital/base > approve. New registrations cannot claim an already
assigned ambulance. Use Crew locations for GPS and Trip reports for end evidence.
Test 3 phones, then 10, then 25: permissions, screen lock, alerts, network recovery,
correct identity/vehicle, acknowledgement, completion evidence and duty off.
Do not create fictional operations or use one shared login for all phones.

Android notification permission, channels, silent/DND, battery restrictions and
service/network availability affect ringing. Firebase remote push is still
unconfigured. An active app/duty service is required for polling alerts.
The shared backend still requires the host PC, Docker, internet and power.
Read MAPA-Crew-Guide.html for the full guide. This package is software delivery;
physical-device and operational acceptance must be recorded separately.
"""
(OUT/'START-HERE.txt').write_text(start,encoding='utf-8')
forward="""MAPA ASR Health - crew app

APK: https://mapa-asr-health.pages.dev/downloads/MAPA-Crew-1.12.2.apk
Guide: https://mapa-asr-health.pages.dev/downloads/MAPA-Crew-Guide.html
Server inside MAPA: https://mapa-asr-health.pages.dev

Install/update, select Register / connect to ASR Health and submit your actual details,
ambulance/hospital, location and new selfie. Wait for command-center approval,
then confirm your name/ambulance and Start duty > Start sharing. Allow precise
location and notifications. Verify the received GPS with control before use.
New crew register once and await verified approval. Contact control if the email
already has an account. Do not post patient details or credentials in groups.
"""
(OUT/'MESSAGE-TO-FORWARD.txt').write_text(forward,encoding='utf-8')
zipname='MAPA-Crew-1.12.2-Install-Pack.zip'
allowed=[APK_NAME,'MAPA-Crew-Guide.html','START-HERE.txt','MESSAGE-TO-FORWARD.txt']
with zipfile.ZipFile(OUT/zipname,'w',zipfile.ZIP_DEFLATED) as bundle:
    for name in allowed: bundle.write(OUT/name,name)
with zipfile.ZipFile(OUT/zipname) as bundle:
    if sorted(bundle.namelist())!=sorted(allowed):raise SystemExit('Unexpected install-pack content.')
shutil.copy2(OUT/zipname,DOWNLOADS/zipname)
print(json.dumps({'install_pack':zipname,'files':len(allowed),'passwords_included':False,'sha256':hashlib.sha256((OUT/zipname).read_bytes()).hexdigest()}))
