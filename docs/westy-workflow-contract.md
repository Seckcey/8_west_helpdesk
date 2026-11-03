# Westy alert-to-ticket workflow

This document defines the original v1 controller contract and the prepared v2
assignee handback extension. The original September release evidence is retained
in [the workflow release packet](westy-workflow-release-packet.md).

**The v2 extension is in source/test preparation.** Its production migration,
application release and any particular ticket's return to Westy are separate
decisions. This document does not establish that they happened. Existing alert
intake, support intake, reporting, portal and approved-time billing keep their
own contracts and gates.

Milepost owns alerts, agent jobs, device authorization and dangerous-command
approval. Safeharbor owns customer-bound tickets, assignment, human takeover,
workflow evidence and closure. Coastmark owns agreements, rates, draft invoices
and approved delivery. These services use their own databases.

## Existing v1 service contract

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

## Prepared v2 assignee handback

The extension keeps one ticket and its original workflow identity, customer,
alert, start time and history. It supports only an unresolved, unmerged
`needs_human` case with a current active assignee and active customer binding.
It does not return a `human_owned` or previously closed workflow to automation.
The signed Milepost controller must establish that the prior commands covered
by a continuation were never dispatched; Safeharbor does not inspect the
producer's execution database or claim that evidence independently.

### The assignee's grant

The ticket card offers **Return to Westy** to the current assignee through the
normal signed-in, CSRF-protected application path. The action records an exact
one-use intent; it does not unassign the ticket, change its status or advance
the workflow version. It adds no confirmation dialog. It creates no command,
technician time, customer reply, financial record or mail.

`POST /api/westy_workflow_grant.php` accepts exactly `action`, `ticket_id`,
`workflow_key`, `expected_version` and `grant_key`. The action is `create` or
`cancel`; `ticket_id` and the original version are positive integers, and keys are
lowercase UUIDv4 values. For creation, `grant_key` is the local idempotency key.
The grant binds the authenticated actor and exact tenant, customer, client,
ticket, workflow, assignee and version. An exact retry returns the saved result;
changed facts under that key conflict.

The original active grant creator can cancel their own pending intent in the
same tenant, including after assignment or workflow version has changed.
Cancellation compares the request with the grant's original version, not the
ticket's newer version. It changes only that grant. Identical cancellation
retries return the saved result; a consumed grant cannot be cancelled or used
again. At most one pending grant belongs to an exact tenant/workflow. This rule
does not block other tickets, workflows, ordinary human work or unrelated jobs.

The existing takeover triggers invalidate the narrowed grant: a covered ticket,
non-system message or time-entry write starting in `needs_human` changes the
workflow to `human_owned` and advances its version. Changing a field back does
not restore the old state/version. A stale grant stays in history, can be
cancelled by its creator, is omitted from discovery and cannot be consumed.
No generic `human_owned` handback or broad revocation hooks are added.

### V2 service envelopes and discovery

V2 uses the same signed service endpoint, exact `milepost-workflow` identity,
HMAC context and raw-byte authentication described above. V1 requests and
responses retain their existing exact shapes and behavior. A missing or partial
v2 capability fails explicitly; a producer must not silently fall back to v1.
See [the v2 machine fixture](contracts/westy-workflow-v2.json).

Both v2 actions have exactly these fields:

`schema_version`, `action`, `event_key`, `workflow_key`, `tenant_slug`,
`customer_id`, `alert_key`, `expected_version`, `grant_key`, `continuation_key`,
`summary`, `occurred_at`.

The schema version is integer `2`. Identity, UUID, UTF-8, duplicate-member,
body-size and timestamp rules remain strict. No job, recovery or caller-supplied
assignee fields belong in this envelope.

| Action | Fixed or version-bound values | Effect |
|---|---|---|
| `status` | `expected_version=0`, both grant/continuation keys `null`, summary empty | Read current binding, ticket, eligible pending grant and accepted continuation; no writes or consumption. |
| `resume` | Current grant-bound version, exact grant UUID, fresh continuation UUID, nonempty summary | Consume the assignee's grant and atomically return the same case to Westy. |

The exact v2 status response contains `schema_version`, `ok`, `action`,
`event_key`, `workflow_key`, `tenant_slug`, `customer_id`, `alert_key`,
`ticket_id`, `ticket_url`, `version`, `state`, `receipt_id`, `replayed`,
`binding`, `ticket`, `pending_grant` and `accepted_continuation`.
It has `schema_version=2`, `action=status`, `receipt_id=null` and
`replayed=false`. Its request event key is a read identity, not a write receipt.

| Status member | Exact contents |
|---|---|
| `binding` | `client_id`, `status=active` |
| `ticket` | `client_id`, `status`, nullable `assignee_id`, `merged_into_id`, `resolved_at` |
| `pending_grant` | `null`, or `grant_key`, `assignee_id`, `expected_version`, `created_at` |
| `accepted_continuation` | `null`, or `grant_key`, `continuation_key`, `event_key`, `version`, `accepted_at`, `request_sha256`, `original_receipt` |

The pending grant is exposed only while unconsumed, uncancelled and still
matching the current assignee, version, `needs_human` state, unresolved/unmerged
ticket and active binding. Status does not modify stale grants. Binding or
scope conflicts return an error rather than a partially populated success.

### Atomic resume and immutable acceptance

Before sending `resume`, Milepost durably freezes only a **non-executing**
continuation intent, its event/grant/continuation UUIDs, exact request bytes and
request hash. A fresh active diagnostic session must not exist yet: an ordinary
worker could otherwise discover and dispatch it before acceptance.

The receiver checks the exact grant, current assignee, active actor/binding,
scope, state and version under the shared lock order:
tenant, service identity when applicable, customer binding, ticket, workflow,
actor/assignee, grant. Creation, cancellation, discovery and consumption
serialize through the workflow row; historical grant reads use deterministic
ordering.

