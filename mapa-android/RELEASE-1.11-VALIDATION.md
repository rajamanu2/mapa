# MAPA Crew 1.11 — 30 September 2026 validation

APK: `https://mapa-asr-health.pages.dev/downloads/MAPA-Crew-1.11.0.apk`

Guide: `https://mapa-asr-health.pages.dev/downloads/MAPA-Crew-Guide.html`

## Current launch scope

ASR Health has selected operator-entered calls on its existing phone number. No Exotel activation or automatic audio capture is required for this path. Firebase is optional for a supervised trial using verified active-duty polling alerts. The current local guide and readiness handover describe this scope; the observed deployment results below record the earlier release and do not imply that subsequent local edits have been published.

## Observed results

- Recovered the exact 1.10 source corresponding to the previously installed APK before applying changes. Internal navigation was retained.
- Built 1.11.0-pilot / Android version code 14 with the existing signer. Certificate SHA256: `6fa24fd0e67495eac8023c36ea2d2ee64faf6b5f040fd63c10f38e09048a4fea`.
- APK 124070 bytes, SHA256 `7247e4cc0fc9d2a2e2543358c316d43645de46e733587f85445b35401aef6cf6`. Public download is byte-identical.
- Android assertions: 268 passed across contract, UI, route, action queue, push policy and map rendering. Native mobile integration: 142 passed using connection-local temporary native tables. Pure policy: 47. Cache boundaries: 22. Push integration: 26. Voice native integration: 73 plus 58 foreign-key checks. Voice bridge: 32.
- Real Firebase SDK Java compilation passed for the optional FCM build using deliberately invalid compile-only identifiers. No FCM APK from that fixture was published and no provider messages were sent.
- Applied only the additive push migration, creating two module tables. Existing users, crew, ambulances, operational orders and database volumes were not replaced. Push remains disabled without its real configuration.
- Gracefully reloaded the public and original native API workers. The database was not restarted.
- Public health, auth page and anonymous branding returned 200; anonymous private-user and mobile-snapshot endpoints returned 401.
- Public native mobile password login with an existing training crew account passed; current snapshot showed trip updates enabled and push unavailable. This was read-only; no call, duty or GPS change occurred. The verification session was revoked. An initial generic verification client received HTTP403; the explicit MAPA verification client subsequently passed without account or access-control changes.
- Updated guide was loaded and visually inspected in the in-app browser. Public ZIP and guide matched local reviewed files. The private real-crew roster is not in the share ZIP.

## Not yet verified

No phone was detected by ADB for this release. Installation, locked-screen GPS, actual trip buttons and background FCM receipt on real phones remain physical acceptance work. Isolated rate-limit tests allow the expected 25-phone polling volume behind one NAT, but do not measure 25-device end-to-end capacity or battery endurance.

No real crew roster or verified ambulance identities were supplied. No new accounts or vehicles were invented. Firebase project/app/server credentials were absent from the inspected setup. Exotel credentials exist privately but do not establish number approval; the bridge public WSS endpoint and staffed fallback remain unconfigured. No live phone calls were placed and no simulated location was presented as live.

The public address still depends on this PC, Docker, Internet and its temporary tunnel. See `LIVE-READINESS.md` for the remaining operator setup and acceptance steps.
