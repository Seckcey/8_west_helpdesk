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
source. Workflow completion creates neither time nor money and sends no mail.
The signed closure digest can support Coastmark's separate billing handoff,
but an actual handoff must reference current approved, imported time events
for this exact customer and ticket. Missing approved time must remain an
actionable billing review; do not invent duration, technician, agreement, rate,
recipient or delivery acceptance. Closing a ticket never implies an invoice
was sent.

## Migration and release gate

Migration **025 is migration-first** and adds two tables and eleven guards.
It creates no user, service identity, ticket, customer, configuration or invoice.
The canonical schema includes the same SQL. Replay preserves rows and receipts.
Before activation, the receiver checks that the eleven guards and enforced
resolution/version constraints are present; a partial migration fails closed.

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
