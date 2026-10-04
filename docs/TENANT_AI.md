# MSP-owned Westy AI in Safeharbor

Safeharbor resolves AI configuration from the customer's provider MSP in 8 West ID. The MSP owns the API key, chooses provider/model/effort and pays usage. Managed customers do not configure a separate AI account. Normal ticket forms, manual support and existing device diagnostics/repair remain available without AI or a connected desktop companion.

`tenant_ai.enabled` ships false, with an empty `service_secret` and null internal binding IDs. Configure the exact reviewed app-local/canonical MSP binding and dedicated HMAC secret only through the accepted release plan. `internal_tenant_id` and `internal_local_tenant_key` must both match before the explicitly preserved internal legacy path is eligible. Unknown tenants, missing bindings and revoked access never use a shared fallback key.

## Chat, usage and replay

The portal checks current customer identity and provider binding before a turn, before each provider/tool round, before saving/emitting streamed output and again at final delivery. Provider/model/effort, catalog, AI revision and credential version must remain consistent. Once an attempt observes an AI connection change or authority outage, it suppresses further output even if the connection returns. Text already delivered with valid authority remains available. Final accounting retains known usage or the unknown reservation; it does not retry the request.

Each turn reserves a bounded amount before network I/O. `portal_westy_ai_attempts` records the attempt sequence, selected provider/model/catalog/revisions, usage receipts and reserved/charged microdollars. There are at most five rounds. Raw desktop images, accessibility text, credentials and provider continuation payloads stay out of durable chat/receipt storage. Tool operations retain their existing identity, device, approval and idempotency boundaries.

New operation IDs use `f1` + an eight-character hexadecimal Unix timestamp + 22 random hexadecimal characters. They remain 32 characters long. A new ID may be at most 60 seconds in the future and must be less than 90 days old. This timestamp grants no identity or action authority. Exact existing retries are reconciled; changed requests and expired IDs cannot become new billable turns after metadata removal.

Ordinary chat does not load a desktop session or require companion configuration. A separate `desktop_resume` must bind the same customer, conversation, operation and authorized native task. Missing native files/schema/configuration or a disconnected/unavailable capability yields a typed unavailable result without a provider retry. Publishing new native packages and accepting the Windows UI remain separate release gates.

## Retention and database permissions

`portal_westy_maintenance.php` clears expired content under the existing exact-scope/advisory-lock rules. A pending AI attempt is retained while live; after 180 seconds, an erased/expired parent can finalize it as unavailable with unknown usage while preserving the full reservation. Completed attempt metadata can be deleted only after its parent is expired, content-free and older than **90 days plus 60 seconds**. Deletion is bounded to 1,000 attempt rows per pass, before parent cleanup. Immutable ticket handoff receipts remain protected.

The reviewed runtime posture keeps schema-wide SELECT/INSERT/UPDATE and the existing table-specific DELETE grants. The only additional DELETE target for this feature is `portal_westy_ai_attempts`. The complete tested DELETE allowlist is:

`canned_responses`, `clients`, `contacts`, `email_threads`, `messages`, `portal_westy_accounts`, `portal_westy_budgets`, `portal_westy_drafts`, `portal_westy_turns`, `svc_rate_buckets`, `svc_support_rate`, `tickets`, `portal_westy_ai_attempts`.

Do not grant schema-wide DELETE. The restricted-account fixture verifies real denial for `time_entries`, `business_report_archives`, `customer_portal_bindings` and `tenants`, and verifies the new receipt trigger's boundary at 90 days +59/+60/+61 seconds. Runtime grant changes require the release owner's exact reviewed plan; the release helper itself never changes grants.

## Onboarding and release

The workspace receipt adds the v1 app-local AI binding key. The privileged ID receiver verifies the current manifest and registers that immutable mapping. Failure to enroll AI does not undo the normal Safeharbor workspace.

The PHP SDK is vendored from `Seckcey/8_west_westy` commit `3003a3c897edace2140de00f02e9279914ea1638`, catalog `2026-10-04.1`; hashes are in `tenant-ai-vendor-manifest.json`. Apply the additive `20261004_tenant_ai.sql` only through the [protected release procedure](TENANT_AI_RELEASE.md), with the portal-turn schema already present. The guarded schema catalog rejects incompatible existing tables, columns, indexes, foreign keys, checks, storage properties or triggers.

Validation includes the pure receipt fixture, restricted-runtime portal MySQL tests, suite SSO tests and exact-schema recovery fixtures. Tests use synthetic provider adapters and isolated databases; no paid calls, production grants, production schema or native packages are changed by this work. The coordinator owns final aggregate-source validation, merge, deployment and live acceptance.
