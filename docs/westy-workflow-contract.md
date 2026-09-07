# Westy alert-to-ticket workflow

This is a release candidate built on September 7, 2026. It is **not enabled or
deployed** by this change. Existing alert intake, support intake, reporting,
portal, and approved-time billing keep their own contracts and gates.

Milepost owns alerts, agent jobs, device authorization and dangerous-command
approval. Safeharbor owns customer-bound tickets, assignment, human takeover,
workflow evidence and closure. Coastmark owns agreements, rates, draft invoices
and approved delivery. These services use their own databases.

## Service contract

`POST /api/svc/westy_workflow.php` uses a separate exact registered service
`milepost-workflow`, active in the signed provider tenant. It requires all of
`westy_workflow.enabled=true`, a dedicated secret of at least 32 bytes, an exact
tenant-slug allowlist, and an exact permanent customer UUID allowlist. The
customer must have an active `suite_customer_sync_bindings` row in that tenant.
There is no tenant-1, email, domain, or customer-name fallback.

Headers are `X-8W-Service`, `X-8W-Timestamp` (Unix seconds) and
`X-8W-Signature` (lowercase hex). The signature is HMAC-SHA256 of:

```text
safeharbor-westy-workflow-v1\n{timestamp}\n{exact raw JSON bytes}
```

The signature is valid for 300 seconds. Bodies are at most 16,384 bytes. JSON
is a flat object with exactly the fields in
[the fixture](contracts/westy-workflow-v1.json); duplicate members are rejected.
UUIDs are lowercase UUIDv4, timestamps exact `YYYY-MM-DDTHH:MM:SSZ` UTC.
`occurred_at` may be at most 24 hours old or 30 seconds ahead. Freeze the body
and event UUID before sending. A retry refreshes the transport timestamp and
signature only. Never reuse an event key for changed facts.

| Action | Expected version | Result |
|---|---|---|
| `claim` | 0 | Find or create one exact `alert:<numeric-id>` ticket for the active bound customer, put it In Progress, and give Westy workflow ownership. |
| `progress` | Current version | Append internal system evidence and advance one version. No command runs. |
| `escalate` | Current version | Stop automation, reopen for triage and optionally assign an exact active Safeharbor technician in the same tenant. |
| `resolve` | Current version | Close only a still-owned ticket after independent job and alert-recovery evidence passes. |
| `status` | Ignored | Return the current state without changing versions or writing a receipt. |

Every operation includes the stable workflow UUID, customer UUID, provider
tenant slug, original alert key, and unique event UUID. All nullable fields
remain present. `assignee_id` is allowed only on escalation. The six
verification fields are null on non-resolution requests. A resolution requires
`verification_method=agent_job_and_alert_recovery`, a positive job ID, a 64-hex
evidence digest, job completion at or after this workflow began, and recovery
of the original alert at or after that job completed. Recovery must be within
15 minutes before `occurred_at`.

**The signed controller is the evidence authority.** Milepost must construct
these facts by checking its own persisted successful job, endpoint and alert
lineage and later agent telemetry. A model sentence, zero exit code alone,
unrelated alert, stale reading, or approval request is insufficient. The
Safeharbor chat bubble cannot sign this service or grant a command approval.
The secret belongs only to the server controller, never a model tool argument,
browser, prompt, or agent endpoint.

The response includes `ok`, `action`, `ticket_id`, `workflow_key`, `version`,
`state`, `ticket_url`, `receipt_id`, `replayed`, plus echoed `event_key`,
`tenant_slug`, `customer_id`, and `alert_key`. Write receipt IDs are their stable
event UUID; status has a null receipt ID. States are `working`, `needs_human`,
`resolved`, and `human_owned`. A replay returns the original immutable result,
which is historical evidence. **Read fresh status before the next command**;
an old successful claim receipt does not mean the ticket is still owned.

404 means disabled, 401 unauthorized, 405 wrong method, 422 invalid facts,
409 conflicting ownership/version/event/scope, and 503 unavailable schema or
server. Stop on ownership conflict. Resolve an ambiguous response through a
fresh status request or exact frozen replay; never issue a second event key to
conceal an uncertain first result.

## Ownership and user experience

Existing alerts may be claimed only when still Open, exactly bound to the same
customer, never assigned, merged, touched, or worked, and still eligible for
the old one-use automatic closure. A mismatched historical catch-all ticket
requires human triage. Claiming consumes that old capability atomically, so the
legacy source-recovery emitter cannot close a Westy-owned ticket.

Human ticket writes, replies, internal notes, message edits/moves/deletes and
time changes revoke active automation at the database boundary. Changing a
field and changing it back does not restore ownership. A controller request
locks the tenant, binding, ticket, and run before it checks authority. Its own
ticket transition and receipt commit together; a later human change wins before
the next request. No session variable bypasses these guards.

