# Hardware upgrade outcomes

**Agreed source contract. Not released or activated.**
The existing v1 and v2 envelopes remain exact and unchanged. This adds
hardware findings through the signed workflow controller, outside the advice
chat bubble. It grants no command, continuation, purchase or deletion authority.

Milepost owns measured hardware constraints, original alert/device lineage,
compatible recommendations and authoritative device-contact linkage.
Safeharbor owns the same original ticket, immutable outcome receipts and a
durable notice linked to the normal mail queue and transports.

## Finding

Use schema version `3`, action `hardware_outcome`, the existing endpoint,
service identity, HMAC context, 16 KiB limit and duplicate-field rejection.
Keep these common flat fields: `schema_version`, `action`, `event_key`,
`workflow_key`, `tenant_slug`, `customer_id`, `alert_key`, `occurred_at`.
Add `expected_version`, `finding_key`, `issue_key`, `decision_revision`,
`device_id`, `device_binding_key`, `evidence_sha256`, `measured_at`,
`constraint_code`, `summary` and `upgrade_options`.

UUIDs are lowercase UUIDv4; hashes are lowercase 64-hex. Versions, device IDs
and revisions are positive JSON integers. Constraint codes are `storage_capacity`,
`memory_capacity`, `hardware_failure` and `unsupported_hardware`. Summary/options
are nonempty UTF-8 text of at most 2,000/4,000 characters. Dates use exact UTC
`YYYY-MM-DDTHH:MM:SSZ`; `measured_at` is no later than `occurred_at`, which is at
most 24 hours old or 30 seconds ahead. After that replay window, recover the
original receipt through fresh status rather than changing frozen bytes.

The producer must persist a stable issue identity for the original workflow
and exact device. A decision starts at revision 1; a new revision explicitly
identifies a materially changed hardware recommendation or decision. Rephrased
text, fresh measurement times or different evidence bytes alone do not create
a new decision. The receiver stores every accepted measured finding but
creates only one ticket note and notice per issue/decision revision. Exact
raw-byte event replay returns its immutable receipt. Reusing a finding or event
identity with changed facts conflicts.

The receiver checks the active tenant/customer/client binding and the original
unmerged, unresolved ticket and workflow. A new material outcome appends a
system note with the measured constraint and practical supported options.
It preserves status, assignee, all attempts, grants and prior history. A working
workflow stops at `needs_human`; an existing `needs_human` workflow advances its
version once so an earlier pending grant becomes stale. `human_owned` remains
human owned. An unchanged decision or retry does not keep advancing versions.
The separate outcome is `needs_hardware_upgrade`, never repair or recovery.

`hardware_status` accepts exactly the eight common fields plus `finding_key`
and writes nothing. The [machine fixture](contracts/westy-workflow-hardware-v3.json)
lists the exact 21-field outcome and 16-field status responses. Original receipt
facts remain separate from current workflow version, ownership, ticket and
notice state. Object-member order does not change those facts; the request
SHA256 covers exact raw bytes. `receipt_id` equals `event_key`. Exact replay
changes only returned `replayed` to true. A new finding for the same decision
gets its own receipt with `deduplicated=true`, sharing the existing notice.

A new material outcome with stale version returns HTTP 409 `version_conflict`.
Missing status findings return `hardware_finding_not_found`. Changed event/finding
facts return `event_key_conflict`/`finding_key_conflict`; skipped revisions return
`decision_revision_conflict`; changed issue/device lineage returns
`hardware_issue_scope_conflict`. Unavailable server/schema returns HTTP 503
`workflow_v3_unavailable`. V1 and V2 retain their existing shapes and behavior.

## Current device contact

The fixed resolver is
`https://support.8westit.com/api/svc/westy_hardware_contact.php`, using the existing
workflow controller secret. Request headers are `X-8W-Service: safeharbor-workflow`,
`X-8W-Timestamp` and `X-8W-Signature`. HMAC-SHA256 signs this exact preimage:

```text
milepost-westy-hardware-contact-v1\nPOST\n/api/svc/westy_hardware_contact.php\n{timestamp}\n{exact raw JSON bytes}
```

The flat request has exactly `schema_version=1`, `tenant_slug`, `customer_id`,
`workflow_key`, `alert_key`, `ticket_id`, `device_id`, `device_binding_key`,
`finding_key`, `issue_key`, `decision_revision` and a random 32-hex `request_key`.
The binding digest is SHA256 of canonical ordered JSON array
`[context, tenant_id, tenant_slug, client_id, customer_id, agent_id, agent_uid,
workflow_key, alert_id, ticket_id]`, with context
`milepost-westy-device-binding-v1` and PHP `JSON_UNESCAPED_SLASHES` encoding.
Milepost recomputes current lineage;
Safeharbor does not infer it from a hostname or local client number.

