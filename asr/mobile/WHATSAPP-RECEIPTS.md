# MAPA WhatsApp receipt setup

The adapter distinguishes an accepted API request from a message delivered or read on the recipient phone. No provider account or real delivery has been tested yet.

Before enabling, apply the mobile migrations, configure the approved template, registered phone ID, API version and system-user access token. Configure `MAPA_WHATSAPP_APP_SECRET` with the Meta app secret and `MAPA_WHATSAPP_VERIFY_TOKEN` with a separate random verification token (at least 20 characters each). Keep these values in server secret configuration, never the Android APK or public assets.

Register the permanent HTTPS callback URL `/mapa/mobile/v1/tracking/whatsapp/webhook` in Meta and subscribe the app to the WABA `messages` webhook. The GET challenge checks the verification token; POST uses HMAC-SHA256 over the exact raw bytes and checks the configured sender phone ID. Suppress query logging on this route, because Meta's verification handshake puts the verification token in the query string.

MAPA retains only the provider message ID, lifecycle status, provider timestamp and optional numeric failure code. It discards message bodies, contacts, recipient numbers and provider error descriptions. Unknown message IDs are acknowledged without writes. Duplicate and delayed events cannot regress delivered/read evidence. Random opaque correlation handles a webhook arriving before the send response, including a send timeout. It cannot authorize access to a tracking link.

The operator status endpoint is `GET /mapa/mobile/v1/tracking/calls/{id}/whatsapp`, with an administrator mobile session and company-scoped call authorization. Accepted/sent is not delivered. Failed is not delivered. Read implies delivered. Missing read receipts do not establish that a message was unread. A network timeout remains unknown, and MAPA never automatically retries that send.

Messages created before the receipt migration have no recorded sender scope or correlation; they are not backfilled by guessing. Do not use old attempts to claim verified delivery.

Acceptance test after external setup: send one explicitly authorized tracking-link template to a test recipient, confirm sender acceptance, recipient delivery and read when available, then verify the operator's displayed lifecycle. Check an offline recipient and an invalid recipient. Confirm the `biz_opaque_callback_data` field is returned by the selected API version. This integration must be tested with the actual Meta account before describing it as live delivery.

References verified on 4 October 2026:

- Meta webhook handshake and signature documentation: https://whatsapp.github.io/WhatsApp-Nodejs-SDK/api-reference/webhooks/start/
- Meta webhook payload and status reference: https://www.postman.com/meta/whatsapp-business-platform/folder/vzaxn16/webhook-payload-reference
- Meta subscription requirements: https://www.postman.com/meta/whatsapp-business-platform/folder/ozgs3jn/webhook-subscriptions

The Meta SDK documentation is archived; MAPA uses its own small verifier, not the archived SDK. Direct current developers.facebook.com documentation returned HTTP 429 during this review. Provider-specific acceptance must still be verified against the configured API version.
