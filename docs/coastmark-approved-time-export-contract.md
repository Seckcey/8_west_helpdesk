# Safeharbor approved time to Coastmark draft lines

**Status:** version 2 is deployed dark and empty. The correction-safe version-3
sender is a local, isolated development slice based on reviewed Safeharbor PR
#84 head `6eba809d54c0e6f9cf647bbc0967ed7368fb8abc`. It has not been pushed,
merged, migrated, deployed, enabled, mapped, or called in production.
That stack is a hard merge-order dependency: land and revalidate PR #84 before
the version-3 commit. Do not transplant only the version-3 files onto `main`,
because correction claims depend on PR #84's append-only adjustment evidence.

This is the suite's only planned financial seam. Safeharbor owns approved time,
correction history, export claims, and delivery receipts. Coastmark owns the
customer/agreement mapping, rate, tax, payment terms, draft invoice, and every
later financial decision. Safeharbor can request a **draft line only**. It can
never approve, post, send, collect payment, create Checkout, create a credit,
or write a journal or ledger entry.

## Live version-2 boundary remains dark

Safeharbor version 2 is live at
`a42872be1f9cd76ebb0a3b2bad91c16f2c0bb0ff`; Coastmark version 2 is live at
`c3c553b437563dc7af00212215f532ee1518ba94`. Both global gates are false.
Production has zero Coastmark mappings, imports, or Safeharbor source lines.
The protected handshake prerequisites exist, but no time entry was approved or
sent and no financial action occurred. The permanent 8 West IT master customer
`4ebaeefa-b101-47f8-ac76-e49ab309d272` remains forbidden.

Version 2 cannot describe an approved-time correction and is not a canary path.
The detached `coastmark_time_export_send()` compatibility boundary remains
permanently refused. Version 3 uses only a durable claim ID.

## Version-3 source event

The canonical event fields, in order, are:

```text
version, event, event_key, predecessor_event_key, source_version,
tenant_key, client_key, entry_key, entry_id, ticket_id, entry_source,
technician_key, worked_at, minutes, note_sha256, billable,
approval_status, approved_at, reviewer_key, adjusted_at,
adjusted_by_key, adjustment_reason_sha256
```

Rules:

- `version=3`. Source version `0` uses
  `event=safeharbor.time_entry.approved` and has no predecessor or adjustment
  fields. A later version uses `event=safeharbor.time_entry.adjusted`, names the
  exact previous event key, and includes the exact immutable adjustment facts.
- `event_key` is a random 128-bit lowercase hexadecimal identifier with the
  `safeharbor-time:` prefix. It identifies one immutable payload; reusing it
  with changed facts is a conflict.
- `tenant_key` is the immutable Safeharbor tenant slug. `client_key` is the
  active Milepost binding's canonical
  `milepost-customer:<UUIDv4>`, never a local row ID, name, email, domain, or
  fuzzy match.
- The exact 8 West IT master customer is hard-blocked in claim code, sender
  validation, Coastmark receiver code, and Coastmark's PostgreSQL constraint.
- Raw time notes, correction reasons, customer contacts, rates, tax, payment
  terms, invoice commands, and secrets never cross the seam. Only lowercase
  SHA-256 digests represent note and reason text.
- Approved facts use whole-second UTC and exact persisted Safeharbor IDs. A
  pending/rejected entry, inactive customer binding, missing reviewer, malformed
  chain, or unapproved correction cannot be claimed.

## Durable claim

Migration `021_coastmark_time_export_v3.sql` adds two append-only tables:

- `coastmark_time_export_claims` stores one canonical payload and digest for one
  source entry/version; and
- `coastmark_time_export_receipts` records every explicit dispatch or status
  attempt and its bounded acknowledgement evidence.

Claim creation is a short MySQL transaction. The least-privilege CLI performs
ordinary reads; migration-owned triggers take the consistent exclusive lock
order immediately before insert: tenant, time entry, actor, then
claim/adjustment evidence. Adjustment creation uses that same prefix, so an
adjustment and a claim cannot pass each other without granting the CLI update,
delete, or table-lock authority.
The claim captures only the next exact source version. An exact repeat returns
the existing claim; changed facts or a broken predecessor chain fail closed.
Database keys enforce one claim per tenant/entry/version and one event key.
Triggers make claims and receipts immutable and permanent. The runtime role
is not used. This operator CLI requires a separate local MySQL identity: source
tables are `SELECT` only and claim/receipt tables are `SELECT, INSERT` only.
It receives no update, delete, DDL, trigger, reference, or grant authority.
Code refuses a dedicated username that matches the configured web/cron user.

