# MAPA ASR Health — physical phone acceptance, 2026-09-29

Status: **phone-to-native-MAPA GPS synchronization verified; remaining supervised rollout checks pending**.

## Verified preparation

- Public Android download returned HTTP 200 and matched the reviewed 1.9.0/code 11 APK SHA256 `5289e433a57c3bb185bace99c8828b28fa6812b8020e429b789652a1dee7e374` (111,782 bytes).
- Existing DEMO crew password login returned HTTP 200 from `https://mapa-asr-health.pages.dev/mapa/mobile/v1/login`.
- Native scope matched ASR Health company `29eff7bc-7b9b-4d8a-b1b4-55f33681bd2d`, `driver_mapa_mobile_demo_01` and `vehicle_mapa_mobile_demo_01`.
- Server advertised duty, GPS, orders and acknowledgement. Activities, photo upload, hospital response and administrative writes remained false.
- Snapshot showed the crew online with one dispatched, already acknowledged DEMO order, `order_mapa_voice_4ab0c3f5d3ae4bc893c902b6f33b99f6`. No recent travel evidence points were returned. No duty/GPS/assignment changes were submitted by this readiness check.
- The exact temporary readiness session was logged out and its subsequent snapshot request returned 401. Anonymous snapshot access also returned 401.
- Windows detected the connected phone as **X300 Pro**, WPD status OK. Android Platform Tools 37.0.1 listed no debugging device with either its default or compatibility USB backend, including after the user reported reconnecting and approving debugging. Windows USB inspection showed the phone exposing only the MTP interface (no Android debugging interface or driver error). Direct installation from the verified APK URL was offered; handset installation and login remain pending.

## Connected-phone follow-up

- The X300 Pro is now an authorized ADB device (model V2514, Android 16).
- Installed MAPA is **1.10.0-pilot, versionCode 13**, newer than this workspace's 1.9.0/code 11 download. It was not downgraded or uninstalled. Inspection of the installed APK confirmed the default origin `https://mapa-asr-health.pages.dev` and `/mapa/mobile/v1` contract path; this does not by itself prove the saved connection setting.
- MAPA's precise-location and notification permissions are granted. At inspection, its foreground location service was not running.
- The native server's last session named `MAPA Android V2514` was created on September 19 and has expired. No new phone login was recorded on September 29 at 14:27 UTC. Last demo-crew GPS receipt was September 19 at 14:22:34 UTC.
- Requested a new sign-in on the handset using the existing crew account, followed by **Resume location sharing**. No credentials, operational records, or duty state were changed by diagnostics.
- Follow-up debugging verified a new, unrevoked `MAPA Android V2514` session created on September 29 at **15:01:48 UTC / 20:31:48 IST**. The phone has successfully authenticated to the selected native MAPA server. GPS receipts remained at September 19 when checked at 15:05 UTC.
- During that follow-up, ADB listed no devices. Windows still identified X300 Pro as an MTP media device, but no Android debugging interface was exposed. Starting the debugger with its previously working USB compatibility mode did not restore the connection. Requested USB reconnection/authorization with MAPA open; app-specific runtime debugging is pending that access. No phone reset, app downgrade, permission bypass, or server mutation was performed.

## Physical checks

### Successful sharing and web verification — September 29, 16:50 UTC onward

- USB debugging reconnected to the same X300 Pro/V2514. Phone location was enabled, precise-location/notification permissions were granted, and the MAPA GPS foreground service was initially absent.
- The user was asked to tap **Resume location sharing → Start sharing**. A successful duty receipt appeared at 16:50:08 UTC, followed by a device GPS receipt at 16:50:10 UTC. `NativeDutyService` then reported `isForeground=true`, location service type and ongoing notification 71.
- Consecutive device GPS reports were accepted at 16:50:10, 16:50:21, 16:50:33, 16:50:44, 16:51:05 and 16:51:17 UTC. Reported accuracy was 100 metres, `source=device_gps`, and `mock_location=false`. No diagnostic code submitted invented GPS coordinates.
- The authenticated operator **Crew locations** page displayed **13 accepted fixes**, device times from 22:20:09 to 22:22:56 IST, with the last server receipt at 22:23:02 IST.
- A final server read at **17:01:21 UTC / 22:31:21 IST** counted **41 accepted handset fixes today**. The newest receipt was 17:01:16 UTC, five seconds old, still reporting 100 m accuracy. This confirms continuing uploads for approximately eleven minutes after sharing started, not only one successful request.
- The native operations map displayed **DEMO Mobile Ambulance — Paderu**, registration **DEMO-MOBILE-01**, linked to **DEMO Mobile Crew**, with matching coordinates and **Last seen: less than a minute**. Its row, map focus and details panel were opened for the user.
- These remain DEMO resources. Their existing `simulated` metadata also causes the history UI's DEMO/simulated label; it was not removed. The reports are live observations from the test handset, not proof of a real ambulance journey or exact pickup point.
- A user-reported error message was requested verbatim but not supplied. The observed sharing interruption was resolved by explicit sharing startup; no application-code fix, permission bypass or reinstall was required to obtain these receipts. Do not assert a cause for the unspecified error.
- Screen-lock testing was requested. At the last observed power-state check the phone was still Awake, so locked-screen reliability is not claimed.
- The installed 1.10.0/code 13 APK was inspected without copying private app data. APK Signature Scheme v2/v3 verification passed; its signing certificate matches the prior pilot (`6fa24fd0e67495eac8023c36ea2d2ee64faf6b5f040fd63c10f38e09048a4fea`). APK SHA256: `676a80b194b9ba8d386b05498f7cf378549da6c58ac58d8dbcd8c880f6b014cb`.
- The same signed binary and a password-free user guide were published under the existing `/downloads/` path. Both public downloads returned HTTP 200 and matched their local files exactly. This is an inspected existing pilot artifact, not a new source build or full release acceptance.

| Check | Observed result |
| --- | --- |
| Phone Android version and authorized ADB connection | Verified again: Android 16, authorized X300 Pro/V2514 |
| Installed APK version/signature-compatible upgrade | 1.10.0/code 13 installed; no upgrade or downgrade performed |
| Native crew sign-in on the handset | Verified by fresh native `MAPA Android V2514` session on September 29 at 15:01:48 UTC |
| Precise-location and notification consent on handset | Existing grants verified; no permissions changed |
| Fresh device GPS and matching ambulance location on web | Verified; movement along a measured route remains untested |
| Matching travel timestamps/accuracy/history | Verified: consecutive receipts, 13 displayed points, 100 m reported accuracy |
| New eligible DEMO call and acknowledgement on phone/web | Pending; current crew has an active DEMO assignment |
| Screen lock, GPS off, network loss/reconnection | Pending |
| English/Telugu layout | Pending |

Only the dedicated fictional resources are in scope. Actual phone observations must be distinguished from seeded/simulated samples; they do not prove the identity of a traveller or a completed transport. No operational deployment or complete ambulance workflow is certified by these preparation checks.