The exact 19-field response echoes all twelve request fields and adds `ok=true`,
`contact_state`, `contact_reference`, `contact_revision`, `email`, `resolved_at`
and `expires_at`. Response service is `milepost-workflow`, signing
`milepost-westy-hardware-contact-v1-response\n{timestamp}\n{exact raw JSON bytes}`.
Safeharbor verifies signature freshness, echoed nonce/scope and expiry. Responses
are at most 8 KiB; HTTPS certificate verification stays on, without redirects.

`present` requires a 32-hex contact reference, positive monotonic integer revision
and normalized person email. `absent`, `stale` and `ambiguous` require those three
values null. Expiry is exactly 30 seconds after resolution. An unavailable
resolver returns 503, never authoritative absence. Only the explicit per-device
customer contact selects a recipient; access grants or paired identities alone
do not designate one.

## Durable notice

The same transaction stores the finding, receipt, ticket note and one durable
notice intent. Its mail queue link stays null until a valid current recipient
is verified. Under the notice claim, atomically create and link the queue row
with that recipient and `max_attempts=0`: the legacy dispatcher cannot send or
retry it. The hardware dispatcher alone owns its claim and submission, using
the existing Graph, SMTP or PHP mail transport. An unresolved intent is not
described as an email already queued to a person.

Before submission, Milepost resolves the current exact device-linked contact
under the same tenant/customer/device/alert lineage. Do not infer a recipient from a ticket contact, billing
contact, staff account, device label, email domain or directory account.

An authoritative missing linkage records `no_device_contact` for a technician
once per notice and calls no provider. Resolver failure, stale evidence or ambiguous linkage
records a separate pending/failed verification result; it is not proof that no
email exists. Freeze the validated current recipient and durable attempt before
the provider call. Recheck the active local tenant/client/ticket binding at the
send boundary. Keep the recipient provenance with the attempt.

Queued, failed before submission, provider accepted, uncertain and delivered
are different facts. Provider acceptance does not prove delivery. A lost
provider reply or a crashed sender cannot authorize blind replay. Only a known
not-submitted or explicit rejection may retry with current recipient validation.
Unknown history for this notice does not block unrelated authorized work.

Established non-submission may retry up to five times with square-minute backoff;
each retry verifies the current recipient and retains prior attempts. Resolver
failures retry after one minute without repeated ticket notes. An interrupted
verification claim can be reclaimed after 30 seconds. A submission claim never
replays; after 120 seconds without a result it becomes uncertain. After committing
its attempt, the sender reacquires and holds the local tenant/customer/ticket
locks through the provider call. Hardware callers do not fall through to another
transport after possible submission; ordinary callers retain prior behavior.

Notice states are `pending`, `verifying`, `verification_failed`, `no_device_contact`,
`queued`, `sending`, `failed`, `uncertain`, `provider_accepted`, `superseded` and
`suppressed`. A new material decision supersedes earlier unsent intents while
accepted and uncertain history stays intact. There is no delivery-confirmation
source here, so no state is labeled delivered.

## Migration and rollback

`037_westy_hardware_outcomes.sql` follows existing 001, 025 and 036 migrations.
It adds four tenant-bound tables and immutable finding/receipt/attempt guards;
the fresh schema carries the same objects. Existing workflow enums, receipts,
grants, privileges, configuration and schedules are unchanged. The established
mail dispatcher processes these intents; there is no second sender or cron.

The release owner uses `bash deploy/deploy.sh --migrate 037_westy_hardware_outcomes.sql`
with normal backups, source/CI gates and release locks. Ship the receiver before
the producer. The contact resolver must be installed before recipient selection;
outage stays verification failure. Source rollback retains all four tables,
receipts and held queue rows. Old dispatchers skip `max_attempts=0` rows. Never
drop history or replay uncertain submissions as part of rollback.

Synthetic coverage includes actual concurrent MySQL arrivals/replays, rollback,
semantic deduplication, human ownership and grant preservation, signed contact
scope/freshness, absent/ambiguous/unavailable recipients, binding/claim races,
provider uncertainty, Graph lost replies and loopback SMTP disconnects before
final acceptance and after 250. Legacy workflow and support-mail regressions
pass. Full PR CI and lead review remain separate release gates.

## Delivery boundaries

Only source, synthetic tests and an open PR are authorized for this worker.
The Safeharbor Sergeant owns documentation review, normal integration,
migration/release and actual ticket/email verification, serialized with the
Milepost release lead. No production mail, device action, purchase or file
deletion is established by this document or synthetic tests.
