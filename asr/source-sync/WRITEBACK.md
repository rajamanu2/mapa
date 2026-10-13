# Controlled ASR record edits

This module is local preparation, disabled by default. It has not been installed or enabled on asrhealth.in. It does not change the original website forms, authentication, dispatch, GPS, crew duty or patient/trip records.

ASR remains authoritative. The native MAPA backend must authenticate and authorize the current company administrator, derive actor_id from that authenticated user (never from browser input), construct a signed command and verify the signed response. Fetch the authoritative export again after a confirmed result. Do not claim that a timeout failed: retry the exact request_id and payload to obtain the committed receipt. If refreshing fails after a successful write, show that the source saved but the mirror is awaiting refresh. A revision conflict requires a new fetch, review and a new request_id, never an automatic overwrite.

## Supported commands

| Action | Fields in values | Original source behavior |
| --- | --- | --- |
| ambulance.profile | vehicle_model (string, max 100 bytes), model_year (integer 1900 through next year, or null) | ambulance_profile.php updates only model and year under amb_profile edit permission |
| ambulance.transfer | phc_id and institute_type_id (source ID strings) | ambulance_index.php transfers only the PHC and institute type under ambulance edit permission |
| driver.transfer | phc_id (source ID string) | drivers.php transfers only the PHC under amb_drivers edit permission |

This intentionally excludes create/delete, daily session edits, repair completion, trips and patient details. These are not implemented by this module. Ambulance transfers preserve mandal, secretariat, parking and coordinates, matching the existing transfer operation; such fields may therefore need review in the original website after a transfer.

POST JSON contains exactly: contract (`mapa-asr-command-v1`), company_id (the SourceSync ASR company UUID), request_id (UUID), actor_id (authenticated MAPA user UUID), action, record_id (original source ID string), expected_revision and values. Compute expected_revision with `SourceCommands::revision('ambulances'|'drivers', exportedRow)`; this normalizes scalar types and hashes all allowlisted exported fields, including unrelated field changes so stale edits fail safely.

Headers: Content-Type application/json; X-Mapa-Timestamp (Unix seconds); X-Mapa-Nonce (32 random hex characters); X-Mapa-Signature from `SourceCommands::signature(secret,timestamp,nonce,exactJsonBody)`. Only POST with no query is accepted, max 8 KiB, within 30 seconds. Response HMAC is `SourceCommands::responseSignature(secret,nonce,exactResponseBody)`. Use a command-specific secret, separate from read sync. Keep the fixed destination server-side: `https://asrhealth.in/mapa-source-command.php`; never accept an arbitrary URL.

## Explicit source deployment prerequisites

1. Approve the source-owner write scope and each actor grant. The existing source application has module edit permissions, coordinator fleet category restrictions and PHC-scoped selectors. Reproduce the approved effective scope in private `actors`; a MAPA administrator is not implicitly an ASR editor. Each entry keyed by MAPA UUID requires actions, vehicle_types (exact source category names), and phc_ids. Both current and destination PHCs must be permitted. `"*"` is only for explicitly approved global editors. Unknown/revoked actors fail closed. Original source-user permission changes must also update these grants; this module does not read original login sessions.
2. Back up the source and confirm all referenced tables are InnoDB. Install the ledger once using an administrator migration outside HTTP handling. No request creates or modifies schema.

```sql
CREATE TABLE mapa_source_commands (
  request_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
  request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  actor_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  action_name VARCHAR(40) NOT NULL,
  record_id VARCHAR(64) NOT NULL,
  expected_revision CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  receipt JSON NULL,
  changes_json JSON NULL,
  created_at DATETIME(6) NOT NULL,
  completed_at DATETIME(6) NULL
) ENGINE=InnoDB;
```

3. Create a separate restricted database account. Grant SELECT on ambulances, amb_drivers, phc, institute_type and type_ambulance; UPDATE only ambulances(vehicle_model,model_year,phc_id,institute_type_id) and amb_drivers(phc_id); SELECT, INSERT and UPDATE only on mapa_source_commands. No DELETE, DDL, patient or trip privileges. Keep the existing exporter SELECT-only account unchanged. The module checks transaction support and uses row locks, atomic updates and ledger commit.
4. Install SourceSync.php, SourceCommands.php and source-command.php as mapa-source-command.php without including config.php/self_heal scripts. Put private JSON config outside public_html, restrictive permissions, and set MAPA_SOURCE_COMMAND_CONFIG. Enable only after schema, grants, HTTPS, source scope and integration tests pass. Do not put secrets in browser, source archive, source control or logs.
5. Protect and retain ledger audit records according to approved retention rules. Receipt stores actor/action/time, original ID, before/after changed fields and a request hash, not full patient or driver records. Keep idempotency records for at least the maximum supported retry period; an old request must not be deliberately replayed after ledger pruning.

The source endpoint rejects permission failures (403), changed records or reused request IDs (409), absent records (404), invalid destinations/fields (422). Public errors reveal no SQL or credentials. Successful commands do not modify MAPA operational assignments: any operational mapping remains a separate reviewed action.
