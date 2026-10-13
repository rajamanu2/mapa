# MAPA source snapshot

Prepared 2026-10-14 from the local MAPA workspace. Includes console, API extensions, Android source, voice bridge, tests and deployment templates. Upstream licenses and pinned dependency submodules are retained.

Private environment files, server snapshots, audit captures, signing keys, generated APKs and archives are excluded. Known operator phone literals are sanitized; configure actual senders with server environment variables.

This is a source publication, not a production deployment. No GitHub Actions deployment is enabled. Existing upstream workflow files were omitted to avoid unintended publishing. The live dispatch worker heartbeat/lock repair remains outstanding. Configure an approved restricted deployment connection before enabling production automation.

Clone with git clone --recurse-submodules https://github.com/rajamanu2/mapa.git. Android lives in mapa-android and voice bridge in mapa-voice-bridge.
