# Append-only adjustments to approved technician time

Status: development slice only. It is not merged, migrated, or deployed.

## Fifth-grade version

An approved time entry is a signed time card. Safeharbor never erases or
rewrites that card. If an owner or admin finds a mistake, they add a new
numbered correction slip. The newest slip says how much of the original card
should count now and why.

The slip may lower the minutes, void them, or restore them up to the original
approved amount. It cannot add extra minutes or turn internal time into
billable time. Extra work must be entered as new pending time and approved in
the normal way.

## Owned facts and invariants

Safeharbor remains the system of record. `time_entries` and
`time_entry_events` stay immutable. `time_entry_approval_adjustments` is a
separate append-only history with one increasing version per tenant and time
entry.

- Only an active owner or admin in the entry's tenant may add a slip.
- The parent must already be approved and retain reviewer evidence.
- `effective_minutes` is between zero and the original approved minutes.
- An originally internal entry can never become billable.
- Zero effective minutes must be nonbillable.
- A nonempty UTF-8 reason is required and is at most 500 characters.
- The caller supplies one conservative adjustment key. An exact retry returns
  the stored row; reusing the key with changed facts is a conflict.
- The caller supplies the exact current version. The service locks the tenant
  namespace, parent, actor, and adjustment key in one fixed order, so two
  admins cannot both win from the same version or race one key across parents.
- Database triggers recheck tenant, active role, parent approval, bounds, and
  version even for direct SQL. Adjustment UPDATE and DELETE are permanently
  refused.

The Time page labels original and effective values separately. A lost browser
reply can resend only the same frozen key and facts. Validation errors reopen
editing; a stale version or changed authority refreshes the page to reconcile.

## Consumer rules

Live Reports and the restricted CSV use the newest effective minutes and
billable flag. Reports compare effective and raw totals and count corrected
entries. The Time ledger and CSV expose the original value plus adjustment
version, reason, and timestamp so the original is never disguised as changed.
Spreadsheet text cells remain formula-neutralized.

Archived business-report definition v1 promised the original approved-time
model. It therefore refuses to generate a new archive for a tenant/client
period containing an adjustment applicable by `generated_at`. Existing
archives remain byte-frozen. A future definition v2 needs a separately
published contract. Persisted report generation and adjustment creation lock
the same tenant row first: whichever wins completes before the other reads or
writes adjustment facts. Persisted generation establishes `generated_at` from
the database UTC clock only after that lock; a deterministic future test clock
may move the cutoff forward but can never backdate it. Report dry runs remain
intentionally unlocked, read-only, and best-effort, so they are never archive
evidence during concurrent operator changes.

The Coastmark v2 payload inspector refuses every adjusted entry, and its send
boundary is hard-retired for all entries before signing or network transport.
Its immutable source key and changed-facts conflict cannot represent a later
correction, and Safeharbor has no durable export claim/receipt or reversal
acknowledgement. Both production gates remain dark. Sending requires a new v3
with serialized export evidence and an append-only Coastmark reversal contract.

## Release and rollback gates

Migration 020 must run before application code that queries adjustments. Use
the privileged migration identity while the Safeharbor endpoint and runtime
database identity are locked, then prove fresh install, upgrade, exact replay,
interrupted-install fail-closed recovery, permanent triggers, zero staging
guards, a DML-only runtime, a real two-connection stale-version race, and both
orders of the persisted-report-versus-adjustment tenant-lock race.

Before deployment, take application and trigger-inclusive database backups and
capture raw time/event counts. After deployment, use a fresh signed-in 8 West
IT owner/admin session to lower one nonbillable canary, retry its exact key,
restore it to the original ceiling, and prove the original row/events did not
change. Keep Coastmark and report generation disabled.

Once an adjustment exists, rollback application code must remain
adjustment-aware. Older readers would resurrect the wrong total, so rollback is
a forward-fix or compatible-reader release, not a return to code that ignores
the append-only slips.
