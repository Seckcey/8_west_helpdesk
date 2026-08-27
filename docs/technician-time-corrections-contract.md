# Technician time corrections and measured overlap contract

Status: implemented and tested on `codex/time-corrections-overlap`; migration
016 is not applied to production until the reviewed migration-first release.

## What this slice owns

Safeharbor remains the system of record for technician time, reviews, and audit
history. This slice adds two rules:

1. A rejected entry may have one append-only replacement. The rejected row and
   its logged/rejected events are never edited or deleted.
2. One technician cannot submit overlapping measured intervals inside one
   tenant while either interval is pending or approved.

It does not enable the Coastmark sender, create invoice lines, post invoices,
send invoices, create Checkout, record payments, or touch a ledger. It also
does not give AI permission to create, correct, review, or approve time.

## Correction invariants

- `time_entries.corrects_time_entry_id` is nullable and immutable.
- A non-null pointer must resolve inside the same tenant to a rejected entry.
- Replacement and rejected rows must have the same technician, ticket, client,
  and source provenance.
- `UNIQUE (tenant_id, corrects_time_entry_id)` permits one direct replacement.
- If that replacement is rejected, a later row may correct the replacement,
  producing a simple append-only chain rather than siblings.
- Every replacement begins pending. Existing owner/admin review rules apply,
  including owner self-review for a one-person MSP.
- The authenticated technician may correct only their own rejected entry. The
  API derives tenant, actor, ticket, client, and source from server-side facts.
- The entry key and every replacement fact participate in exact idempotent
  replay. Reusing a key with changed facts is a conflict.

The Time page displays the rejection reason, `Correct & resubmit`, the parent
entry number on a replacement, and the replacement's current status. Measured
corrections can edit start, end, and worked-at UTC evidence; minutes are derived
from the corrected interval. The browser freezes one new correction key and
exact JSON payload before its first request, so a lost response retries the
same row. A definitive validation/conflict response clears only that unsent
draft so the operator can edit it; uncertain transport/server failures retain
the exact key and payload.

## Measured overlap invariants

Measured intervals are positive, at most 24 hours, and treated as half-open:
`[started_at, ended_at)`. Therefore `10:00–11:00` and `11:00–12:00` may both
exist; `10:30–10:45` overlaps the first interval.

The conflict scope is exact `tenant_id + user_id`. Another technician or tenant
may work at the same clock time. Only pending and approved intervals block.
Rejected intervals remain in history but stop blocking, which lets their one
pending correction reuse corrected interval evidence.

`time_entry_interval_guards` holds one persistent row per covered UTC day and
technician. The BEFORE INSERT trigger creates and locks the one or two guard
rows in date order. It then performs a current locking read of
`time_entry_measured_intervals`. Every competing insert covering the same day
must wait on the same database row, so both requests cannot decide from stale
snapshots.

`time_entry_measured_intervals` is a database-owned mirror used only for that
locking read. Parent triggers create it and move its status during the same
transaction. Foreign keys, a positive-interval check, and no-update/no-delete
triggers keep its facts aligned with immutable `time_entries` rows.

## Migration-first release gate

Before deploying code that selects `corrects_time_entry_id`:

1. Fetch the reviewed default branch and record its exact commit.
2. Back up the Safeharbor database with triggers and the current application.
3. Verify migration 011's seven guards and capture current time/event counts.
4. Run migration `016_time_corrections_overlap.sql` with the trigger-capable
   operator. Its final row must report exact correction column/index/FK,
   auxiliary structure `1`, twelve permanent triggers, zero staging triggers,
   and equal measured parent/registry counts.
5. Re-run the migration once; counts and time facts must remain unchanged.
6. Deploy only the exact reviewed default-branch commit.
7. With a fresh signed-in 8 West IT session, create one measured pending canary,
   prove an overlapping request returns conflict, reject it with a reason,
   submit one correction, replay the same correction key, and approve it.
8. Confirm the original rejected row/events are byte-identical, the replacement
   is the only child, reports still select approved billable rows only, and the
   Coastmark sender remains disabled.

Migration 016 is additive. Application rollback may leave its nullable column,
auxiliary rows, and stronger database guards in place. A database restore is
disaster recovery, not the normal code rollback. During its seven-trigger
replacement, five temporary guards deny every time insert/update/delete and
audit update/delete. An interruption therefore fails closed; exact migration
replay restores all twelve permanent guards before those blockers are removed.
Five earlier temporary guards likewise protect direct auxiliary-table writes
while their permanent integrity triggers are replaced during a replay.
