# Technician time corrections and measured overlap contract

Status: merged through Safeharbor PR #55 / merge
`bb580a293f89deab278473e986c2e1232d2bc0c1` and deployed on 2026-08-27.
Migration 016 and the matching application source are live. The structural
release is complete; the fresh signed-in 8 West IT correction canary remains an
operator follow-up.

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

For any rebuild or replay before deploying code that selects
`corrects_time_entry_id`:

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

Production completed steps 1–6 on 2026-08-27. Steps 7–8 remain deliberately
open: no credentials or identity bypass were used to manufacture an
authenticated acceptance session.

## Production release evidence — 2026-08-27

- Exact source: merge
  `bb580a293f89deab278473e986c2e1232d2bc0c1`; exact-main Validate run
  `33057438562` passed, including the real two-connection overlap race.
- The fresh root-only rollback record is
  `/srv/8west/backups/safeharbor/20260827T092220Z-pre-time-corrections`.
  Its verified `SHA256SUMS` records:
  - application archive
    `b8f105f843f1e5738ec28368b91e823019ab09492d81cee512ba4ddff69b9de4`;
  - protected config
    `c5644541ba4aa93cd097d657e48bf194d1f3db92aefda673817e2d2a76785693`;
  - trigger-inclusive database dump
    `96c132bf1a36f475b6639869c49767f262afb2d0ee0cedf7c30a185bb596d391`;
  - protected runtime-grant record
    `e4cab4e47ea97f9ccbdfb1a9c90a41de917ac5fb65d2695e4471581d6e4174b2`.
  The archive was readable, the dump-completed marker was present, and a
  scratch restore proved 31 base tables, 43 pre-migration triggers, one time
  entry, and one time event.
- A Safeharbor-only Apache endpoint lock returned `403` during the migration
  window without stopping shared Apache. The exact `safeharbor@localhost`
  runtime account was
  then locked, zero remaining connections were proved, and it was unlocked
  only after the exact migration replay and postflight succeeded.
- Byte provenance is recorded honestly. The initial clean-working-tree copy
  had Windows CRLF bytes at SHA-256
  `6730158402a6e4539af48fb265bee05bf33e2f4d273bddd7cf299ff666c373c3`.
  It applied and replayed successfully, but it was not claimed as the exact Git
  blob. The separately archived `git show` blob at SHA-256
  `0f07b0da0ab4c9e68308cc3ddf2af4c7e4e2171be81682168202245c8395eb25`
  was replayed under the database-account lock and emitted the same postflight:
  `1,1,1,1,12,0,0,0`.
- Final database evidence is `1:2:12:0:1:1:0`: one exact correction column,
  two auxiliary tables, twelve permanent triggers, zero staging triggers, one
  time entry, one time event, and zero measured-registry rows. Time facts were
  unchanged and there are still zero correction rows before the canary.
- Matching application code deployed from a clean detached checkout of the
  merge. All 128 non-stamp tracked paths matched after CRLF normalization, and
  the three intentional cache-stamp files matched after normalizing only their
  `?v=` token. There were no missing or extra non-brand application paths.
- The protected config remained byte-identical at the SHA above. The restored
  SSL vhost and its pre-lock copy both hash to
  `8f56a2ddfd42a072139d3ff7c111720940e307ffe2751bb03948fc5abc1a5e43`;
  Apache syntax passed. Public login returned `200`, and an unauthenticated
  timer application request returned `401`.
- Coastmark export remains disabled with empty tenant/client allowlists and no
  secret. Business-report configuration remains absent, all report tables are
  empty, and no scheduler was installed.

Migration 016 is additive. Application rollback may leave its nullable column,
auxiliary rows, and stronger database guards in place. A database restore is
disaster recovery, not the normal code rollback. During its seven-trigger
replacement, five temporary guards deny every time insert/update/delete and
audit update/delete. An interruption therefore fails closed; exact migration
replay restores all twelve permanent guards before those blockers are removed.
Five earlier temporary guards likewise protect direct auxiliary-table writes
while their permanent integrity triggers are replaced during a replay.
