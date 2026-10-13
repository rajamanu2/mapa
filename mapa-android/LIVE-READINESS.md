# MAPA ASR Health — rollout handover, 1 October 2026

## Selected launch workflow: operator-entered calls

The operator answers ASR Health's existing telephone number outside MAPA and enters the details in **New Call**. This workflow needs no Exotel account, number activation, voice streaming, transcription or speech API. Manual intake and dispatch use their own enabled capability; provider intake remains disabled.

The operator records the callback number when available, caller-reported problem, pickup address/pin, landmark/access directions and receiving destination. Search and a dropped map pin fill candidate coordinates; the operator reads the location back to the caller and confirms it before dispatch. The operator reviews priority and ambulance requirements, selects an eligible crew/ambulance and explicitly confirms dispatch. Saving a draft does not send an ambulance.

The assigned Android app receives the same native call while the app or explicitly started duty service is active. Crew review and accept it, wait for a new server-received GPS report after acceptance, then confirm response start, transport and receiving-team handover when those events actually occur. Control verifies the acknowledgement and trip updates in MAPA. If an alert or update is missing, control coordinates on the existing phone number and checks the displayed error; it must not treat a notification as acceptance.

## Software delivered

The native Android app uses the same ASR Health API and database as the web console. Each crew phone is bound to its approved native driver, ambulance and hospital/base. Version 1.12 lets drivers submit real details and a fresh selfie/GPS on their own phone. Staff must verify identity, select existing actual ambulance/base records and approve before that phone can start duty or receive work. Installing the APK does not approve an identity, invent a vehicle or take over an existing assignment.

Version 1.12 retains crew-confirmed response start, transport and hospital handover for the existing single-destination ASR workflow. The backend writes the native order, tracking timeline, crew audit and immutable retry receipt in one transaction. It checks current assignment, current call revision, duty, accepted call and recent non-mock GPS from this sign-in after acceptance. Completion requires a separate handover confirmation, a trip-end note, a fresh camera photograph and current-session accepted GPS. The note, private photograph, fix/capture time and accuracy commit with the native trip for command-center review. Exact committed retries return the original receipt after evidence expiry. Definitely rejected stale captures need explicit recapture; uncertain updates preserve their exact saved body. It releases only this call's current job and retains its crew history. The web read caches are refreshed after commit.

Custom workflow rules, additional proof requirements, entities, return trips, multi-stop trips, financial transactions and manifests continue through the console. This adapter does not trigger automatic next-call allocation, completion webhooks or outgoing completion messages. Those are not required to persist the supported native trip, and are not silently enabled. GPS is device-reported evidence; neither location nor crew confirmation is independent proof of patient handover.

FCM integration now includes encrypted device registration, session/vehicle validation, assignment hooks for native and manual call intake, background queue delivery, expiring generic alerts and invalid-token handling. Patient/caller/location details are not included in the push payload. It remains disabled until the correct Firebase project and server credential are installed. The dependency-free 1.12 APK displays this explicitly; it retains foreground-service polling alerts. Firebase is optional for the supervised manual-call trial with active duty sharing and verified polling alerts. A Firebase-configured APK and physical phone acceptance test are required before claiming remote push delivery.

The Exotel/Sarvam bridge has protocol tests, current English/Telugu TTS request fields and a redacted commissioning check. It is not a connected public hotline and is outside the selected launch workflow. If automatic telephone capture is requested later, follow `../mapa-voice-bridge/deploy/LIVE-COMMISSIONING.md`. Operator review still controls dispatch; speech extraction does not autonomously dispatch a real ambulance.

## Required before the 25-phone rollout

1. Give each driver the new APK and registration guide. Each uses a distinct real contact email and supplies their own name, phone, ambulance registration/name and hospital/base. Staff review the private selfie and details under Crew registration, then approve a matching actual native vehicle/base. Verify identity, membership and vehicle binding. Existing numbered accounts remain unchanged; registration cannot claim an existing email or assignment.
2. Verify each phone's identity and fresh GPS on the web, safe movement, notification permission, screen-lock operation, network recovery, session expiry and assignment changes. Use the local 30-second alert test, then verify an authorized assignment alert on each handset. Checking the alert stops ringing without accepting the call. Android permissions, channels, silent/DND and service availability govern sound. Keep the duty/location-sharing service active. Reopen MAPA and explicitly resume sharing after reboot, force-stop or an interrupted service.
3. Verify the operator's manual entry, confirmed pickup pin, eligible ambulance selection and dispatch, then actual crew acknowledgement and each supported trip stage. Control must verify received updates and have telephone coordination available when polling or an update fails. Do not manufacture incidents or contact patients for acceptance testing.
4. Test in waves of 3, then 10, then 25 phones. Shared-network rate-limit regression is covered; this is not a measured 25-device load or battery endurance test. Prior physical GPS evidence was from version 1.10; the new registration, trip evidence and repeat-alert workflow need handset acceptance. RELEASE-1.12-VALIDATION.md records the exact observed test scope.
5. Move the backend to commissioned always-on hosting before relying on it when this PC is off. The current Pages address relays to this Windows PC, Docker and a temporary tunnel. Refresh the cloud image with the current mobile/intake modules before migration; verify backups/restoration, operator escalation and release-signing custody.

## Optional later integrations

- Remote push: configure Firebase for Android package `in.asr.ambulance`, provide the matching app configuration and mount the server service-account credential privately. Build/sign the FCM variant with the existing signer, then verify delivery on a locked phone. Never put the server credential in an APK, guide or public folder.
- Automatic telephone capture: commission the Exotel number, secure public audio endpoint, English/Telugu speech and staffed human fallback only if this separate feature is requested. These are not prerequisites for manual New Call operation.

## Release boundary

The selected manual workflow still needs verified real identities, devices, operator acceptance and dependable hosting before operational approval. The software can be installed for supervised verification; an installed app or passing isolated test does not prove operational readiness. Provider commissioning applies only to optional automatic telephone capture.