The ticket card explains who owns the outcome, shows the latest findings, opens
Milepost's real troubleshooting/approval page and offers **Take over ticket**.
Takeover uses the existing authenticated and CSRF-protected assignment action;
the card updates only after that action succeeds and preserves the draft.
Evidence is internal system history, not a client reply, billable time, or an
invoice. The composer now says when no client contact exists and retains that
truth when switching between Reply and Internal note.

## Billing boundary

Existing approved-time v3 claim/send/status remains the supported billing
source. Verified closure atomically creates one `westy_billing_outbox` row in
`waiting_for_time`. It creates neither time nor money and sends no mail.
The separate default-off `westy_billing_handoff` configuration controls the
actual CLI worker `app/cron/westy_billing_dispatch.php`.

The worker selects the oldest due rows, at most ten per invocation. It requires
the exact still-closed ticket/customer, approved original billable time and
each entry's latest adjustment version with an accepted or replayed Coastmark
v3 import receipt. Pending time, missing exports and ambiguous deliveries wait
for review. Reopened tickets, changed customer lineage or source facts changed
after a payload was frozen become blocked exceptions. Post-closure time review
may transfer workflow ownership to a technician without erasing the original
closure evidence; billing still requires the ticket's exact unchanged closure
timestamp. Never invent duration, technician, agreement, rate or recipient.

The payload is pinned once, with deterministic `safeharbor-billing:<32hex>`
event key for that tenant/run, before any network request. It posts the agreed
`safeharbor.ticket.billing_requested` v1 contract to the exact HTTPS
`https://coastmark.8westit.com/api/integrations/safeharbor/billing-handoffs`,
using dedicated `safeharbor-billing` identity/key and HMAC-SHA256 of
`timestamp + "\n" + exact_body`. This is not the time exporter secret. The
body references current accepted `time_event_keys`, UUID `run_key`, closure
timestamps and verification digest, and contains no financial amounts or
client message text.

Timeouts, lost responses and server failures retain the exact body for replay
with bounded exponential backoff and an eight-attempt ceiling. A two-minute
lease contains overlapping/crashed workers. A new source version after a frozen
claim blocks delivery rather than replacing its body. Exact receiver replay
reconciles a lost acknowledgment. Accepted receipts cannot be changed or sent
again. An accepted handoff means **Coastmark invoice review is ready**; it is
not posting, mail acceptance, or inbox delivery. The ticket card shows billing
state and links to the canonical invoice review after verified acknowledgment.

## Migration and release gate

Migration **025 is migration-first** and adds three tables, thirteen guards
and a read-only definer health view.
It creates no user, service identity, ticket, customer, configuration or invoice.
The canonical schema includes the same SQL. Replay preserves rows and receipts.
Before activation, the receiver checks that the thirteen guards and enforced
resolution/version constraints are present; a partial migration fails closed.
The view exposes one readiness bit under the operator definer so the runtime
keeps only DML grants. Direct trigger metadata inspection would require
[MySQL TRIGGER privilege](https://dev.mysql.com/doc/mysql-infoschema-excerpt/8.0/en/information-schema-triggers-table.html).

Use the existing Safeharbor runbook: exact green default-branch SHA; verified
root-only application/config/trigger-inclusive database/grants backup and scratch
restore; Safeharbor-only endpoint/database write freeze; migration replay and
postflight; matching source deploy; restore existing report scheduler and
protected gates. Do not run this migration with the shared web runtime account.
It needs the operator's reviewed database privileges. Leave both producer and
receiver disabled until the exact customer and device canary is approved.

Rollback disables the producer and receiver first, preserves both tables and
all receipts, and restores the prior application. Never re-enable legacy
auto-close eligibility for any ticket consumed by this workflow. No shared
Apache restart, source migration, mail send, invoice send, or production canary
is part of this source change.

Validation: `php app/tests/westy_workflow_test.php` and the disposable MySQL
`westy_workflow_mysql_test.php` cover contextual auth, malformed/duplicate JSON,
tenant/customer isolation, exact retries, version races, recovery boundaries,
human takeover including same-second revert, escalation, immutable history,
migration replay and missing guards. Existing full PHP/MySQL and browser
contracts remain required before release.

`php app/tests/westy_billing_test.php` proves that pending/missing/ambiguous
time never leaves Safeharbor, an uncertain handoff retries identical bytes,
changed source versions become exceptions, reopened/inactive customers fail
closed, and only the exact Coastmark invoice review destination is accepted.
The MySQL suite also runs the actual sender under a DML-only identity using
an injected response and proves payload/accepted-receipt immutability.