Claiming and sending are two separate operator actions and two separate gates.
Both `claim_enabled` and `enabled` default to false.

```bash
php app/db/export_approved_time.php \
  --claim \
  --tenant-slug=8west \
  --entry-id=<exact approved entry id> \
  --entry-key=<exact immutable entry key> \
  --actor-user-id=<active owner/admin id>

php app/db/export_approved_time.php --inspect-claim --claim-id=<claim id>
```

Output contains identifiers, version, state, and digests only. It never prints
notes, reasons, signatures, or secrets.

## Explicit delivery and acknowledgement

The operator sends one exact claim ID. There is no list selection, queue, cron,
batch, scheduler, loop, or automatic retry.

```bash
php app/db/export_approved_time.php --send --claim-id=<claim id>
```

Before transport, Safeharbor appends a `dispatching` receipt. A matching 201
acknowledges a new Coastmark event. A matching 200 is an exact replay and is
also terminal success. An acknowledgement is trusted only when its event key,
payload hash, Coastmark event ID, disposition, and draft identifiers match the
claim's rules. Coastmark's `manual_exception` acknowledgement deliberately has
no line ID.

A changed-fact 409 is terminal conflict and requires a human. A timeout,
connection error, server error, malformed response, or mismatched
acknowledgement is `ambiguous`: Coastmark may have committed, so Safeharbor
blocks another send until an explicit signed status check resolves it.

```bash
php app/db/export_approved_time.php --status --claim-id=<claim id>
```

Status sends only the service/version/tenant/event key/payload hash. Exact
status is terminal success. Conflict requires a human. A signed 404 records
`absent` and permits a later **explicit** resend; it does not retry by itself.
An ambiguous status remains blocked.

## Coastmark correction behavior

Coastmark stores every accepted source version as an immutable event. It
prices the original from its enabled customer/agreement/time-line mapping and
snapshots its own minutes-per-unit, rate, and tax facts.

- The original approval creates one positive `safeharbor_time` line on a
  dedicated draft.
- A correction must be the next exact version on the same mapping, customer,
  entry, and predecessor chain. Coastmark prices it from the original snapshot,
  never today's mutable rate.
- While that invoice is still draft, Coastmark creates one immutable signed
  `safeharbor_time_adjustment` line for only the difference. A full reversal is
  a negative line; no prior event or line is deleted or rewritten.
- If the invoice is approved, sent, void, or otherwise non-draft, Coastmark
  records an immutable `manual_exception` event and changes no line or total.
  A human decides the later accounting treatment.

Exact replay is a no-op; changed facts conflict. No receiver path posts, sends,
pays, creates Checkout, creates a credit, writes a ledger/journal, or invokes a
billing batch.

## Release and canary gates

Keep both version-3 gates false and every Coastmark mapping disabled until all
of these are true:

1. Exact Safeharbor and Coastmark commits pass their complete repository suites,
   including disposable MySQL and PostgreSQL fresh/upgrade/replay, locking/race,
   timeout-after-commit recovery, immutable evidence, reserved-master,
   signed-negative-line, and least-privilege tests.
   Safeharbor PR #84 must land and be revalidated before its stacked version-3
   commit is considered for merge.
2. Both repositories have reviewed migrations, verified backups/rollback
   evidence, protected configuration, and exact release authorization.
3. The approved test-only 8 West Lifestyle rule is `$145.00/hour`, exact
   approved minutes with no minimum or round-up, `Net 30`, and `0%` tax only for
   a separately itemized pure technician-labor line. Hardware, software,
   licenses, parts, and bundled items remain excluded and manual. Coastmark must
   own and display these facts before the disabled mapping is enabled; this
   Safeharbor branch stores none of them.
4. Only the exact Lifestyle UUID
   `f22fc65c-70ca-439e-b703-f85c82da885d` is used for the controlled canary.
   The master MSP UUID remains forbidden.

Canary proof must cover original delivery, exact replay, changed-fact conflict,
one correction, full reversal, ambiguous-delivery status recovery, and a
non-draft manual exception. Financial aggregates must prove no invoice approval,
send, posting, journal, payment, Checkout, credit, or batch action.

If any check fails, turn off both gates and the mapping. Preserve every claim,
receipt, Coastmark event, and draft line. Use a forward fix; do not delete
evidence or run either migration down after evidence exists.