Consumption returns the same ticket to In Progress, clears its assignment as
explicitly requested, leaves legacy auto-close eligibility zero and makes the
same workflow `working` at exactly `expected_version + 1`. The transaction
accounts for its own ticket takeover trigger, consumes the grant once, binds
the continuation/event/request hash, appends attributed system history and
stores an immutable `resume` receipt. Existing triggers are not disabled.

The exact resume success response contains `schema_version`, `ok`, `action`,
`event_key`, `workflow_key`, `tenant_slug`, `customer_id`, `alert_key`,
`ticket_id`, `ticket_url`, `version`, `state`, `receipt_id`, `replayed`,
`grant_key`, `continuation_key`, `accepted_at` and `request_sha256`.
Its action/state are `resume`/`working`; `receipt_id` equals the event UUID.
The hash covers the exact frozen resume request bytes. The original complete
response is stored with `replayed=false`; an exact direct replay changes only
the returned replay marker to `true`, under the existing authentication and
request-validity rules. A changed body conflicts, and a consumed grant cannot
authorize a different event or continuation.

V2 status reads the latest consumed grant's original receipt and association
from immutable storage, without reconstructing acceptance from current state.
The embedded original receipt remains `replayed=false`. Its association fields
must match that receipt. Historical acceptance remains visible after later
human takeover or escalation, and never authorizes execution by itself.

### Lost responses, local finalization and Stop

Milepost validates the original/replayed receipt, or the authoritative original
receipt embedded in fresh v2 status, against its saved request hash, fixed
identifiers, complete scope, ticket and expected resulting version. A fresh
status can recover that proof after a lost response, local commit failure or
expiration of the original resume request. It does not extend replay validity,
alter the frozen body or mint replacement resume identifiers.

Only validated acceptance plus fresh ownership, Stop, policy, binding and
actual execution checks may create a fresh active session. In its own local
transaction Milepost preserves an attributed prior-binding hash and creates
the fresh session/current-pointer association with compare-and-swap. The old
closed session, probes, jobs, actions and queue items remain unchanged. A local
commit with an uncertain outcome is reconciled against the same continuation
identity before another creation is attempted.

Before new execution, fresh status must match the exact binding, ticket,
accepted grant/continuation/event and accepted version. The current version may
be newer, but the current workflow must still be `working`, with In Progress
status and no assignee, merge or resolution. The local current session must
still belong to that continuation. A later `needs_human` or `human_owned`
state stops this continuation even though historical acceptance is visible.

If Stop arrives after remote acceptance and before local dispatch, preserve
the accepted association and stopped local intent; create no active session
and dispatch nothing. If fresh status still shows Westy ownership, use the
existing exact-version v1 `escalate` action with truthful stopped-before-dispatch
evidence. If a human already took ownership, respect it. Preserve an uncertain
escalation's frozen request for reconciliation; do not replace its key or
advance a version to bypass conflict. Old jobs/generations and unknown results
are never replayed or reclassified to manufacture a fresh continuation.

### Prepared migration and production boundary

The new artifact `036_westy_workflow_resume.sql` adds only required `resume`
receipt-enum support and dedicated one-use grant persistence/binding indexes.
The canonical fresh-install schema mirrors it. Migration 025 remains historical
and unchanged; there is no backfill, deletion of existing history or broad
takeover-trigger change.

Qualification must cover v1 compatibility, exact v2 fields/null shapes,
grant creation/cancellation/retry, human-write/version races, consumed-grant
reuse refusal, immutable receipt/hash readback, late recovery, and initial,
replayed and partial migration behavior using synthetic data and ordinary
runtime privileges. Container tests run on Coastline through its shared slot
and heavy-test mutex, one stack at a time. Required CI remains in force.

Production migration and matching receiver capability must precede v2 producer
publication. Rollback planning retains grant and receipt history; an old
application is not permission to remove a consumed grant, downgrade away a
stored `resume` receipt, replay old work or transfer a human assignment.
The exact production preflight, rollback, case handback, recipe choices and
endpoint actions remain in the final approval packet. Source/test preparation
alone establishes none of those outcomes.

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
again. The receiver returns HTTP 201 for creation and HTTP 200 for an exact replay.
An accepted handoff means **Coastmark invoice review is ready**; it is
not posting, mail acceptance, or inbox delivery. The ticket card shows billing
state and links to the canonical invoice review after verified acknowledgment.

## Migration and release gate

Migration **025 is migration-first** and adds three tables, thirteen guards
and a read-only definer health function.
It creates no user, service identity, ticket, customer, configuration or invoice.
The canonical schema includes the same SQL. Replay preserves rows and receipts.
Before activation, the receiver checks that the thirteen guards and enforced
resolution/version constraints are present; a partial migration fails closed.
The function exposes one readiness bit under the operator definer. The runtime
keeps its DML grants plus EXECUTE on this one function (not database-wide EXECUTE).
The operator must grant this specific permission after installing migration 025. Direct trigger metadata inspection would require
[MySQL TRIGGER privilege](https://dev.mysql.com/doc/mysql-infoschema-excerpt/8.0/en/information-schema-triggers-table.html).

Ship it with `bash deploy/deploy.sh --migrate app/db/migrations/025_westy_workflow.sql`.
The deploy script backs up the database first and applies the migration as the database
administrator while Safeharbor is stopped, never as the web runtime account. Then add the
EXECUTE grant above. Leave both producer and receiver disabled until the exact customer and
device canary is approved.

Rollback disables the producer and receiver first, preserves all three tables and
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
The MySQL suite also runs the actual sender under a DML identity with only
health-function EXECUTE added, using
an injected response and proves payload/accepted-receipt immutability.
