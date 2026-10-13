# MAPA ASR Health — real crew phone setup

Current update: **1.11.0-pilot, version code 14**, Android 8 or newer. It connects to the same ASR Health backend as the web console. The exact installed 1.10 source was recovered before rebuilding, preserving navigation and the existing signing certificate. This release passed software/package tests; no physical phone was connected for update acceptance.

Share the [new-user guide](https://mapa-asr-health.pages.dev/downloads/MAPA-Crew-Guide.html) for installation, daily sharing, web checks and onboarding requirements. Its local source is `MAPA-CREW-START-GUIDE.html`.

## Install and connect

1. Download [MAPA Crew 1.11.0](https://mapa-asr-health.pages.dev/downloads/MAPA-Crew-1.11.0.apk) and update the existing MAPA app in place. Do not uninstall, clear app data or bypass a signing/security error. Keep a newer installed version and ask control about compatibility.
2. Open MAPA. Server must be `https://mapa-asr-health.pages.dev`, without `/fleet-ops` or `/call-intake/`. Older deliberate server selections are preserved, so check this after an upgrade.
3. Use your own active native ASR Health crew account, provisioned from the real roster and explicitly bound to its matching driver and vehicle. Credentials are supplied privately; no shared login is included in the APK. The web administrator account does not represent a crew member.
4. Confirm your actual crew name and ambulance registration. Start duty explicitly, accept the location-sharing explanation, and grant precise location and notifications on the phone.
5. Keep mobile data or Wi-Fi available. A USB cable alone does not supply an Internet connection.

The 1.11 APK SHA256 is `7247e4cc0fc9d2a2e2543358c316d43645de46e733587f85445b35401aef6cf6`. Its certificate matches the installed 1.10 app. Public download bytes match the reviewed build. No phone session, password or server credential is bundled.

## Observe the same records on phone and web

Open [MAPA Call Intake](https://mapa-asr-health.pages.dev/call-intake/) in the PC browser and choose **Crew locations**. On the phone, wait for **GPS is being shared** and a recent **Last received** time. Compare the ambulance identity, device timestamp, server receipt and accuracy in **Travel record** with the web view. Walk a short safe route with the phone and compare fresh device and server times. Do not generate fictional incidents or simulated locations.

The phone requests location observations at roughly five-second intervals and performs its foreground sync loop at roughly ten-second intervals. Network and Android scheduling can add delay; this is polling, not guaranteed instantaneous delivery. Stored or seeded positions are not evidence of a new phone fix.

The September 30 read-only inventory found all 7 existing drivers and 7 ambulances marked as training/simulated, and 2 enabled crew bindings linked to training resources. No real crew bindings have been verified yet. Supply the actual 25-person roster and receiving hospitals before provisioning. Preserve historical records and labels; do not rename fictional resources into real ones or erase assignments to bypass eligibility.

After the first real fix, verify screen-lock updates, GPS off, airplane mode/reconnection, acknowledgement when an actual assigned call is received, English/Telugu layout and session sign-out. Record observed results in [PHONE-TEST-2026-09-29.md](PHONE-TEST-2026-09-29.md).

## Remaining functionality

- On September 30, new Call Intake drafts were changed to real manual calls and new training calls disabled. Training history is excluded from the operational call queue without deleting it. Public authentication and capability checks passed after a graceful API worker reload. Isolated voice checks passed (71 assertions, 58 native foreign-key checks, zero production rows or notifications), as did 109 UI rule checks.
- Ready for supervised device testing: native crew login, assigned calls, caller coordination details, pickup pin, duty, GPS receipt/history, acknowledgement and crew-confirmed single-destination ASR trip completion.
- Still unavailable in this native pilot: photo/proof upload, hospital response writes, custom/multi-stop/return/financial workflows and mobile administration writes. These extended workflows remain in the console.
- Alerts use foreground polling; Firebase push is not commissioned. Force-stopping the app or Android battery restrictions can stop updates.
- This shared HTTPS backend still needs the PC, Docker and Internet running. Always-on hosting, release signing/distribution and the remaining workflow extensions are separate rollout work.

Use [README.md](README.md) and [NATIVE-ACCEPTANCE.md](NATIVE-ACCEPTANCE.md) for the full contract and supervised acceptance checks. The previous command-mode setup is archived in `history/command-1.6` and is not an active product connection.

See [LIVE-READINESS.md](LIVE-READINESS.md) for the precise Firebase, hotline, real roster, hosting and device checks still required.
