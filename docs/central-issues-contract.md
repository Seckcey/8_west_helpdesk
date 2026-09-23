# Central private issue history v1

Central owns the customer interface and current OIDC authorization. Safeharbor owns the durable issue and conversation history. This lane is independent of managed-service tickets: it has no assignee, technician queue, mail, SLA, time entry, invoice, controller job or repair dispatch. Existing Safeharbor customer and staff interfaces do not list these records.

## Scope and admission

The dedicated `central_issues` configuration is disabled by default. It binds one Safeharbor tenant and one independently verified Milepost provider tenant, with a new 64-hex caller secret. It does not reuse `svc`, support intake, customer synchronization, Westy workflow or mail credentials. A separately optional assistant secret admits future server-produced assistant turns; the customer caller cannot write them. No assistant key is required or installed for the initial history release.

An operator-managed `central_issue_accounts` row binds account UUID, immutable ID subject/tenant, provider tenant, customer UUID, customer registry binding, binding version and enabled flag. It must match Central's current account record and ID profile. The existing active `suite_customer_sync_bindings` row supplies the canonical local client; names or email never create ownership. The initial owner/customer choice already has an active registry binding to Safeharbor client 16 under tenant 1. Reverify it at admission.

Every operation rechecks this exact binding and the customer registry's active status inside its transaction. Queries filter tenant and account; issue/event UUIDs alone are never authority. Ownership is immutable: this release cannot transfer an account or its history to another identity. Disable the account and revoke prior Central authorization before any separately reviewed ownership change. Binding versions cannot regress. Disabling or customer deactivation denies reads, writes, replay and export. Central checks its own binding again before rendering a service response. This is final-check authorization, not distributed atomicity after a response has been delivered.

## Transport and replay

`POST /api/svc/central_issues.php` accepts JSON, at most 16 KiB, with an exact method/path and dedicated HMAC-SHA256 over `central.issues.v1`, caller, method, path, timestamp and the raw-body SHA-256. Timestamp tolerance is 60 seconds. TLS is required between the applications. The caller is exactly `central-web` or `central-ai`; each uses its own secret. Request and response logs never contain text, credentials or authorization headers.

Customer operations are `list`, `read`, `create`, `message`, `state` and `erase`. An assistant caller may only append a bounded assistant message to an existing open issue. It cannot create issues, change ownership/state, erase history, record diagnostics or claim a repair. Initial UI forms always select the customer caller/operation on the server; browser data cannot set actor authority.

Every mutation carries a UUID v4 operation key and exact expected issue version. Create begins at version 1. Subsequent accepted events advance by exactly one. The service locks account and issue rows, then checks its durable operation receipt before the expected version. An exact replay returns the original issue/version receipt without another event; reusing a key for a different payload is 409. A stale version is 409 and leaves all text/state unchanged. Read replays simply reauthorize and read current state; they cannot duplicate a mutation. Interrupted responses can be retried safely with the same key. A new key is never generated automatically after an uncertain write.

## Data and limits

Issue titles are plain text, 1–140 characters. Messages are plain text, 1–4,000 characters, with bounded UTF-8 bytes and no NUL/control characters except normal line breaks and tabs. All incoming text uses `utf8_clean()`. HTML is escaped on display; links, device labels and assistant output remain untrusted data. No attachments or executable markup are admitted.

The private service limits each account to 100 retained issues, 1,000 lifetime issue tombstones, 10 MiB retained message content and 30 new mutations per minute. Each issue has at most 200 ordinary events; content erasure remains available as event 201. Read, exact replay and erasure remain available at the mutation rate limit. List pages contain at most 20 issues. Export is at most one complete bounded issue. Those are pilot storage safeguards, not paid-plan allowances. Per-account locking makes caps and version changes consistent under concurrent requests. A cursor identifies an existing issue inside the same account; a foreign or unavailable cursor is refused. Request receipts use a separate stable secret digest key, so their text cannot be guessed from an unkeyed hash; rotating transport keys does not invalidate receipts.

Initial states are `open`, `unresolved`, `resolved` and `cancelled`. Only the account owner changes them, with an explicit reason. A resolved issue is labeled **customer-reported resolution**; it never counts as an AI repair or independently verified recovery. Closed issues can be reopened explicitly. Assistant messages cannot resolve an issue or create action authority. Later diagnostics, approvals and independently verified repair events require their own reviewed contracts.

An owner may erase an issue's title and message content through an explicit confirmation form. The service keeps only a content-free tombstone, event/operation identifiers, versions and keyed request digests for replay/audit. Erasure is irreversible and cannot be undone by replay or reopening. The first history release makes no commercial retention promise; paid launch must settle retention, legal exceptions and deletion policy. Export is available before erasure. No application or proxy log receives conversation text.

## Central experience

Activity shows private issues, their state and last update. The owner can start an issue, add a note, mark its outcome, reopen it, export JSON/plain text or erase it. Changes use CSRF protection and redirect after a successful write. Version conflicts and uncertain transport failures retain the submitted draft and operation key for a safe explicit retry. History reopens after a fresh sign-in. The UI explains that this preview saves issue history; live AI replies arrive in a later slice. No fake assistant answer, human escalation or ticket notification is generated.

## Release and evidence

Additive migration 029 and canonical fresh schema must match. Default-off code is releasable independently, but activation needs verified backups and scratch restore, the Safeharbor-only write freeze, exact green-main migration/replay/postflight, matching source deployment, dedicated service identity/configuration, and the independently matched account row. Preserve the report scheduler, mail, controller and billing activation scopes. Never stop shared Apache for this release.

After the final migration/replay, grant only `EXECUTE` on `safeharbor.central_issue_schema_health` to the existing Safeharbor runtime account. Recreating this function removes its prior routine grant. The runtime keeps its existing DML scope; it receives no schema/trigger privileges and no additional DELETE privilege. Health refuses requests if a history trigger, enforced check or foreign key is missing. Roll code back with this lane disabled and retain its additive tables, guards and durable receipts.

Test two customers under one provider and a second tenant, customer deactivation, changed/disabled binding, stale versions, exact/conflicting replay, concurrent writes/caps, malformed/oversized Unicode, assistant/customer separation, erasure/replay, pagination and scoped export. Snapshot existing ticket/message/mail/time/billing counts around test operations to prove this lane creates no side effects. Then verify real owner history after ordinary OIDC/MFA sign-in, desktop/phone/keyboard operation and safe failure messages. Synthetic assistant storage does not prove live model inference.
